<?php

/**
 * Unit tests for the Notifier's goal subjects.
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
 * The goal notifications read "Goal reached: <statement>" and "Turn limit used: <statement>".
 *
 * @spec openspec/changes/agents-standing-goal/specs/agent-schedule/spec.md#requirement-goal-turns-continue-the-same-session-through-the-scheduled-run-gates-req-aggoal-002
 */
class NotifierGoalTest extends TestCase {

	/**
	 * The parsed subject of a goal notification.
	 *
	 * @param string $subject goal_reached or goal_exhausted.
	 *
	 * @return string
	 */
	private function parsed(string $subject): string {
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('imagePath')->willReturn('/img');
		$urls->method('getAbsoluteURL')->willReturn('https://x/img');

		$parsed = '';
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn('hermiq');
		$notification->method('getSubject')->willReturn($subject);
		$notification->method('getSubjectParameters')->willReturn(['name' => 'Every overdue permit application has had a reminder']);
		$notification->method('setParsedSubject')->willReturnCallback(
			function (string $text) use (&$parsed, $notification): INotification {
				$parsed = $text;
				return $notification;
			}
		);
		$notification->method('setParsedMessage')->willReturnSelf();
		$notification->method('setIcon')->willReturnSelf();

		(new Notifier($factory, $urls))->prepare($notification, 'en');

		return $parsed;
	}//end parsed()

	/**
	 * Both goal endings have their own subject.
	 *
	 * @return void
	 */
	public function testGoalNotificationsNameTheGoal(): void {
		$this->assertSame('Goal reached: Every overdue permit application has had a reminder', $this->parsed('goal_reached'));
		$this->assertSame('Turn limit used: Every overdue permit application has had a reminder', $this->parsed('goal_exhausted'));
	}//end testGoalNotificationsNameTheGoal()
}//end class
