<?php

/**
 * Tests for TaskWriteService (tools-nextcloud-tasks).
 *
 * Calendars are doubles of the real OCP interfaces; the DAV backend is a double
 * with the three method signatures of OCA\DAV\CalDAV\CalDavBackend at v34.0.3,
 * substituted through the protected resolver the way NotesWriteServiceTest
 * substitutes Notes. The refusals come first: a shared-in list that is not
 * written is the behaviour nobody watches fail.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\NcNative
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\NcNative;

use DateTimeImmutable;
use OCA\Hermiq\Service\NcNative\AgentArtefactMarker;
use OCA\Hermiq\Service\NcNative\TaskCalendarObject;
use OCA\Hermiq\Service\NcNative\TaskWriteService;
use OCP\Calendar\ICalendarIsShared;
use OCP\Calendar\ICalendarIsWritable;
use OCP\Calendar\ICreateFromString;
use OCP\Calendar\IManager as ICalendarManager;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Task list resolution, listing, creating and completing.
 *
 * @spec openspec/changes/tools-nextcloud-tasks/specs/nc-native-tools/spec.md#requirement-an-agent-can-create-a-task-in-the-users-own-list-marked-as-agent-authored-req-nctask-002
 */
final class TaskWriteServiceTest extends TestCase {

	private const COMPONENTS = '{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set';

	/**
	 * What each calendar double stored, by uri.
	 *
	 * @var array<string, array<int, array{0: string, 1: string}>>
	 */
	private array $stored = [];

	/**
	 * The search options each calendar double was asked, by uri.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private array $searched = [];

	/**
	 * A calendar double over the real OCP interfaces.
	 *
	 * @param string $uri The calendar uri.
	 * @param bool $shared Whether it is shared with the user by someone else.
	 * @param array<int, array<string, mixed>> $rows What search() returns.
	 * @param bool $writable Whether it reports itself writable.
	 *
	 * @return ICreateFromString&ICalendarIsWritable&ICalendarIsShared
	 */
	private function calendar(string $uri, bool $shared = false, array $rows = [], bool $writable = true): object {
		$calendar = $this->createMockForIntersectionOfInterfaces(
			[ICreateFromString::class, ICalendarIsWritable::class, ICalendarIsShared::class]
		);
		$calendar->method('getUri')->willReturn($uri);
		$calendar->method('getKey')->willReturn('id-' . $uri);
		$calendar->method('getDisplayName')->willReturn(ucfirst($uri));
		$calendar->method('isShared')->willReturn($shared);
		$calendar->method('isWritable')->willReturn($writable);
		$calendar->method('search')->willReturnCallback(
			function (string $pattern, array $properties = [], array $options = []) use ($uri, $rows): array {
				$this->searched[$uri][] = $options;
				if (isset($options['uid']) === true) {
					return array_values(array_filter($rows, static fn (array $row): bool => $row['uid'] === $options['uid']));
				}

				return $rows;
			}
		);
		$calendar->method('createFromString')->willReturnCallback(
			function (string $name, string $data) use ($uri): void {
				$this->stored[$uri][] = [$name, $data];
			}
		);

		return $calendar;

	}//end calendar()

	/**
	 * A backend double with the CalDavBackend v34 signatures this service calls.
	 *
	 * @param array<string, array<int, string>> $components Component set per calendar uri.
	 * @param array<string, string> $objects Stored calendar data by "<calendarId>/<objectUri>".
	 *
	 * @return object The backend double; its public $updates records every update.
	 */
	private function backend(array $components, array $objects = []): object {
		$rows = [];
		foreach ($components as $uri => $set) {
			$rows[] = [
				'uri' => $uri,
				self::COMPONENTS => new class($set) {
					/**
					 * @param array<int, string> $set The components.
					 */
					public function __construct(private array $set) {
					}

					/**
					 * @return array<int, string>
					 */
					public function getValue(): array {
						return $this->set;
					}
				},
			];
		}

		return new class($rows, $objects) {
			/** @var array<int, array{0: mixed, 1: string, 2: string}> */
			public array $updates = [];

			/**
			 * @param array<int, array<string, mixed>> $rows Calendar rows.
			 * @param array<string, string> $objects Stored objects.
			 */
			public function __construct(private array $rows, private array $objects) {
			}

			/**
			 * @param string $principalUri The principal.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function getCalendarsForUser($principalUri) {
				return $this->rows;
			}

			/**
			 * @return array<string, mixed>|null
			 */
			public function getCalendarObject($calendarId, $objectUri, int $calendarType = 0) {
				$key = $calendarId . '/' . $objectUri;
				if (isset($this->objects[$key]) === false) {
					return null;
				}

				return ['uri' => $objectUri, 'calendardata' => $this->objects[$key]];
			}

			/**
			 * @return string
			 */
			public function updateCalendarObject($calendarId, $objectUri, $calendarData, $calendarType = 0) {
				$this->updates[] = [$calendarId, (string)$objectUri, (string)$calendarData];

				return '"etag"';
			}
		};

	}//end backend()

