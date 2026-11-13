<?php

/**
 * Unit tests for the Notifier's report subject.
 *
 * @category Tests
 * @package  OCA\Hermiq\Tests\Unit\Notification
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

namespace OCA\Hermiq\Tests\Unit\Notification;

use OCA\Hermiq\Notification\Notifier;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;

/**
 * A due report reads "Your report is ready: AI oversight" and names its period.
 *
 * @spec openspec/changes/reports-on-a-schedule/specs/reports/spec.md#requirement-a-person-shall-receive-a-report-on-a-schedule-they-choose
 */
class NotifierReportTest extends TestCase {

	/**
	 * The parsed subject and message of a report notification.
	 *
	 * @param array<string, string> $parameters The stored subject parameters.
	 *
	 * @return array{0: string, 1: string}
	 */
	private function parsed(array $parameters): array {
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('imagePath')->willReturn('/img');
		$urls->method('getAbsoluteURL')->willReturn('https://x/img');

		$subject = '';
		$message = '';
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn('hermiq');
		$notification->method('getSubject')->willReturn('report_due');
		$notification->method('getSubjectParameters')->willReturn($parameters);
		$notification->method('setParsedSubject')->willReturnCallback(
			function (string $text) use (&$subject, $notification): INotification {
				$subject = $text;
				return $notification;
			}
		);
		$notification->method('setParsedMessage')->willReturnCallback(
			function (string $text) use (&$message, $notification): INotification {
				$message = $text;
				return $notification;
			}
		);
		$notification->method('setIcon')->willReturnSelf();

		(new Notifier($factory, $urls))->prepare($notification, 'en');

		return [$subject, $message];
	}//end parsed()

	/**
	 * The report is named and its period is stated.
	 *
	 * @return void
	 */
	public function testTheReportIsNamedWithItsPeriod(): void {
		[$subject, $message] = $this->parsed(['report' => 'AiOversight', 'from' => '2026-11-06', 'to' => '2026-11-13']);

		$this->assertSame('Your report is ready: AI oversight', $subject);
		$this->assertSame('It covers 2026-11-06 to 2026-11-13. Open it to read what happened in that period.', $message);
	}//end testTheReportIsNamedWithItsPeriod()
}//end class
