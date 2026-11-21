<?php

/**
 * Unit tests for RunDoneNotifier (personal-preferences, task 2.4).
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/personal-preferences/spec.md#requirement-the-voorkeuren-section-offers-the-drawn-preferences-req-pref-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Service\PersonalPreferences;
use OCA\Hermiq\Service\RunDoneNotifier;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Hermiq\Service\RunDoneNotifier
 * @uses   \OCA\Hermiq\Service\PersonalPreferences
 */
class RunDoneNotifierTest extends TestCase {

	/**
	 * What the notification double was given, by setter.
	 *
	 * @var array<string, array<int, mixed>>
	 */
	private array $set = [];

	/**
	 * The notification manager double.
	 *
	 * @var INotificationManager&MockObject
	 */
	private INotificationManager $manager;

	/**
	 * The logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * A notifier for people who switched the preference on (pieter) or not (anouk).
	 *
	 * @return RunDoneNotifier
	 */
	private function notifier(): RunDoneNotifier {
		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturnCallback(
			static fn (string $uid, string $app, string $key, mixed $default): string => ($app === 'hermiq' && $key === 'pref_notify-run-done') ? (['pieter' => '1', 'anouk' => ''][$uid] ?? '') : ''
		);

		$this->set = [];
		$notification = $this->createMock(INotification::class);
		foreach (['setApp', 'setUser', 'setDateTime', 'setObject', 'setSubject', 'setLink'] as $setter) {
			$notification->method($setter)->willReturnCallback(
				function (mixed ...$args) use ($setter, $notification): INotification {
					$this->set[$setter] = $args;
					return $notification;
				}
			);
		}

		$this->manager = $this->createMock(INotificationManager::class);
		$this->manager->method('createNotification')->willReturn($notification);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getAbsoluteURL')->willReturnCallback(static fn (string $url): string => 'https://cloud.example' . $url);
		$this->logger = $this->createMock(LoggerInterface::class);

		return new RunDoneNotifier(
			preferences: new PersonalPreferences(config: $config),
			notificationManager: $this->manager,
			urlGenerator: $urls,
			logger: $this->logger,
		);
	}//end notifier()

	/**
	 * Someone who switched it on is told the agent and that the run succeeded.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/personal-preferences/spec.md#requirement-the-voorkeuren-section-offers-the-drawn-preferences-req-pref-002
	 */
	public function testTheOwnerWhoAskedIsToldTheAgentAndTheResult(): void {
		$notifier = $this->notifier();
		$this->manager->expects($this->once())->method('notify');

		self::assertTrue($notifier->notifyScheduledRun(userId: 'pieter', agentName: 'Hulp bij Woo-besluit', scheduleUuid: 'sched 1', succeeded: true, channel: 'talk'));
		self::assertSame(['hermiq'], $this->set['setApp']);
		self::assertSame(['pieter'], $this->set['setUser']);
		self::assertSame(['schedule', 'sched 1'], $this->set['setObject']);
		self::assertSame([RunDoneNotifier::SUBJECT, ['name' => 'Hulp bij Woo-besluit', 'result' => 'ok']], $this->set['setSubject']);
		self::assertSame(['https://cloud.example/index.php/apps/hermiq/runs?schedule=sched%201'], $this->set['setLink']);
	}//end testTheOwnerWhoAskedIsToldTheAgentAndTheResult()

	/**
	 * A failed run is reported as an error, also when output goes to notifications.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/personal-preferences/spec.md#requirement-the-voorkeuren-section-offers-the-drawn-preferences-req-pref-002
	 */
	public function testAFailedRunIsReportedAsAnError(): void {
		$notifier = $this->notifier();
		$this->manager->expects($this->once())->method('notify');

		self::assertTrue($notifier->notifyScheduledRun(userId: 'pieter', agentName: 'Mail helper', scheduleUuid: 's2', succeeded: false, channel: 'notification'));
		self::assertSame([RunDoneNotifier::SUBJECT, ['name' => 'Mail helper', 'result' => 'error']], $this->set['setSubject']);
	}//end testAFailedRunIsReportedAsAnError()

	/**
	 * Nobody who left it off is notified, nor a person with no account name.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/personal-preferences/spec.md#requirement-the-voorkeuren-section-offers-the-drawn-preferences-req-pref-002
	 */
	public function testNobodyWhoLeftItOffIsNotified(): void {
		$notifier = $this->notifier();
		$this->manager->expects($this->never())->method('notify');

		self::assertFalse($notifier->notifyScheduledRun(userId: 'anouk', agentName: 'A', scheduleUuid: 's', succeeded: true, channel: 'none'));
		self::assertFalse($notifier->notifyScheduledRun(userId: '', agentName: 'A', scheduleUuid: 's', succeeded: true, channel: 'none'));
	}//end testNobodyWhoLeftItOffIsNotified()

	/**
	 * A successful run already delivered as a notification is not announced twice.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/personal-preferences/spec.md#requirement-the-voorkeuren-section-offers-the-drawn-preferences-req-pref-002
	 */
	public function testASuccessDeliveredAsANotificationIsNotAnnouncedTwice(): void {
		$notifier = $this->notifier();
		$this->manager->expects($this->never())->method('notify');

		self::assertFalse($notifier->notifyScheduledRun(userId: 'pieter', agentName: 'A', scheduleUuid: 's', succeeded: true, channel: 'notification'));
	}//end testASuccessDeliveredAsANotificationIsNotAnnouncedTwice()

	/**
	 * A notification that cannot be sent is logged, never thrown.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/personal-preferences/spec.md#requirement-the-voorkeuren-section-offers-the-drawn-preferences-req-pref-002
	 */
	public function testAFailedNotificationIsLoggedNotThrown(): void {
		$notifier = $this->notifier();
		$this->manager->method('notify')->willThrowException(new RuntimeException('notifications app off'));
		$this->logger->expects($this->once())->method('warning')->with($this->stringContains('notifications app off'));

		self::assertFalse($notifier->notifyScheduledRun(userId: 'pieter', agentName: 'A', scheduleUuid: 's', succeeded: true, channel: 'none'));
	}//end testAFailedNotificationIsLoggedNotThrown()
}//end class