	/**
	 * Build the service over the given calendars and backend (null: no DAV backend).
	 *
	 * @param array<int, object> $calendars The user's calendars.
	 * @param object|null $backend The backend double.
	 *
	 * @return TaskWriteService
	 */
	private function service(array $calendars, ?object $backend): TaskWriteService {
		$manager = $this->createMock(ICalendarManager::class);
		$manager->method('getCalendarsForPrincipal')->with('principals/users/alice')->willReturn($calendars);

		$marker = new AgentArtefactMarker(
			$this->createMock(ISystemTagManager::class),
			$this->createMock(ISystemTagObjectMapper::class),
			$this->createMock(LoggerInterface::class)
		);

		return new class($manager, $marker, new TaskCalendarObject(), $this->createMock(ContainerInterface::class), $this->createMock(LoggerInterface::class), $backend) extends TaskWriteService {
			/**
			 * @param mixed ...$args Parent arguments, then the backend double.
			 */
			public function __construct(ICalendarManager $m, AgentArtefactMarker $a, TaskCalendarObject $t, ContainerInterface $c, LoggerInterface $l, private ?object $double) {
				parent::__construct($m, $a, $t, $c, $l);
			}

			/**
			 * @return object|null
			 */
			protected function davBackend(): ?object {
				return $this->double;
			}
		};

	}//end service()

	/**
	 * A search row as CalDavBackend::search() shapes a VTODO.
	 *
	 * @param string $uid The task uid.
	 * @param string $summary The summary.
	 * @param string|null $due The due date, or null.
	 * @param string|null $status The STATUS, or null.
	 *
	 * @return array<string, mixed>
	 */
	private function row(string $uid, string $summary, ?string $due = null, ?string $status = null): array {
		$object = ['UID' => [$uid, []], 'SUMMARY' => [$summary, []]];
		if ($due !== null) {
			$object['DUE'] = [new DateTimeImmutable($due), []];
		}

		if ($status !== null) {
			$object['STATUS'] = [$status, []];
		}

		return ['id' => 1, 'type' => 'VTODO', 'uid' => $uid, 'uri' => $uid . '.ics', 'objects' => [$object], 'timezones' => []];

	}//end row()

	/**
	 * The three standard calendars: an event calendar, the user's own task list
	 * and a colleague's list shared with the user.
	 *
	 * @param array<int, array<string, mixed>> $ownRows Tasks in the own list.
	 * @param array<int, array<string, mixed>> $sharedRows Tasks in the shared list.
	 *
	 * @return array<int, object>
	 */
	private function standardCalendars(array $ownRows = [], array $sharedRows = []): array {
		return [
			$this->calendar(uri: 'personal'),
			$this->calendar(uri: 'werkvoorraad-burgerzaken', rows: $ownRows),
			$this->calendar(uri: 'team-vergunningen_shared_by_bob', shared: true, rows: $sharedRows),
		];

	}//end standardCalendars()

	/**
	 * The component sets matching standardCalendars().
	 *
	 * @return array<string, array<int, string>>
	 */
	private function standardComponents(): array {
		return [
			'personal' => ['VEVENT'],
			'werkvoorraad-burgerzaken' => ['VTODO'],
			'team-vergunningen_shared_by_bob' => ['VEVENT', 'VTODO'],
		];

	}//end standardComponents()

	/**
	 * A list a colleague shared is never written, and the refusal says why.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tools-nextcloud-tasks/specs/nc-native-tools/spec.md#requirement-an-agent-can-create-a-task-in-the-users-own-list-marked-as-agent-authored-req-nctask-002
	 */
	public function testASharedInListIsNotWritten(): void {
		$service = $this->service($this->standardCalendars(), $this->backend($this->standardComponents()));

		$result = $service->invoke(
			uid: 'alice',
			toolId: 'hermiq.createTask',
			arguments: ['summary' => 'x', 'taskListUri' => 'team-vergunningen_shared_by_bob']
		);

		$this->assertSame('task_list_not_writable', $result['error']['code'] ?? null);
		$this->assertSame('That task list is shared with you by someone else and cannot be written.', $result['error']['message']);
		$this->assertSame([], $this->stored);

	}//end testASharedInListIsNotWritten()

