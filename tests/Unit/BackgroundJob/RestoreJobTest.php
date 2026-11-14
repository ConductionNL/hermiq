<?php

/**
 * Unit tests for RestoreJob.
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
 * @spec openspec/changes/operations-backup-and-restore/specs/backup-restore/spec.md#requirement-a-restore-stops-the-organisations-agents-and-writes-through-the-audit-trail-req-bkup-003
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\BackgroundJob;

use OCA\Hermiq\BackgroundJob\RestoreJob;
use OCA\Hermiq\Service\Backup\BackupSettings;
use OCA\Hermiq\Service\Backup\BackupSettingsStore;
use OCA\Hermiq\Service\Backup\RestorePoint;
use OCA\Hermiq\Service\Backup\RestoreRunner;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * The queued restore hands its point and administrator to the runner.
 *
 * @covers \OCA\Hermiq\BackgroundJob\RestoreJob
 * @uses   \OCA\Hermiq\Service\Backup\RestorePoint
 * @uses   \OCA\Hermiq\Service\Backup\BackupSettings
 * @uses   \OCA\Hermiq\Service\Backup\BackupFiles
 */
final class RestoreJobTest extends TestCase {

	/**
	 * Run the job with an argument.
	 *
	 * @param RestoreRunner   $runner   The runner.
	 * @param LoggerInterface $logger   The logger.
	 * @param mixed           $argument The argument.
	 *
	 * @return void
	 */
	private function runJob(RestoreRunner $runner, LoggerInterface $logger, mixed $argument): void {
		$store = $this->createMock(BackupSettingsStore::class);
		$store->method('get')->willReturn(new BackupSettings(ownerUid: 'admin'));
		$job = new RestoreJob(time: $this->createMock(ITimeFactory::class), settings: $store, runner: $runner, logger: $logger);
		(new ReflectionMethod($job, 'run'))->invoke($job, $argument);
	}//end runJob()

	/**
	 * The point and the administrator reach the runner.
	 *
	 * @return void
	 */
	public function testTheRestoreRunsWithItsPointAndAdministrator(): void {
		$runner = $this->createMock(RestoreRunner::class);
		$runner->expects($this->once())->method('run')->with(
			$this->callback(static fn (RestorePoint $p): bool => $p->kind === 'moment' && $p->organisation === 'tilburg' && $p->removeCreatedAfter === true),
			$this->isInstanceOf(BackupSettings::class),
			'admin'
		)->willReturn(['organisation' => 'tilburg', 'outcome' => 'completed', 'written' => 1, 'removed' => 0, 'failures' => [], 'released' => true]);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with($this->stringContains('tilburg: completed'));

		$this->runJob(
			runner: $runner,
			logger: $logger,
			argument: ['point' => ['kind' => 'moment', 'organisation' => 'tilburg', 'at' => '2026-09-28T02:00:00+00:00', 'removeCreatedAfter' => true], 'adminUid' => 'admin']
		);
	}//end testTheRestoreRunsWithItsPointAndAdministrator()

	/**
	 * A refused restore, or a broken argument, is logged and does not escape the job.
	 *
	 * @return void
	 */
	public function testARefusalIsLogged(): void {
		$runner = $this->createMock(RestoreRunner::class);
		$runner->method('run')->willThrowException(new RuntimeException('newer register version'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->exactly(2))->method('error');

		$this->runJob(runner: $runner, logger: $logger, argument: ['point' => ['kind' => 'backup', 'file' => 'hermiq-backup-tilburg-2026-09-28-0200.json.gz'], 'adminUid' => 'admin']);
		$this->runJob(runner: $runner, logger: $logger, argument: null);
	}//end testARefusalIsLogged()
}//end class
