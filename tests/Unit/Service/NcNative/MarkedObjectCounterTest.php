<?php

/**
 * Hermiq MarkedObjectCounter: the marked contacts and calendar items a viewer can see.
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
 *
 * @spec openspec/changes/agent-authored-marking/specs/nc-native-tools/spec.md#requirement-a-person-can-mark-a-labelled-file-as-reviewed-and-the-oversight-page-counts-the-labels-req-ailbl-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\NcNative;

use OCA\Hermiq\Service\NcNative\MarkedObjectCounter;
use OCP\Calendar\ICalendar;
use OCP\Calendar\IManager as ICalendarManager;
use OCP\Contacts\IManager as IContactsManager;
use OCP\IAddressBook;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the marked contact and calendar item counts.
 *
 * @covers \OCA\Hermiq\Service\NcNative\MarkedObjectCounter
 */
class MarkedObjectCounterTest extends TestCase {

	private const MARK = 'X-HERMIQ-AGENT-AUTHORED';

	/**
	 * An address book answering the given cards.
	 *
	 * @param list<array<string, mixed>>|RuntimeException $cards  The cards, or the failure search() throws.
	 * @param bool                                        $system Whether it is the system address book.
	 *
	 * @return IAddressBook
	 */
	private function book(array|RuntimeException $cards, bool $system = false): IAddressBook {
		$book = $this->createMock(IAddressBook::class);
		$book->method('isSystemAddressBook')->willReturn($system);
		if ($system === true) {
			$book->expects($this->never())->method('search');
			return $book;
		}

		$search = $book->method('search')->with('', ['FN'], $this->callback(static fn (array $o): bool => ($o['limit'] ?? 0) > 0));
		if ($cards instanceof RuntimeException) {
			$search->willThrowException($cards);
		} else {
			$search->willReturn($cards);
		}

		return $book;
	}//end book()

	/**
	 * A calendar answering the given search rows.
	 *
	 * @param list<array<string, mixed>>|RuntimeException $rows    The rows, or the failure search() throws.
	 * @param bool                                        $deleted Whether it is in the trash bin.
	 *
	 * @return ICalendar
	 */
	private function calendar(array|RuntimeException $rows, bool $deleted = false): ICalendar {
		$calendar = $this->createMock(ICalendar::class);
		$calendar->method('isDeleted')->willReturn($deleted);
		if ($deleted === true) {
			$calendar->expects($this->never())->method('search');
			return $calendar;
		}

		$search = $calendar->method('search')->with('', ['SUMMARY'], ['types' => ['VEVENT']], $this->greaterThan(0));
		if ($rows instanceof RuntimeException) {
			$search->willThrowException($rows);
		} else {
			$search->willReturn($rows);
		}

		return $calendar;
	}//end calendar()

	/**
	 * The counter over the given address books and calendars.
	 *
	 * @param list<IAddressBook> $books     The viewer's address books.
	 * @param list<ICalendar>    $calendars The viewer's calendars.
	 * @param int                $warnings  How many unreadable sources are logged.
	 *
	 * @return MarkedObjectCounter
	 */
	private function counter(array $books = [], array $calendars = [], int $warnings = 0): MarkedObjectCounter {
		$contacts = $this->createMock(IContactsManager::class);
		$contacts->method('getUserAddressBooks')->willReturn($books);
		$calendarManager = $this->createMock(ICalendarManager::class);
		$calendarManager->method('getCalendarsForPrincipal')->with('principals/users/olga')->willReturn($calendars);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->exactly($warnings))->method('warning');

