<?php

/**
 * Unit tests for the hermiq.listCalendarEvents tool.
 *
 * The calendar double answers in the exact shape Nextcloud's CalDAV search
 * produces. `CalDavBackend::transformSearchData()` (nextcloud/server v34,
 * apps/dav/lib/CalDAV/CalDavBackend.php:2441-2475) stores a property the VEVENT
 * allows once (sabre/vobject VEvent::getValidationRules(), `'SUMMARY' => '?'`)
 * as ONE `[value, parameters]` pair, and a property it allows many times as a
 * list of such pairs. So `SUMMARY[0]` is the summary itself and `SUMMARY[0][0]`
 * is its first character.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\Hermiq\Tests\Unit\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Mcp;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Hermiq\Mcp\HermiqToolProvider;
use OCA\Hermiq\Service\CourseRecommendationEngine;
use OCA\Hermiq\Service\DelegationService;
use OCA\Hermiq\Service\MemoryService;
use OCA\Hermiq\Service\NcNative\MailReadService;
use OCA\Hermiq\Service\NcNative\NcNativeWriteService;
use OCA\Hermiq\Service\ToolAccessRequestService;
use OCA\Hermiq\Service\WebResearch\WebFetchService;
use OCA\Hermiq\Service\WebResearch\WebSearchClient;
use OCP\App\IAppManager;
use OCP\Calendar\ICalendar;
use OCP\Calendar\IManager as ICalendarManager;
use OCP\Contacts\IManager as IContactsManager;
use OCP\Files\IRootFolder;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Mail\IMailer;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * An agent listing your calendar gets each event's whole title, its start, and only the window it asked for.
 */
final class HermiqToolProviderCalendarTest extends TestCase {

	/**
	 * The options the calendar was searched with.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $searchOptions = null;

	public function testEachEventCarriesItsWholeSummary(): void {
		$result = $this->provider()->invokeTool('hermiq.listCalendarEvents', ['days' => 7]);

		self::assertSame(['Team meeting', 'Dentist'], array_column($result['events'], 'summary'));
	}//end testEachEventCarriesItsWholeSummary()

	public function testEachEventCarriesItsStart(): void {
		$result = $this->provider()->invokeTool('hermiq.listCalendarEvents', ['days' => 7]);

		self::assertSame('2026-10-02T09:30:00+02:00', $result['events'][0]['start']);
		self::assertSame('', $result['events'][1]['start'], 'an event without a start says so instead of failing');
	}//end testEachEventCarriesItsStart()

	public function testTheCalendarIsSearchedForTheAskedWindowOnly(): void {
		$before = new DateTimeImmutable();
		$this->provider()->invokeTool('hermiq.listCalendarEvents', ['days' => 3]);

		$range = ($this->searchOptions['timerange'] ?? null);
		self::assertIsArray($range, 'the look-ahead window must reach the calendar search');
		self::assertInstanceOf(DateTimeInterface::class, $range['start']);
		self::assertInstanceOf(DateTimeInterface::class, $range['end']);
		self::assertGreaterThanOrEqual($before->getTimestamp(), $range['start']->getTimestamp());
		self::assertSame(3 * 86400, $range['end']->getTimestamp() - $range['start']->getTimestamp());
	}//end testTheCalendarIsSearchedForTheAskedWindowOnly()

	public function testTheWindowIsCappedAtNinetyDays(): void {
		$result = $this->provider()->invokeTool('hermiq.listCalendarEvents', ['days' => 400]);

		self::assertSame(90, $result['windowDays']);
		$range = $this->searchOptions['timerange'];
		self::assertSame(90 * 86400, $range['end']->getTimestamp() - $range['start']->getTimestamp());
	}//end testTheWindowIsCappedAtNinetyDays()

	/**
	 * The provider with one calendar answering in CalDAV's search shape.
	 *
	 * @return HermiqToolProvider
	 */
	private function provider(): HermiqToolProvider {
		$calendar = $this->createMock(ICalendar::class);
		$calendar->method('getDisplayName')->willReturn('Personal');
		$calendar->method('search')->willReturnCallback(
			function (string $pattern, array $properties = [], array $options = [], ?int $limit = null): array {
				$this->searchOptions = $options;
				return [
					[
						'id' => 1,
						'type' => 'VEVENT',
						'uid' => 'a',
						'objects' => [
							[
								// Once-only properties: one [value, parameters] pair.
								'SUMMARY' => ['Team meeting', []],
								'DTSTART' => [new DateTimeImmutable('2026-10-02T09:30:00+02:00'), ['TZID' => 'Europe/Amsterdam']],
								'UID' => ['a', []],
								// A many-times property: a list of pairs.
								'ATTENDEE' => [['mailto:a@example.com', []], ['mailto:b@example.com', []]],
							],
						],
					],
					[
						'id' => 2,
						'type' => 'VEVENT',
						'uid' => 'b',
						'objects' => [
							['SUMMARY' => ['Dentist', []], 'UID' => ['b', []]],
						],
					],
				];
			}
		);

		$calendars = $this->createMock(ICalendarManager::class);
		$calendars->method('getCalendarsForPrincipal')->with('principals/users/alice')->willReturn([$calendar]);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new HermiqToolProvider(
			$session,
			$this->createMock(IRootFolder::class),
			$this->createMock(IContactsManager::class),
			$calendars,
			$this->createMock(IMailer::class),
			$this->createMock(IAppManager::class),
			$this->createMock(ContainerInterface::class),
			$this->createMock(CourseRecommendationEngine::class),
			$this->createMock(MemoryService::class),
			$this->createMock(WebSearchClient::class),
			$this->createMock(WebFetchService::class),
			$this->createMock(DelegationService::class),
			$this->createMock(NcNativeWriteService::class),
			$this->createMock(MailReadService::class),
			$this->createMock(ToolAccessRequestService::class),
			$this->createMock(LoggerInterface::class)
		);
	}//end provider()
}//end class
