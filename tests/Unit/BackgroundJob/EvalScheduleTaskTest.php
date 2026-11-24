<?php

/**
 * EvalScheduleTask (reuse-scheduled-bias-evals, task 3).
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-11-24-reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-dataset-can-run-against-an-agent-on-a-schedule-req-biaseval-003
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\BackgroundJob;

use DateTimeImmutable;
use OCA\Hermiq\BackgroundJob\EvalScheduleTask;
use OCA\Hermiq\Service\EvalScheduleRunner;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * EvalScheduleTask.
 *
 * @spec openspec/changes/archive/2026-11-24-reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-dataset-can-run-against-an-agent-on-a-schedule-req-biaseval-003
 */
class EvalScheduleTaskTest extends TestCase {

	/**
	 * The tick runs the due schedules at the clock's moment and logs each failure.
	 *
	 * @return void
	 */
	public function testTheTickRunsAtTheClocksMomentAndLogsEachFailure(): void {
		$time = $this->createMock(originalClassName: ITimeFactory::class);
		$time->method('getTime')->willReturn(1796108400);

		$runner = $this->createMock(originalClassName: EvalScheduleRunner::class);
		$runner->expects($this->once())->method('runDue')
			->with($this->callback(callback: static fn (DateTimeImmutable $now): bool => $now->getTimestamp() === 1796108400))
			->willReturn(['sched-1' => 'engine down']);

		$logged = [];
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []) use (&$logged): void {
				$logged[] = [$message, $context];
			}
		);

		$job = new EvalScheduleTask(time: $time, runner: $runner, logger: $logger);
		(new ReflectionMethod(objectOrMethod: $job, method: 'run'))->invoke($job, null);

		$this->assertSame(
			expected: [['[hermiq] A scheduled eval run could not run: engine down', ['schedule' => 'sched-1']]],
			actual: $logged
		);
	}//end testTheTickRunsAtTheClocksMomentAndLogsEachFailure()
}//end class
