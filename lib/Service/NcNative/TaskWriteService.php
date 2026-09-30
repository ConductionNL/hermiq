<?php

/**
 * Hermiq Nextcloud Tasks service (tools-nextcloud-tasks).
 *
 * Lists, creates and completes tasks in the acting user's CalDAV task lists.
 * Every list is resolved through the calendar manager for the user's own
 * principal, the IDOR scope every calendar tool uses. Writes go only to lists
 * the user owns and can write; a list a colleague shared is read, never written.
 * No verb here deletes a task.
 *
 * Why the DAV backend: OCP 34 tells neither a calendar's component set nor a way
 * to replace an existing object (`createFromString()` is a Sabre create that
 * refuses a UID already present). Both come from the DAV app's CalDavBackend,
 * resolved lazily behind `class_exists()` and a method probe like
 * `NotesWriteService` resolves Notes. Without it completing is not offered, and
 * listing and creating say the lists are not available. See the change's
 * design.md, "Task 1 outcome".
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
 * @spec openspec/specs/nc-native-tools/spec.md#requirement-an-agent-can-create-a-task-in-the-users-own-list-marked-as-agent-authored-req-nctask-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\NcNative;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Hermiq\Mcp\NcTaskToolDescriptors;
use OCP\Calendar\ICalendar;
use OCP\Calendar\ICalendarIsShared;
use OCP\Calendar\ICalendarIsWritable;
use OCP\Calendar\ICreateFromString;
use OCP\Calendar\IManager as ICalendarManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Task tools over the acting user's own task lists.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\NcNative
 *
 * @spec openspec/specs/nc-native-tools/spec.md#requirement-an-agent-can-create-a-task-in-the-users-own-list-marked-as-agent-authored-req-nctask-002
 */
class TaskWriteService {

	use ErrorEnvelopeTrait;

	/**
	 * The DAV app's CalDAV backend, by name so it is never a hard dependency.
	 */
	private const DAV_BACKEND = 'OCA\\DAV\\CalDAV\\CalDavBackend';

	/**
	 * The calendar property holding the supported component set.
	 */
	private const COMPONENT_SET = '{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set';

	/**
	 * The most tasks one listing returns.
	 */
	private const MAX_TASKS = 50;

	/**
	 * How many objects one list is asked for before filtering.
	 */
	private const SEARCH_LIMIT = 500;

	/**
	 * Accepted `due` and `dueBefore` shapes: a date, or a date-time with optional zone.
	 */
	private const ISO_DATE = '/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})?)?$/';

