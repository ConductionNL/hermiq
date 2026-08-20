<?php

/**
 * Hermiq Calendar Write Service (nc-native-write-tools).
 *
 * Creates events in calendars the acting user owns and can write to.
 *
 * Attendees ARE supported, and that is the reason the tool reaching this service
 * is declared `reach: external` with `destructiveHint: true`. Nextcloud dispatches
 * iMIP invitations for attendees, so the effect lands in a third party's inbox and
 * cannot be recalled — the same shape as `sendMail`. The attendee COUNT is
 * returned for the audit record; the addresses are not.
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
 * @spec openspec/specs/nc-native-tools/spec.md#requirement-per-object-idor-guard-on-every-provider
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\NcNative;

use DateTimeImmutable;
use OCP\Calendar\ICalendarIsWritable;
use OCP\Calendar\ICreateFromString;
use OCP\Calendar\IManager as ICalendarManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Event creation scoped to the acting user's own writable calendars.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\NcNative
 *
 * @spec openspec/specs/nc-native-tools/spec.md#requirement-per-object-idor-guard-on-every-provider
 */
class CalendarWriteService {

	use ErrorEnvelopeTrait;

	/**
	 * Constructor.
	 *
	 * @param ICalendarManager $calendarManager Calendar access for the acting user's principal.
	 * @param AgentArtefactMarker $marker ADR-088 marking.
	 * @param LoggerInterface $logger Diagnostics.
	 */
	public function __construct(
		private readonly ICalendarManager $calendarManager,
		private readonly AgentArtefactMarker $marker,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Create an event in a calendar the acting user owns and can write to.
	 *
	 * @param string $uid The acting user id.
	 * @param array<string, mixed> $arguments summary, start, end, description, location, calendarUri, attendees.
	 * @param string $agentId The invoking agent id, for the ADR-088 mark.
	 *
	 * @return array<string, mixed> The result, or an error envelope.
	 *
	 * @spec openspec/specs/nc-native-tools/spec.md#requirement-per-object-idor-guard-on-every-provider
	 */
	public function create(string $uid, array $arguments, string $agentId = ''): array {
		$invalid = $this->validate(arguments: $arguments);
		if ($invalid !== null) {
			return $invalid;
		}

		$calendar = $this->resolveWritableCalendar(
			uid: $uid,
			calendarUri: trim((string)($arguments['calendarUri'] ?? ''))
		);
		if ($calendar === null) {
			return $this->err(
				code: 'no_writable_calendar',
				message: 'No writable calendar owned by you matched. Subscriptions and read-only shares cannot be written to.'
			);
		}

		$attendees = $this->normaliseAttendees(raw: ($arguments['attendees'] ?? []));

		return $this->store(
			calendar: $calendar,
			arguments: $arguments,
			attendees: $attendees,
			agentId: $agentId
		);

	}//end create()

	/**
	 * Validate the caller's arguments, returning an error envelope or null.
	 *
	 * @param array<string, mixed> $arguments The tool arguments.
	 *
	 * @return array<string, mixed>|null The error envelope, or null when valid.
	 */
	private function validate(array $arguments): ?array {
		if (trim((string)($arguments['summary'] ?? '')) === '') {
			return $this->err(code: 'invalid_argument', message: 'A summary is required.');
		}

		$start = $this->parseDate(value: (string)($arguments['start'] ?? ''));
		$end = $this->parseDate(value: (string)($arguments['end'] ?? ''));
		if ($start === null || $end === null) {
			return $this->err(code: 'invalid_argument', message: 'start and end must be ISO-8601 date-times.');
		}

		if ($end <= $start) {
			return $this->err(code: 'invalid_argument', message: 'end must be after start.');
		}

		return null;

	}//end validate()

	/**
	 * Build and store the event.
	 *
	 * @param ICreateFromString $calendar The resolved target calendar.
	 * @param array<string, mixed> $arguments The tool arguments.
	 * @param array<int, string> $attendees The normalised attendee addresses.
	 * @param string $agentId The invoking agent id.
	 *
	 * @return array<string, mixed> The result, or an error envelope.
	 */
	private function store(ICreateFromString $calendar, array $arguments, array $attendees, string $agentId): array {
		$objectUri = '';

		try {
			$builder = $this->calendarManager->createEventBuilder();
			$builder->setSummary(trim((string)($arguments['summary'] ?? '')));
			$builder->setStartDate(new DateTimeImmutable((string)($arguments['start'] ?? '')));
			$builder->setEndDate(new DateTimeImmutable((string)($arguments['end'] ?? '')));

			$description = trim((string)($arguments['description'] ?? ''));
			if ($description !== '') {
				$builder->setDescription($description);
			}

			$location = trim((string)($arguments['location'] ?? ''));
			if ($location !== '') {
				$builder->setLocation($location);
			}

			foreach ($attendees as $attendee) {
				$builder->addAttendee($attendee);
			}

			// ADR-088: the mark goes into the SAME serialised object that is
			// stored, so the event cannot exist unmarked even momentarily. System
			// tags do not apply to CalDAV objects, hence an `X-` property — which
			// also travels with the event through sync and export.
			$ics = $this->withAgentProperty(ics: $builder->toIcs(), agentId: $agentId);

			$objectUri = ('hermiq-' . bin2hex(random_bytes(8)) . '.ics');
			$calendar->createFromString($objectUri, $ics);
		} catch (Throwable $e) {
			$this->logger->warning('Hermiq: calendar event creation failed', ['exception' => $e]);

			return $this->err(code: 'calendar_write_failed', message: 'The event could not be created.');
		}//end try

		return [
			'created' => true,
			'summary' => trim((string)($arguments['summary'] ?? '')),
			'calendar' => $calendar->getDisplayName(),
			'attendeeCount' => count($attendees),
			'artefact' => ['type' => 'calendar-event', 'id' => $objectUri],
		];

	}//end store()

	/**
	 * Resolve a calendar the acting user owns AND can write to.
	 *
	 * `ICalendarIsWritable` is checked explicitly: a subscription or a read-only
	 * share appears in the principal's calendar list and would otherwise be
	 * selected silently, failing at write time with an opaque error.
	 *
	 * @param string $uid The acting user id.
	 * @param string $calendarUri Optional specific calendar uri.
	 *
	 * @return ICreateFromString|null The calendar, or null when none qualifies.
	 */
	private function resolveWritableCalendar(string $uid, string $calendarUri): ?ICreateFromString {
		$calendars = $this->calendarManager->getCalendarsForPrincipal('principals/users/' . $uid);

		foreach ($calendars as $calendar) {
			if (($calendar instanceof ICreateFromString) === false) {
				continue;
			}

			if (($calendar instanceof ICalendarIsWritable) === true && $calendar->isWritable() === false) {
				continue;
			}

			if ($calendarUri !== '' && $calendar->getUri() !== $calendarUri) {
				continue;
			}

			return $calendar;
		}

		return null;

	}//end resolveWritableCalendar()

	/**
	 * Inject the ADR-088 `X-` property into the serialised VEVENT.
	 *
	 * Operates on ICS this service just generated — never on caller-supplied
	 * data — so a naive line insert is safe here in a way it would not be against
	 * arbitrary input.
	 *
	 * @param string $ics The serialised calendar object.
	 * @param string $agentId The invoking agent id.
	 *
	 * @return string The marked calendar object.
	 */
	private function withAgentProperty(string $ics, string $agentId): string {
		$anchor = 'BEGIN:VEVENT';
		$line = (AgentArtefactMarker::OBJECT_PROPERTY . ':' . $this->marker->objectPropertyValue(agentId: $agentId));

		$position = strpos($ics, $anchor);
		if ($position === false) {
			return $ics;
		}

		$insertAt = ($position + strlen($anchor));

		return substr($ics, 0, $insertAt) . "\r\n" . $line . substr($ics, $insertAt);

	}//end withAgentProperty()

	/**
	 * Normalise the attendee argument into a list of valid-looking addresses.
	 *
	 * @param mixed $raw The raw attendees argument.
	 *
	 * @return array<int, string> The attendee addresses.
	 */
	private function normaliseAttendees(mixed $raw): array {
		if (is_array($raw) === false) {
			return [];
		}

		$attendees = [];
		foreach ($raw as $entry) {
			$address = trim((string)$entry);
			if ($address === '' || filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
				continue;
			}

			$attendees[] = $address;
		}

		return array_values(array_unique($attendees));

	}//end normaliseAttendees()

	/**
	 * Parse an ISO-8601 date-time argument.
	 *
	 * @param string $value The raw value.
	 *
	 * @return DateTimeImmutable|null The parsed date, or null when unparseable.
	 */
	private function parseDate(string $value): ?DateTimeImmutable {
		if (trim($value) === '') {
			return null;
		}

		try {
			return new DateTimeImmutable($value);
		} catch (Throwable) {
			return null;
		}

	}//end parseDate()

}//end class
