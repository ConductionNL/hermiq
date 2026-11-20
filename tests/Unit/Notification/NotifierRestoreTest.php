<?php

/**
 * Unit tests for the Notifier's restore subject.
 *
 * @category Tests
 * @package  OCA\Hermiq\Tests\Unit\Notification
 *
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
 * A finished restore says whether the agents run again, and names the failures.
 *
 * @covers \OCA\Hermiq\Notification\Notifier
 *
 * @spec openspec/changes/operations-backup-and-restore/specs/backup-restore/spec.md#requirement-a-restore-stops-the-organisations-agents-and-writes-through-the-audit-trail-req-bkup-003
 */
class NotifierRestoreTest extends TestCase {

	/**
	 * The parsed subject and message of a restore notification.
	 *
	 * @param array<string, mixed> $parameters The subject parameters.
	 *
	 * @return array{0: string, 1: string}
	 */
	private function parsed(array $parameters): array {
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $text, array $params=[]): string => vsprintf($text, $params));
		$l->method('n')->willReturnCallback(
			static fn (string $singular, string $plural, int $count, array $params=[]): string => vsprintf(str_replace('%n', (string)$count, ($count === 1 ? $singular : $plural)), $params)
		);
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('imagePath')->willReturn('/img');
		$urls->method('getAbsoluteURL')->willReturn('https://x/img');

		$subject = '';
		$message = '';
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn('hermiq');
		$notification->method('getSubject')->willReturn('restore_finished');
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
	 * A clean restore and one with two failures.
	 *
	 * @return void
	 */
	public function testTheRestoreNotificationSaysHowItWent(): void {
		$this->assertSame(
			['Restore of tilburg completed', 'Every object was written back and the agents run again.'],
			$this->parsed(['organisation' => 'tilburg', 'failures' => 0, 'failed' => []])
		);

		[$subject, $message] = $this->parsed(['organisation' => 'tilburg', 'failures' => 2, 'failed' => ['agent a-1: invalid', 'agent a-3: invalid']]);
		$this->assertSame('Restore of tilburg completed with 2 failures', $subject);
		$this->assertStringContainsString('agent a-1: invalid; agent a-3: invalid', $message);
		$this->assertStringContainsString('stay stopped', $message);
	}//end testTheRestoreNotificationSaysHowItWent()
}//end class
