<?php

/**
 * DECLARATION-ONLY stub for OpenRegister's TaskService.
 *
 * 🔴 EVERY BODY THROWS (the Capability-stub rule, see
 * tests/Stubs/Service/Capability/ToolGrantResolver.php for the full
 * argument): this file exists so PHPStan and Psalm can RESOLVE the class in
 * the `php-quality` job, which does not check out OpenRegister, and so unit
 * tests can `createMock()` it. Only the two methods hermiq's
 * ApprovalTaskBridge calls are declared, with the REAL signatures from
 * openregister development (`import()` is the trusted, non-HTTP creation
 * path; `terminateAsMoot()` is the idempotent release). A signature drift
 * against the real service surfaces in the mock expectations, not behind a
 * fake that answers.
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
use OCA\OpenRegister\Db\Task;

/**
 * Analysis-and-mocking stand-in. Never executed; every body throws.
 */
class TaskService {

	/**
	 * Refuses: this stub exists for static analysis and mocking only.
	 *
	 * @return never
	 *
	 * @throws LogicException Always.
	 */
	private static function refuse(): never {
		throw new LogicException(
			'OCA\OpenRegister\Service\Task\TaskService was resolved from hermiq\'s ANALYSIS STUB '
			. 'rather than from OpenRegister. The real service ships with OpenRegister.'
		);
	}//end refuse()

	/**
	 * Create a task on the TRUSTED path (real signature; stub throws).
	 *
	 * @param array<string, mixed> $data The task fields, canonical or legacy.
	 * @param string|null $actor The creating identity.
	 *
	 * @return Task The created task.
	 */
	public function import(array $data, ?string $actor): Task {
		unset($data, $actor);
		self::refuse();
	}//end import()

	/**
	 * Terminate a task made moot (real signature; stub throws).
	 *
	 * @param string $uuid The task uuid.
	 * @param string $reason What made it moot.
	 * @param string $source The propagation source recorded as actor.
	 *
	 * @return Task The terminated task, or the task untouched when already terminal.
	 */
	public function terminateAsMoot(string $uuid, string $reason, string $source): Task {
		unset($uuid, $reason, $source);
		self::refuse();
	}//end terminateAsMoot()
}//end class