	/**
	 * Constructor.
	 *
	 * @param ICalendarManager $calendarManager Calendars of the acting user's principal.
	 * @param AgentArtefactMarker $marker ADR-088 mark value.
	 * @param TaskCalendarObject $taskObject VTODO build and completion rewrite.
	 * @param ContainerInterface $container Lazy DAV backend resolution.
	 * @param LoggerInterface $logger Diagnostics.
	 */
	public function __construct(
		private readonly ICalendarManager $calendarManager,
		private readonly AgentArtefactMarker $marker,
		private readonly TaskCalendarObject $taskObject,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Run one task tool for the acting user. Never throws.
	 *
	 * @param string $uid The acting (session) user id.
	 * @param string $toolId The namespaced tool id.
	 * @param array<string, mixed> $arguments The tool arguments; `agentId` is run-injected.
	 *
	 * @return array<string, mixed> The result, or an error envelope.
	 *
	 * @spec openspec/specs/nc-native-tools/spec.md#requirement-task-tools-are-default-denied-never-delete-and-record-identity-without-content-req-nctask-004
	 */
	public function invoke(string $uid, string $toolId, array $arguments): array {
		try {
			return match ($toolId) {
				NcTaskToolDescriptors::LIST_TASKS => $this->listTasks(uid: $uid, arguments: $arguments),
				NcTaskToolDescriptors::CREATE_TASK => $this->createTask(uid: $uid, arguments: $arguments),
				NcTaskToolDescriptors::COMPLETE_TASK => $this->completeTask(uid: $uid, arguments: $arguments),
				default => $this->err(code: 'unknown_tool', message: 'This is not a task tool.'),
			};
		} catch (Throwable $e) {
			$this->logger->warning('Hermiq: task tool failed', ['exception' => $e]);

			return $this->err(code: 'tasks_failed', message: 'The task lists could not be reached.');
		}

	}//end invoke()

	/**
	 * Whether a completed task can be written back on this instance.
	 *
	 * @return bool True when the DAV backend's update path is available.
	 *
	 * @spec openspec/specs/nc-native-tools/spec.md#requirement-an-agent-can-complete-a-task-without-losing-what-the-user-wrote-req-nctask-003
	 */
	public function canComplete(): bool {
		return $this->davBackend() !== null;

	}//end canComplete()

	/**
	 * List tasks from every task list the user can read.
	 *
	 * @param string $uid The acting user id.
	 * @param array<string, mixed> $arguments status, dueBefore, taskListUri, limit.
	 *
	 * @return array<string, mixed> The tasks, or an error envelope.
	 *
	 * @spec openspec/specs/nc-native-tools/spec.md#requirement-an-agent-can-list-the-acting-users-tasks-req-nctask-001
	 */
	public function listTasks(string $uid, array $arguments): array {
		$status = (string)($arguments['status'] ?? 'open');
		$dueBefore = trim((string)($arguments['dueBefore'] ?? ''));
		if (in_array($status, ['open', 'completed', 'all'], true) === false
			|| ($dueBefore !== '' && preg_match(self::ISO_DATE, $dueBefore) !== 1)
		) {
			return $this->err(code: 'invalid_argument', message: 'status is open, completed or all; dueBefore is an ISO-8601 date.');
		}

		$lists = $this->taskLists(uid: $uid);
		if ($lists === null) {
			return $this->unavailable();
		}

		$limit = max(1, min(self::MAX_TASKS, (int)($arguments['limit'] ?? self::MAX_TASKS)));
		$listUri = trim((string)($arguments['taskListUri'] ?? ''));
		$before = null;
		if ($dueBefore !== '') {
			$before = new DateTimeImmutable($dueBefore);
		}

		try {
			$tasks = [];
			foreach ($lists as $list) {
				if ($listUri === '' || $list->getUri() === $listUri) {
					$tasks = array_merge($tasks, $this->tasksOf(list: $list, status: $status, before: $before));
				}
			}
		} catch (Throwable $e) {
			$this->logger->warning('Hermiq: task listing failed', ['exception' => $e]);

			return $this->err(code: 'tasks_read_failed', message: 'Your tasks could not be read.');
		}

		usort($tasks, static fn (array $a, array $b): int => [$a['due'] === null, strtotime((string)$a['due'])] <=> [$b['due'] === null, strtotime((string)$b['due'])]);

		return ['tasks' => array_slice($tasks, 0, $limit), 'truncated' => count($tasks) > $limit];

	}//end listTasks()

	/**
	 * Create a marked task in one of the user's own task lists.
	 *
	 * @param string $uid The acting user id.
	 * @param array<string, mixed> $arguments summary, due, description, priority, taskListUri, agentId.
	 *
	 * @return array<string, mixed> The result, or an error envelope.
	 *
	 * @spec openspec/specs/nc-native-tools/spec.md#requirement-an-agent-can-create-a-task-in-the-users-own-list-marked-as-agent-authored-req-nctask-002
	 */
	public function createTask(string $uid, array $arguments): array {
		$invalid = $this->validate(arguments: $arguments);
		if ($invalid !== null) {
			return $invalid;
		}

		$target = $this->writableList(uid: $uid, listUri: trim((string)($arguments['taskListUri'] ?? '')));
		if (($target instanceof ICreateFromString) === false) {
			return $target;
		}

		$taskUid = $this->newUid();
		$ics = $this->taskObject->build(
			taskUid: $taskUid,
			fields: $arguments,
			markValue: $this->marker->objectPropertyValue(agentId: (string)($arguments['agentId'] ?? '')),
			now: new DateTimeImmutable()
		);

		try {
			$target->createFromString('hermiq-' . bin2hex(random_bytes(8)) . '.ics', $ics);
		} catch (Throwable $e) {
			$this->logger->warning('Hermiq: task creation failed', ['exception' => $e]);

			return $this->err(code: 'task_write_failed', message: 'The task could not be created.');
		}

		return [
			'created' => true,
			'uid' => $taskUid,
			'summary' => trim((string)$arguments['summary']),
			'list' => $target->getUri(),
			'listName' => $target->getDisplayName(),
			'artefact' => ['type' => 'task', 'id' => $target->getUri() . '/' . $taskUid],
		];

	}//end createTask()

	/**
	 * Complete one of the user's tasks, keeping everything else they wrote.
	 *
	 * @param string $uid The acting user id.
	 * @param array<string, mixed> $arguments uid, taskListUri, agentId.
	 *
	 * @return array<string, mixed> The result, or an error envelope.
	 *
	 * @spec openspec/specs/nc-native-tools/spec.md#requirement-an-agent-can-complete-a-task-without-losing-what-the-user-wrote-req-nctask-003
	 */
	public function completeTask(string $uid, array $arguments): array {
		$backend = $this->davBackend();
		if ($backend === null) {
			return $this->err(code: 'tasks_not_writable', message: 'Completing tasks is not available on this instance.');
		}

		$taskUid = trim((string)($arguments['uid'] ?? ''));
		if ($taskUid === '') {
			return $this->err(code: 'invalid_argument', message: 'The task uid is required; take it from listTasks.');
		}

		$lists = $this->ownWritableLists(uid: $uid, listUri: trim((string)($arguments['taskListUri'] ?? '')));
		if (isset($lists['error']) === true) {
			return $lists;
		}

		foreach ($lists as $list) {
			$rows = $list->search('', [], ['types' => ['VTODO'], 'uid' => $taskUid], 1);
			if (isset($rows[0]['uri']) === true) {
				return $this->writeCompleted(
					backend: $backend,
					list: $list,
					objectUri: (string)$rows[0]['uri'],
					taskUid: $taskUid,
					agentId: (string)($arguments['agentId'] ?? '')
				);
			}
		}

		return $this->err(code: 'task_not_found', message: 'No task with that uid is in a task list of yours.');

	}//end completeTask()

	/**
	 * Read, rewrite and store one task object through the DAV backend.
	 *
	 * @param object $backend The DAV backend.
	 * @param ICalendar $list The user's own list holding the task.
	 * @param string $objectUri The object's uri in that list.
	 * @param string $taskUid The task uid.
	 * @param string $agentId The run's agent id, for the mark.
	 *
	 * @return array<string, mixed> The result, or an error envelope.
	 */
	private function writeCompleted(object $backend, ICalendar $list, string $objectUri, string $taskUid, string $agentId): array {
		$calendarId = $this->calendarId(list: $list);
		$stored = $backend->getCalendarObject($calendarId, $objectUri);
		if (is_array($stored) === false || is_string($stored['calendardata'] ?? null) === false) {
			return $this->err(code: 'task_not_found', message: 'No task with that uid is in a task list of yours.');
		}

		$rewritten = $this->taskObject->complete(
			ics: $stored['calendardata'],
			markValue: $this->marker->objectPropertyValue(agentId: $agentId),
			now: new DateTimeImmutable()
		);
		if ($rewritten === null) {
			return $this->err(code: 'artefact_not_marked', message: 'The task could not be marked, so it was not changed.');
		}

		try {
			$backend->updateCalendarObject($calendarId, $objectUri, $rewritten);
		} catch (Throwable $e) {
			$this->logger->warning('Hermiq: task completion failed', ['exception' => $e]);

			return $this->err(code: 'task_write_failed', message: 'The task could not be completed.');
		}

		return [
			'completed' => true,
			'uid' => $taskUid,
			'list' => $list->getUri(),
			'artefact' => ['type' => 'task', 'id' => $list->getUri() . '/' . $taskUid],
		];

	}//end writeCompleted()

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
			$task = $this->taskFromRow(row: $row, list: $list);
			$done = ($task['status'] === 'COMPLETED');
			if (($status === 'open' && $done === true) || ($status === 'completed' && $done === false)) {
				continue;
			}

			$due = $this->property(object: ($row['objects'][0] ?? []), name: 'DUE');
			if ($before !== null && (($due instanceof DateTimeInterface) === false || $due >= $before)) {
				continue;
			}

			$tasks[] = $task;
		}

