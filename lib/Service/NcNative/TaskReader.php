<?php

/**
 * Hermiq task reader (tools-nextcloud-tasks).
 *
 * Reads VTODO tasks from task lists the caller already resolved for the acting
 * user, and shapes them for the agent: uid, summary, due, status, priority and
 * list, never the description.
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
 * @spec openspec/specs/nc-native-tools/spec.md#requirement-an-agent-can-list-the-acting-users-tasks-req-nctask-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\NcNative;

use DateTimeImmutable;
use DateTimeInterface;
use OCP\Calendar\ICalendar;

/**
 * Listing tasks from resolved task lists.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\NcNative
 *
 * @spec openspec/specs/nc-native-tools/spec.md#requirement-an-agent-can-list-the-acting-users-tasks-req-nctask-001
 */
class TaskReader {

	/**
	 * How many objects one list is asked for before filtering.
	 */
	private const SEARCH_LIMIT = 500;

	/**
	 * The tasks of the given lists, filtered, sorted by due date (undated last).
	 *
	 * @param array<int, ICalendar> $lists The task lists.
	 * @param string $status open, completed or all.
	 * @param DateTimeImmutable|null $before Only tasks due before this, when set.
	 * @param string $listUri Only this list, or '' for all.
	 *
	 * @return array<int, array<string, mixed>> The tasks.
	 *
	 * @spec openspec/specs/nc-native-tools/spec.md#requirement-an-agent-can-list-the-acting-users-tasks-req-nctask-001
	 */
	public function read(array $lists, string $status, ?DateTimeImmutable $before, string $listUri): array {
		$tasks = [];
		foreach ($lists as $list) {
			if ($listUri === '' || $list->getUri() === $listUri) {
				$tasks = array_merge($tasks, $this->tasksOf(list: $list, status: $status, before: $before));
			}
		}

		usort($tasks, static fn (array $a, array $b): int => [$a['due'] === null, strtotime((string)$a['due'])]
			<=> [$b['due'] === null, strtotime((string)$b['due'])]);

		return $tasks;

	}//end read()

	/**
	 * The tasks of one list, filtered by status and due date.
	 *
	 * @param ICalendar $list The task list.
	 * @param string $status open, completed or all.
	 * @param DateTimeImmutable|null $before Only tasks due before this, when set.
	 *
	 * @return array<int, array<string, mixed>> The tasks.
	 */
	private function tasksOf(ICalendar $list, string $status, ?DateTimeImmutable $before): array {
		$tasks = [];
		foreach ($list->search('', [], ['types' => ['VTODO']], self::SEARCH_LIMIT) as $row) {
			$object = ($row['objects'][0] ?? []);
			$task = $this->taskFromObject(row: $row, object: $object, list: $list);
			if ($this->matches(task: $task, due: $this->property(object: $object, name: 'DUE'), status: $status, before: $before) === true) {
				$tasks[] = $task;
			}
		}

		return $tasks;

	}//end tasksOf()

	/**
	 * Whether a task passes the status and due-date filters.
	 *
	 * @param array<string, mixed> $task The shaped task.
	 * @param mixed $due The raw DUE value.
	 * @param string $status open, completed or all.
	 * @param DateTimeImmutable|null $before Only tasks due before this, when set.
	 *
	 * @return bool True when it passes.
	 */
	private function matches(array $task, mixed $due, string $status, ?DateTimeImmutable $before): bool {
		$done = ($task['status'] === 'COMPLETED');
		if (($status === 'open' && $done === true) || ($status === 'completed' && $done === false)) {
			return false;
		}

		if ($before === null) {
			return true;
		}

		return ($due instanceof DateTimeInterface) && $due < $before;

	}//end matches()

	/**
	 * Shape one search object as a task.
	 *
	 * @param array<string, mixed> $row A CalDavBackend::search() row.
	 * @param mixed $object The row's VTODO.
	 * @param ICalendar $list The list it came from.
	 *
	 * @return array<string, mixed> uid, summary, due, status, priority, list, listName.
	 */
	private function taskFromObject(array $row, mixed $object, ICalendar $list): array {
		$status = strtoupper((string)$this->property(object: $object, name: 'STATUS'));
		if ($status === '' && $this->property(object: $object, name: 'COMPLETED') !== null) {
			$status = 'COMPLETED';
		}

		if ($status === '') {
			$status = 'NEEDS-ACTION';
		}

		$due = $this->property(object: $object, name: 'DUE');
		$dueText = null;
		if ($due instanceof DateTimeInterface) {
			$dueText = $due->format(DATE_ATOM);
		}

		$priority = $this->property(object: $object, name: 'PRIORITY');
		$priorityValue = null;
		if (is_numeric($priority) === true) {
			$priorityValue = (int)$priority;
		}

		return [
			'uid' => (string)($row['uid'] ?? ''),
			'summary' => (string)$this->property(object: $object, name: 'SUMMARY'),
			'due' => $dueText,
			'status' => $status,
			'priority' => $priorityValue,
			'list' => $list->getUri(),
			'listName' => $list->getDisplayName(),
		];

	}//end taskFromObject()

	/**
	 * Read one property value from a search object, in either shape the backend
	 * uses: `[value, params]` for a single property, `[[value, params], ...]` for
	 * one that may repeat.
	 *
	 * @param mixed $object The search object.
	 * @param string $name The property name.
	 *
	 * @return mixed The value, or null.
	 */
	private function property(mixed $object, string $name): mixed {
		$property = null;
		if (is_array($object) === true) {
			$property = ($object[$name] ?? null);
		}

		if (is_array($property) === false || array_key_exists(0, $property) === false) {
			return null;
		}

		if (is_array($property[0]) === true) {
			return ($property[0][0] ?? null);
		}

		return $property[0];

	}//end property()

}//end class
