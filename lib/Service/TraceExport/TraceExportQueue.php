<?php

/**
 * Hermiq Trace Export Queue.
 *
 * The hand-off between a finished run and the background job that exports it. A writer
 * calls `enqueueRun()` or `enqueueChatTurn()`; when export is on for that source, the
 * trace is built (in memory, no network) and kept as a file in the app's data folder,
 * and one `TraceExportJob` is queued to send it. Nextcloud caps a job argument at 4000
 * characters, so the trace itself does not ride on the job.
 *
 * Never throws and never changes the run: every failure is logged and swallowed.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Hermiq\Service\TraceExport
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

namespace OCA\Hermiq\Service\TraceExport;

use OCA\Hermiq\BackgroundJob\TraceExportJob;
use OCA\Hermiq\Kernel\AppIdentity;
use OCP\BackgroundJob\IJobList;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Keep finished runs' traces until the export job sends them.
 */
class TraceExportQueue {

	/**
	 * The app data folder holding traces not yet sent.
	 *
	 * @var string
	 */
	public const PENDING_FOLDER = 'trace-export-pending';

	/**
	 * The app data folder holding batches being sent or retried.
	 *
	 * @var string
	 */
	public const BATCH_FOLDER = 'trace-export-batches';

	/**
	 * The most traces in one batch.
	 *
	 * @var integer
	 */
	public const BATCH_SIZE = 50;

	/**
	 * The app's data, resolved on first use.
	 *
	 * @var IAppData|null
	 */
	private ?IAppData $appData = null;

	/**
	 * Constructor.
	 *
	 * @param TraceExportSettings $settings        Whether and how much to export.
	 * @param TraceExporter       $exporter        Builds the trace.
	 * @param IJobList            $jobList         Queues the export job.
	 * @param IAppDataFactory     $appDataFactory  The app data the traces wait in.
	 * @param LoggerInterface     $logger          Logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly TraceExportSettings $settings,
		private readonly TraceExporter $exporter,
		private readonly IJobList $jobList,
		private readonly IAppDataFactory $appDataFactory,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Hand a finished scheduled, flow, webhook or skill run to the export, when export is on.
	 *
	 * @param array<string, mixed> $context      The run's audit context.
	 * @param string               $trigger      `schedule`, `flow`, `webhook` or `skill`.
	 * @param string               $organisation The run's organisation, '' when none.
	 *
	 * @return bool Whether the run was queued.
	 *
	 * @spec openspec/changes/observability-export-traces/tasks.md#task-4-every-run-writer-and-the-chat-turn-enqueue
	 */
	public function enqueueRun(array $context, string $trigger, string $organisation = ''): bool {
		try {
			if ($this->settings->exports(source: 'runs') === false) {
				return false;
			}

			$content = (string)$this->settings->get()['content'];
			return $this->store(trace: $this->exporter->fromRun(context: $context, trigger: $trigger, content: $content, organisation: $organisation));
		} catch (Throwable $e) {
			$this->logger->warning('[TraceExportQueue] A run could not be queued for trace export: ' . $e->getMessage());
			return false;
		}

	}//end enqueueRun()

	/**
	 * Hand a finished chat turn to the export, when export is on.
	 *
	 * @param array<string, mixed> $turn         The turn (see TraceExporter::fromChatTurn()).
	 * @param string               $organisation The turn's organisation, '' when none.
	 *
	 * @return bool Whether the turn was queued.
	 *
	 * @spec openspec/changes/observability-export-traces/tasks.md#task-4-every-run-writer-and-the-chat-turn-enqueue
	 */
	public function enqueueChatTurn(array $turn, string $organisation = ''): bool {
		try {
			if ($this->settings->exports(source: 'chat') === false) {
				return false;
			}

			$content = (string)$this->settings->get()['content'];
			return $this->store(trace: $this->exporter->fromChatTurn(turn: $turn, content: $content, organisation: $organisation));
		} catch (Throwable $e) {
			$this->logger->warning('[TraceExportQueue] A chat turn could not be queued for trace export: ' . $e->getMessage());
			return false;
		}

	}//end enqueueChatTurn()

