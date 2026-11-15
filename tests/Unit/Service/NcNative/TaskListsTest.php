<?php

/**
 * Hermiq TaskLists unit tests.
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
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\NcNative;

use OCA\Hermiq\Service\NcNative\TaskLists;
use OCP\Calendar\ICalendar;
use OCP\Calendar\ICalendarIsShared;
use OCP\Calendar\ICalendarIsWritable;
use OCP\Calendar\ICreateFromString;
use OCP\Calendar\IManager as ICalendarManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for which calendars count as task lists and which may be written.
 *
 * @covers \OCA\Hermiq\Service\NcNative\TaskLists
 */
class TaskListsTest extends TestCase {

	/**
	 * The CalDAV component-set property.
	 *
	 * @var string
	 */
	private const SET = '{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set';

	/**
	 * A component set holding these components.
	 *
	 * @param list<string> $components The components.
	 *
	 * @return object The set.
	 */
	private function set(array $components): object {
		return new class($components) {
			public function __construct(private array $components) {
			}

			public function getValue(): array {
				return $this->components;
			}
		};
	}//end set()

	/**
	 * A writable, own (not shared) calendar.
	 *
	 * @param string $uri      The uri.
	 * @param bool   $shared   Whether shared with the user.
	 * @param bool   $writable Whether writable.
	 *
	 * @return ICalendar The calendar.
	 */
	private function calendar(string $uri, bool $shared = false, bool $writable = true): ICalendar {
		$calendar = $this->createMockForIntersectionOfInterfaces(
			[ICreateFromString::class, ICalendarIsWritable::class, ICalendarIsShared::class]
		);
		$calendar->method('getUri')->willReturn($uri);
		$calendar->method('isShared')->willReturn($shared);
		$calendar->method('isWritable')->willReturn($writable);
		return $calendar;
	}//end calendar()

	/**
	 * TaskLists over a backend double and the manager's calendars.
	 *
	 * @param object|null     $backend   The backend, or null when CalDAV is absent.
	 * @param list<ICalendar> $calendars What the manager returns.
	 *
	 * @return TaskLists The lists.
	 */
	private function lists(?object $backend, array $calendars = []): TaskLists {
		$manager = $this->createMock(ICalendarManager::class);
		$manager->method('getCalendarsForPrincipal')->with('principals/users/alice')->willReturn($calendars);

		return new class($manager, $this->createMock(ContainerInterface::class), $this->createMock(LoggerInterface::class), $backend) extends TaskLists {
			public function __construct(ICalendarManager $m, ContainerInterface $c, LoggerInterface $l, private ?object $double) {
				parent::__construct($m, $c, $l);
			}

			public function davBackend(): ?object {
				return $this->double;
			}
		};
	}//end lists()

	/**
	 * A backend whose rows are these.
	 *
	 * @param list<mixed> $rows The calendar rows.
	 *
	 * @return object The backend.
	 */
	private function backend(array $rows): object {
		return new class($rows) {
			public ?string $principal = null;

			public function __construct(private array $rows) {
			}

			public function getCalendarsForUser(string $principal): array {
				$this->principal = $principal;
				return $this->rows;
			}
		};
	}//end backend()

	/**
	 * Only calendars whose component set holds VTODO are task lists; malformed rows are skipped.
	 *
	 * @return void
	 */
	public function testOnlyVtodoCalendarsAreTaskLists(): void {
		$backend = $this->backend(
			[
				'not a row',
				['uri' => 'no-set'],
				['uri' => 'plain-set', self::SET => ['VTODO']],
				['uri' => 'events', self::SET => $this->set(['VEVENT'])],
				['uri' => 'tasks', self::SET => $this->set(['VEVENT', 'VTODO'])],
			]
		);
		$tasks = $this->calendar('tasks');
		$lists = $this->lists($backend, [$this->calendar('events'), $this->calendar('no-set'), $tasks, $this->calendar('plain-set')]);

		$this->assertSame([$tasks], $lists->all('alice'));
		$this->assertSame('principals/users/alice', $backend->principal);
	}//end testOnlyVtodoCalendarsAreTaskLists()

	/**
	 * Without CalDAV there are no lists and writing reports tasks unavailable.
	 *
	 * @return void
	 */
	public function testWithoutCaldavTasksAreUnavailable(): void {
		$lists = $this->lists(null);

		$this->assertNull($lists->all('alice'));
		$this->assertSame(
			['error' => ['code' => 'tasks_not_available', 'message' => 'Task lists are not available on this instance.']],
			$lists->ownWritable('alice', '')
		);
	}//end testWithoutCaldavTasksAreUnavailable()

	/**
	 * Without a list uri every own writable list is returned and shared ones are skipped.
	 *
	 * @return void
	 */
	public function testOwnWritableSkipsSharedAndReadOnlyLists(): void {
		$own = $this->calendar('own');
		$rows = [];
		foreach (['own', 'shared', 'readonly'] as $uri) {
			$rows[] = ['uri' => $uri, self::SET => $this->set(['VTODO'])];
		}

		$lists = $this->lists(
			$this->backend($rows),
			[$this->calendar('shared', shared: true), $own, $this->calendar('readonly', writable: false)]
		);

		$this->assertSame([$own], $lists->ownWritable('alice', ''));
		$this->assertSame([$own], $lists->ownWritable('alice', 'own'));
		$this->assertSame([], $lists->ownWritable('alice', 'missing'));
	}//end testOwnWritableSkipsSharedAndReadOnlyLists()

	/**
	 * Naming a list someone else shared is refused, not silently skipped.
	 *
	 * @return void
	 */
	public function testNamingASharedListIsRefused(): void {
		$lists = $this->lists(
			$this->backend([['uri' => 'shared', self::SET => $this->set(['VTODO'])]]),
			[$this->calendar('shared', shared: true)]
		);

		$this->assertSame(
			['error' => ['code' => 'task_list_not_writable', 'message' => 'That task list is shared with you by someone else and cannot be written.']],
			$lists->ownWritable('alice', 'shared')
		);
	}//end testNamingASharedListIsRefused()

	/**
	 * A numeric calendar key becomes an int; any other key stays a string.
	 *
	 * @return void
	 */
	public function testCalendarIdIsAnIntOnlyForDigitKeys(): void {
		$numeric = $this->createMock(ICalendar::class);
		$numeric->method('getKey')->willReturn('42');
		$text = $this->createMock(ICalendar::class);
		$text->method('getKey')->willReturn('cal-42');

		$lists = $this->lists(null);

		$this->assertSame(42, $lists->calendarId($numeric));
		$this->assertSame('cal-42', $lists->calendarId($text));
	}//end testCalendarIdIsAnIntOnlyForDigitKeys()
}//end class
