<?php

/**
 * RunNowTool: a run by hand of the caller's own schedule, under the route's guards.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Mcp
 *
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

namespace OCA\Hermiq\Tests\Unit\Service\Mcp;

use OCA\Hermiq\Kernel\Contracts\LiteracyRequirementInterface;
use OCA\Hermiq\Kernel\Contracts\ScheduleServiceInterface;
use OCA\Hermiq\Service\Mcp\RunNowTool;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Hermiq\Service\Mcp\RunNowTool
 * @uses   \OCA\Hermiq\Service\Mcp\DomainTools
 * @uses   \OCA\Hermiq\Service\Schedule\OwnedSchedule
 */
final class RunNowToolTest extends TestCase {

	private ScheduleServiceInterface&MockObject $schedules;

	private ObjectService&MockObject $objects;

	protected function setUp(): void {
		$this->schedules = $this->createMock(ScheduleServiceInterface::class);
		$this->objects = $this->createMock(ObjectService::class);
	}

	private function tool(?LiteracyRequirementInterface $literacy = null): RunNowTool {
		return new RunNowTool($this->schedules, $this->objects, $this->createMock(LoggerInterface::class), $literacy);
	}

	private static function schedule(string $owner, array $data = []): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('s-1');
		$entity->setOwner($owner);
		$entity->setObject($data);
		return $entity;
	}

	public function testAnEmptyScheduleIdIsRefusedBeforeAnythingLoads(): void {
		$this->objects->expects($this->never())->method('find');
		$this->schedules->expects($this->never())->method('runNow');

		$this->assertSame(['error' => ['code' => 'invalid_arguments', 'message' => 'scheduleId is required.']], $this->tool()->run('alice', ''));
	}

	public function testTheCallersOwnScheduleRunsOnceAndTheStatusIsReadAfterTheRun(): void {
		$before = self::schedule('alice', ['lastStatus' => 'idle']);
		$after = self::schedule('alice', ['lastStatus' => 'ok', 'lastError' => null, 'nextRun' => '2026-11-18T08:00:00+00:00']);
		$this->objects->expects($this->exactly(2))->method('find')->willReturnOnConsecutiveCalls($before, $after);
		$this->schedules->expects($this->once())->method('runNow')->with($this->identicalTo($before));

		$this->assertSame(
			['scheduleId' => 's-1', 'status' => 'ok', 'error' => null, 'nextRun' => '2026-11-18T08:00:00+00:00'],
			$this->tool()->run('alice', 's-1')
		);
	}

	public function testAScheduleOfSomebodyElseIsNotRunAndReadsAsNotFound(): void {
		$this->objects->method('find')->willReturn(self::schedule('bob'));
		$this->schedules->expects($this->never())->method('runNow');

		$this->assertSame(['error' => ['code' => 'not_found', 'message' => 'Schedule not found.']], $this->tool()->run('alice', 's-1'));
	}

	public function testTheCourseRequirementIsCheckedAfterOwnershipAndStopsTheRun(): void {
		$this->objects->method('find')->willReturn(self::schedule('alice'));
		$literacy = $this->createMock(LiteracyRequirementInterface::class);
		$literacy->expects($this->once())->method('refusal')->with('alice')->willReturn(['message' => 'Finish the AI course first.', 'errorCode' => 'x', 'courseUrl' => '/c']);
		$this->schedules->expects($this->never())->method('runNow');

		$this->assertSame(['error' => ['code' => 'literacy_required', 'message' => 'Finish the AI course first.']], $this->tool(literacy: $literacy)->run('alice', 's-1'));
	}
}
