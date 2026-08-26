<?php

/**
 * Repair step for removing the background-job registrations left behind by the
 * move out of the retired `OCA\Hermiq\Cron` namespace.
 *
 * @category Repair
 * @package  OCA\Hermiq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);


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
 *
 * @spec exclude No canonical spec covers the OCA\Hermiq\Cron ->
 *  OCA\Hermiq\BackgroundJob move. ADR-100 Decision 3 is an architecture
 *  record, not a capability spec, and the jobs' behaviour is unchanged — only
 *  where their classes live. Pointing this at a spec describing what the jobs
 *  DO would claim conformance to a requirement that says nothing about
 *  registration cleanup.
 *
 * @psalm-suppress UnusedClass Nextcloud instantiates repair steps from
 *  the `<repair-steps>` block in appinfo/info.xml, which is XML — psalm
 *  reads PHP and therefore sees no caller. The sibling steps escape this
 *  only because unrelated docblocks happen to `{@see}` them, which is a
 *  coincidence rather than a contract.
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
	 *
	 * @spec exclude See the class docblock — no capability spec covers the
	 *  namespace move this step cleans up after.
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
	 *
	 * @spec exclude See the class docblock — no capability spec covers the
	 *  namespace move this step cleans up after.
	 */
	public function run(IOutput $output): void {
		foreach (self::RETIRED_JOB_CLASSES as $class) {
			try {
				// PHPStan: remove() is typed `class-string<IJob>|IJob`, and a
				// plain string is exactly what this step must pass — the
				// classes are GONE, which is the whole reason the row has to be
				// removed. A class-string is unobtainable by construction, and
				// remove() only ever uses the value as the `class` column to
				// delete on, so the narrower type is about callers registering
				// jobs, not callers retiring them.
				/**
				 * @phpstan-ignore argument.type
				 * @psalm-suppress ArgumentTypeCoercion
				 */
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
