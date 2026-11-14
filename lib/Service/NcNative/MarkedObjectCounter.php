<?php

/**
 * Hermiq MarkedObjectCounter.
 *
 * Counts the contacts and calendar items a viewer can see that carry a given vCard /
 * iCalendar property, such as the agent-authored mark `X-HERMIQ-AGENT-AUTHORED`
 * (AgentArtefactMarker::OBJECT_PROPERTY). There is no index over `X-` properties, so the
 * count reads the objects of the viewer's own address books and calendars through the
 * public contacts and calendar APIs, which only return what that viewer may see, and
 * checks each object for the property. The read is capped; the result says when it was.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\NcNative
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/agent-authored-marking/specs/nc-native-tools/spec.md#requirement-a-person-can-mark-a-labelled-file-as-reviewed-and-the-oversight-page-counts-the-labels-req-ailbl-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\NcNative;

use OCP\Calendar\ICalendar;
use OCP\Calendar\IManager as ICalendarManager;
use OCP\IAddressBook;
use OCP\Contacts\IManager as IContactsManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Counts the viewer's contacts and calendar items that carry one property.
 *
 * @spec openspec/changes/agent-authored-marking/specs/nc-native-tools/spec.md#requirement-a-person-can-mark-a-labelled-file-as-reviewed-and-the-oversight-page-counts-the-labels-req-ailbl-002
 */
class MarkedObjectCounter {

	/**
	 * The most objects of one kind (contacts, or calendar items) read for one count.
	 *
	 * @var int
	 */
	public const MAX_OBJECTS = 5000;

	/**
	 * Constructor.
	 *
	 * @param IContactsManager $contactsManager The viewer's address books (the session user).
	 * @param ICalendarManager $calendarManager The calendars of a principal.
	 * @param LoggerInterface  $logger          Notes an address book or calendar that could not be read.
	 *
	 * @spec openspec/changes/agent-authored-marking/specs/nc-native-tools/spec.md#requirement-a-person-can-mark-a-labelled-file-as-reviewed-and-the-oversight-page-counts-the-labels-req-ailbl-002
	 */
	public function __construct(
		private readonly IContactsManager $contactsManager,
		private readonly ICalendarManager $calendarManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The contacts in the session user's address books that carry the property.
	 *
	 * The system address book (every account of the instance) is skipped: nothing an
	 * agent writes lands there.
	 *
	 * @param string $property The vCard property name, e.g. `X-HERMIQ-AGENT-AUTHORED`.
	 *
	 * @return array{count: int, truncated: bool} `truncated` says more contacts exist than were read.
	 *
	 * @spec openspec/changes/agent-authored-marking/specs/nc-native-tools/spec.md#requirement-a-person-can-mark-a-labelled-file-as-reviewed-and-the-oversight-page-counts-the-labels-req-ailbl-002
	 */
	public function contacts(string $property): array {
		$batches = [];
		foreach ($this->contactsManager->getUserAddressBooks() as $book) {
			if ($book->isSystemAddressBook() === false) {
				$batches[] = fn (int $limit): array => $this->cards(book: $book, limit: $limit);
			}
		}

		return $this->tally(
			batches: $batches,
			matches: static fn (mixed $card): bool => is_array($card) === true && isset($card[$property]) === true
		);
	}//end contacts()

	/**
	 * The calendar items in the viewer's calendars (own and shared with them) that carry the property.
	 *
	 * Calendars in the trash bin are skipped. A shared calendar only returns what its
	 * share lets the viewer see.
	 *
	 * @param string $uid      The viewer.
	 * @param string $property The iCalendar property name, e.g. `X-HERMIQ-AGENT-AUTHORED`.
	 *
	 * @return array{count: int, truncated: bool} `truncated` says more items exist than were read.
	 *
	 * @spec openspec/changes/agent-authored-marking/specs/nc-native-tools/spec.md#requirement-a-person-can-mark-a-labelled-file-as-reviewed-and-the-oversight-page-counts-the-labels-req-ailbl-002
	 */
	public function calendarItems(string $uid, string $property): array {
		if ($uid === '') {
			return ['count' => 0, 'truncated' => false];
		}

		$batches = [];
		foreach ($this->calendarManager->getCalendarsForPrincipal('principals/users/' . $uid) as $calendar) {
			if ($calendar->isDeleted() === false) {
				$batches[] = fn (int $limit): array => $this->calendarObjects(calendar: $calendar, limit: $limit);
			}
		}

		return $this->tally(
			batches: $batches,
			matches: fn (mixed $object): bool => $this->carries(object: $object, property: $property)
		);
	}//end calendarItems()

	/**
	 * Count the rows that match over the sources in turn, reading at most MAX_OBJECTS rows.
	 *
	 * Each source is read only when the walk reaches it, with room for one row more
	 * than is left, so a full read can tell that more exist.
	 *
	 * @param array<int, callable(int): array<int, mixed>> $batches The sources, each read with a row limit.
	 * @param callable(mixed): bool                        $matches Whether a row counts.
	 *
	 * @return array{count: int, truncated: bool} `truncated` says more rows exist than were read.
	 */
	private function tally(array $batches, callable $matches): array {
		$count = 0;
		$read = 0;
		foreach ($batches as $batch) {
			if ($read >= self::MAX_OBJECTS) {
				return ['count' => $count, 'truncated' => true];
			}

			foreach ($batch(self::MAX_OBJECTS - $read + 1) as $row) {
				if ($read >= self::MAX_OBJECTS) {
					return ['count' => $count, 'truncated' => true];
				}

				$read++;
				if ($matches($row) === true) {
					$count++;
				}
			}
		}

		return ['count' => $count, 'truncated' => false];
	}//end tally()

	/**
	 * The cards of one address book, at most $limit of them; none when it cannot be read.
	 *
	 * @param IAddressBook $book  The address book.
	 * @param int          $limit The most cards to read.
	 *
	 * @return array<int, mixed> The search rows.
	 */
	private function cards(IAddressBook $book, int $limit): array {
		try {
			return $book->search('', ['FN'], ['limit' => $limit]);
		} catch (Throwable $e) {
			$this->logger->warning('Hermiq: an address book could not be read for the AI label count', ['exception' => $e]);
			return [];
		}
	}//end cards()

	/**
	 * The events of one calendar, at most $limit of them; none when it cannot be read.
	 *
	 * Every event has a SUMMARY (the calendar write tool requires one), which is an
	 * indexed property, so an empty pattern over it reaches them all.
	 *
	 * @param ICalendar $calendar The calendar.
	 * @param int       $limit    The most events to read.
	 *
	 * @return array<int, mixed> The search rows.
	 */
	private function calendarObjects(ICalendar $calendar, int $limit): array {
		try {
			return $calendar->search('', ['SUMMARY'], ['types' => ['VEVENT']], $limit);
		} catch (Throwable $e) {
			$this->logger->warning('Hermiq: a calendar could not be read for the AI label count', ['exception' => $e]);
			return [];
		}
	}//end calendarObjects()

	/**
	 * Whether a calendar search row has a component that carries the property.
	 *
	 * @param mixed  $object   One row of ICalendar::search(): its `objects` are the components.
	 * @param string $property The property name.
	 *
	 * @return bool
	 */
	private function carries(mixed $object, string $property): bool {
		if (is_array($object) === false || is_array($object['objects'] ?? null) === false) {
			return false;
		}

		foreach ($object['objects'] as $component) {
			if (is_array($component) === true && isset($component[$property]) === true) {
				return true;
			}
		}

		return false;
	}//end carries()
}//end class
