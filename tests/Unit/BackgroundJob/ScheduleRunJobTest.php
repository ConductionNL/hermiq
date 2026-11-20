<?php

/**
 * Unit tests for ScheduleRunJob.
 *
 * The queued job hands its schedule and occurrence to ScheduleService::runQueued(),
 * drops an argument without them, and logs a failure instead of throwing it.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\BackgroundJob;

use OCA\Hermiq\BackgroundJob\ScheduleRunJob;
use OCA\Hermiq\Kernel\Contracts\ScheduleServiceInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * @spec openspec/changes/operations-parallel-run-workers/specs/agent-schedule/spec.md#requirement-each-occurrence-is-claimed-once-whatever-the-number-of-workers-req-prun-001
 */
#[CoversClass(ScheduleRunJob::class)]
final class ScheduleRunJobTest extends TestCase {

	private ScheduleServiceInterface&MockObject $service;
	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		$this->service = $this->createMock(ScheduleServiceInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	private function runJob(mixed $argument): void {
		$job = new ScheduleRunJob(time: $this->createMock(ITimeFactory::class), scheduleService: $this->service, logger: $this->logger);
		(new ReflectionMethod($job, 'run'))->invoke($job, $argument);
	}//end runJob()

	/**
	 * The job runs the occurrence it was queued for.
	 *
	 * @return void
	 */
	public function testTheJobRunsItsOccurrence(): void {
		$this->service->expects($this->once())->method('runQueued')
			->with('sched-1', 'sched-1:2026-09-28T02:00:00+00:00')
			->willReturn('ran');

		$this->runJob(['scheduleId' => 'sched-1', 'occurrenceKey' => 'sched-1:2026-09-28T02:00:00+00:00']);
	}//end testTheJobRunsItsOccurrence()

	/**
	 * An argument without a schedule or an occurrence runs nothing and is logged.
	 *
	 * @return void
	 */
	public function testAnIncompleteArgumentRunsNothing(): void {
		$this->service->expects($this->never())->method('runQueued');
		$this->logger->expects($this->exactly(3))->method('warning');

		$this->runJob(['scheduleId' => 'sched-1']);
		$this->runJob(['occurrenceKey' => 'k']);
		$this->runJob('garbage');
	}//end testAnIncompleteArgumentRunsNothing()

	/**
	 * A failure inside the run is logged, not thrown at the worker.
	 *
	 * @return void
	 */
	public function testAFailureIsLoggedNotThrown(): void {
		$this->service->method('runQueued')->willThrowException(new RuntimeException('register down'));
		$this->logger->expects($this->once())->method('error')->with(
			$this->stringContains('failed before it was recorded'),
			$this->callback(static fn (array $context): bool => $context['error'] === 'register down' && $context['schedule'] === 'sched-1')
		);

		$this->runJob(['scheduleId' => 'sched-1', 'occurrenceKey' => 'k']);
	}//end testAFailureIsLoggedNotThrown()
}//end class
