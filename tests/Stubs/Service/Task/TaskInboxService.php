<?php

/**
 * DECLARATION-ONLY stub for OpenRegister's TaskInboxService.
 *
 * 🔴 EVERY BODY THROWS (the Capability-stub rule, see
 * tests/Stubs/Service/Capability/ToolGrantResolver.php): this file exists so
 * PHPStan and Psalm can resolve the class without an OpenRegister checkout,
 * and so unit tests can `createMock()` it. Only `inbox()`, the one method
 * hermiq's ApprovalTaskInbox calls, is declared, with the REAL signature from
 * openregister development.
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

use LogicException;
use OCA\OpenRegister\Db\TaskInboxCriteria;

/**
 * Analysis-and-mocking stand-in. Never executed; every body throws.
 */
class TaskInboxService {

	/**
	 * One inbox page (real signature; stub throws).
	 *
	 * @param TaskInboxCriteria  $criteria Scope, filters, sort and identity.
	 * @param TaskInboxPage|null $page     The page window.
	 *
	 * @return array{results: array<int, array<string, mixed>>, total: int, limit: int, offset: int} The page.
	 *
	 * @throws LogicException Always.
	 */
	public function inbox(TaskInboxCriteria $criteria, ?TaskInboxPage $page = null): array {
		throw new LogicException(
			'OCA\OpenRegister\Service\Task\TaskInboxService was resolved from hermiq\'s ANALYSIS STUB '
			. 'rather than from OpenRegister. The real service ships with OpenRegister.'
		);
	}//end inbox()
}//end class
