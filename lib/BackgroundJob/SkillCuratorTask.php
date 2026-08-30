<?php

/**
 * Hermiq SkillCuratorTask.
 *
 * The background Curator job (skills-marketplace) that manages skill lifecycle without ever
 * hard-deleting: it transitions skills active→stale→archived by age threshold on each run.
 * A thin TimedJob wrapper (the ScheduleTask pattern) — all logic lives in
 * SkillMarketplaceService::curate() (ADR-002).
 *
 * @category Cron
 * @package  OCA\Hermiq\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/skills-marketplace/tasks.md#3-curator-background-job
 */

declare(strict_types=1);

namespace OCA\Hermiq\BackgroundJob;

use OCA\Hermiq\Service\SkillMarketplaceService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/**
 * Daily background job that curates skill lifecycle (active→stale→archived, no hard-delete).
 *
 * @psalm-api
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 *
 * @spec openspec/changes/skills-marketplace/tasks.md#task-3-1
 */
class SkillCuratorTask extends TimedJob {
	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time Time factory for TimedJob scheduling.
	 * @param SkillMarketplaceService $marketplaceService Service that curates skill lifecycle.
	 *
	 * @spec openspec/changes/skills-marketplace/tasks.md#task-3-1
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly SkillMarketplaceService $marketplaceService,
	) {
		parent::__construct(time: $time);

		// Curation is not time-critical — run daily.
		$this->setInterval(seconds: 86400);
		$this->setAllowParallelRuns(allow: false);

	}//end __construct()

	/**
	 * Execute one curation pass.
	 *
	 * @param mixed $argument The (unused) background-job argument.
	 *
	 * @return void
	 *
	 * @phpstan-param mixed $argument
	 *
	 * @spec openspec/changes/skills-marketplace/tasks.md#task-3-1
	 */
	public function run(mixed $argument): void {
		$this->marketplaceService->curate();

	}//end run()
}//end class
