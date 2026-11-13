<?php

/**
 * FacadeToolInvoker's refusals: what each gate returns to the model, records in
 * the run trace and shows on the stream, and when it lets the call through.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Engine
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/agent-guardrails/specs/agent-guardrails/spec.md#requirement-every-guardrail-action-is-visible-in-run-history
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Engine;

use OCA\Hermiq\Service\ApprovalService;
use OCA\Hermiq\Service\Engine\FacadeToolInvoker;
use OCA\Hermiq\Service\Engine\InvocationPolicy;
use OCA\Hermiq\Service\Engine\InvocationScope;
use OCA\Hermiq\Service\Engine\InvocationServices;
use OCA\Hermiq\Service\Engine\RunTraceCollector;
use OCA\Hermiq\Service\Engine\StreamYieldChannel;
use OCA\Hermiq\Service\ToolSearchService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Mcp\Service\Mcp\ToolRegistryFacade;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Policy denial, constraint violation, unresolved owner, confirm and gate refusals.
 */
final class FacadeToolInvokerRefusalTest extends TestCase {

	private const AGENT = 'agent-1';

	/** @var array<int, array<string, mixed>> */
	private array $calls = [];

	/** @var array<int, array<string, mixed>> */
	private array $results = [];

	private RunTraceCollector $trace;

	private ToolRegistryFacade&MockObject $facade;

	/**
	 * Fresh doubles per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->trace = new RunTraceCollector();
		$this->facade = $this->createMock(ToolRegistryFacade::class);
	}//end setUp()

	/**
	 * A channel that records what it was given.
	 *
	 * @return StreamYieldChannel
	 */
	private function channel(): StreamYieldChannel {
		$channel = new StreamYieldChannel();
		$channel->onToolCall(function (array $payload): void {
			$this->calls[] = $payload;
		});
		$channel->onToolResult(function (array $payload): void {
			$this->results[] = $payload;
		});
		return $channel;
	}//end channel()

	/**
	 * An approval row.
	 *
	 * @param string $uuid   The uuid.
	 * @param string $status The status.
	 *
	 * @return ObjectEntity
	 */
	private function approval(string $uuid, string $status = 'pending'): ObjectEntity {
		$approval = new ObjectEntity();
		$approval->setUuid($uuid);
		$approval->setObject(['status' => $status]);
		return $approval;
	}//end approval()

	/**
	 * The trace steps without their timing fields.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function steps(): array {
		return array_map(
			static fn (array $step): array => array_diff_key($step, array_flip(['seq', 'startedAt', 'endedAt', 'durationMs'])),
			$this->trace->toArray()
		);
	}//end steps()

	/**
	 * A deny-classified tool is refused, traced under its function name as denied, and shown.
	 *
	 * @return void
	 */
	public function testAPolicyDenialIsRefusedTracedAndShown(): void {
		$this->facade->expects($this->never())->method('invokeTool');
		$invoker = new FacadeToolInvoker(
			facade: $this->facade,
			channel: $this->channel(),
			trace: $this->trace,
			policy: new InvocationPolicy(
				mcpIdByName: ['x_delete' => 'x.delete'],
				toolPolicy: ['x.delete' => 'deny']
			)
		);

		$out = $invoker->x_delete(id: '7');

		self::assertSame(
			'{"isError":true,"error":"tool_denied_by_policy","toolId":"x.delete","message":"This action is denied by the organisation\'s guardrail policy and cannot be run."}',
			$out
		);
		self::assertSame([['type' => 'tool', 'name' => 'x_delete', 'outcome' => 'denied']], $this->steps());
		self::assertSame([['toolId' => 'x.delete', 'arguments' => ['id' => '7']]], $this->calls);
		self::assertSame([['toolId' => 'x.delete', 'result' => json_decode($out, true), 'isError' => true]], $this->results);
	}//end testAPolicyDenialIsRefusedTracedAndShown()

