<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\Hermiq\Repair;

use OCP\BackgroundJob\IJobList;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Removes the `oc_jobs` rows left behind when this app's background jobs moved
 * out of the retired `OCA\Hermiq\Cron` namespace into
 * `OCA\Hermiq\BackgroundJob` (ADR-100 Decision 3).
 *
 * THIS IS NOT PRECAUTIONARY — THE ORPHANS WERE MEASURED. On a live instance
 * carrying the equivalent move for opencatalogi, `oc_jobs` still held
 * `OCA\OpenCatalogi\Cron\DirectorySync` and `…\Cron\RetentionEvaluation`
 * beside their `BackgroundJob` replacements, naming classes that no longer
 * exist.
 *
 * WHY THE MOVE ALONE DOES NOT DO THIS. `appinfo/info.xml`'s `<job>` entries are
 * a REGISTRATION instruction, not a description of state. On upgrade Nextcloud
 * ADDS any job it does not already have; it never removes one whose class
 * disappeared, because it cannot tell a renamed class from one that is merely
 * unavailable this boot. So the rename leaves the instance holding both rows.
 *
 * The orphan is not inert. `\OC\BackgroundJob\JobList::buildJob()` cannot
 * instantiate a class that does not exist, so every cron tick that reaches the
 * row fails to build it, and that failure is logged rather than raised — the
 * quiet kind of broken, on an instance where the replacement job runs fine and
 * nothing looks wrong.
 *
 * Idempotent: `IJobList::remove()` on an absent class is a no-op, so a fresh
 * install passes through unchanged and re-running costs one DELETE matching
 * nothing.
 */
class RemoveRetiredCronJobs implements IRepairStep {

	/**
	 * The classes retired by the move, named in full and deliberately as
	 * literals.
	 *
	 * String constants rather than `SomeClass::class` because these classes NO
	 * LONGER EXIST — a `::class` reference would not compile, which is the
	 * whole point of the list.
	 *
	 * Classes the app never registered in `info.xml` are included too: they
	 * lived in the same retired namespace, and an instance that ever registered
	 * one by hand carries the same dead row. Removing a registration that was
	 * never there costs nothing.
	 *
	 * @var string[]
	 */
	private const RETIRED_JOB_CLASSES = [
		'OCA\Hermiq\Cron\AgentRunRequestedJob',
		'OCA\Hermiq\Cron\ConversationTitleJob',
		'OCA\Hermiq\Cron\ScheduleTask',
		'OCA\Hermiq\Cron\SkillConsolidationTask',
		'OCA\Hermiq\Cron\SkillCuratorTask',
		'OCA\Hermiq\Cron\SkillLearningsCaptureJob',
		'OCA\Hermiq\Cron\SkillLearningsPromotionTask',
		'OCA\Hermiq\Cron\TalkTurnJob',
		'OCA\Hermiq\Cron\WebhookAgentRunJob',
	];

	/**
	 * @param IJobList        $jobList The background job list.
	 * @param LoggerInterface $logger  The logger.
	 */
	public function __construct(
		private IJobList $jobList,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The step's name, as shown by `occ upgrade`.
	 *
	 * @return string The name.
	 */
	public function getName(): string {
		return 'Remove background-job registrations for the retired Hermiq\Cron namespace';
	}//end getName()

	/**
	 * Remove each retired job registration.
	 *
	 * Never raises. A repair step that aborts the upgrade over a job row would
	 * trade a dormant orphan for an instance that will not start, which is the
	 * worse failure — so a removal that goes wrong is reported and the step
	 * continues with the next class.
	 *
	 * @param IOutput $output The upgrade output.
	 *
	 * @return void
	 */
	public function run(IOutput $output): void {
		foreach (self::RETIRED_JOB_CLASSES as $class) {
			try {
				$this->jobList->remove($class);
				$output->info('Removed retired background job registration: ' . $class);
			} catch (Throwable $e) {
				// Reported, not raised — see the docblock above.
				$this->logger->warning(
					'[RemoveRetiredCronJobs] Could not remove ' . $class . ': ' . $e->getMessage(),
					['app' => 'hermiq', 'exception' => $e]
				);
				$output->warning('Could not remove ' . $class . ': ' . $e->getMessage());
			}
		}

	}//end run()
}//end class
