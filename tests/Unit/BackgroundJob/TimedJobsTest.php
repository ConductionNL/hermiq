<?php

/**
 * Characterisation of hermiq's timed jobs: their interval, that none runs
 * in parallel with itself, what each calls, and which failures are logged
 * instead of thrown.
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
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\BackgroundJob;

use OCA\Hermiq\Kernel\Contracts\SkillConsolidationServiceInterface;
use OCA\Hermiq\BackgroundJob\RunRetentionCleanupJob;
use OCA\Hermiq\BackgroundJob\SkillConsolidationTask;
use OCA\Hermiq\BackgroundJob\SkillCuratorTask;
use OCA\Hermiq\BackgroundJob\SkillLearningsPromotionTask;
use OCA\Hermiq\BackgroundJob\WorkspaceReaperJob;
use OCA\Hermiq\Service\AiFeature\RunRetentionCleaner;
use OCA\Hermiq\Service\SkillConsolidationService;
use OCA\Hermiq\Service\SkillLearningsPromotionService;
use OCA\Hermiq\Service\SkillMarketplaceService;
use OCA\Hermiq\Service\Workspace\ServerSideWorkspaceProvider;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Schedules and failure handling of the timed jobs.
 *
 * @covers \OCA\Hermiq\BackgroundJob\RunRetentionCleanupJob
 * @covers \OCA\Hermiq\BackgroundJob\WorkspaceReaperJob
 * @covers \OCA\Hermiq\BackgroundJob\SkillConsolidationTask
 * @covers \OCA\Hermiq\BackgroundJob\SkillCuratorTask
 * @covers \OCA\Hermiq\BackgroundJob\SkillLearningsPromotionTask
 */
final class TimedJobsTest extends TestCase {

	/**
	 * Log lines as `level message`.
	 *
	 * @var list<string>
	 */
	private array $logged = [];

	/**
	 * A logger that records level and message.
	 *
	 * @return LoggerInterface
	 */
	private function logger(): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(function ($message, array $context = []) use ($level): void {
				$this->logged[] = $level . ' ' . $message . (isset($context['exception']) === true ? ' +exception' : '');
			});
		}

		return $logger;
	}//end logger()

	/**
	 * Run a timed job's run().
	 *
	 * @param TimedJob $job The job.
	 *
	 * @return void
	 */
	private static function tick(TimedJob $job): void {
		(new ReflectionMethod($job, 'run'))->invoke($job, null);
	}//end tick()

	/**
	 * Interval and parallelism of every timed job.
	 *
	 * @return void
	 */
	public function testSchedules(): void {
		$time = $this->createMock(ITimeFactory::class);
		$jobs = [
			'retention' => new RunRetentionCleanupJob(time: $time, cleaner: $this->createMock(RunRetentionCleaner::class), logger: $this->logger()),
			'reaper' => new WorkspaceReaperJob(time: $time, provider: $this->createMock(ServerSideWorkspaceProvider::class), logger: $this->logger()),
			'consolidation' => new SkillConsolidationTask(time: $time, container: $this->createMock(ContainerInterface::class), logger: $this->logger()),
			'curator' => new SkillCuratorTask(time: $time, marketplaceService: $this->createMock(SkillMarketplaceService::class)),
			'promotion' => new SkillLearningsPromotionTask(time: $time, promotionService: $this->createMock(SkillLearningsPromotionService::class)),
		];

		$schedules = array_map(static fn (TimedJob $job): array => [$job->getInterval(), $job->getAllowParallelRuns()], $jobs);

		self::assertSame(
			[
				'retention' => [86400, false],
				'reaper' => [900, false],
				'consolidation' => [86400, false],
				'curator' => [86400, false],
				'promotion' => [86400, false],
			],
			$schedules
		);
	}//end testSchedules()

	/**
	 * Retention logs how much it removed, and logs a failure as an error.
	 *
	 * @return void
	 */
	public function testRetentionLogsItsCountOrItsFailure(): void {
		$cleaner = $this->createMock(RunRetentionCleaner::class);
		$cleaner->method('clean')->willReturnOnConsecutiveCalls(7, $this->throwException(new RuntimeException('db locked')));
		$job = new RunRetentionCleanupJob(time: $this->createMock(ITimeFactory::class), cleaner: $cleaner, logger: $this->logger());

		self::tick($job);
		self::tick($job);

		self::assertSame(
			['info Hermiq run retention removed 7 expired run payloads', 'error Hermiq run retention cleanup failed: db locked +exception'],
			$this->logged
		);
	}//end testRetentionLogsItsCountOrItsFailure()

	/**
	 * The reaper logs only when it removed something, and logs a failure as an error.
	 *
	 * @return void
	 */
	public function testTheReaperLogsOnlyRemovalsAndFailures(): void {
		$provider = $this->createMock(ServerSideWorkspaceProvider::class);
		$provider->method('reap')->willReturnOnConsecutiveCalls(0, 3, $this->throwException(new RuntimeException('disk gone')));
		$job = new WorkspaceReaperJob(time: $this->createMock(ITimeFactory::class), provider: $provider, logger: $this->logger());

		self::tick($job);
		self::tick($job);
		self::tick($job);

		self::assertSame(
			['info Hermiq removed 3 idle run workspaces', 'error Hermiq workspace reaping failed: disk gone +exception'],
			$this->logged
		);
	}//end testTheReaperLogsOnlyRemovalsAndFailures()

	/**
	 * Consolidation resolves its service lazily, runs one pass, and logs a
	 * failure (including an unresolvable service) as a warning.
	 *
	 * @return void
	 */
	public function testConsolidationRunsAPassOrWarns(): void {
		$service = $this->createMock(SkillConsolidationService::class);
		$service->expects($this->once())->method('runPass')->willReturn([]);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')
			->with(SkillConsolidationServiceInterface::class)
			->willReturnOnConsecutiveCalls($service, $this->throwException(new RuntimeException('not registered')));
		$job = new SkillConsolidationTask(time: $this->createMock(ITimeFactory::class), container: $container, logger: $this->logger());

		$job->run(argument: null);
		$job->run(argument: null);

		self::assertSame(['warning Hermiq skill consolidation pass failed: not registered +exception'], $this->logged);
	}//end testConsolidationRunsAPassOrWarns()

	/**
	 * The curator and the promotion task each call their service once per tick.
	 *
	 * @return void
	 */
	public function testCuratorAndPromotionCallTheirService(): void {
		$marketplace = $this->createMock(SkillMarketplaceService::class);
		$marketplace->expects($this->once())->method('curate')->willReturn([]);
		$promotion = $this->createMock(SkillLearningsPromotionService::class);
		$promotion->expects($this->once())->method('promoteAll');

		(new SkillCuratorTask(time: $this->createMock(ITimeFactory::class), marketplaceService: $marketplace))->run(argument: null);
		(new SkillLearningsPromotionTask(time: $this->createMock(ITimeFactory::class), promotionService: $promotion))->run(argument: null);
	}//end testCuratorAndPromotionCallTheirService()
}//end class
