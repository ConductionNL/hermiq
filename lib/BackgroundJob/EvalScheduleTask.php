<?php

/**
 * Hermiq EvalScheduleTask: runs the due eval schedules.
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-dataset-can-run-against-an-agent-on-a-schedule-req-biaseval-003
 */

declare(strict_types=1);

namespace OCA\Hermiq\BackgroundJob;

use OCA\Hermiq\Kernel\Contracts\EvalScheduleServiceInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Hourly, never in parallel: one tick runs every due eval schedule (ADR-002).
 * Eval schedules are monthly by default, so an hour is fine-grained enough.
 *
 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-dataset-can-run-against-an-agent-on-a-schedule-req-biaseval-003
 */
class EvalScheduleTask extends TimedJob {

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory                 $time      The clock.
	 * @param EvalScheduleServiceInterface $schedules Runs the due schedules.
	 * @param LoggerInterface              $logger    Logs a failed tick.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly EvalScheduleServiceInterface $schedules,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: 3600);
		$this->setAllowParallelRuns(allow: false);
	}//end __construct()

	/**
	 * Run the due eval schedules.
	 *
	 * @param mixed $argument Unused: the job polls every due schedule.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-dataset-can-run-against-an-agent-on-a-schedule-req-biaseval-003
	 */
	public function run(mixed $argument): void {
		try {
			$this->schedules->runDue();
		} catch (Throwable $e) {
			$this->logger->error('[hermiq] Eval schedules failed this tick: ' . $e->getMessage(), ['exception' => $e]);
		}
	}//end run()
}//end class
