<?php

/**
 * Hermiq ScheduleRunJob
 *
 * Runs one queued occurrence of a schedule on a background worker.
 *
 * @category BackgroundJob
 * @package  OCA\Hermiq\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\BackgroundJob;

use OCA\Hermiq\Kernel\Contracts\ScheduleServiceInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * One occurrence the schedule tick committed and queued, `{scheduleId, occurrenceKey}`.
 *
 * A `QueuedJob`, so any number of `occ background-job:worker` processes may
 * run these at once; Nextcloud removes the job from the list before running
 * it. The claim, the gates and the limits are ScheduleService::runQueued()'s,
 * so this job stays a wrapper (hermiq ADR-002, hydra ADR-069).
 *
 * @spec openspec/specs/agent-schedule/spec.md#requirement-each-occurrence-is-claimed-once-whatever-the-number-of-workers-req-prun-001
 */
class ScheduleRunJob extends QueuedJob {

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory             $time            Nextcloud's clock.
	 * @param ScheduleServiceInterface $scheduleService Runs the queued occurrence.
	 * @param LoggerInterface          $logger          Logs a job that could not run.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly ScheduleServiceInterface $scheduleService,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
	}//end __construct()

	/**
	 * Run the occurrence the argument names.
	 *
	 * @param mixed $argument `{scheduleId, occurrenceKey}`.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agent-schedule/spec.md#requirement-each-occurrence-is-claimed-once-whatever-the-number-of-workers-req-prun-001
	 */
	protected function run($argument): void {
		$scheduleId = '';
		$occurrenceKey = '';
		if (is_array($argument) === true) {
			$scheduleId = (string)($argument['scheduleId'] ?? '');
			$occurrenceKey = (string)($argument['occurrenceKey'] ?? '');
		}

		if ($scheduleId === '' || $occurrenceKey === '') {
			$this->logger->warning(
				message: '[ScheduleRunJob] A queued run without a schedule or occurrence was dropped',
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return;
		}

		try {
			$outcome = $this->scheduleService->runQueued(scheduleUuid: $scheduleId, occurrenceKey: $occurrenceKey);
		} catch (Throwable $e) {
			// The occurrence stays in flight; the tick's sweep records it as failed.
			$this->logger->error(
				message: '[ScheduleRunJob] A queued scheduled run failed before it was recorded',
				context: ['file' => __FILE__, 'line' => __LINE__, 'schedule' => $scheduleId, 'error' => $e->getMessage()]
			);
			return;
		}

		$this->logger->debug(
			message: '[ScheduleRunJob] Queued scheduled run handled',
			context: ['file' => __FILE__, 'line' => __LINE__, 'schedule' => $scheduleId, 'outcome' => $outcome]
		);

	}//end run()
}//end class
