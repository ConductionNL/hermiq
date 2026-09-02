<?php

/**
 * Tests the dispatch tick's arming pass and its failure isolation.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-eligible-schedules-delegate-their-clock-to-the-engine
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\BackgroundJob;

use OCA\Hermiq\BackgroundJob\ScheduleTask;
use OCA\Hermiq\Service\Schedule\ScheduleFlowBridge;
use OCA\Hermiq\Service\ScheduleService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The tick arms first and never lets the bridge block the dispatch.
 *
 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-eligible-schedules-delegate-their-clock-to-the-engine
 */
class ScheduleTaskTest extends TestCase {

	/**
	 * The tick syncs the engine delegation BEFORE dispatching, so a schedule
	 * created since the last tick delegates within one poll interval.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-eligible-schedules-delegate-their-clock-to-the-engine
	 */
	public function testArmsBeforeDispatching(): void {
		$order = [];

		$bridge = $this->createMock(ScheduleFlowBridge::class);
		$bridge->expects($this->once())->method('syncAll')->willReturnCallback(
			function () use (&$order): array {
				$order[] = 'sync';
				return ['mirrored' => 0, 'refreshed' => 0, 'retired' => 0, 'skipped' => 0, 'failed' => 0];
			}
		);

		$scheduleService = $this->createMock(ScheduleService::class);
		$scheduleService->expects($this->once())->method('run')->willReturnCallback(
			function () use (&$order): void {
				$order[] = 'dispatch';
			}
		);

		$task = new ScheduleTask(
			time: $this->createMock(ITimeFactory::class),
			scheduleService: $scheduleService,
			flowBridge: $bridge,
			logger: $this->createMock(LoggerInterface::class),
		);
		$task->run(argument: null);

		$this->assertSame(['sync', 'dispatch'], $order);

	}//end testArmsBeforeDispatching()

	/**
	 * A bridge failure is isolated: the tick still dispatches locally, so an
	 * engine-side outage never silences every schedule.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-eligible-schedules-delegate-their-clock-to-the-engine
	 */
	public function testBridgeFailureNeverBlocksTheTick(): void {
		$bridge = $this->createMock(ScheduleFlowBridge::class);
		$bridge->method('syncAll')->willThrowException(new RuntimeException('flow store down'));

		$scheduleService = $this->createMock(ScheduleService::class);
		$scheduleService->expects($this->once())->method('run');

		$task = new ScheduleTask(
			time: $this->createMock(ITimeFactory::class),
			scheduleService: $scheduleService,
			flowBridge: $bridge,
			logger: $this->createMock(LoggerInterface::class),
		);
		$task->run(argument: null);

	}//end testBridgeFailureNeverBlocksTheTick()
}//end class
