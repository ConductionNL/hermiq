<?php

/**
 * Hermiq Trace Export Job.
 *
 * Sends the waiting traces off the request path, one batch of at most 50 per run of the
 * job. A failed batch is retried three times with growing backoff, then dropped with one
 * warning. After each batch the `trace-export` connection is reported, throttled, so the
 * Integrations page shows the last send or the error. A failure never touches a run.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category BackgroundJob
 * @package  OCA\Hermiq\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/observability-export-traces/specs/trace-export/spec.md#requirement-export-never-slows-or-changes-a-run-req-trex-003
 */

declare(strict_types=1);

namespace OCA\Hermiq\BackgroundJob;

use OCA\Hermiq\Service\Connection\ConnectionReporter;
use OCA\Hermiq\Service\TraceExport\OtlpPayloadBuilder;
use OCA\Hermiq\Service\TraceExport\TraceExportQueue;
use OCA\Hermiq\Service\TraceExport\TraceExportSender;
use OCA\Hermiq\Service\TraceExport\TraceExportSettings;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Send one batch of traces, retrying and finally dropping it.
 */
class TraceExportJob extends QueuedJob {

	/**
	 * Retries after the first try.
	 *
	 * @var integer
	 */
	public const MAX_RETRIES = 3;

	/**
	 * Seconds before the first retry; doubled for each later one.
	 *
	 * @var integer
	 */
	public const BACKOFF_SECONDS = 60;

	/**
	 * The connection this job reports.
	 *
	 * @var string
	 */
	public const CONNECTION_KEY = 'trace-export';

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory                $time      Clock.
	 * @param TraceExportQueue            $queue     The waiting traces and batches; re-queues a retry or the next batch.
	 * @param TraceExportSettings         $settings  The export settings.
	 * @param TraceExportSender           $sender    Sends it.
	 * @param ConnectionReporter          $reporter  Reports the `trace-export` connection, throttled.
	 * @param LoggerInterface             $logger    Logger.
	 *
	 * @return void
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly TraceExportQueue $queue,
		private readonly TraceExportSettings $settings,
		private readonly TraceExportSender $sender,
		private readonly ConnectionReporter $reporter,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);

	}//end __construct()

	/**
	 * Send a batch: the one named in the argument (a retry) or the next waiting one.
	 *
	 * @param mixed $argument `[]`, or `{batch, attempt, notBefore}` for a retry.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/observability-export-traces/tasks.md#task-3-the-queue-the-job-and-the-broker-call
	 */
	protected function run($argument): void {
		try {
			$this->sendBatch(argument: (array)$argument);
		} catch (Throwable $e) {
			$this->logger->warning('[TraceExportJob] Trace export failed: ' . $e->getMessage());
		}

	}//end run()

	/**
	 * Send one batch and decide what follows.
	 *
	 * @param array<string, mixed> $argument The job argument.
	 *
	 * @return void
	 */
	private function sendBatch(array $argument): void {
		$settings = $this->settings->get();
		if ($this->deferred(argument: $argument) === true || $this->disabled(argument: $argument, settings: $settings) === true) {
			return;
		}

		$batch = (string)($argument['batch'] ?? '');
		if ($batch === '') {
			$batch = (string)$this->queue->takeBatch();
		}

		$traces = $this->queue->readBatch(name: $batch);
		if ($batch === '' || $traces === null) {
			return;
		}

		$resource = ['hermiq.organisation' => (string)$settings['organisation']];
		$outcome = $this->sender->send(payload: (new OtlpPayloadBuilder())->build(traces: $traces, resource: $resource), settings: $settings);
		$this->settle(batch: ['name' => $batch, 'attempt' => (int)($argument['attempt'] ?? 0)], outcome: $outcome);
		if ($this->queue->hasPending() === true) {
			$this->queue->requeue(argument: []);
		}

	}//end sendBatch()

	/**
	 * Put a retry back unchanged when its time has not come yet.
	 *
	 * @param array<string, mixed> $argument The job argument.
	 *
	 * @return bool True when the retry was put back.
	 */
	private function deferred(array $argument): bool {
		if ((string)($argument['batch'] ?? '') === '' || (int)($argument['notBefore'] ?? 0) <= $this->time->getTime()) {
			return false;
		}

		$this->queue->requeue(argument: $argument);
		return true;

	}//end deferred()

	/**
	 * When export was switched off, drop the batch that waits: it is not sent.
	 *
	 * @param array<string, mixed> $argument The job argument.
	 * @param array<string, mixed> $settings The trace export settings.
	 *
	 * @return bool True when export is off.
	 */
	private function disabled(array $argument, array $settings): bool {
		if ($settings['enabled'] === true) {
			return false;
		}

		$batch = (string)($argument['batch'] ?? '');
		if ($batch !== '') {
			$this->queue->dropBatch(name: $batch);
		}

		return true;

	}//end disabled()

	/**
	 * Report the outcome, and retry or drop a failed batch.
	 *
	 * @param array{name: string, attempt: int}                          $batch   The batch name and the retries already made.
	 * @param array{ok: bool, retry: bool, status: int, message: string} $outcome The send's outcome.
	 *
	 * @return void
	 */
	private function settle(array $batch, array $outcome): void {
		if ($outcome['ok'] === true) {
			$this->queue->dropBatch(name: $batch['name']);
			$this->reporter->reportThrottled(key: self::CONNECTION_KEY, status: 'configured', message: 'Last batch sent at ' . gmdate('Y-m-d H:i') . ' UTC.');
			return;
		}

		if ($outcome['retry'] === true && $batch['attempt'] < self::MAX_RETRIES) {
			$this->retry(batch: $batch);
			return;
		}

		$this->queue->dropBatch(name: $batch['name']);
		$this->logger->warning('[TraceExportJob] A trace batch was dropped after ' . ($batch['attempt'] + 1) . ' tries: ' . $outcome['message']);
		$this->reporter->reportThrottled(key: self::CONNECTION_KEY, status: 'error', message: $outcome['message']);

	}//end settle()

	/**
	 * Queue the next try of a batch, after an exponential backoff.
	 *
	 * @param array{name: string, attempt: int} $batch The batch name and the retries already made.
	 *
	 * @return void
	 */
	private function retry(array $batch): void {
		$attempt = $batch['attempt'];
		$this->queue->requeue(
			argument: [
				'batch' => $batch['name'],
				'attempt' => ($attempt + 1),
				'notBefore' => ($this->time->getTime() + (self::BACKOFF_SECONDS * (2 ** $attempt))),
			]
		);

	}//end retry()
}//end class
