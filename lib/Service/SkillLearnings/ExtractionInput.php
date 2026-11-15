<?php

/**
 * Hermiq ExtractionInput
 *
 * The candidates and run trace learnings are extracted from.
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
 * The candidates and run trace learnings are extracted from.
 *
 * @spec exclude Parameter object, no functional requirement of its own.
 */
final class ExtractionInput {

	/**
	 * Constructor.
	 *
	 * @param array<int, array<string, mixed>> $candidates The parsed current candidates.
	 * @param string $trace The run-trace excerpt.
	 *
	 * @spec exclude Parameter object, no functional requirement of its own.
	 */
	public function __construct(
		public readonly array $candidates,
		public readonly string $trace,
	) {
	}//end __construct()
}//end class
