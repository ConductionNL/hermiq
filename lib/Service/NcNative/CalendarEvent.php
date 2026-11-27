<?php

/**
 * Hermiq CalendarEvent.
 *
 * One event being created: the calendar it goes to, the tool's fields, its attendees and the agent that authors it.
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

use OCP\Calendar\ICreateFromString;

/**
 * One event creation in progress; it never leaves CalendarWriteService.
 *
 * @spec openspec/specs/nc-native-tools/spec.md#requirement-per-object-idor-guard-on-every-provider
 */
final class CalendarEvent {

	/**
	 * The normalised attendee addresses.
	 *
	 * @var array<int, string>
	 */
	public array $attendees = [];

	/**
	 * The invoking agent id, for the ADR-088 mark.
	 *
	 * @var string
	 */
	public string $agentId = '';

	/**
	 * Start the creation.
	 *
	 * @param ICreateFromString    $calendar  The resolved target calendar.
	 * @param array<string, mixed> $arguments The tool arguments.
	 */
	public function __construct(
		public readonly ICreateFromString $calendar,
		public readonly array $arguments,
	) {
	}//end __construct()
}//end class