	/**
	 * Without a list named, the task lands in the user's own task list: not in the
	 * event calendar that comes first, and not in the shared-in list.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tools-nextcloud-tasks/specs/nc-native-tools/spec.md#requirement-an-agent-can-create-a-task-in-the-users-own-list-marked-as-agent-authored-req-nctask-002
	 */
	public function testOnlyOwnWritableTaskListsAreWriteTargets(): void {
		$calendars = [
			$this->calendar(uri: 'personal'),
			$this->calendar(uri: 'team-vergunningen_shared_by_bob', shared: true),
			$this->calendar(uri: 'read-only-list', writable: false),
			$this->calendar(uri: 'werkvoorraad-burgerzaken'),
		];
		$components = $this->standardComponents() + ['read-only-list' => ['VTODO']];
		$service = $this->service($calendars, $this->backend($components));

		$result = $service->invoke(
			uid: 'alice',
			toolId: 'hermiq.createTask',
			arguments: ['summary' => 'Besluit bezwaar Kerkstraat 12 versturen', 'due' => '2026-10-02', 'agentId' => 'agent-7']
		);

		$this->assertTrue($result['created'] ?? false, json_encode($result));
		$this->assertSame(['werkvoorraad-burgerzaken'], array_keys($this->stored));

		[$name, $ics] = $this->stored['werkvoorraad-burgerzaken'][0];
		$this->assertMatchesRegularExpression('/^hermiq-[0-9a-f]{16}\.ics$/', $name);
		$this->assertStringContainsString("BEGIN:VTODO\r\n", $ics);
		$this->assertStringContainsString("\r\nSUMMARY:Besluit bezwaar Kerkstraat 12 versturen\r\n", $ics);
		$this->assertStringContainsString("\r\nDUE;VALUE=DATE:20261002\r\n", $ics);
		$this->assertStringContainsString("\r\nX-HERMIQ-AGENT-AUTHORED:hermiq:agent-7\r\n", $ics);

		// The run record gets the list and the uid, and not the task text.
		$this->assertSame('task', $result['artefact']['type']);
		$this->assertSame('werkvoorraad-burgerzaken/' . $result['uid'], $result['artefact']['id']);
		$this->assertStringContainsString("\r\nUID:" . $result['uid'] . "\r\n", $ics);
		$this->assertStringNotContainsString('Kerkstraat', json_encode($result['artefact']));

	}//end testOnlyOwnWritableTaskListsAreWriteTargets()

	/**
	 * With no task list of their own the user is told how to get one.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tools-nextcloud-tasks/specs/nc-native-tools/spec.md#requirement-an-agent-can-create-a-task-in-the-users-own-list-marked-as-agent-authored-req-nctask-002
	 */
	public function testNoOwnTaskListIsReportedHonestly(): void {
		$service = $this->service(
			[$this->calendar(uri: 'personal'), $this->calendar(uri: 'team-vergunningen_shared_by_bob', shared: true)],
			$this->backend($this->standardComponents())
		);

		$result = $service->invoke(uid: 'alice', toolId: 'hermiq.createTask', arguments: ['summary' => 'x']);

		$this->assertSame('no_task_list', $result['error']['code'] ?? null);
		$this->assertSame('You have no task list. Create one in the Tasks app.', $result['error']['message']);
		$this->assertSame([], $this->stored);

	}//end testNoOwnTaskListIsReportedHonestly()

	/**
	 * Invalid arguments are refused before anything is resolved or written.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tools-nextcloud-tasks/specs/nc-native-tools/spec.md#requirement-an-agent-can-create-a-task-in-the-users-own-list-marked-as-agent-authored-req-nctask-002
	 */
	public function testInvalidArgumentsAreRefused(): void {
		$service = $this->service($this->standardCalendars(), $this->backend($this->standardComponents()));

		foreach ([['summary' => ' '], ['summary' => 'x', 'due' => 'volgende vrijdag'], ['summary' => 'x', 'priority' => 12]] as $arguments) {
			$result = $service->invoke(uid: 'alice', toolId: 'hermiq.createTask', arguments: $arguments);
			$this->assertSame('invalid_argument', $result['error']['code'] ?? null, json_encode($arguments));
		}

		$this->assertSame([], $this->stored);

	}//end testInvalidArgumentsAreRefused()