	/**
	 * Move up to one batch of waiting traces into a batch file.
	 *
	 * @return string|null The batch name, or null when nothing waits.
	 *
	 * @spec openspec/changes/observability-export-traces/tasks.md#task-3-the-queue-the-job-and-the-broker-call
	 */
	public function takeBatch(): ?string {
		$pending = $this->folder(name: self::PENDING_FOLDER);
		$traces = [];
		foreach (array_slice($pending->getDirectoryListing(), 0, self::BATCH_SIZE) as $file) {
			$trace = json_decode($file->getContent(), true);
			if (is_array($trace) === true) {
				$traces[] = $trace;
			}

			$file->delete();
		}

		if ($traces === []) {
			return null;
		}

		$name = 'batch-' . bin2hex(random_bytes(8)) . '.json';
		$this->folder(name: self::BATCH_FOLDER)->newFile($name, (string)json_encode($traces));

		return $name;

	}//end takeBatch()

	/**
	 * The traces of a batch.
	 *
	 * @param string $name The batch name.
	 *
	 * @return list<array<string, mixed>>|null The traces, or null when the batch is gone.
	 *
	 * @spec openspec/changes/observability-export-traces/tasks.md#task-3-the-queue-the-job-and-the-broker-call
	 */
	public function readBatch(string $name): ?array {
		try {
			$traces = json_decode($this->folder(name: self::BATCH_FOLDER)->getFile($name)->getContent(), true);
		} catch (NotFoundException) {
			return null;
		}

		if (is_array($traces) === false) {
			return null;
		}

		return array_values(array_filter($traces, 'is_array'));

	}//end readBatch()

	/**
	 * Forget a batch, sent or dropped.
	 *
	 * @param string $name The batch name.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/observability-export-traces/tasks.md#task-3-the-queue-the-job-and-the-broker-call
	 */
	public function dropBatch(string $name): void {
		try {
			$this->folder(name: self::BATCH_FOLDER)->getFile($name)->delete();
		} catch (NotFoundException) {
			// Already gone.
		}

	}//end dropBatch()

	/**
	 * Whether traces are waiting.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/observability-export-traces/tasks.md#task-3-the-queue-the-job-and-the-broker-call
	 */
	public function hasPending(): bool {
		return ($this->folder(name: self::PENDING_FOLDER)->getDirectoryListing() !== []);

	}//end hasPending()

	/**
	 * Queue the trace export job with an argument: `[]` to drain the queue, or a retry.
	 *
	 * @param array<string, mixed> $argument The job argument.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/observability-export-traces/tasks.md#task-3-the-queue-the-job-and-the-broker-call
	 */
	public function requeue(array $argument): void {
		$this->jobList->add(TraceExportJob::class, $argument);

	}//end requeue()

	/**
	 * Keep a trace and make sure a job will send it.
	 *
	 * @param array<string, mixed> $trace The trace record.
	 *
	 * @return bool Always true; failures throw to the caller's guard.
	 */
	private function store(array $trace): bool {
		$this->folder(name: self::PENDING_FOLDER)->newFile((string)$trace['traceId'] . '.json', (string)json_encode($trace));
		// One job drains the queue: the same (class, argument) pair is queued once.
		$this->jobList->add(TraceExportJob::class, []);

		return true;

	}//end store()

	/**
	 * An app data folder, created when missing.
	 *
	 * @param string $name The folder name.
	 *
	 * @return ISimpleFolder
	 */
	private function folder(string $name): ISimpleFolder {
		if ($this->appData === null) {
			$this->appData = $this->appDataFactory->get(AppIdentity::APP_ID);
		}

		try {
			return $this->appData->getFolder($name);
		} catch (NotFoundException) {
			return $this->appData->newFolder($name);
		}

	}//end folder()
}//end class
