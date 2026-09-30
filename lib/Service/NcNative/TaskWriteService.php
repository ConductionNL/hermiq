<?php

/**
 * Hermiq Nextcloud Tasks service (tools-nextcloud-tasks).
 *
 * Runs the three task tools for the acting user: list, create and complete.
 * Lists are resolved by `TaskLists` for the user's own principal; writes go only
 * to lists the user owns and can write, and a list a colleague shared is read,
 * never written. No verb here deletes a task. Every write carries the ADR-088
 * mark inside the stored object, and the result's `artefact` holds the list and
 * the uid, never the task's text.
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
use OCA\Hermiq\Mcp\NcTaskToolDescriptors;
use OCP\Calendar\ICalendar;
use OCP\Calendar\ICreateFromString;
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
	 * The most tasks one listing returns.
	 */
	private const MAX_TASKS = 50;

	/**
	 * Accepted `due` and `dueBefore` shapes: a date, or a date-time with optional zone.
	 */
	private const ISO_DATE = '/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})?)?$/';

	/**
	 * Constructor.
	 *
	 * @param TaskLists $lists The user's task lists and the DAV backend.
	 * @param TaskReader $reader Listing.
	 * @param TaskCalendarObject $taskObject VTODO build and completion rewrite.
	 * @param AgentArtefactMarker $marker ADR-088 mark value.
	 * @param LoggerInterface $logger Diagnostics.
	 */
	public function __construct(
		private readonly TaskLists $lists,
		private readonly TaskReader $reader,
		private readonly TaskCalendarObject $taskObject,
		private readonly AgentArtefactMarker $marker,
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
		return $this->lists->davBackend() !== null;

	}//end canComplete()

	/**
	 * List tasks from every task list the user can read, at most fifty.
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
		if (in_array($status, ['open', 'completed', 'all'], true) === false || $this->isDateOrEmpty(value: $dueBefore) === false) {
			return $this->err(code: 'invalid_argument', message: 'status is open, completed or all; dueBefore is an ISO-8601 date.');
		}

		$lists = $this->lists->all(uid: $uid);
		if ($lists === null) {
			return $this->lists->unavailable();
		}

		$before = null;
		if ($dueBefore !== '') {
			$before = new DateTimeImmutable($dueBefore);
		}

		try {
			$tasks = $this->reader->read(
				lists: $lists,
				status: $status,
				before: $before,
				listUri: trim((string)($arguments['taskListUri'] ?? ''))
			);
		} catch (Throwable $e) {
			$this->logger->warning('Hermiq: task listing failed', ['exception' => $e]);

			return $this->err(code: 'tasks_read_failed', message: 'Your tasks could not be read.');
		}

		$limit = max(1, min(self::MAX_TASKS, (int)($arguments['limit'] ?? self::MAX_TASKS)));

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

		$own = $this->lists->ownWritable(uid: $uid, listUri: trim((string)($arguments['taskListUri'] ?? '')));
		if (isset($own['error']) === true) {
			return $own;
		}

		$target = ($own[0] ?? null);
		if (($target instanceof ICreateFromString) === false) {
			return $this->err(code: 'no_task_list', message: 'You have no task list. Create one in the Tasks app.');
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
		$backend = $this->lists->davBackend();
		if ($backend === null) {
			return $this->err(code: 'tasks_not_writable', message: 'Completing tasks is not available on this instance.');
		}

		$taskUid = trim((string)($arguments['uid'] ?? ''));
		if ($taskUid === '') {
			return $this->err(code: 'invalid_argument', message: 'The task uid is required; take it from listTasks.');
		}

		$own = $this->lists->ownWritable(uid: $uid, listUri: trim((string)($arguments['taskListUri'] ?? '')));
		if (isset($own['error']) === true) {
			return $own;
		}

		foreach ($own as $list) {
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

		return $this->notFound();

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
		$calendarId = $this->lists->calendarId(list: $list);
		$stored = $backend->getCalendarObject($calendarId, $objectUri);
		if (is_array($stored) === false || is_string($stored['calendardata'] ?? null) === false) {
			return $this->notFound();
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

		if ($this->isDateOrEmpty(value: trim((string)($arguments['due'] ?? ''))) === false) {
			return $this->err(code: 'invalid_argument', message: 'due must be an ISO-8601 date or date-time.');
		}

		$priority = ($arguments['priority'] ?? null);
		if ($priority === null || $priority === '') {
			return null;
		}

		if (is_numeric($priority) === false || (int)$priority < 1 || (int)$priority > 9) {
			return $this->err(code: 'invalid_argument', message: 'priority is a number from 1 (high) to 9 (low).');
		}

		return null;

	}//end validate()

	/**
	 * Whether a value is empty or an ISO-8601 date or date-time.
	 *
	 * @param string $value The value.
	 *
	 * @return bool True when acceptable.
	 */
	private function isDateOrEmpty(string $value): bool {
		return $value === '' || preg_match(self::ISO_DATE, $value) === 1;

	}//end isDateOrEmpty()

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
	 * The error for a task that is not in a list of the user's.
	 *
	 * @return array<string, mixed> The error envelope.
	 */
	private function notFound(): array {
		return $this->err(code: 'task_not_found', message: 'No task with that uid is in a task list of yours.');

	}//end notFound()

}//end class
