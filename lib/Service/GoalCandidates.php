<?php

/**
 * Hermiq GoalCandidates.
 *
 * The learning goals a goal suggestion chooses from: as the caller listed them,
 * and as they were sent to the model (flattened, blank entries left out, every
 * goal keeping its position in the caller's list).
 *
 * @category ValueObject
 * @package  OCA\Hermiq\Service
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
 * @spec openspec/changes/lesson-authoring-ai-delegate/specs/lesson-authoring/spec.md#requirement-req-007-suggest-which-given-goals-a-lesson-covers
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

/**
 * The candidate goals of one suggestion.
 *
 * @spec openspec/changes/lesson-authoring-ai-delegate/specs/lesson-authoring/spec.md#requirement-req-007-suggest-which-given-goals-a-lesson-covers
 */
final class GoalCandidates {

	/**
	 * Hold the candidates.
	 *
	 * @param array<int, mixed>  $original The goals in the caller's order, reindexed from zero.
	 * @param array<int, string> $goals    The goals as sent, keyed by their position in `$original`.
	 */
	public function __construct(
		public readonly array $original,
		public readonly array $goals,
	) {
	}//end __construct()
}//end class
