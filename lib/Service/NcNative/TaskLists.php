<?php

/**
 * Hermiq task lists (tools-nextcloud-tasks).
 *
 * Resolves the acting user's CalDAV task lists through the calendar manager for
 * the user's own principal, the IDOR scope every calendar tool uses, and tells
 * the lists the user owns and can write apart from lists a colleague shared.
 *
 * OCP 34 tells neither a calendar's component set nor a way to replace an
 * existing object (`createFromString()` is a Sabre create that refuses a UID
 * already present). Both come from the DAV app's CalDavBackend, resolved lazily
 * behind `class_exists()` and a method probe like `NotesWriteService` resolves
 * Notes. See the archived change's design.md, "Task 1 outcome".
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

use OCP\Calendar\ICalendar;
use OCP\Calendar\ICalendarIsShared;
use OCP\Calendar\ICalendarIsWritable;
use OCP\Calendar\ICreateFromString;
use OCP\Calendar\IManager as ICalendarManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The acting user's task lists, and the DAV backend behind them.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\NcNative
 *
 * @spec openspec/specs/nc-native-tools/spec.md#requirement-an-agent-can-create-a-task-in-the-users-own-list-marked-as-agent-authored-req-nctask-002
 */
class TaskLists {

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
	 * Constructor.
	 *
	 * @param ICalendarManager $calendarManager Calendars of the acting user's principal.
	 * @param ContainerInterface $container Lazy DAV backend resolution.
	 * @param LoggerInterface $logger Diagnostics.
	 */
	public function __construct(
		private readonly ICalendarManager $calendarManager,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * The user's task lists (own and shared-in), or null without the DAV backend.
	 *
	 * @param string $uid The acting user id.
	 *
	 * @return array<int, ICalendar>|null The lists.
	 *
	 * @spec openspec/specs/nc-native-tools/spec.md#requirement-an-agent-can-list-the-acting-users-tasks-req-nctask-001
	 */
	public function all(string $uid): ?array {
		$backend = $this->davBackend();
		if ($backend === null) {
			return null;
		}

		$principal = 'principals/users/' . $uid;
		$withTasks = [];
		foreach ((array)$backend->getCalendarsForUser($principal) as $row) {
			if ($this->holdsTasks(row: $row) === true) {
				$withTasks[(string)$row['uri']] = true;
			}
		}

		return array_values(
			array_filter(
				$this->calendarManager->getCalendarsForPrincipal($principal),
				static fn (ICalendar $calendar): bool => isset($withTasks[$calendar->getUri()])
			)
		);

	}//end all()

	/**
	 * The user's own writable task lists, optionally only the named one, or an
	 * error envelope (no backend, or the named list is not the user's to write).
	 *
	 * @param string $uid The acting user id.
	 * @param string $listUri The named list, or ''.
	 *
	 * @return array<int|string, mixed> The lists, or an error envelope.
	 *
	 * @spec openspec/specs/nc-native-tools/spec.md#requirement-an-agent-can-create-a-task-in-the-users-own-list-marked-as-agent-authored-req-nctask-002
	 */
	public function ownWritable(string $uid, string $listUri): array {
		$lists = $this->all(uid: $uid);
		if ($lists === null) {
			return $this->unavailable();
		}

		$own = [];
		foreach ($lists as $list) {
			if ($listUri !== '' && $list->getUri() !== $listUri) {
				continue;
			}

			if ($this->isOwnWritable(list: $list) === true) {
				$own[] = $list;
				continue;
			}

			if ($listUri !== '') {
				return $this->err(
					code: 'task_list_not_writable',
					message: 'That task list is shared with you by someone else and cannot be written.'
				);
			}
		}

		return $own;

	}//end ownWritable()

	/**
	 * The error when task lists cannot be told apart on this instance.
	 *
	 * @return array<string, mixed> The error envelope.
	 *
	 * @spec openspec/specs/nc-native-tools/spec.md#requirement-an-agent-can-list-the-acting-users-tasks-req-nctask-001
	 */
	public function unavailable(): array {
		return $this->err(code: 'tasks_not_available', message: 'Task lists are not available on this instance.');

	}//end unavailable()

	/**
	 * The backend's id for a list: numeric in the database, the key as given otherwise.
	 *
	 * @param ICalendar $list The list.
	 *
	 * @return int|string The calendar id.
	 *
	 * @spec openspec/specs/nc-native-tools/spec.md#requirement-an-agent-can-complete-a-task-without-losing-what-the-user-wrote-req-nctask-003
	 */
	public function calendarId(ICalendar $list): int|string {
		$key = $list->getKey();
		if (ctype_digit($key) === true) {
			return (int)$key;
		}

		return $key;

	}//end calendarId()

	/**
	 * Resolve the DAV app's CalDAV backend, or null when absent or drifted.
	 *
	 * Public for the writes that need it; overridable so tests can substitute a
	 * double, because the class exists only inside a Nextcloud server.
	 *
	 * @return object|null The backend, or null.
	 *
	 * @spec openspec/specs/nc-native-tools/spec.md#requirement-an-agent-can-complete-a-task-without-losing-what-the-user-wrote-req-nctask-003
	 */
	public function davBackend(): ?object {
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
	 * Whether a backend calendar row declares VTODO in its component set.
	 *
	 * @param mixed $row A getCalendarsForUser() row.
	 *
	 * @return bool True for a task list.
	 */
	private function holdsTasks(mixed $row): bool {
		if (is_array($row) === false) {
			return false;
		}

		$set = ($row[self::COMPONENT_SET] ?? null);
		if (is_object($set) === false || method_exists($set, 'getValue') === false) {
			return false;
		}

		return in_array('VTODO', (array)$set->getValue(), true);

	}//end holdsTasks()

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

}//end class
