<?php

/**
 * Hermiq SkillPipeline
 *
 * The skill services a consolidation passes through: maturity, versions, learnings and evals.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\SkillConsolidation
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
 *
 * @spec exclude Parameter object, no functional requirement of its own.
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\SkillConsolidation;

use OCA\Hermiq\Service\EvalRunService;
use OCA\Hermiq\Service\SkillLearningsCaptureService;
use OCA\Hermiq\Service\SkillLearningsPromotionService;
use OCA\Hermiq\Service\SkillMaturityService;
use OCA\Hermiq\Service\SkillVersionService;

/**
 * The skill services a consolidation passes through: maturity, versions, learnings and evals.
 *
 * @spec exclude Parameter object, no functional requirement of its own.
 */
final class SkillPipeline {

	/**
	 * Constructor.
	 *
	 * @param SkillMaturityService $maturityService Computed-maturity write guard on apply.
	 * @param SkillVersionService $versionService Base-version pin + post-apply version id.
	 * @param SkillLearningsPromotionService $promotionService Learnings grammar owner (entry counting, file name).
	 * @param SkillLearningsCaptureService $captureService `files` map helpers (fileContent).
	 * @param EvalRunService $evalRunService Paired draft-vs-active comparison (skill-evals seam).
	 *
	 * @spec exclude Parameter object, no functional requirement of its own.
	 */
	public function __construct(
		public readonly SkillMaturityService $maturityService,
		public readonly SkillVersionService $versionService,
		public readonly SkillLearningsPromotionService $promotionService,
		public readonly SkillLearningsCaptureService $captureService,
		public readonly EvalRunService $evalRunService,
	) {
	}//end __construct()
}//end class