	/**
	 * Listing open tasks due before a date returns only those, from every list the
	 * user can read, asking CalDAV for VTODO components only.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tools-nextcloud-tasks/specs/nc-native-tools/spec.md#requirement-an-agent-can-list-the-acting-users-tasks-req-nctask-001
	 */
	public function testListReturnsOpenTasksDueBefore(): void {
		$own = [
			$this->row(uid: 't1', summary: 'Terugbellen mevrouw De Vries over parkeervergunning', due: '2026-10-02'),
			$this->row(uid: 't2', summary: 'Afgerond', due: '2026-10-01', status: 'COMPLETED'),
			$this->row(uid: 't3', summary: 'Later', due: '2026-11-01'),
			$this->row(uid: 't4', summary: 'Zonder datum'),
		];
		$shared = [$this->row(uid: 's1', summary: 'Vergunning Dorpsstraat', due: '2026-10-03', status: 'IN-PROCESS')];
		$service = $this->service($this->standardCalendars($own, $shared), $this->backend($this->standardComponents()));

		$result = $service->invoke(
			uid: 'alice',
			toolId: 'hermiq.listTasks',
			arguments: ['status' => 'open', 'dueBefore' => '2026-10-05']
		);

		$this->assertSame(['t1', 's1'], array_column($result['tasks'], 'uid'));
		$this->assertSame('Terugbellen mevrouw De Vries over parkeervergunning', $result['tasks'][0]['summary']);
		$this->assertSame('2026-10-02', substr((string)$result['tasks'][0]['due'], 0, 10));
		$this->assertSame('werkvoorraad-burgerzaken', $result['tasks'][0]['list']);
		$this->assertSame('NEEDS-ACTION', $result['tasks'][0]['status']);
		$this->assertArrayNotHasKey('personal', $this->searched, 'An event calendar is not a task list.');
		$this->assertSame(['VTODO'], $this->searched['werkvoorraad-burgerzaken'][0]['types']);

		$completed = $service->invoke(uid: 'alice', toolId: 'hermiq.listTasks', arguments: ['status' => 'completed']);
		$this->assertSame(['t2'], array_column($completed['tasks'], 'uid'));

	}//end testListReturnsOpenTasksDueBefore()

	/**
	 * At most fifty tasks come back, whatever the caller asks for.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tools-nextcloud-tasks/specs/nc-native-tools/spec.md#requirement-an-agent-can-list-the-acting-users-tasks-req-nctask-001
	 */
	public function testListIsCappedAtFifty(): void {
		$rows = [];
		for ($i = 0; $i < 60; $i++) {
			$rows[] = $this->row(uid: 't' . $i, summary: 'Taak ' . $i);
		}

		$service = $this->service($this->standardCalendars($rows), $this->backend($this->standardComponents()));
		$result = $service->invoke(uid: 'alice', toolId: 'hermiq.listTasks', arguments: ['limit' => 500]);

		$this->assertCount(50, $result['tasks']);

	}//end testListIsCappedAtFifty()

	/**
	 * Completing rewrites the object in place through the DAV backend, under its
	 * own uri, keeping what the user wrote; the record holds list and uid only.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tools-nextcloud-tasks/specs/nc-native-tools/spec.md#requirement-an-agent-can-complete-a-task-without-losing-what-the-user-wrote-req-nctask-003
	 */
	public function testCompleteUpdatesTheObjectInPlace(): void {
		$uid = '7c1e2a4b-5d6f-4a8b-9c0d-1e2f3a4b5c6d';
		$own = [$this->row(uid: $uid, summary: 'Terugbellen mevrouw De Vries over parkeervergunning', due: '2026-10-02')];
		$backend = $this->backend(
			$this->standardComponents(),
			['id-werkvoorraad-burgerzaken/' . $uid . '.ics' => TaskCalendarObjectTest::TASKS_APP_FIXTURE]
		);
		$service = $this->service($this->standardCalendars($own), $backend);

		$result = $service->invoke(uid: 'alice', toolId: 'hermiq.completeTask', arguments: ['uid' => $uid, 'agentId' => 'agent-7']);

		$this->assertTrue($result['completed'] ?? false, json_encode($result));
		$this->assertCount(1, $backend->updates);
		[$calendarId, $objectUri, $data] = $backend->updates[0];
		$this->assertSame('id-werkvoorraad-burgerzaken', $calendarId);
		$this->assertSame($uid . '.ics', $objectUri);
		$this->assertStringContainsString("\r\nSTATUS:COMPLETED\r\n", $data);
		$this->assertStringContainsString("\r\nX-HERMIQ-AGENT-AUTHORED:hermiq:agent-7\r\n", $data);
		$this->assertStringContainsString("DESCRIPTION:Mevrouw belde over de vergunning voor de Kerkstraat\\, zij wil \r\n weten", $data);
		$this->assertSame(['type' => 'task', 'id' => 'werkvoorraad-burgerzaken/' . $uid], $result['artefact']);
		$this->assertSame([], $this->stored, 'Completing never creates a second object.');

	}//end testCompleteUpdatesTheObjectInPlace()

