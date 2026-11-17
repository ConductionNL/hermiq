<?php

/**
 * Hermiq CaptureUsage
 *
 * The tokens one learnings capture spent, and the run it spent them for.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\SkillLearnings
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

namespace OCA\Hermiq\Service\SkillLearnings;

/**
 * The tokens one learnings capture spent, and the run it spent them for.
 *
 * @spec exclude Parameter object, no functional requirement of its own.
 */
final class CaptureUsage {

	/**
	 * Constructor.
	 *
	 * @param string $scheduleUuid The originating run's schedule uuid.
	 * @param string $agentId The run's agent uuid.
	 * @param string $runId The originating run's id.
	 * @param int $promptTokens Estimated prompt tokens across the pass.
	 * @param int $completionTokens Estimated completion tokens across the pass.
	 *
	 * @spec exclude Parameter object, no functional requirement of its own.
	 */
	public function __construct(
		public readonly string $scheduleUuid,
		public readonly string $agentId,
		public readonly string $runId,
		public readonly int $promptTokens,
		public readonly int $completionTokens,
	) {
	}//end __construct()
}//end class
