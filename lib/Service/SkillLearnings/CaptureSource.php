<?php

/**
 * Hermiq CaptureSource
 *
 * The run a skill learning is captured from.
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
 * The run a skill learning is captured from.
 *
 * @spec exclude Parameter object, no functional requirement of its own.
 */
final class CaptureSource {

	/**
	 * Constructor.
	 *
	 * @param string $runId The originating run's id.
	 * @param string $trace The persisted run-trace excerpt.
	 * @param string $agentId The run's agent uuid (budget scope).
	 * @param string $organisation The run's organisation (budget + model-policy scope).
	 * @param string $evalFail Optional failed-eval marker ref (`''` = none).
	 *
	 * @spec exclude Parameter object, no functional requirement of its own.
	 */
	public function __construct(
		public readonly string $runId,
		public readonly string $trace,
		public readonly string $agentId,
		public readonly string $organisation,
		public readonly string $evalFail,
	) {
	}//end __construct()
}//end class
