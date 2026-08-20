<?php

/**
 * Tests for the nc-native-write-tools CalendarWriteService.
 *
 * The tests that matter here are the REFUSALS. A write that works is easy to
 * demonstrate; a write that correctly declines the read-only calendar is the thing
 * nobody watches fail, and therefore the thing worth asserting.
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

use OCA\Hermiq\Service\NcNative\AgentArtefactMarker;
use OCA\Hermiq\Service\NcNative\CalendarWriteService;
use OCP\Calendar\ICalendarEventBuilder;
use OCP\Calendar\ICalendarIsWritable;
use OCP\Calendar\ICreateFromString;
use OCP\Calendar\IManager as ICalendarManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for CalendarWriteService.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\NcNative
 */
class CalendarWriteServiceTest extends TestCase {

	/**
	 * Valid arguments for a one-hour event.
	 *
	 * @var array<string, mixed>
	 */
	private const VALID = [
		'summary' => 'Kickoff',
		'start' => '2026-09-01T09:00:00Z',
		'end' => '2026-09-01T10:00:00Z',
	];

	/**
	 * Build the service.
	 *
	 * @param ICalendarManager $calendars Calendar manager double.
	 * @param AgentArtefactMarker|null $marker Marker double.
	 *
	 * @return CalendarWriteService
	 */
	private function service(ICalendarManager $calendars, ?AgentArtefactMarker $marker = null): CalendarWriteService {
		return new CalendarWriteService(
			$calendars,
			$marker ?? $this->createMock(AgentArtefactMarker::class),
			$this->createMock(LoggerInterface::class)
		);

	}//end service()

	/**
	 * A calendar double that is writable (or not) and captures what was stored.
	 *
	 * @param bool $writable Whether the calendar reports itself writable.
	 * @param string|null $captured Reference filled with the stored ICS.
	 *
	 * @return ICreateFromString&ICalendarIsWritable
	 */
	private function calendar(bool $writable, ?string &$captured = null): object {
		$calendar = $this->createMockForIntersectionOfInterfaces([ICreateFromString::class, ICalendarIsWritable::class]);
		$calendar->method('isWritable')->willReturn($writable);
		$calendar->method('getUri')->willReturn('personal');
		$calendar->method('getDisplayName')->willReturn('Personal');
		$calendar->method('createFromString')->willReturnCallback(
			function (string $name, string $data) use (&$captured): void {
				$captured = $data;
			}
		);

		return $calendar;

	}//end calendar()

	/**
	 * A builder double producing a minimal ICS and recording attendees.
	 *
	 * @param array<int, string> $attendees Reference-filled attendee list.
	 *
	 * @return ICalendarEventBuilder
	 */
	private function builder(array &$attendees): ICalendarEventBuilder {
		$builder = $this->createMock(ICalendarEventBuilder::class);
		foreach (['setSummary', 'setStartDate', 'setEndDate', 'setDescription', 'setLocation'] as $setter) {
			$builder->method($setter)->willReturnSelf();
		}

		$builder->method('addAttendee')->willReturnCallback(
			function (string $email) use (&$attendees, $builder): ICalendarEventBuilder {
				$attendees[] = $email;

				return $builder;
			}
		);
		$builder->method('toIcs')->willReturn("BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nSUMMARY:x\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n");

		return $builder;

	}//end builder()

	/**
	 * A read-only calendar is never written to, though it is in the principal's list.
	 *
	 * @return void
	 */
	public function testReadOnlyCalendarIsRefused(): void {
		$calendars = $this->createMock(ICalendarManager::class);
		$calendars->method('getCalendarsForPrincipal')->willReturn([$this->calendar(false)]);

		$result = $this->service($calendars)->create('alice', self::VALID);

		$this->assertSame('no_writable_calendar', $result['error']['code']);

	}//end testReadOnlyCalendarIsRefused()

