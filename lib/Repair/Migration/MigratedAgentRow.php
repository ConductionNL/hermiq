<?php

/**
 * Hermiq MigratedAgentRow
 *
 * One migrated agent row and where it is stored.
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

/**
 * One migrated agent row and where it is stored.
 *
 * @spec exclude Parameter object, no functional requirement of its own.
 */
final class MigratedAgentRow {

	/**
	 * Constructor.
	 *
	 * @param string $schema The target schema slug.
	 * @param string $uuid The source uuid, preserved as the object identity.
	 * @param array<string, mixed> $data The mapped object payload.
	 * @param array<string, mixed> $row The source DB row (for owner/organisation/created).
	 *
	 * @spec exclude Parameter object, no functional requirement of its own.
	 */
	public function __construct(
		public readonly string $schema,
		public readonly string $uuid,
		public readonly array $data,
		public readonly array $row,
	) {
	}//end __construct()
}//end class
