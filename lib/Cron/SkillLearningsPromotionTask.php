<?php

/**
 * Hermiq SkillLearningsPromotionTask.
 *
 * The daily mechanical learnings promotion job (skill-learnings, ADR-068 §3): moves
 * candidates confirmed in 3+ distinct runs (or explaining a failed eval case) from
 * `learning-candidates.md` into the five-section `learnings.md`, and drops candidates
 * untouched for 30 days — WITHOUT any LLM call. A thin TimedJob wrapper (the
 * SkillCuratorTask pattern) — all logic lives in SkillLearningsPromotionService
 * (ADR-002).
 *
 * @category Cron
 * @package  OCA\Hermiq\Cron
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
 * @spec openspec/specs/skill-learnings/spec.md#requirement-promotion-is-a-mechanical-two-stage-background-pass
 */

declare(strict_types=1);

namespace OCA\Hermiq\Cron;

use OCA\Hermiq\Service\SkillLearningsPromotionService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/**
 * Daily background job that mechanically promotes/expires learnings candidates.
 *
 * @psalm-api
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 *
 * @spec openspec/specs/skill-learnings/spec.md#requirement-promotion-is-a-mechanical-two-stage-background-pass
 */
class SkillLearningsPromotionTask extends TimedJob {
	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time Time factory for TimedJob scheduling.
	 * @param SkillLearningsPromotionService $promotionService Service that promotes/expires candidates.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly SkillLearningsPromotionService $promotionService,
	) {
		parent::__construct(time: $time);

		// Learnings are a slow loop by design — run daily; parallel runs disallowed
		// (the SkillCuratorTask pattern).
		$this->setInterval(seconds: 86400);
		$this->setAllowParallelRuns(allow: false);

	}//end __construct()

	/**
	 * Execute one promotion pass (per-skill isolation lives in the service).
	 *
	 * @param mixed $argument The (unused) background-job argument.
	 *
	 * @return void
	 *
	 * @phpstan-param mixed $argument
	 *
	 * @spec openspec/specs/skill-learnings/spec.md#requirement-promotion-is-a-mechanical-two-stage-background-pass
	 */
	public function run(mixed $argument): void {
		$this->promotionService->promoteAll();

	}//end run()
}//end class
