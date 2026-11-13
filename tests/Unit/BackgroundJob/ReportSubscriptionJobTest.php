<?php

/**
 * Unit tests for ReportSubscriptionJob: a due subscription sends its report and records it; others send nothing.
 *
 * @category Tests
 * @package  OCA\Hermiq\Tests\Unit\BackgroundJob
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

namespace OCA\Hermiq\Tests\Unit\BackgroundJob;

use DateTimeImmutable;
use OCA\Hermiq\BackgroundJob\ReportSubscriptionJob;
use OCA\Hermiq\Service\ReportSubscriptionSchedule;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IURLGenerator;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Sending, skipping and recording, over a recorded notification manager and object service.
 */
class ReportSubscriptionJobTest extends TestCase {

	/**
	 * The notifications sent, as their recorded setter calls.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $sent = [];

	/**
	 * The objects saved, as [data, uuid].
	 *
	 * @var array<int, array{0: array, 1: string}>
	 */
	private array $saved = [];

	/**
	 * The setter calls of every notification created, by object id.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $recorded = [];

	/**
	 * A job over recording doubles.
	 *
	 * @return ReportSubscriptionJob
	 */
	private function job(): ReportSubscriptionJob {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], mixed $register = null, mixed $schema = null, ?string $uuid = null): ObjectEntity {
				$this->saved[] = [$object, (string)$uuid];
				return new ObjectEntity();
			}
		);

		$manager = $this->createMock(IManager::class);
		$manager->method('createNotification')->willReturnCallback(fn (): INotification => $this->notification());
		$manager->method('notify')->willReturnCallback(
			function (INotification $notification): void {
				$this->sent[] = $this->recorded[spl_object_id($notification)];
			}
		);

		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->with('hermiq.dashboard.page')->willReturn('https://cloud.example/apps/hermiq/');

		return new ReportSubscriptionJob(
			$this->createMock(ITimeFactory::class),
			$objects,
			$manager,
			$urls,
			$this->createMock(LoggerInterface::class),
			new ReportSubscriptionSchedule()
		);
	}//end job()

	/**
	 * A notification double that records every setter.
	 *
	 * @return INotification
	 */
	private function notification(): INotification {
		$notification = $this->createMock(INotification::class);
		$id = spl_object_id($notification);
		$this->recorded[$id] = [];
		foreach (['setApp', 'setUser', 'setDateTime', 'setObject', 'setSubject', 'setLink'] as $setter) {
			$notification->method($setter)->willReturnCallback(
				function (mixed ...$args) use ($notification, $setter, $id): INotification {
					$this->recorded[$id][$setter] = $args;
					return $notification;
				}
			);
		}

		return $notification;
	}//end notification()

	/**
	 * A stored subscription.
	 *
	 * @param array<string, mixed> $data  Its fields.
	 * @param string               $owner Who made it.
	 *
	 * @return ObjectEntity
	 */
	private static function subscription(array $data, string $owner = 'fenna'): ObjectEntity {
		$subscription = new ObjectEntity();
		$subscription->setUuid('sub-1');
		$subscription->setOwner($owner);
		$subscription->setObject($data + ['person' => 'fenna', 'report' => 'AiOversight', 'frequency' => 'weekly', 'status' => 'active']);
		return $subscription;
	}//end subscription()

	/**
	 * A weekly subscription a week after its last report sends it, linked to that week, and records the send.
	 *
	 * @return void
	 */
	public function testAWeeklySubscriptionSendsTheReportForThatWeek(): void {
		$now = new DateTimeImmutable('2026-11-13T09:00:00+00:00');

		$this->assertTrue($this->job()->sendIfDue(self::subscription(['lastSent' => '2026-11-06T09:00:00+00:00']), $now));

		$this->assertCount(1, $this->sent);
		$this->assertSame(['hermiq'], $this->sent[0]['setApp']);
		$this->assertSame(['fenna'], $this->sent[0]['setUser']);
		$this->assertSame(['reportsubscription', 'sub-1'], $this->sent[0]['setObject']);
		$this->assertSame(['report_due', ['report' => 'AiOversight', 'from' => '2026-11-06', 'to' => '2026-11-13']], $this->sent[0]['setSubject']);
		$this->assertSame(['https://cloud.example/apps/hermiq/ai-oversight?from=2026-11-06&to=2026-11-13'], $this->sent[0]['setLink']);

		$this->assertCount(1, $this->saved);
		$this->assertSame('2026-11-13T09:00:00+00:00', $this->saved[0][0]['lastSent']);
		$this->assertSame('sub-1', $this->saved[0][1]);
	}//end testAWeeklySubscriptionSendsTheReportForThatWeek()

	/**
	 * A cancelled subscription sends nothing and records nothing.
	 *
	 * @return void
	 */
	public function testACancelledSubscriptionSendsNothing(): void {
		$now = new DateTimeImmutable('2026-11-13T09:00:00+00:00');

		$this->assertFalse($this->job()->sendIfDue(self::subscription(['status' => 'cancelled', 'lastSent' => '2026-01-01T00:00:00+00:00']), $now));

		$this->assertSame([], $this->sent);
		$this->assertSame([], $this->saved);
	}//end testACancelledSubscriptionSendsNothing()

	/**
	 * A subscription that is not yet due waits.
	 *
	 * @return void
	 */
	public function testASubscriptionThatIsNotDueWaits(): void {
		$this->assertFalse(
			$this->job()->sendIfDue(self::subscription(['lastSent' => '2026-11-10T09:00:00+00:00']), new DateTimeImmutable('2026-11-13T09:00:00+00:00'))
		);
		$this->assertSame([], $this->sent);
	}//end testASubscriptionThatIsNotDueWaits()

	/**
	 * A subscription made for somebody else, or for an unknown report, sends nothing.
	 *
	 * @return void
	 */
	public function testNobodyIsSubscribedByAnotherPersonOrToAnUnknownReport(): void {
		$now = new DateTimeImmutable('2026-11-13T09:00:00+00:00');
		$job = $this->job();

		$this->assertFalse($job->sendIfDue(self::subscription([], 'mallory'), $now));
		$this->assertFalse($job->sendIfDue(self::subscription(['report' => 'Payroll']), $now));
		$this->assertSame([], $this->sent);
		$this->assertSame([], $this->saved);
	}//end testNobodyIsSubscribedByAnotherPersonOrToAnUnknownReport()
}//end class
