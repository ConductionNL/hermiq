<?php

/**
 * Hermiq ReportSubscriptionSchedule.
 *
 * When a report subscription is due, and which period the report it sends
 * covers. Pure: no clock of its own, no storage.
 *
 * @category Service
 * @package  OCA\Hermiq\Service
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

namespace OCA\Hermiq\Service;

use DateInterval;
use DateTimeImmutable;

/**
 * The due rule and the covered period of a weekly or monthly report subscription.
 *
 * @spec openspec/changes/reports-on-a-schedule/specs/reports/spec.md#requirement-a-person-shall-receive-a-report-on-a-schedule-they-choose
 */
final class ReportSubscriptionSchedule {

	public const WEEKLY = 'weekly';

	public const MONTHLY = 'monthly';

	public const ACTIVE = 'active';

	public const CANCELLED = 'cancelled';

	/**
	 * Whether a subscription sends its report now.
	 *
	 * An active subscription that never sent is due at once. After that a
	 * weekly one is due seven days after the last report, a monthly one a
	 * calendar month after it. A cancelled subscription, or one with a
	 * frequency this class does not know, is never due.
	 *
	 * @param string                 $status    `active` or `cancelled`.
	 * @param string                 $frequency `weekly` or `monthly`.
	 * @param DateTimeImmutable|null $lastSent  When the last report went out, if ever.
	 * @param DateTimeImmutable      $now       The moment asked about.
	 *
	 * @return bool True when the report goes out now.
	 *
	 * @spec openspec/changes/reports-on-a-schedule/specs/reports/spec.md#requirement-a-person-shall-receive-a-report-on-a-schedule-they-choose
	 */
	public function isDue(string $status, string $frequency, ?DateTimeImmutable $lastSent, DateTimeImmutable $now): bool {
		$interval = $this->interval(frequency: $frequency);
		if ($status !== self::ACTIVE || $interval === null) {
			return false;
		}

		if ($lastSent === null) {
			return true;
		}

		return $now >= $lastSent->add($interval);
	}//end isDue()

	/**
	 * The period a report sent now covers: the week or month that ends now.
	 *
	 * @param string            $frequency `weekly` or `monthly`.
	 * @param DateTimeImmutable $now       The moment the report is sent.
	 *
	 * @return array{from: DateTimeImmutable, to: DateTimeImmutable}|null The period, or null for an unknown frequency.
	 *
	 * @spec openspec/changes/reports-on-a-schedule/specs/reports/spec.md#requirement-a-person-shall-receive-a-report-on-a-schedule-they-choose
	 */
	public function period(string $frequency, DateTimeImmutable $now): ?array {
		$interval = $this->interval(frequency: $frequency);
		if ($interval === null) {
			return null;
		}

		return ['from' => $now->sub($interval), 'to' => $now];
	}//end period()

	/**
	 * The length of one period.
	 *
	 * @param string $frequency `weekly` or `monthly`.
	 *
	 * @return DateInterval|null The interval, or null for an unknown frequency.
	 */
	private function interval(string $frequency): ?DateInterval {
		return match ($frequency) {
			self::WEEKLY => new DateInterval('P7D'),
			self::MONTHLY => new DateInterval('P1M'),
			default => null,
		};
	}//end interval()
}//end class
