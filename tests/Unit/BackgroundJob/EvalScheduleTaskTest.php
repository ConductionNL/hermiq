<?php

/**
 * Unit tests for EvalScheduleTask (reuse-scheduled-bias-evals).
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
 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-dataset-can-run-against-an-agent-on-a-schedule-req-biaseval-003
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\BackgroundJob;

use OCA\Hermiq\BackgroundJob\EvalScheduleTask;
use OCA\Hermiq\Kernel\Contracts\EvalScheduleServiceInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * One hourly tick, never in parallel, that runs the due eval schedules.
 *
 * @covers \OCA\Hermiq\BackgroundJob\EvalScheduleTask
 *
 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-dataset-can-run-against-an-agent-on-a-schedule-req-biaseval-003
 */
class EvalScheduleTaskTest extends TestCase {

	/**
	 * Call the protected run() as the job list would.
	 *
	 * @param EvalScheduleTask $job The job.
	 *
	 * @return void
	 */
	private function tick(EvalScheduleTask $job): void {
		(new ReflectionMethod($job, 'run'))->invoke($job, null);
	}//end tick()

	/**
	 * A tick runs the due schedules once.
	 *
	 * @return void
	 */
	public function testATickRunsTheDueSchedules(): void {
		$schedules = $this->createMock(EvalScheduleServiceInterface::class);
		$schedules->expects($this->once())->method('runDue')->willReturn(2);

		$job = new EvalScheduleTask(time: $this->createMock(ITimeFactory::class), schedules: $schedules, logger: $this->createMock(LoggerInterface::class));
		$this->tick(job: $job);

		$this->assertFalse($job->getAllowParallelRuns(), 'Two ticks must never run the same schedule twice.');
		$interval = (new \ReflectionProperty($job, 'interval'))->getValue($job);
		$this->assertSame(3600, $interval);
		$this->assertInstanceOf(IJob::class, $job);
	}//end testATickRunsTheDueSchedules()

	/**
	 * A failing tick is logged and does not escape into the job runner.
	 *
	 * @return void
	 */
	public function testAFailingTickIsLogged(): void {
		$schedules = $this->createMock(EvalScheduleServiceInterface::class);
		$schedules->method('runDue')->willThrowException(new RuntimeException('register down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with($this->stringContains('register down'));

		$this->tick(job: new EvalScheduleTask(time: $this->createMock(ITimeFactory::class), schedules: $schedules, logger: $logger));
	}//end testAFailingTickIsLogged()
}//end class
