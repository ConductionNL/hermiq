<?php

/**
 * Unit tests for ReportSubscriptionSchedule: when a report subscription is due, and its period.
 *
 * @category Tests
 * @package  OCA\Hermiq\Tests\Unit\Service
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/reports-on-a-schedule/specs/reports/spec.md#requirement-a-person-shall-receive-a-report-on-a-schedule-they-choose
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Hermiq\Service\ReportSubscriptionSchedule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Weekly is due seven days after the last report, monthly a calendar month after it; cancelled never.
 */
class ReportSubscriptionScheduleTest extends TestCase {

	/**
	 * Subscriptions and whether they are due at 2026-11-13 09:00 UTC.
	 *
	 * @return array<string, array{0: string, 1: string, 2: ?string, 3: bool}>
	 */
	public static function cases(): array {
		return [
			'weekly, never sent'               => ['active', 'weekly', null, true],
			'weekly, exactly a week ago'       => ['active', 'weekly', '2026-11-06T09:00:00+00:00', true],
			'weekly, a minute short of a week' => ['active', 'weekly', '2026-11-06T09:01:00+00:00', false],
			'monthly, a month ago'             => ['active', 'monthly', '2026-10-13T09:00:00+00:00', true],
			'monthly, three weeks ago'         => ['active', 'monthly', '2026-10-23T09:00:00+00:00', false],
			'cancelled, never sent'            => ['cancelled', 'weekly', null, false],
			'cancelled, long overdue'          => ['cancelled', 'monthly', '2026-01-01T00:00:00+00:00', false],
			'unknown frequency'                => ['active', 'daily', null, false],
		];
	}//end cases()

	/**
	 * Each subscription is due exactly when its rule says.
	 *
	 * @param string      $status    The status.
	 * @param string      $frequency The frequency.
	 * @param string|null $lastSent  When it was last sent.
	 * @param bool        $due       Whether it is due.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testTheDueRule(string $status, string $frequency, ?string $lastSent, bool $due): void {
		$last = null;
		if ($lastSent !== null) {
			$last = new DateTimeImmutable($lastSent);
		}

		$this->assertSame($due, (new ReportSubscriptionSchedule())->isDue($status, $frequency, $last, new DateTimeImmutable('2026-11-13T09:00:00+00:00')));
	}//end testTheDueRule()

	/**
	 * The report covers the week or month that ends at the send.
	 *
	 * @return void
	 */
	public function testThePeriodEndsAtTheSend(): void {
		$schedule = new ReportSubscriptionSchedule();
		$now      = new DateTimeImmutable('2026-11-13T09:00:00+00:00');

		$week = $schedule->period('weekly', $now);
		$this->assertSame('2026-11-06T09:00:00+00:00', $week['from']->format(DATE_ATOM));
		$this->assertSame('2026-11-13T09:00:00+00:00', $week['to']->format(DATE_ATOM));
		$this->assertSame('2026-10-13', $schedule->period('monthly', $now)['from']->format('Y-m-d'));
		$this->assertNull($schedule->period('daily', $now));
	}//end testThePeriodEndsAtTheSend()
}//end class
