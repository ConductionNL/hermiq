<?php

/**
 * Hermiq FamilyMigration
 *
 * One schema family being moved, and where progress is reported.
 *
 * @category Repair
 * @package  OCA\Hermiq\Repair\Migration
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

namespace OCA\Hermiq\Repair\Migration;

use OCP\Migration\IOutput;

/**
 * One schema family being moved, and where progress is reported.
 *
 * @spec exclude Parameter object, no functional requirement of its own.
 */
final class FamilyMigration {

	/**
	 * Constructor.
	 *
	 * @param IOutput $output Repair output channel.
	 * @param string $sourceSchema Schema slug to read from.
	 * @param string $targetSchema Schema slug to write to.
	 *
	 * @spec exclude Parameter object, no functional requirement of its own.
	 */
	public function __construct(
		public readonly IOutput $output,
		public readonly string $sourceSchema,
		public readonly string $targetSchema,
	) {
	}//end __construct()
}//end class
