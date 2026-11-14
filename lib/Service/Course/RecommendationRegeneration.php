<?php

/**
 * Hermiq RecommendationRegeneration
 *
 * The stored recommendation being regenerated, and where it lives.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Course
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

namespace OCA\Hermiq\Service\Course;

use DateTimeImmutable;
use OCA\OpenRegister\Db\ObjectEntity;

/**
 * The stored recommendation being regenerated, and where it lives.
 *
 * @spec exclude Parameter object, no functional requirement of its own.
 */
final class RecommendationRegeneration {

	/**
	 * Constructor.
	 *
	 * @param ObjectEntity|null $existing The previously-persisted CourseRecommendation, if any.
	 * @param DateTimeImmutable $now The current time (UTC).
	 * @param string $registerSlug The resolved slug, passed rather than re-resolved
	 *                             so the read cannot disagree with the gate above it.
	 *
	 * @spec exclude Parameter object, no functional requirement of its own.
	 */
	public function __construct(
		public readonly ?ObjectEntity $existing,
		public readonly DateTimeImmutable $now,
		public readonly string $registerSlug,
	) {
	}//end __construct()
}//end class