	/**
	 * Calendars are only ever resolved through the acting user's own principal.
	 *
	 * @return void
	 */
	public function testCalendarsResolveOnlyViaTheActingUsersPrincipal(): void {
		$calendars = $this->createMock(ICalendarManager::class);
		$calendars->expects($this->once())
			->method('getCalendarsForPrincipal')
			->with('principals/users/alice')
			->willReturn([]);

		$result = $this->service($calendars)->create('alice', self::VALID);

		$this->assertArrayHasKey('error', $result);

	}//end testCalendarsResolveOnlyViaTheActingUsersPrincipal()

	/**
	 * Attendees are accepted and COUNTED for the audit record; the addresses
	 * themselves are not returned.
	 *
	 * @return void
	 */
	public function testAttendeesAreAcceptedAndOnlyCounted(): void {
		$added = [];
		$calendars = $this->createMock(ICalendarManager::class);
		$calendars->method('getCalendarsForPrincipal')->willReturn([$this->calendar(true)]);
		$calendars->method('createEventBuilder')->willReturn($this->builder($added));

		$result = $this->service($calendars)->create(
			'alice',
			(self::VALID + ['attendees' => ['a@example.org', 'b@example.org', 'not-an-address']])
		);

		$this->assertArrayNotHasKey('error', $result);
		$this->assertSame(2, $result['attendeeCount'], 'The invalid address is dropped; the two valid ones counted.');
		$this->assertSame(['a@example.org', 'b@example.org'], $added);
		$this->assertStringNotContainsString(
			'a@example.org',
			(string)json_encode($result),
			'Attendee addresses must not be returned.'
		);

	}//end testAttendeesAreAcceptedAndOnlyCounted()

	/**
	 * The ADR-088 mark is written into the SAME object that is stored, so the event
	 * cannot exist unmarked even momentarily.
	 *
	 * @return void
	 */
	public function testStoredEventCarriesTheAgentAuthoredProperty(): void {
		$captured = null;
		$added = [];
		$calendars = $this->createMock(ICalendarManager::class);
		$calendars->method('getCalendarsForPrincipal')->willReturn([$this->calendar(true, $captured)]);
		$calendars->method('createEventBuilder')->willReturn($this->builder($added));

		$marker = $this->createMock(AgentArtefactMarker::class);
		$marker->method('objectPropertyValue')->willReturn('hermiq:agent-7');

		$result = $this->service($calendars, $marker)->create('alice', self::VALID, 'agent-7');

		$this->assertArrayNotHasKey('error', $result);
		$this->assertStringContainsString(
			AgentArtefactMarker::OBJECT_PROPERTY . ':hermiq:agent-7',
			(string)$captured
		);
		$this->assertStringContainsString('BEGIN:VEVENT', (string)$captured);

	}//end testStoredEventCarriesTheAgentAuthoredProperty()

	/**
	 * An end before the start is refused rather than silently swapped.
	 *
	 * @return void
	 */
	public function testInvertedDateRangeIsRefused(): void {
		$calendars = $this->createMock(ICalendarManager::class);
		$calendars->expects($this->never())->method('getCalendarsForPrincipal');

		$result = $this->service($calendars)->create(
			'alice',
			['summary' => 'Backwards', 'start' => '2026-09-01T10:00:00Z', 'end' => '2026-09-01T09:00:00Z']
		);

		$this->assertSame('invalid_argument', $result['error']['code']);

	}//end testInvertedDateRangeIsRefused()

	/**
	 * An unparseable date is refused before any calendar is touched.
	 *
	 * @return void
	 */
	public function testUnparseableDateIsRefused(): void {
		$calendars = $this->createMock(ICalendarManager::class);
		$calendars->expects($this->never())->method('getCalendarsForPrincipal');

		$result = $this->service($calendars)->create(
			'alice',
			['summary' => 'Whenever', 'start' => 'soon', 'end' => 'later']
		);

		$this->assertSame('invalid_argument', $result['error']['code']);

	}//end testUnparseableDateIsRefused()

}//end class