		return new MarkedObjectCounter(contactsManager: $contacts, calendarManager: $calendarManager, logger: $logger);
	}//end counter()

	/**
	 * Only cards carrying the property count, over every address book but the system one.
	 *
	 * @return void
	 */
	public function testCountsMarkedContactsAcrossBooksButNotTheSystemBook(): void {
		$counter = $this->counter(
			books: [
				$this->book(cards: [['FN' => 'A', self::MARK => 'agent'], ['FN' => 'B']]),
				$this->book(cards: [], system: true),
				$this->book(cards: [['FN' => 'C', self::MARK => 'agent:x'], ['FN' => 'D', self::MARK => 'agent']]),
			]
		);

		$this->assertSame(['count' => 3, 'truncated' => false], $counter->contacts(property: self::MARK));
	}

	/**
	 * An address book that cannot be read is logged and skipped; the others still count.
	 *
	 * @return void
	 */
	public function testAnUnreadableAddressBookIsSkipped(): void {
		$counter = $this->counter(
			books: [
				$this->book(cards: new RuntimeException('backend down')),
				$this->book(cards: [['FN' => 'A', self::MARK => 'agent']]),
			],
			warnings: 1
		);

		$this->assertSame(['count' => 1, 'truncated' => false], $counter->contacts(property: self::MARK));
	}

	/**
	 * More cards than the cap: the count stops at the cap and says it was truncated.
	 *
	 * @return void
	 */
	public function testContactsBeyondTheCapAreTruncated(): void {
		$cards = array_fill(0, (MarkedObjectCounter::MAX_OBJECTS + 1), ['FN' => 'A', self::MARK => 'agent']);
		$counter = $this->counter(books: [$this->book(cards: $cards)]);

		$this->assertSame(['count' => MarkedObjectCounter::MAX_OBJECTS, 'truncated' => true], $counter->contacts(property: self::MARK));
	}

	/**
	 * A calendar item counts when one of its components carries the property; trashed calendars are skipped.
	 *
	 * @return void
	 */
	public function testCountsMarkedCalendarItemsAndSkipsTrashedCalendars(): void {
		$counter = $this->counter(
			calendars: [
				$this->calendar(
					rows: [
						['uri' => 'a.ics', 'objects' => [['SUMMARY' => [['Standup', []]], self::MARK => [['agent', []]]]]],
						['uri' => 'b.ics', 'objects' => [['SUMMARY' => [['Lunch', []]]]]],
						['uri' => 'c.ics', 'objects' => [['SUMMARY' => [['Series', []]]], ['SUMMARY' => [['Moved', []]], self::MARK => [['agent', []]]]]],
						['uri' => 'd.ics'],
						'not a row',
					]
				),
				$this->calendar(rows: [], deleted: true),
			]
		);

		$this->assertSame(['count' => 2, 'truncated' => false], $counter->calendarItems(uid: 'olga', property: self::MARK));
	}

	/**
	 * A calendar that cannot be read is logged and skipped.
	 *
	 * @return void
	 */
	public function testAnUnreadableCalendarIsSkipped(): void {
		$counter = $this->counter(
			calendars: [
				$this->calendar(rows: new RuntimeException('backend down')),
				$this->calendar(rows: [['objects' => [[self::MARK => [['agent', []]]]]]]),
			],
			warnings: 1
		);

		$this->assertSame(['count' => 1, 'truncated' => false], $counter->calendarItems(uid: 'olga', property: self::MARK));
	}

	/**
	 * Without a viewer there is nothing to count, and no calendar is asked for.
	 *
	 * @return void
	 */
	public function testNoViewerCountsNoCalendarItems(): void {
		$calendarManager = $this->createMock(ICalendarManager::class);
		$calendarManager->expects($this->never())->method('getCalendarsForPrincipal');
		$counter = new MarkedObjectCounter(
			contactsManager: $this->createMock(IContactsManager::class),
			calendarManager: $calendarManager,
			logger: $this->createMock(LoggerInterface::class)
		);

		$this->assertSame(['count' => 0, 'truncated' => false], $counter->calendarItems(uid: '', property: self::MARK));
	}

	/**
	 * Calendar items beyond the cap, over two calendars: the second is not read and the count is truncated.
	 *
	 * @return void
	 */
	public function testCalendarItemsBeyondTheCapAreTruncated(): void {
		$rows = array_fill(0, MarkedObjectCounter::MAX_OBJECTS, ['objects' => [[self::MARK => [['agent', []]]]]]);
		$second = $this->createMock(ICalendar::class);
		$second->method('isDeleted')->willReturn(false);
		$second->expects($this->never())->method('search');
		$counter = $this->counter(calendars: [$this->calendar(rows: $rows), $second]);

		$this->assertSame(['count' => MarkedObjectCounter::MAX_OBJECTS, 'truncated' => true], $counter->calendarItems(uid: 'olga', property: self::MARK));
	}
}
