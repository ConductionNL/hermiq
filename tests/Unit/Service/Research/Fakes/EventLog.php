<?php

/**
 * What happened during a research run, in order (tools-deep-research-report).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Research\Fakes
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-a-user-can-start-deep-research-and-sees-the-plan-req-research-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Research\Fakes;

/**
 * An ordered log of model calls, messages, searches and fetches.
 */
class EventLog {

	/**
	 * The events, in order.
	 *
	 * @var list<string>
	 */
	public array $events = [];

	/**
	 * Note one event.
	 *
	 * @param string $event The event.
	 *
	 * @return void
	 */
	public function note(string $event): void {
		$this->events[] = $event;
	}//end note()

	/**
	 * The events that start with this prefix.
	 *
	 * @param string $prefix The prefix, e.g. `fetch:`.
	 *
	 * @return list<string>
	 */
	public function starting(string $prefix): array {
		return array_values(array_filter($this->events, static fn (string $event): bool => str_starts_with($event, $prefix)));
	}//end starting()
}//end class
