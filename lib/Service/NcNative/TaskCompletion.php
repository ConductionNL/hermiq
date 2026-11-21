<?php

/**
 * Hermiq TaskCompletion.
 *
 * One task being completed: the DAV backend, the task's uid and the run's agent, then where the task was found.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\NcNative
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/nc-native-tools/spec.md#requirement-an-agent-can-complete-a-task-without-losing-what-the-user-wrote-req-nctask-003
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\NcNative;

use OCP\Calendar\ICalendar;

/**
 * One task completion in progress; it never leaves TaskWriteService.
 *
 * @spec openspec/specs/nc-native-tools/spec.md#requirement-an-agent-can-complete-a-task-without-losing-what-the-user-wrote-req-nctask-003
 */
final class TaskCompletion {

	/**
	 * The run's agent id, for the mark.
	 *
	 * @var string
	 */
	public string $agentId = '';

	/**
	 * The user's own list holding the task, once found.
	 *
	 * @var ICalendar|null
	 */
	public ?ICalendar $list = null;

	/**
	 * The backend's id for that list, once found.
	 *
	 * @var int|string
	 */
	public int|string $calendarId = 0;

	/**
	 * The task object's uri in that list, once found.
	 *
	 * @var string
	 */
	public string $objectUri = '';

	/**
	 * Start the completion.
	 *
	 * @param object $backend The DAV backend.
	 * @param string $taskUid The task uid.
	 */
	public function __construct(
		public readonly object $backend,
		public readonly string $taskUid,
	) {
	}//end __construct()
}//end class
