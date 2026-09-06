<?php

/**
 * Test stub for OpenRegister TaskTerminalEvent.
 *
 * Stands in for OCA\OpenRegister\Event\TaskTerminalEvent when OpenRegister is
 * not installed (standalone CI). Mirrors the real event's constructor and
 * accessors exactly (openregister development,
 * lib/Event/TaskTerminalEvent.php, change flow-user-task-node): it carries
 * the task as persisted in its terminal state, and `isCommitted()` tells the
 * after-commit dispatch (run continuation allowed) from the in-transaction
 * one (timer bookkeeping only). hermiq's TaskTerminalListener routes on the
 * FQN string and reads through `is_callable`, so it consumes this stub the
 * same way it would the real class. The real event ships with OpenRegister.
 *
 * @category Test
 * @package  OCA\OpenRegister\Event
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

namespace OCA\OpenRegister\Event;

use OCA\OpenRegister\Db\Task;
use OCP\EventDispatcher\Event;

/**
 * Minimal TaskTerminalEvent stub for standalone unit runs.
 *
 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The real event's contract:
 * one class serves the in-transaction and after-commit dispatch points.
 */
class TaskTerminalEvent extends Event {

	/**
	 * Constructor — same signature as the real event.
	 *
	 * @param Task $task The task, already persisted in a terminal state.
	 * @param bool $committed TRUE when the terminal write's transaction has
	 *                        closed; FALSE when dispatched inside it.
	 */
	public function __construct(
		private readonly Task $task,
		private readonly bool $committed = true,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * The terminal task.
	 *
	 * @return Task The task as persisted.
	 */
	public function getTask(): Task {
		return $this->task;
	}//end getTask()

	/**
	 * Whether the terminal write's transaction has closed.
	 *
	 * @return bool TRUE for the after-commit dispatch.
	 */
	public function isCommitted(): bool {
		return $this->committed;
	}//end isCommitted()
}//end class
