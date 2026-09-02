<?php

/**
 * Tests the schedule dispatch node's delegation guard and dispatch.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-a-mirrored-run-declares-and-re-enters-its-governed-identity
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Flow;

use OCA\Hermiq\Flow\HermiqScheduleDispatchNode;
use OCA\Hermiq\Service\ScheduleService;
use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use UnexpectedValueException;

/**
 * Delegation guard, gate pass-through and item annotation.
 *
 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-a-mirrored-run-declares-and-re-enters-its-governed-identity
 */
class HermiqScheduleDispatchNodeTest extends TestCase {

	/**
	 * Mock ScheduleService.
	 *
	 * @var ScheduleService&MockObject
	 */
	private ScheduleService $scheduleService;

	/**
	 * Mock ObjectService.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService $objectService;

	/**
	 * Mock FlowMapper handed out by the container.
	 *
	 * @var FlowMapper&MockObject
	 */
	private FlowMapper $flowMapper;

	/**
	 * Wire fresh mocks before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->scheduleService = $this->createMock(ScheduleService::class);
		$this->objectService = $this->createMock(ObjectService::class);
		$this->flowMapper = $this->createMock(FlowMapper::class);
	}//end setUp()

	/**
	 * Build the node with the current mocks.
	 *
	 * @return HermiqScheduleDispatchNode
	 */
	private function node(): HermiqScheduleDispatchNode {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static function (string $text, array $params = []): string {
				return vsprintf(str_replace('%s', '%s', $text), $params);
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($this->flowMapper);

		return new HermiqScheduleDispatchNode(
			scheduleService: $this->scheduleService,
			objectService: $this->objectService,
			container: $container,
			logger: $this->createMock(LoggerInterface::class),
			l10n: $l10n,
			urls: $this->createMock(IURLGenerator::class),
		);
	}//end node()

	/**
	 * Build a schedule entity.
	 *
	 * @param array<string,mixed> $payload The schedule body.
	 *
	 * @return ObjectEntity
	 */
	private function schedule(array $payload): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('sched-1');
		$entity->setOwner('alice');
		$entity->setObject($payload);

		return $entity;
	}//end schedule()

	/**
	 * A nameless dispatch step refuses at execute time too: a seeded or
	 * imported flow never went through validateConfig().
	 *
	 * @return void
	 *
	 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-a-mirrored-run-declares-and-re-enters-its-governed-identity
	 */
	public function testExecuteRefusesWithoutScheduleId(): void {
		$this->expectException(UnexpectedValueException::class);
		$this->node()->execute(items: [['json' => []]], config: [], context: []);

	}//end testExecuteRefusesWithoutScheduleId()

	/**
	 * A flow the schedule no longer names is stale: the step refuses, never
	 * runs the agent, and switches the leftover flow off.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-a-mirrored-run-declares-and-re-enters-its-governed-identity
	 */
	public function testStaleDelegationRefusesAndDisablesTheFlow(): void {
		$this->objectService->method('find')->willReturn(
			$this->schedule(['enabled' => true, 'engineFlowId' => 'the-real-clock'])
		);
		$this->scheduleService->expects($this->never())->method('runNow');

		$staleFlow = new Flow();
		$staleFlow->setUuid('stale-flow');
		$staleFlow->setEnabled(true);
		$this->flowMapper->method('findByUuid')->willReturn($staleFlow);

		$updated = null;
		$this->flowMapper->method('update')->willReturnCallback(
			function (Flow $f) use (&$updated): Flow {
				$updated = $f;
				return $f;
			}
		);

		try {
			$this->node()->execute(
				items: [['json' => []]],
				config: ['scheduleId' => 'sched-1'],
				context: ['payload' => ['flowId' => 'stale-flow']]
			);
			$this->fail('A stale delegation must refuse.');
		} catch (UnexpectedValueException $e) {
			$this->assertStringContainsString('not delegated', $e->getMessage());
		}

		$this->assertNotNull($updated, 'The stale flow must be switched off.');
		$this->assertFalse($updated->getEnabled());

	}//end testStaleDelegationRefusesAndDisablesTheFlow()

	/**
	 * A disabled schedule must simply not run, loudly.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-a-mirrored-run-declares-and-re-enters-its-governed-identity
	 */
	public function testDisabledScheduleRefuses(): void {
		$this->objectService->method('find')->willReturn(
			$this->schedule(['enabled' => false, 'engineFlowId' => 'flow-1'])
		);
		$this->scheduleService->expects($this->never())->method('runNow');

		$this->expectException(UnexpectedValueException::class);
		$this->node()->execute(
			items: [['json' => []]],
			config: ['scheduleId' => 'sched-1'],
			context: ['payload' => ['flowId' => 'flow-1']]
		);

	}//end testDisabledScheduleRefuses()

	/**
	 * The happy path re-enters runNow() once (all gates included) and puts
	 * the occurrence's status on each item.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-a-mirrored-run-declares-and-re-enters-its-governed-identity
	 */
	public function testDispatchRunsOnceAndAnnotatesItems(): void {
		$this->objectService->method('find')->willReturn(
			$this->schedule(['enabled' => true, 'engineFlowId' => 'flow-1', 'lastStatus' => 'ok'])
		);
		$this->scheduleService->expects($this->once())->method('runNow');

		$out = $this->node()->execute(
			items: [['json' => ['a' => 1]]],
			config: ['scheduleId' => 'sched-1'],
			context: ['payload' => ['flowId' => 'flow-1']]
		);

		$this->assertCount(1, $out);
		$this->assertSame('sched-1', $out[0]['json']['scheduleId']);
		$this->assertSame('ok', $out[0]['json']['scheduleStatus']);
		$this->assertSame(1, $out[0]['json']['a'], 'Existing item fields survive.');

	}//end testDispatchRunsOnceAndAnnotatesItems()

	/**
	 * A gate skip is a step success: runNow() records awaiting_approval on
	 * the schedule and returns normally, and the item carries that status.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-a-mirrored-run-declares-and-re-enters-its-governed-identity
	 */
	public function testGateSkipIsAStepSuccessWithTheGateStatus(): void {
		$schedule = $this->schedule(
			['enabled' => true, 'engineFlowId' => 'flow-1', 'requiresApproval' => true, 'lastStatus' => 'awaiting_approval']
		);
		$this->objectService->method('find')->willReturn($schedule);
		$this->scheduleService->expects($this->once())->method('runNow');

		$out = $this->node()->execute(
			items: [['json' => []]],
			config: ['scheduleId' => 'sched-1'],
			context: ['payload' => ['flowId' => 'flow-1']]
		);

		$this->assertSame('awaiting_approval', $out[0]['json']['scheduleStatus']);

	}//end testGateSkipIsAStepSuccessWithTheGateStatus()
}//end class
