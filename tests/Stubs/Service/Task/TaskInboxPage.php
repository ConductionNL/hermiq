<?php

/**
 * Value-object stub for OpenRegister's TaskInboxPage.
 *
 * Mirrors the REAL constructor of openregister development so hermiq's
 * ApprovalTaskInbox builds the same criteria it builds against the real
 * class; it only stores what it is given and decides nothing.
 *
 * @category Test
 * @package  OCA\OpenRegister\Service\Task
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

namespace OCA\OpenRegister\Service\Task;

/**
 * The page window of an inbox read.
 */
final class TaskInboxPage {

	/**
	 * Constructor.
	 *
	 * @param int $limit  Rows per page.
	 * @param int $offset Rows skipped.
	 */
	public function __construct(
		public readonly int $limit = 25,
		public readonly int $offset = 0,
	) {
	}//end __construct()
}//end class
