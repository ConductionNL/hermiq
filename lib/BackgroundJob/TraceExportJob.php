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
use OCP\BackgroundJob\IJobList;
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
	 * @param TraceExportQueue            $queue     The waiting traces and batches.
	 * @param TraceExportSettings         $settings  The export settings.
	 * @param OtlpPayloadBuilder          $builder   Builds the OTLP body.
	 * @param TraceExportSender           $sender    Sends it.
	 * @param IJobList                    $jobList   Re-queues a retry or the next batch.
	 * @param ConnectionReporter          $reporter  Reports the `trace-export` connection, throttled.
	 * @param LoggerInterface             $logger    Logger.
	 *
	 * @return void
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly TraceExportQueue $queue,
		private readonly TraceExportSettings $settings,
		private readonly OtlpPayloadBuilder $builder,
		private readonly TraceExportSender $sender,
		private readonly IJobList $jobList,
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
		$batch = (string)($argument['batch'] ?? '');
		$attempt = (int)($argument['attempt'] ?? 0);

		if ($batch !== '' && (int)($argument['notBefore'] ?? 0) > $this->time->getTime()) {
			// Not yet time for this retry: put it back unchanged.
			$this->jobList->add(self::class, $argument);
			return;
		}

		if ($settings['enabled'] !== true) {
			// Export was switched off: what waits is not sent.
			if ($batch !== '') {
				$this->queue->dropBatch(name: $batch);
			}

			return;
		}

		if ($batch === '') {
			$batch = (string)$this->queue->takeBatch();
		}

		$traces = $this->queue->readBatch(name: $batch);
		if ($batch === '' || $traces === null) {
			return;
		}

		$resource = ['hermiq.organisation' => (string)$settings['organisation']];
		$outcome = $this->sender->send(payload: $this->builder->build(traces: $traces, resource: $resource), settings: $settings);
		$this->settle(batch: $batch, attempt: $attempt, outcome: $outcome);

		if ($this->queue->hasPending() === true) {
			$this->jobList->add(self::class, []);
		}

	}//end sendBatch()

	/**
	 * Report the outcome, and retry or drop a failed batch.
	 *
	 * @param string                                                      $batch   The batch name.
	 * @param int                                                         $attempt The retries already made.
	 * @param array{ok: bool, retry: bool, status: int, message: string} $outcome The send's outcome.
	 *
	 * @return void
	 */
	private function settle(string $batch, int $attempt, array $outcome): void {
		if ($outcome['ok'] === true) {
			$this->queue->dropBatch(name: $batch);
			$this->reporter->reportThrottled(key: self::CONNECTION_KEY, status: 'configured', message: 'Last batch sent at ' . gmdate('Y-m-d H:i') . ' UTC.');
			return;
		}

		if ($outcome['retry'] === true && $attempt < self::MAX_RETRIES) {
			$this->jobList->add(
				self::class,
				[
					'batch' => $batch,
					'attempt' => ($attempt + 1),
					'notBefore' => ($this->time->getTime() + (self::BACKOFF_SECONDS * (2 ** $attempt))),
				]
			);
			return;
		}

		$this->queue->dropBatch(name: $batch);
		$this->logger->warning('[TraceExportJob] A trace batch was dropped after ' . ($attempt + 1) . ' tries: ' . $outcome['message']);
		$this->reporter->reportThrottled(key: self::CONNECTION_KEY, status: 'error', message: $outcome['message']);

	}//end settle()
}//end class
