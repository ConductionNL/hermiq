<?php

/**
 * Characterisation of the small flow value objects and the node listener:
 * AgentStepRun's configuration fallbacks, FlowItemSlot's output item (json
 * replaced, binary kept, paired to its input) and the four nodes
 * HermiqFlowNodeListener registers, in order, on the palette event only.
 *
 * @category Tests
 * @package  OCA\Hermiq\Tests\Unit\Flow
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

namespace OCA\Hermiq\Tests\Unit\Flow;

use OCA\Hermiq\Flow\AgentStepRun;
use OCA\Hermiq\Flow\FlowItemSlot;
use OCA\Hermiq\Flow\HermiqAgentNode;
use OCA\Hermiq\Flow\HermiqFlowNodeListener;
use OCA\Hermiq\Flow\HermiqScheduleDispatchNode;
use OCA\Hermiq\Flow\HermiqWorkloadCollectNode;
use OCA\Hermiq\Flow\HermiqWorkloadNode;
use OCA\OpenRegister\Service\Flow\RegisterFlowNodesEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;

/**
 * Flow step values and node registration.
 *
 * @covers \OCA\Hermiq\Flow\AgentStepRun
 * @covers \OCA\Hermiq\Flow\FlowItemSlot
 * @covers \OCA\Hermiq\Flow\HermiqFlowNodeListener
 */
final class FlowStepValuesTest extends TestCase {

	/**
	 * agentId wins over agent and is trimmed; output defaults to `result`.
	 *
	 * @return void
	 */
	public function testAgentStepConfigurationFallbacks(): void {
		$full = new AgentStepRun(config: ['agentId' => ' a-1 ', 'agent' => 'a-2', 'organisation' => 'org-1', 'output' => 'review'], owner: 'alice');
		$legacy = new AgentStepRun(config: ['agent' => ' a-2 '], owner: 'alice');
		$empty = new AgentStepRun(config: [], owner: 'alice');

		self::assertSame(['a-1', 'org-1', 'review'], [$full->agentId(), $full->organisation(), $full->outputKey()]);
		self::assertSame(['a-2', '', 'result'], [$legacy->agentId(), $legacy->organisation(), $legacy->outputKey()]);
		self::assertSame('', $empty->agentId());
	}//end testAgentStepConfigurationFallbacks()

	/**
	 * The output item replaces json, keeps binary and pairs with its input index.
	 *
	 * @return void
	 */
	public function testAnItemSlotPairsItsOutputWithItsInput(): void {
		$slot = new FlowItemSlot(item: ['json' => ['a' => 1], 'binary' => ['file' => ['id' => 9]], 'pairedItem' => ['item' => 0]], index: 4);

		self::assertSame(['a' => 1], $slot->json());
		self::assertSame(
			['json' => ['b' => 2], 'binary' => ['file' => ['id' => 9]], 'pairedItem' => ['item' => 4]],
			$slot->withJson(json: ['b' => 2])
		);
	}//end testAnItemSlotPairsItsOutputWithItsInput()

	/**
	 * An item without json or binary reads as empty.
	 *
	 * @return void
	 */
	public function testABareItemReadsAsEmpty(): void {
		$slot = new FlowItemSlot(item: [], index: 'k');

		self::assertSame([], $slot->json());
		self::assertSame(['json' => [], 'binary' => [], 'pairedItem' => ['item' => 'k']], $slot->withJson(json: []));
	}//end testABareItemReadsAsEmpty()

	/**
	 * The palette event gets the agent, workload, collect and schedule nodes in
	 * that order; any other event is ignored.
	 *
	 * @return void
	 */
	public function testTheListenerRegistersTheFourNodesOnThePaletteEvent(): void {
		$agent = $this->createMock(HermiqAgentNode::class);
		$workload = $this->createMock(HermiqWorkloadNode::class);
		$collect = $this->createMock(HermiqWorkloadCollectNode::class);
		$schedule = $this->createMock(HermiqScheduleDispatchNode::class);
		$listener = new HermiqFlowNodeListener(
			agentNode: $agent,
			workloadNode: $workload,
			collectNode: $collect,
			scheduleDispatchNode: $schedule
		);

		$registered = [];
		$event = $this->createMock(RegisterFlowNodesEvent::class);
		$event->method('registerNode')->willReturnCallback(static function ($node) use (&$registered): void {
			$registered[] = $node;
		});

		$listener->handle(new Event());
		self::assertSame([], $registered);

		$listener->handle($event);
		self::assertSame([$agent, $workload, $collect, $schedule], $registered);
	}//end testTheListenerRegistersTheFourNodesOnThePaletteEvent()
}//end class
