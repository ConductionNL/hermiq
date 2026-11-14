<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Hermiq\Tests\Unit\Service\Handoff
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/hermiq
 *
 * @spec openspec/changes/flows-hand-off-and-wait/specs/agent-handoffs/spec.md#requirement-a-flow-started-by-an-agent-closes-its-handoff-when-the-flow-run-ends-req-handoff-003
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Handoff;

use OCA\Hermiq\Listener\FlowRunTerminalListener;
use OCA\Hermiq\Service\Engine\FacadeToolInvoker;
use OCA\Hermiq\Service\Handoff\FlowHandoffs;
use OCA\Hermiq\Service\Handoff\FlowTurn;
use OCA\Hermiq\Service\Handoff\HandoffService;
use OCA\OpenRegister\Event\FlowRunTerminalEvent;
use OCA\OpenRegister\Mcp\Service\Mcp\ToolRegistryFacade;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A flow an agent queued opens a handoff, and the run's terminal event closes it with the run's items.
 *
 * @covers \OCA\Hermiq\Service\Handoff\FlowHandoffs
 * @covers \OCA\Hermiq\Service\Handoff\FlowTurn
 * @covers \OCA\Hermiq\Listener\FlowRunTerminalListener
 */
class FlowHandoffsTest extends TestCase {

	/**
	 * The handoff record.
	 *
	 * @var HandoffService&MockObject
	 */
	private HandoffService&MockObject $handoffs;

	/**
	 * OpenRegister's tool surface.
	 *
	 * @var ToolRegistryFacade&MockObject
	 */
	private ToolRegistryFacade&MockObject $tools;

	/**
	 * Build the mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->handoffs = $this->createMock(HandoffService::class);
		$this->tools = $this->createMock(ToolRegistryFacade::class);
	}//end setUp()

	/**
	 * The service, with the terminal event present or absent.
	 *
	 * @param bool $eventAvailable Whether OpenRegister has the event.
	 *
	 * @return FlowHandoffs
	 */
	private function flows(bool $eventAvailable = true): FlowHandoffs {
		return new FlowHandoffs(
			handoffs: $this->handoffs,
			tools: $this->tools,
			logger: $this->createMock(LoggerInterface::class),
			eventAvailable: $eventAvailable
		);
	}//end flows()

	/**
	 * A queued run in a turn with a conversation opens a flow handoff holding its run id, and the model is told.
	 *
	 * @return void
	 */
	public function testAQueuedRunOpensAFlowHandoff(): void {
		$this->handoffs->expects($this->once())->method('open')
			->with(
				[
					'kind'           => 'flow',
					'agentId'        => 'agent-1',
					'conversationId' => 'conv-1',
					'requestedBy'    => 'anne',
					'label'          => 'flow-vergunningcheck',
					'target'         => 'flow-vergunningcheck',
					'externalRef'    => 'run-42',
				]
			)
			->willReturn(['id' => 'h-1']);

		$result = $this->flows()->afterRunFlow(
			arguments: ['flowId' => 'flow-vergunningcheck'],
			result: ['runUuid' => 'run-42', 'status' => 'queued', 'queued' => true],
			turn: new FlowTurn(agentId: 'agent-1', conversationId: 'conv-1', owner: 'anne')
		);

		$this->assertSame(
			['runUuid' => 'run-42', 'status' => 'queued', 'queued' => true, 'handoff' => 'h-1', 'note' => FlowHandoffs::RUNNING_NOTE],
			$result
		);
	}//end testAQueuedRunOpensAFlowHandoff()

	/**
	 * Without the terminal event no handoff opens, and the model is told the result will not come back.
	 *
	 * @return void
	 */
	public function testWithoutTheEventNoHandoffOpens(): void {
		$this->handoffs->expects($this->never())->method('open');

		$result = $this->flows(eventAvailable: false)->afterRunFlow(
			arguments: ['flowId' => 'flow-vergunningcheck'],
			result: ['runUuid' => 'run-42', 'status' => 'queued', 'queued' => true],
			turn: new FlowTurn(agentId: 'agent-1', conversationId: 'conv-1', owner: 'anne')
		);

		$this->assertSame(
			'This OpenRegister cannot report when the flow ends, so its result will not come back here.',
			$result['note']
		);
		$this->assertArrayNotHasKey('handoff', $result);
	}//end testWithoutTheEventNoHandoffOpens()

