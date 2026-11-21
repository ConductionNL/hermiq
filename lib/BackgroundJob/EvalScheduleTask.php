<?php

/**
 * Hourly tick that runs due eval schedules (reuse-scheduled-bias-evals).
 *
 * Never parallel. The work is in `EvalScheduleRunner`; this job supplies the
 * moment and logs each schedule that could not run.
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
 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-dataset-can-run-against-an-agent-on-a-schedule-req-biaseval-003
 */

declare(strict_types=1);

namespace OCA\Hermiq\BackgroundJob;

use DateTimeImmutable;
use OCA\Hermiq\Service\EvalScheduleRunner;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Hourly runner of due eval schedules.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $argument is part of the background job signature.
 *
 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-dataset-can-run-against-an-agent-on-a-schedule-req-biaseval-003
 */
class EvalScheduleTask extends TimedJob {

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory       $time   The clock.
	 * @param EvalScheduleRunner $runner Runs the due schedules.
	 * @param LoggerInterface    $logger Records a schedule that could not run.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-dataset-can-run-against-an-agent-on-a-schedule-req-biaseval-003
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly EvalScheduleRunner $runner,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);

		// A monthly schedule runs within the hour it is due, and never twice at once.
		$this->setAllowParallelRuns(allow: false);
		$this->setInterval(seconds: 3600);
	}//end __construct()

	/**
	 * Run the due schedules and log the ones that failed.
	 *
	 * @param mixed $argument Unused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-dataset-can-run-against-an-agent-on-a-schedule-req-biaseval-003
	 */
	protected function run($argument): void {
		$failures = $this->runner->runDue(now: new DateTimeImmutable(datetime: '@' . $this->time->getTime()));
		foreach ($failures as $schedule => $reason) {
			$this->logger->warning(
				message: '[hermiq] A scheduled eval run could not run: ' . $reason,
				context: ['schedule' => $schedule]
			);
		}
	}//end run()
}//end class
