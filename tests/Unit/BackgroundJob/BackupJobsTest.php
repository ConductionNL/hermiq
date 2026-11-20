<?php

/**
 * Unit tests for BackupTask and BackupNowJob.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/operations-backup-and-restore/specs/backup-restore/spec.md#requirement-an-administrator-can-back-up-an-organisations-hermiq-data-to-files-on-a-schedule-req-bkup-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\BackgroundJob;

use DateTime;
use OCA\Hermiq\BackgroundJob\BackupNowJob;
use OCA\Hermiq\BackgroundJob\BackupTask;
use OCA\Hermiq\Service\Backup\BackupSettings;
use OCA\Hermiq\Service\Backup\BackupSettingsStore;
use OCA\Hermiq\Service\Backup\BackupWriter;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\Job;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * The nightly backup runs once, at the chosen hour, when switched on.
 *
 * @covers \OCA\Hermiq\BackgroundJob\BackupTask
 * @covers \OCA\Hermiq\BackgroundJob\BackupNowJob
 * @uses   \OCA\Hermiq\Service\Backup\BackupSettings
 */
final class BackupJobsTest extends TestCase {

	private BackupSettingsStore&MockObject $store;

	private BackupWriter&MockObject $writer;

	/**
	 * Fresh doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = $this->createMock(BackupSettingsStore::class);
		$this->writer = $this->createMock(BackupWriter::class);
	}//end setUp()

	/**
	 * Run a job's run().
	 *
	 * @param Job $job The job.
	 *
	 * @return void
	 */
	private function runJob(Job $job): void {
		(new ReflectionMethod($job, 'run'))->invoke($job, null);
	}//end runJob()

	/**
	 * The nightly task at a given moment.
	 *
	 * @param string               $now    The moment.
	 * @param LoggerInterface|null $logger The logger.
	 *
	 * @return BackupTask
	 */
	private function task(string $now, ?LoggerInterface $logger=null): BackupTask {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime($now));
		return new BackupTask(time: $time, settings: $this->store, writer: $this->writer, logger: $logger ?? $this->createMock(LoggerInterface::class));
	}//end task()

	/**
	 * Switched on, in the chosen hour, not yet run today: it backs up and remembers the day.
	 *
	 * @return void
	 */
	public function testItBacksUpOnceInTheChosenHour(): void {
		$settings = new BackupSettings(enabled: true, hour: 2, ownerUid: 'admin');
		$this->store->method('get')->willReturn($settings);
		$this->store->method('lastRunDate')->willReturn('2026-09-27');
		$this->store->expects($this->once())->method('markRun')->with('2026-09-28');
		$this->writer->expects($this->once())->method('backUp')->with($settings)->willReturn([]);

		$this->runJob($this->task('2026-09-28 02:10'));
	}//end testItBacksUpOnceInTheChosenHour()

	/**
	 * Off, another hour, or already run today: nothing.
	 *
	 * @return array<string, array{0: BackupSettings, 1: string, 2: string}>
	 */
	public static function quiet(): array {
		return [
			'switched off'   => [new BackupSettings(enabled: false, hour: 2, ownerUid: 'admin'), '2026-09-28 02:10', ''],
			'another hour'   => [new BackupSettings(enabled: true, hour: 2, ownerUid: 'admin'), '2026-09-28 03:10', ''],
			'already today'  => [new BackupSettings(enabled: true, hour: 2, ownerUid: 'admin'), '2026-09-28 02:40', '2026-09-28'],
		];
	}//end quiet()

	/**
	 * @dataProvider quiet
	 *
	 * @param BackupSettings $settings The settings.
	 * @param string         $now      The moment.
	 * @param string         $lastRun  The day it last ran.
	 *
	 * @return void
	 */
	public function testOtherwiseItDoesNothing(BackupSettings $settings, string $now, string $lastRun): void {
		$this->store->method('get')->willReturn($settings);
		$this->store->method('lastRunDate')->willReturn($lastRun);
		$this->store->expects($this->never())->method('markRun');
		$this->writer->expects($this->never())->method('backUp');

		$this->runJob($this->task($now));
	}//end testOtherwiseItDoesNothing()

	/**
	 * A failed backup is logged as an error and not retried in the same day.
	 *
	 * @return void
	 */
	public function testAFailedBackupIsLoggedAndNotRetriedToday(): void {
		$this->store->method('get')->willReturn(new BackupSettings(enabled: true, hour: 2, ownerUid: 'admin'));
		$this->store->method('lastRunDate')->willReturn('');
		$this->store->expects($this->once())->method('markRun')->with('2026-09-28');
		$this->writer->method('backUp')->willThrowException(new RuntimeException('disk full'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with($this->stringContains('disk full'));

		$this->runJob($this->task('2026-09-28 02:05', $logger));
	}//end testAFailedBackupIsLoggedAndNotRetriedToday()

	/**
	 * The task wakes every hour and never runs twice at once.
	 *
	 * @return void
	 */
	public function testItWakesHourly(): void {
		$task = $this->task('2026-09-28 02:00');
		$this->assertSame(3600, (new \ReflectionProperty($task, 'interval'))->getValue($task));
	}//end testItWakesHourly()

	/**
	 * "Back up now" backs up with the stored settings, switched on or not.
	 *
	 * @return void
	 */
	public function testBackupNowBacksUpWithTheStoredSettings(): void {
		$settings = new BackupSettings(enabled: false, ownerUid: 'admin');
		$this->store->method('get')->willReturn($settings);
		$this->writer->expects($this->once())->method('backUp')->with($settings)->willReturn(['tilburg' => []]);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with('Hermiq backed up 1 organisation(s) on request');

		$this->runJob(new BackupNowJob(time: $this->createMock(ITimeFactory::class), settings: $this->store, writer: $this->writer, logger: $logger));
	}//end testBackupNowBacksUpWithTheStoredSettings()
}//end class
