<?php

/**
 * Tests the schedule flow mirror rollback command.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-in-flight-schedules-migrate-and-roll-back
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Command;

use OCA\Hermiq\Command\RollbackScheduleFlowMirror;
use OCA\Hermiq\Service\Schedule\ScheduleFlowBridge;
use OCA\Hermiq\Service\ScheduleService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Rollback clears markers first, deletes flows second, and dry-run touches
 * nothing.
 *
 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-in-flight-schedules-migrate-and-roll-back
 */
class RollbackScheduleFlowMirrorTest extends TestCase {

	/**
	 * Mock ObjectService.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService $objectService;

	/**
	 * Mock ScheduleService.
	 *
	 * @var ScheduleService&MockObject
	 */
	private ScheduleService $scheduleService;

	/**
	 * Mock bridge.
	 *
	 * @var ScheduleFlowBridge&MockObject
	 */
	private ScheduleFlowBridge $flowBridge;

	/**
	 * Wire fresh mocks before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objectService = $this->createMock(ObjectService::class);
		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnSelf();
		$this->scheduleService = $this->createMock(ScheduleService::class);
		$this->flowBridge = $this->createMock(ScheduleFlowBridge::class);
	}//end setUp()

	/**
	 * Build a tester around the command with the current mocks.
	 *
	 * @return CommandTester
	 */
	private function tester(): CommandTester {
		return new CommandTester(
			new RollbackScheduleFlowMirror(
				objectService: $this->objectService,
				scheduleService: $this->scheduleService,
				flowBridge: $this->flowBridge,
				logger: $this->createMock(LoggerInterface::class),
			)
		);
	}//end tester()

	/**
	 * Build a schedule entity.
	 *
	 * @param string $uuid The uuid.
	 * @param array<string,mixed> $payload The payload.
	 *
	 * @return ObjectEntity
	 */
	private function schedule(string $uuid, array $payload): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($payload);

		return $entity;
	}//end schedule()

	/**
	 * Only delegated schedules are rolled back: marker cleared, flow deleted.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-in-flight-schedules-migrate-and-roll-back
	 */
	public function testRollsBackOnlyDelegatedSchedules(): void {
		$this->objectService->method('findAll')->willReturn(
			[
				$this->schedule('sched-1', ['engineFlowId' => 'flow-1']),
				$this->schedule('sched-2', ['engineFlowId' => '']),
				$this->schedule('sched-3', []),
			]
		);

		$this->scheduleService->expects($this->once())->method('clearEngineDelegation');
		$deleted = [];
		$this->flowBridge->method('deleteFlow')->willReturnCallback(
			function (string $flowId) use (&$deleted): void {
				$deleted[] = $flowId;
			}
		);

		$tester = $this->tester();
		$this->assertSame(0, $tester->execute([]));
		$this->assertSame(['flow-1'], $deleted);
		$this->assertStringContainsString('1 schedules rolled back', $tester->getDisplay());

	}//end testRollsBackOnlyDelegatedSchedules()

	/**
	 * Dry-run reports without clearing or deleting anything.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-in-flight-schedules-migrate-and-roll-back
	 */
	public function testDryRunTouchesNothing(): void {
		$this->objectService->method('findAll')->willReturn(
			[$this->schedule('sched-1', ['engineFlowId' => 'flow-1'])]
		);

		$this->scheduleService->expects($this->never())->method('clearEngineDelegation');
		$this->flowBridge->expects($this->never())->method('deleteFlow');

		$tester = $this->tester();
		$this->assertSame(0, $tester->execute(['--dry-run' => true]));
		$this->assertStringContainsString('Would roll back schedule sched-1', $tester->getDisplay());

	}//end testDryRunTouchesNothing()

	/**
	 * An unreadable schedule store exits non-zero instead of reporting a
	 * rollback that never ran.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-in-flight-schedules-migrate-and-roll-back
	 */
	public function testUnreadableStoreExitsNonZero(): void {
		$this->objectService->method('findAll')->willThrowException(new \RuntimeException('db gone'));

		$tester = $this->tester();
		$this->assertSame(1, $tester->execute([]));
		$this->assertStringContainsString('Could not read schedules', $tester->getDisplay());

	}//end testUnreadableStoreExitsNonZero()

	/**
	 * A schedule whose marker cannot be cleared is counted as failed and the
	 * command exits non-zero, so a partial rollback never reads as complete.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-in-flight-schedules-migrate-and-roll-back
	 */
	public function testFailedClearCountsAndExitsNonZero(): void {
		$this->objectService->method('findAll')->willReturn(
			[$this->schedule('sched-1', ['engineFlowId' => 'flow-1'])]
		);
		$this->scheduleService->method('clearEngineDelegation')
			->willThrowException(new \RuntimeException('save refused'));

		$tester = $this->tester();
		$this->assertSame(1, $tester->execute([]));
		$this->assertStringContainsString('1 failed', $tester->getDisplay());

	}//end testFailedClearCountsAndExitsNonZero()
}//end class
