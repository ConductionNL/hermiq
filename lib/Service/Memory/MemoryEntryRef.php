<?php

/**
 * Hermiq MemoryEntryRef
 *
 * Which memory entry is removed, and the budget it is counted against.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Memory
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

namespace OCA\Hermiq\Service\Memory;

/**
 * Which memory entry is removed, and the budget it is counted against.
 *
 * @spec exclude Parameter object, no functional requirement of its own.
 */
final class MemoryEntryRef {

	/**
	 * Constructor.
	 *
	 * @param string $schema The schema slug to save under.
	 * @param string $entryId The entry id to soft-delete.
	 * @param int $defaultBudget The budget to assume when the object stores none.
	 *
	 * @spec exclude Parameter object, no functional requirement of its own.
	 */
	public function __construct(
		public readonly string $schema,
		public readonly string $entryId,
		public readonly int $defaultBudget,
	) {
	}//end __construct()
}//end class
