<?php

/**
 * Hermiq DraftEvalSubject
 *
 * The skill and draft content an eval runs against.
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

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * The skill and draft content an eval runs against.
 *
 * @spec exclude Parameter object, no functional requirement of its own.
 */
final class DraftEvalSubject {

	/**
	 * Constructor.
	 *
	 * @param ObjectEntity $skill The skill under comparison.
	 * @param array<string, mixed> $data The draft payload (proposed content source).
	 *
	 * @spec exclude Parameter object, no functional requirement of its own.
	 */
	public function __construct(
		public readonly ObjectEntity $skill,
		public readonly array $data,
	) {
	}//end __construct()
}//end class
