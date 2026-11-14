<?php

/**
 * Hermiq LearningsSeedTarget
 *
 * The skill a learnings seed is ensured for.
 *
 * @category Repair
 * @package  OCA\Hermiq\Repair\Seed
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

namespace OCA\Hermiq\Repair\Seed;

use OCA\OpenRegister\Db\ObjectEntity;
use OCP\Migration\IOutput;

/**
 * The skill a learnings seed is ensured for.
 *
 * @spec exclude Parameter object, no functional requirement of its own.
 */
final class LearningsSeedTarget {

	/**
	 * Constructor.
	 *
	 * @param ObjectEntity $skill The existing tender-summary Skill.
	 * @param IOutput $output Repair output channel.
	 * @param array<string, mixed>|null $refreshed Freshness-refreshed payload, or null when untouchable.
	 *
	 * @spec exclude Parameter object, no functional requirement of its own.
	 */
	public function __construct(
		public readonly ObjectEntity $skill,
		public readonly IOutput $output,
		public readonly ?array $refreshed = null,
	) {
	}//end __construct()
}//end class
