<?php

/**
 * Hermiq SkillConsolidationTask.
 *
 * The daily consolidation job (skill-self-improvement, ADR-068 §5) — a thin TimedJob
 * sibling of SkillCuratorTask. All logic lives in `SkillConsolidationService::runPass()`
 * (ADR-002): per skill it reconciles decided-but-unapplied Approvals (idempotent),
 * advances pending drafts through pre-qualification, evaluates the threshold/regression
 * triggers, and runs the post-acceptance regression watch.
 *
 * Fail-safe construction (one poison background job must not brick the fleet cron):
 * the constructor does NO I/O and resolves NO cross-app service — the service (whose
 * dependency graph reaches into OpenRegister) is resolved lazily inside `run()`, and
 * the whole pass is try/catch wrapped on top of the service's own per-skill isolation.
 *
 * @category Cron
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
 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-consolidation-proposes-a-draft-version-and-never-edits-the-active-skill
 */

declare(strict_types=1);

namespace OCA\Hermiq\BackgroundJob;

use OCA\Hermiq\Service\SkillConsolidationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Daily background job that proposes, pre-qualifies and reconciles skill
 * consolidation drafts — draft-only, approval-gated, never editing the active skill.
 *
 * @psalm-api
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 *
 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-consolidation-proposes-a-draft-version-and-never-edits-the-active-skill
 */
class SkillConsolidationTask extends TimedJob {
	/**
	 * Constructor — deliberately NO cross-app dependency here: a FATAL at job
	 * construction escapes cron's try/catch and aborts the whole background pass
	 * for every app, so the service is resolved lazily in run().
	 *
	 * @param ITimeFactory $time Time factory for TimedJob scheduling.
	 * @param ContainerInterface $container Lazy SkillConsolidationService resolution.
	 * @param LoggerInterface $logger PSR-3 logger (pass-level failure isolation).
	 *
	 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-consolidation-proposes-a-draft-version-and-never-edits-the-active-skill
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);

		// Consolidation is not time-critical — run daily, like the Curator.
		$this->setInterval(seconds: 86400);
		$this->setAllowParallelRuns(allow: false);

	}//end __construct()

	/**
	 * Execute one consolidation pass. Never throws: OpenRegister being absent (or any
	 * pass-level failure) is logged and swallowed so this job can never poison the
	 * shared cron loop.
	 *
	 * @param mixed $argument The (unused) background-job argument.
	 *
	 * @return void
	 *
	 * @phpstan-param mixed $argument
	 *
	 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-consolidation-proposes-a-draft-version-and-never-edits-the-active-skill
	 */
	public function run(mixed $argument): void {
		try {
			$service = $this->container->get(SkillConsolidationService::class);
			$service->runPass();
		} catch (Throwable $e) {
			$this->logger->warning(
				'Hermiq skill consolidation pass failed: ' . $e->getMessage(),
				['exception' => $e]
			);
		}

	}//end run()
}//end class
