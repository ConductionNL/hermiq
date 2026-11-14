<?php

/**
 * Hermiq L5EvidenceContext
 *
 * The dataset, attribution and time the level-5 evidence belongs to.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Eval
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

namespace OCA\Hermiq\Service\Eval;

use DateTimeImmutable;

/**
 * The dataset, attribution and time the level-5 evidence belongs to.
 *
 * @spec exclude Parameter object, no functional requirement of its own.
 */
final class L5EvidenceContext {

	/**
	 * Constructor.
	 *
	 * @param string $datasetId The dataset UUID.
	 * @param string $attributionMode joint|per-skill (the run's snapshot).
	 * @param DateTimeImmutable $endedAt The run's endedAt (becomes lastValidated).
	 *
	 * @spec exclude Parameter object, no functional requirement of its own.
	 */
	public function __construct(
		public readonly string $datasetId,
		public readonly string $attributionMode,
		public readonly DateTimeImmutable $endedAt,
	) {
	}//end __construct()
}//end class