	/**
	 * Without an agent context a confirm-classified call is refused as unavailable.
	 *
	 * @return void
	 */
	public function testAConfirmCallWithoutAgentContextIsUnavailable(): void {
		$invoker = new FacadeToolInvoker(
			facade: $this->facade,
			channel: $this->channel(),
			trace: $this->trace,
			policy: new InvocationPolicy(
				mcpIdByName: ['x_send' => 'x.send'],
				toolPolicy: ['x.send' => 'confirm']
			)
		);

		$out = json_decode($invoker->x_send(to: 'a'), true);

		self::assertSame(
			['isError' => true, 'error' => 'approval_required', 'toolId' => 'x.send', 'status' => 'unavailable', 'message' => 'This action requires human approval, but no agent context is available to route it — refused.'],
			$out
		);
		self::assertSame([['type' => 'tool', 'name' => 'x.send', 'outcome' => 'denied']], $this->steps());
		self::assertCount(1, $this->calls);
	}//end testAConfirmCallWithoutAgentContextIsUnavailable()

	/**
	 * A confirm call raises one pending approval, is refused again while it is
	 * pending, and runs once after approval, consuming it.
	 *
	 * @return void
	 */
	public function testAConfirmCallAwaitsThenRunsOnceApproved(): void {
		$approvals = $this->createMock(ApprovalService::class);
		$ensured = [];
		$approvals->method('ensurePendingApprovalForToolCall')->willReturnCallback(
			function (string $agentId, string $toolId, array $arguments, string $correlationId) use (&$ensured): ObjectEntity {
				$ensured[] = [$agentId, $toolId, $arguments, $correlationId];
				return $this->approval(uuid: 'appr-new');
			}
		);
		$pending = null;
		$approved = null;
		$approvals->method('findPendingApprovalForToolCall')->willReturnCallback(static function () use (&$pending): ?ObjectEntity {
			return $pending;
		});
		$approvals->method('findApprovedUnconsumedToolCallApproval')->willReturnCallback(static function () use (&$approved): ?ObjectEntity {
			return $approved;
		});
		$consumed = [];
		$approvals->method('markToolCallApprovalConsumed')->willReturnCallback(static function (ObjectEntity $approval) use (&$consumed): void {
			$consumed[] = $approval->getUuid();
		});
		$this->facade->method('invokeTool')->willReturn(['result' => ['sent' => true], 'isError' => false]);
		$invoker = new FacadeToolInvoker(
			facade: $this->facade,
			channel: $this->channel(),
			trace: $this->trace,
			services: new InvocationServices(
				approvalService: $approvals
			),
			scope: new InvocationScope(
				agentId: self::AGENT
			),
			policy: new InvocationPolicy(
				mcpIdByName: ['x_send' => 'x.send'],
				toolPolicy: ['x.send' => 'confirm']
			)
		);

		$first = json_decode($invoker->x_send(to: 'a'), true);
		self::assertSame(['status' => 'pending', 'message' => 'This action requires human approval before it can run.', 'approvalId' => 'appr-new'], array_intersect_key($first, array_flip(['status', 'message', 'approvalId'])));
		self::assertSame(['agent-1', 'x.send', ['to' => 'a']], array_slice($ensured[0], 0, 3));
		self::assertSame(64, strlen($ensured[0][3]));

		$pending = $this->approval(uuid: 'appr-new');
		$second = json_decode($invoker->x_send(to: 'a'), true);
		self::assertSame(['status' => 'pending', 'message' => 'This action is still awaiting human approval.', 'approvalId' => 'appr-new'], array_intersect_key($second, array_flip(['status', 'message', 'approvalId'])));
		self::assertCount(1, $ensured, 'No duplicate approval while one is pending.');

		$approved = $this->approval(uuid: 'appr-new', status: 'approved');
		$invoker->x_send(to: 'a');
		self::assertSame(['appr-new'], $consumed);
		self::assertSame(
			['awaiting_approval', 'awaiting_approval', 'invoked_after_approval'],
			array_column($this->steps(), 'outcome')
		);
	}//end testAConfirmCallAwaitsThenRunsOnceApproved()