		return $tasks;

	}//end tasksOf()

	/**
	 * Shape one search row as a task.
	 *
	 * @param array<string, mixed> $row A CalDavBackend::search() row.
	 * @param ICalendar $list The list it came from.
	 *
	 * @return array<string, mixed> uid, summary, due, status, priority, list, listName.
	 */
	private function taskFromRow(array $row, ICalendar $list): array {
		$object = ($row['objects'][0] ?? []);
		$status = strtoupper((string)$this->property(object: $object, name: 'STATUS'));
		if ($status === '' && $this->property(object: $object, name: 'COMPLETED') !== null) {
			$status = 'COMPLETED';
		}

		$due = $this->property(object: $object, name: 'DUE');
		$priority = $this->property(object: $object, name: 'PRIORITY');

		return [
			'uid' => (string)($row['uid'] ?? ''),
			'summary' => (string)$this->property(object: $object, name: 'SUMMARY'),
			'due' => ($due instanceof DateTimeInterface) ? $due->format(DATE_ATOM) : null,
			'status' => ($status === '') ? 'NEEDS-ACTION' : $status,
			'priority' => is_numeric($priority) ? (int)$priority : null,
			'list' => $list->getUri(),
			'listName' => $list->getDisplayName(),
		];

	}//end taskFromRow()

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
		$property = (is_array($object) === true) ? ($object[$name] ?? null) : null;
		if (is_array($property) === false || array_key_exists(0, $property) === false) {
			return null;
		}

		if (is_array($property[0]) === true) {
			return ($property[0][0] ?? null);
		}

		return $property[0];

	}//end property()

	/**
	 * The user's task lists (own and shared-in), or null without the DAV backend.
	 *
	 * @param string $uid The acting user id.
	 *
	 * @return array<int, ICalendar>|null The lists.
	 */
	private function taskLists(string $uid): ?array {
		$backend = $this->davBackend();
		if ($backend === null) {
			return null;
		}

		$principal = 'principals/users/' . $uid;
		$withTasks = [];
		foreach ((array)$backend->getCalendarsForUser($principal) as $row) {
			$set = (is_array($row) === true) ? ($row[self::COMPONENT_SET] ?? null) : null;
			if (is_object($set) === true && method_exists($set, 'getValue') === true
				&& in_array('VTODO', (array)$set->getValue(), true) === true
			) {
				$withTasks[(string)$row['uri']] = true;
			}
		}

		return array_values(
			array_filter(
				$this->calendarManager->getCalendarsForPrincipal($principal),
				static fn (ICalendar $calendar): bool => isset($withTasks[$calendar->getUri()])
			)
		);

	}//end taskLists()

	/**
	 * Whether the user owns a list and can write it.
	 *
	 * @param ICalendar $list The list.
	 *
	 * @return bool True for an own, writable list.
	 */
	private function isOwnWritable(ICalendar $list): bool {
		return $list instanceof ICreateFromString
			&& $list instanceof ICalendarIsWritable && $list->isWritable() === true
			&& $list instanceof ICalendarIsShared && $list->isShared() === false;

	}//end isOwnWritable()

	/**
	 * The user's own writable task lists, optionally only the named one.
	 *
	 * @param string $uid The acting user id.
	 * @param string $listUri The named list, or ''.
	 *
	 * @return array<int|string, mixed> The lists, or an error envelope.
	 */
	private function ownWritableLists(string $uid, string $listUri): array {
		$lists = $this->taskLists(uid: $uid);
		if ($lists === null) {
			return $this->unavailable();
		}

		$own = [];
		foreach ($lists as $list) {
			if ($listUri !== '' && $list->getUri() !== $listUri) {
				continue;
			}

			if ($this->isOwnWritable(list: $list) === false) {
				if ($listUri !== '') {
					return $this->notWritable();
				}

				continue;
			}

			$own[] = $list;
		}

		return $own;

	}//end ownWritableLists()

	/**
	 * The list a new task goes into, or an error envelope.
	 *
	 * @param string $uid The acting user id.
	 * @param string $listUri The named list, or '' for the first own list.
	 *
	 * @return ICreateFromString|array<string, mixed> The list, or an error envelope.
	 */
	private function writableList(string $uid, string $listUri): ICreateFromString|array {
		$lists = $this->ownWritableLists(uid: $uid, listUri: $listUri);
		if (isset($lists['error']) === true) {
			return $lists;
		}

		$first = ($lists[0] ?? null);
		if ($first instanceof ICreateFromString) {
			return $first;
		}

		return $this->err(code: 'no_task_list', message: 'You have no task list. Create one in the Tasks app.');

	}//end writableList()

	/**
	 * Validate create arguments.
	 *
	 * @param array<string, mixed> $arguments The tool arguments.
	 *
	 * @return array<string, mixed>|null The error envelope, or null when valid.
	 */
	private function validate(array $arguments): ?array {
		if (trim((string)($arguments['summary'] ?? '')) === '') {
			return $this->err(code: 'invalid_argument', message: 'A summary is required.');
		}

		$due = trim((string)($arguments['due'] ?? ''));
		if ($due !== '' && preg_match(self::ISO_DATE, $due) !== 1) {
			return $this->err(code: 'invalid_argument', message: 'due must be an ISO-8601 date or date-time.');
		}

		$priority = ($arguments['priority'] ?? null);
		if ($priority !== null && $priority !== '' && (is_numeric($priority) === false || (int)$priority < 1 || (int)$priority > 9)) {
			return $this->err(code: 'invalid_argument', message: 'priority is a number from 1 (high) to 9 (low).');
		}

		return null;

	}//end validate()

	/**
	 * The backend's id for a list: numeric in the database, the key as given otherwise.
	 *
	 * @param ICalendar $list The list.
	 *
	 * @return int|string The calendar id.
	 */
	private function calendarId(ICalendar $list): int|string {
		$key = $list->getKey();
		if (ctype_digit($key) === true) {
			return (int)$key;
		}

		return $key;

	}//end calendarId()

	/**
	 * A random RFC 4122 version 4 UID for a new task.
	 *
	 * @return string The UID.
	 */
	private function newUid(): string {
		$bytes = random_bytes(16);
		$bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
		$bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));

	}//end newUid()

	/**
	 * Resolve the DAV app's CalDAV backend, or null when absent or drifted.
	 *
	 * `protected` so tests can substitute a double: the class exists only inside a
	 * Nextcloud server, never in this app's own vendor tree.
	 *
	 * @return object|null The backend, or null.
	 *
	 * @spec openspec/specs/nc-native-tools/spec.md#requirement-an-agent-can-complete-a-task-without-losing-what-the-user-wrote-req-nctask-003
	 */
	protected function davBackend(): ?object {
		if (class_exists(self::DAV_BACKEND) === false) {
			return null;
		}

		try {
			$backend = $this->container->get(self::DAV_BACKEND);
		} catch (Throwable $e) {
			$this->logger->debug('Hermiq: CalDAV backend could not be resolved', ['exception' => $e]);

			return null;
		}

		if (is_object($backend) === false) {
			return null;
		}

		foreach (['getCalendarsForUser', 'getCalendarObject', 'updateCalendarObject'] as $method) {
			if (method_exists($backend, $method) === false) {
				$this->logger->warning('Hermiq: CalDAV backend is missing expected method {method}', ['method' => $method]);

				return null;
			}
		}

		return $backend;

	}//end davBackend()

	/**
	 * The error when task lists cannot be told apart on this instance.
	 *
	 * @return array<string, mixed> The error envelope.
	 */
	private function unavailable(): array {
		return $this->err(code: 'tasks_not_available', message: 'Task lists are not available on this instance.');

	}//end unavailable()

	/**
	 * The error for a list the user may not write.
	 *
	 * @return array<string, mixed> The error envelope.
	 */
	private function notWritable(): array {
		return $this->err(code: 'task_list_not_writable', message: 'That task list is shared with you by someone else and cannot be written.');

	}//end notWritable()

}//end class
