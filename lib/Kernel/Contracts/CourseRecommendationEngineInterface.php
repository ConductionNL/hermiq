<?php

/**
 * Contract of CourseRecommendationEngine
 *
 * The public surface of CourseRecommendationEngine that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Hermiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Kernel\Contracts;

/**
 * Contract of CourseRecommendationEngine.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CourseRecommendationEngineInterface {

	/**
	 * Serve the learner's current recommendations, regenerating when stale/absent.
	 *
	 * The AiFeature gate and the Scholiq-installed check run UNCONDITIONALLY first
	 * — even on what would otherwise be a fresh-cache hit — so a feature disabled
	 * (or Scholiq uninstalled) after a recommendation was generated stops being
	 * served immediately, not only after the 24h TTL (EU AI Act fail-closed
	 * posture: "the feature genuinely does not compute or serve anything
	 * pre-acknowledgement", design.md "EU AI Act posture" §1).
	 *
	 * @param string $learnerUid The caller's own Nextcloud user id (self-scoped;
	 *                           callers MUST resolve this from their own session,
	 *                           never from request input — spec.md "Recommendation
	 *                           access is self-scoped").
	 *
	 * @return array<string, mixed> The CourseRecommendation payload (plus `uuid`
	 *                              when persisted).
	 *
	 * @spec openspec/changes/archive/2026-07-16-ai-course-recommendations/tasks.md#task-2-8
	 */
	public function getOrRegenerate(string $learnerUid): array;
}//end interface
