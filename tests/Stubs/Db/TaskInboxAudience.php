<?php

/**
 * Value-object stub for OpenRegister's TaskInboxAudience.
 *
 * Mirrors the REAL constructor of openregister development so hermiq's
 * ApprovalTaskInbox builds the same criteria it builds against the real
 * class; it only stores what it is given and decides nothing.
 *
 * @category Test
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * Who reads the inbox: identity facts and the relationship asked about.
 */
final class TaskInboxAudience {

	/**
	 * Constructor.
	 *
	 * @param string             $uid      The reader.
	 * @param array<int, string> $groupIds The reader's groups.
	 * @param bool               $isAdmin  Whether the reader is an admin.
	 * @param string             $scope    assigned, pooled, watched or all.
	 */
	public function __construct(
		public readonly string $uid,
		public readonly array $groupIds = [],
		public readonly bool $isAdmin = false,
		public readonly string $scope = TaskInboxCriteria::SCOPE_ASSIGNED,
	) {
	}//end __construct()
}//end class
