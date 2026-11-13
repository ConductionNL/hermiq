<?php

/**
 * Hermiq IncidentSeed
 *
 * One incident row to seed, and where progress is reported.
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

use OCP\Migration\IOutput;

/**
 * One incident row to seed, and where progress is reported.
 *
 * @spec exclude Parameter object, no functional requirement of its own.
 */
final class IncidentSeed {

	/**
	 * Constructor.
	 *
	 * @param IOutput $output Repair output channel.
	 * @param array<string,string> $row The seed row (see seedRows() for its shape).
	 *
	 * @spec exclude Parameter object, no functional requirement of its own.
	 */
	public function __construct(
		public readonly IOutput $output,
		public readonly array $row,
	) {
	}//end __construct()
}//end class