	/**
	 * A gated call names its reach and is pending, then denied once a reviewer
	 * denies it, and runs once approved; the gate shows only its result.
	 *
	 * @return void
	 */
	public function testAGatedCallIsPendingDeniedOrRun(): void {
		$approvals = $this->createMock(ApprovalService::class);
		$decided = null;
		$approvals->method('findDecidedApprovalForToolInvocation')->willReturnCallback(static function () use (&$decided): ?ObjectEntity {
			return $decided;
		});
		$approvals->method('ensurePendingApprovalForToolInvocation')->willReturn($this->approval(uuid: 'appr-gate'));
		$search = $this->createMock(ToolSearchService::class);
		$search->method('isGranted')->willReturn(false);
		$this->facade->method('invokeTool')->willReturn(['result' => ['deleted' => true], 'isError' => false]);
		$invoker = new FacadeToolInvoker(
			facade: $this->facade,
			channel: $this->channel(),
			trace: $this->trace,
			services: new InvocationServices(
				toolSearchService: $search,
				approvalService: $approvals
			),
			scope: new InvocationScope(
				agentId: self::AGENT
			),
			policy: new InvocationPolicy(
				mcpIdByName: ['x_delete' => 'x.delete'],
				descriptorsByName: ['x_delete' => ['name' => 'x_delete', 'annotations' => ['destructiveHint' => true]]]
			)
		);

		$pending = json_decode($invoker->x_delete(id: '7'), true);
		self::assertSame(['isError', 'error', 'toolId', 'reach', 'status', 'approvalId', 'message'], array_keys($pending));
		self::assertSame(['approval_required', 'pending', 'appr-gate', 'This action requires human approval before it can run.'], [$pending['error'], $pending['status'], $pending['approvalId'], $pending['message']]);
		self::assertSame([], $this->calls, 'The gate shows only its result.');
		self::assertCount(1, $this->results);

		$decided = $this->approval(uuid: 'appr-old', status: 'denied');
		$denied = json_decode($invoker->x_delete(id: '7'), true);
		self::assertSame(['denied', 'appr-old', 'This action was denied by a reviewer and cannot be run.'], [$denied['status'], $denied['approvalId'], $denied['message']]);

		$decided = $this->approval(uuid: 'appr-old', status: 'approved');
		self::assertSame(['deleted' => true], json_decode($invoker->x_delete(id: '7'), true));
	}//end testAGatedCallIsPendingDeniedOrRun()

	/**
	 * A flow-queueing call without an owner is refused as owner_unresolved, traced as refused.
	 *
	 * @return void
	 */
	public function testAFlowCallWithoutAnOwnerIsRefused(): void {
		$this->facade->expects($this->never())->method('invokeTool');
		$invoker = new FacadeToolInvoker(
			facade: $this->facade,
			channel: $this->channel(),
			trace: $this->trace,
			policy: new InvocationPolicy(
				mcpIdByName: ['openregister_runFlow' => 'openregister.runFlow']
			)
		);

		$out = $invoker->openregister_runFlow(flowId: 'f');

		self::assertSame(
			'{"ok":false,"isError":true,"error":"owner_unresolved","toolId":"openregister.runFlow","message":"This run has no identified owner, so it cannot queue a flow run on anyone\'s behalf."}',
			$out
		);
		self::assertSame([['error' => 'owner_unresolved', 'type' => 'tool', 'name' => 'openregister.runFlow', 'outcome' => 'refused']], $this->steps());
		self::assertCount(1, $this->calls);
		self::assertCount(1, $this->results);
	}//end testAFlowCallWithoutAnOwnerIsRefused()
}//end class
