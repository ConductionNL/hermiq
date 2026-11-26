<?php

/**
 * Value-object stub for OpenRegister's TaskInboxSelection.
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
 * What an inbox query selects; only the filter half is modelled here.
 */
final class TaskInboxSelection {

	/**
	 * Constructor.
	 *
	 * @param TaskInboxFilter $filter The row filters.
	 */
	public function __construct(
		public readonly TaskInboxFilter $filter = new TaskInboxFilter(),
	) {
	}//end __construct()
}//end class