	/**
	 * A turn without a conversation, a result without a run, or a failing open leaves the result as it is.
	 *
	 * @return void
	 */
	public function testNothingToDeliverToLeavesTheResultAlone(): void {
		$this->handoffs->expects($this->once())->method('open')->willThrowException(new RuntimeException('no register'));
		$flows = $this->flows();
		$queued = ['runUuid' => 'run-42', 'queued' => true];

		$this->assertSame($queued, $flows->afterRunFlow(arguments: [], result: $queued, turn: new FlowTurn(agentId: 'agent-1', conversationId: '', owner: 'anne')));
		$this->assertSame(['error' => 'x'], $flows->afterRunFlow(arguments: [], result: ['error' => 'x'], turn: new FlowTurn(agentId: 'agent-1', conversationId: 'conv-1', owner: 'anne')));
		$this->assertSame($queued, $flows->afterRunFlow(arguments: [], result: $queued, turn: new FlowTurn(agentId: 'agent-1', conversationId: 'conv-1', owner: 'anne')));
	}//end testNothingToDeliverToLeavesTheResultAlone()

	/**
	 * A completed run closes its handoff as done with the run's items, read through flowRunStatus.
	 *
	 * @return void
	 */
	public function testACompletedRunClosesWithItsItems(): void {
		$this->handoffs->method('waitingFor')->with('flow', 'run-42')->willReturn('h-1');
		$this->tools->expects($this->once())->method('invokeTool')
			->with('openregister.flowRunStatus', ['runUuid' => 'run-42'])
			->willReturn(['result' => ['found' => true, 'status' => 'completed', 'items' => [['vergunningVereist' => true]]], 'isError' => false]);
		$this->handoffs->expects($this->once())->method('close')
			->with('h-1', 'done', ['items' => [['vergunningVereist' => true]]])
			->willReturn(true);

		$this->assertTrue($this->flows()->closeForRun(runUuid: 'run-42', status: 'completed'));
	}//end testACompletedRunClosesWithItsItems()

	/**
	 * A failed or stopped run closes its handoff as failed with the status.
	 *
	 * @return void
	 */
	public function testAFailedRunClosesAsFailed(): void {
		$this->handoffs->method('waitingFor')->willReturn('h-1');
		$this->tools->method('invokeTool')->willReturn(['result' => ['error' => 'gone'], 'isError' => true]);
		$this->handoffs->expects($this->once())->method('close')
			->with('h-1', 'failed', ['status' => 'dead_letter', 'outcome' => null])
			->willReturn(true);

		$this->assertTrue($this->flows()->closeForRun(runUuid: 'run-42', status: 'dead_letter'));
	}//end testAFailedRunClosesAsFailed()

	/**
	 * A run no agent waits for closes nothing.
	 *
	 * @return void
	 */
	public function testARunNobodyWaitsForClosesNothing(): void {
		$this->handoffs->method('waitingFor')->willReturn(null);
		$this->tools->expects($this->never())->method('invokeTool');
		$this->handoffs->expects($this->never())->method('close');

		$this->assertFalse($this->flows()->closeForRun(runUuid: 'run-7', status: 'completed'));
	}//end testARunNobodyWaitsForClosesNothing()

	/**
	 * The listener closes on the terminal event and ignores every other event.
	 *
	 * @return void
	 */
	public function testTheListenerClosesOnTheTerminalEventOnly(): void {
		$flows = $this->createMock(FlowHandoffs::class);
		$flows->expects($this->once())->method('closeForRun')->with('run-42', 'completed');
		$listener = new FlowRunTerminalListener(flowHandoffs: $flows, logger: $this->createMock(LoggerInterface::class));

		$listener->handle(new FlowRunTerminalEvent(runUuid: 'run-42', status: 'completed'));
		$listener->handle(new Event());
	}//end testTheListenerClosesOnTheTerminalEventOnly()

	/**
	 * The invoker hands a queued runFlow result to the flow handoffs, with the turn's agent, conversation and owner.
	 *
	 * @return void
	 */
	public function testTheInvokerOpensTheHandoffOfAQueuedRun(): void {
		$this->tools->method('invokeTool')->willReturn(['result' => ['runUuid' => 'run-42', 'queued' => true], 'isError' => false]);
		$this->handoffs->expects($this->once())->method('open')
			->with($this->callback(static fn (array $h): bool => $h['agentId'] === 'agent-1' && $h['conversationId'] === 'conv-1' && $h['requestedBy'] === 'alice' && $h['externalRef'] === 'run-42'))
			->willReturn(['id' => 'h-1']);

		$invoker = new FacadeToolInvoker(
			facade: $this->tools,
			agentId: 'agent-1',
			mcpIdByName: ['openregister_runFlow' => 'openregister.runFlow'],
			ownerUid: 'alice',
			flowHandoffs: $this->flows(),
			conversationId: 'conv-1'
		);

		$out = json_decode($invoker->openregister_runFlow(flowId: 'flow-vergunningcheck'), true);

		$this->assertSame('h-1', $out['handoff']);
		$this->assertSame(FlowHandoffs::RUNNING_NOTE, $out['note']);
	}//end testTheInvokerOpensTheHandoffOfAQueuedRun()
}//end class