	/**
	 * A task in a shared-in list, or one that is not there, is not completed.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tools-nextcloud-tasks/specs/nc-native-tools/spec.md#requirement-an-agent-can-complete-a-task-without-losing-what-the-user-wrote-req-nctask-003
	 */
	public function testCompleteRefusesSharedInAndUnknownTasks(): void {
		$shared = [$this->row(uid: 's1', summary: 'Vergunning Dorpsstraat')];
		$backend = $this->backend(
			$this->standardComponents(),
			['id-team-vergunningen_shared_by_bob/s1.ics' => TaskCalendarObjectTest::TASKS_APP_FIXTURE]
		);
		$service = $this->service($this->standardCalendars([], $shared), $backend);

		$sharedResult = $service->invoke(
			uid: 'alice',
			toolId: 'hermiq.completeTask',
			arguments: ['uid' => 's1', 'taskListUri' => 'team-vergunningen_shared_by_bob']
		);
		$this->assertSame('task_list_not_writable', $sharedResult['error']['code'] ?? null);

		$unknown = $service->invoke(uid: 'alice', toolId: 'hermiq.completeTask', arguments: ['uid' => 's1']);
		$this->assertSame('task_not_found', $unknown['error']['code'] ?? null);

		$this->assertSame([], $backend->updates);

	}//end testCompleteRefusesSharedInAndUnknownTasks()

	/**
	 * Without the DAV backend nothing pretends to work: complete is not offered,
	 * and listing and creating say the task lists are not available.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tools-nextcloud-tasks/specs/nc-native-tools/spec.md#requirement-an-agent-can-complete-a-task-without-losing-what-the-user-wrote-req-nctask-003
	 */
	public function testWithoutTheDavBackendCompleteIsNotOffered(): void {
		$service = $this->service($this->standardCalendars(), null);

		$this->assertFalse($service->canComplete());
		$this->assertTrue($this->service($this->standardCalendars(), $this->backend([]))->canComplete());
		$this->assertSame('tasks_not_available', $service->invoke(uid: 'alice', toolId: 'hermiq.listTasks', arguments: [])['error']['code'] ?? null);
		$this->assertSame('tasks_not_available', $service->invoke(uid: 'alice', toolId: 'hermiq.createTask', arguments: ['summary' => 'x'])['error']['code'] ?? null);
		$this->assertSame('tasks_not_writable', $service->invoke(uid: 'alice', toolId: 'hermiq.completeTask', arguments: ['uid' => 'x'])['error']['code'] ?? null);

	}//end testWithoutTheDavBackendCompleteIsNotOffered()

	/**
	 * A failing calendar never throws across the tool boundary.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tools-nextcloud-tasks/specs/nc-native-tools/spec.md#requirement-task-tools-are-default-denied-never-delete-and-record-identity-without-content-req-nctask-004
	 */
	public function testAFailingCalendarNeverThrows(): void {
		$broken = $this->createMockForIntersectionOfInterfaces(
			[ICreateFromString::class, ICalendarIsWritable::class, ICalendarIsShared::class]
		);
		$broken->method('getUri')->willReturn('werkvoorraad-burgerzaken');
		$broken->method('getKey')->willReturn('1');
		$broken->method('isShared')->willReturn(false);
		$broken->method('isWritable')->willReturn(true);
		$broken->method('search')->willThrowException(new RuntimeException('db down'));
		$broken->method('createFromString')->willThrowException(new RuntimeException('409'));
		$service = $this->service([$broken], $this->backend($this->standardComponents()));

		$this->assertSame('tasks_read_failed', $service->invoke(uid: 'alice', toolId: 'hermiq.listTasks', arguments: [])['error']['code'] ?? null);
		$this->assertSame('task_write_failed', $service->invoke(uid: 'alice', toolId: 'hermiq.createTask', arguments: ['summary' => 'x'])['error']['code'] ?? null);
		$this->assertSame('unknown_tool', $service->invoke(uid: 'alice', toolId: 'hermiq.deleteTask', arguments: [])['error']['code'] ?? null);

	}//end testAFailingCalendarNeverThrows()

}//end class
