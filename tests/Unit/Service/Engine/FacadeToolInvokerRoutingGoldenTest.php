<?php

/**
 * Golden tests for the order of FacadeToolInvoker's governance gates and for the
 * arguments and trace it builds around a dispatch.
 *
 * Pins which gate answers when several would refuse the same call (organisation
 * `deny`, then an argument-constraint violation, then an unresolved flow owner,
 * then `confirm`), which tools receive the run's agent id, and which artefact
 * identity a write step records, so the routing can be split into named steps
 * without reordering a gate.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Engine
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/agent-guardrails/tasks.md#task-5-tool-classification-autodeny-enforced-in-facadetoolinvoker
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Engine;

use OCA\Hermiq\Service\ApprovalService;
use OCA\Hermiq\Service\Engine\FacadeToolInvoker;
use OCA\Hermiq\Service\Engine\InvocationPolicy;
use OCA\Hermiq\Service\Engine\InvocationScope;
use OCA\Hermiq\Service\Engine\InvocationServices;
use OCA\Hermiq\Service\Engine\RunTraceCollector;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Mcp\Service\Mcp\ToolRegistryFacade;
use OCA\OpenRegister\Service\Capability\ToolGrantResolver;
use PHPUnit\Framework\TestCase;

/**
 * Golden tests for the FacadeToolInvoker gate order.
 *
 * @spec openspec/changes/agent-guardrails/tasks.md#task-5-tool-classification-autodeny-enforced-in-facadetoolinvoker
 */
class FacadeToolInvokerRoutingGoldenTest extends TestCase {

	/**
	 * A granted flow.
	 *
	 * @var string
	 */
	private const FLOW_A = '00000000-0000-0000-0000-00000000000a';

	/**
	 * Every facade call, as [toolId, arguments].
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private array $calls = [];

	/**
	 * A facade that records each call and answers with the given result.
	 *
	 * @param array<string, mixed> $result The result to answer with.
	 *
	 * @return ToolRegistryFacade
	 */
	private function facade(array $result = ['ok' => true]): ToolRegistryFacade {
		$facade = $this->createMock(ToolRegistryFacade::class);
		$facade->method('invokeTool')->willReturnCallback(
			function (string $toolId, array $arguments) use ($result): array {
				$this->calls[] = [$toolId, $arguments];
				return ['result' => $result, 'isError' => false];
			}
		);
		return $facade;
	}//end facade()

	/**
	 * An invoker for `openregister_runFlow`, granted for FLOW_A only.
	 *
	 * @param string $classification The organisation's classification of runFlow.
	 * @param string|null $ownerUid The flow owner, or null when unresolved.
	 * @param ApprovalService|null $approvals The approval service.
	 *
	 * @return FacadeToolInvoker
	 */
	private function flowInvoker(string $classification, ?string $ownerUid, ?ApprovalService $approvals = null): FacadeToolInvoker {
		$resolver = new ToolGrantResolver();
		$grants = ['openregister.runFlow?flowId=' . self::FLOW_A];

		return new FacadeToolInvoker(
			facade: $this->facade(),
			services: new InvocationServices(
				approvalService: ($approvals ?? $this->createMock(ApprovalService::class))
			),
			scope: new InvocationScope(
				agentId: 'agent-1',
				ownerUid: $ownerUid
			),
			policy: new InvocationPolicy(
				mcpIdByName: ['openregister_runFlow' => 'openregister.runFlow'],
				toolPolicy: ['openregister.runFlow' => $classification],
				argumentConstraints: $resolver->argumentConstraints(grants: $grants),
				waivedConstraintSets: $resolver->waivedConstraintSets(grants: $grants)
			)
		);
	}//end flowInvoker()

	/**
	 * When several gates would refuse one call, the first in order answers: the
	 * organisation's `deny`, then the grant's argument constraint, then an unresolved
	 * flow owner, then `confirm`; a call past every gate reaches the facade with its
	 * owner stamped.
	 *
	 * @return void
	 */
	public function testTheFirstGateInOrderAnswers(): void {
		$other = '00000000-0000-0000-0000-00000000000f';
		$approval = new ObjectEntity();
		$approval->setUuid('appr-1');
		$approval->setObject(['status' => 'pending']);
		$approvals = $this->createMock(ApprovalService::class);
		$approvals->method('ensurePendingApprovalForToolCall')->willReturn($approval);

		$cases = [
			[['deny', null, $other], '{"isError":true,"error":"tool_denied_by_policy","toolId":"openregister.runFlow","message":"This action is denied by the organisation\'s guardrail policy and cannot be run."}'],
			[['auto', null, $other], '{"ok":false,"isError":true,"error":"grant_constraint_violated","toolId":"openregister.runFlow","argument":"flowId","message":"Argument \'flowId\' is not permitted by this agent\'s grant."}'],
			[['confirm', null, self::FLOW_A], '{"ok":false,"isError":true,"error":"owner_unresolved","toolId":"openregister.runFlow","message":"This run has no identified owner, so it cannot queue a flow run on anyone\'s behalf."}'],
			[['confirm', 'alice', self::FLOW_A], '{"isError":true,"error":"approval_required","toolId":"openregister.runFlow","status":"pending","message":"This action requires human approval before it can run.","approvalId":"appr-1"}'],
			[['auto', 'alice', self::FLOW_A], '{"ok":true}'],
		];
		foreach ($cases as [[$classification, $owner, $flowId], $expected]) {
			$this->assertSame($expected, $this->flowInvoker($classification, $owner, $approvals)->openregister_runFlow(flowId: $flowId), $classification . ' ' . $flowId);
		}

		$this->assertSame([['openregister_runFlow', ['flowId' => self::FLOW_A, 'triggeredBy' => 'alice']]], $this->calls);

	}//end testTheFirstGateInOrderAnswers()

	/**
	 * The memory, artefact-write, integriq and delegation tools receive the run's
	 * agent id; other tools, and every tool of a run without an agent, do not.
	 *
	 * @return void
	 */
	public function testOnlyAgentScopedToolsReceiveTheAgentId(): void {
		$map = [
			'hermiq_rememberMemory' => 'hermiq.rememberMemory',
			'hermiq_createTask' => 'hermiq.createTask',
			'integriq_runSynchronization' => 'integriq.runSynchronization',
			'hermiq_delegateAgent' => 'hermiq.delegateAgent',
			'hermiq_listFiles' => 'hermiq.listFiles',
			'hermiq_searchTools' => 'hermiq.searchTools',
		];
		foreach (['agent-1', null] as $agentId) {
			$invoker = new FacadeToolInvoker(
				facade: $this->facade(),
				scope: new InvocationScope(
					agentId: $agentId
				),
				policy: new InvocationPolicy(
					mcpIdByName: $map
				)
			);
			foreach (array_keys($map) as $name) {
				$this->assertSame('{"ok":true}', $invoker->{$name}(x: 1));
			}
		}

		$stamped = ['x' => 1, 'agentId' => 'agent-1'];
		$plain = ['x' => 1];
		$this->assertSame(
			[
				['hermiq_rememberMemory', $stamped],
				['hermiq_createTask', $stamped],
				['integriq_runSynchronization', $stamped],
				['hermiq_delegateAgent', $stamped],
				['hermiq_listFiles', $plain],
				['hermiq_searchTools', $plain],
				['hermiq_rememberMemory', $plain],
				['hermiq_createTask', $plain],
				['integriq_runSynchronization', $plain],
				['hermiq_delegateAgent', $plain],
				['hermiq_listFiles', $plain],
				['hermiq_searchTools', $plain],
			],
			$this->calls
		);

	}//end testOnlyAgentScopedToolsReceiveTheAgentId()

	/**
	 * A write step records an artefact only when the result names one with a scalar
	 * type and id, and records both as strings.
	 *
	 * @return void
	 */
	public function testAWriteStepRecordsOnlyAWellFormedArtefact(): void {
		$cases = [
			[['a' => 1], null],
			[['artefact' => 'x'], null],
			[['artefact' => null], null],
			[['artefact' => ['type' => ['n'], 'id' => 1]], null],
			[['artefact' => ['type' => 'note']], null],
			[['artefact' => ['type' => 'note', 'id' => 7]], ['type' => 'note', 'id' => '7']],
		];
		foreach ($cases as $index => [$result, $artefact]) {
			$trace = new RunTraceCollector();
			$invoker = new FacadeToolInvoker(
				facade: $this->facade($result),
				trace: $trace,
				policy: new InvocationPolicy(
					mcpIdByName: ['hermiq_createNote' => 'hermiq.createNote']
				)
			);
			$invoker->hermiq_createNote(title: 'T');
			$this->assertSame($artefact, ($trace->toArray()[0]['artefact'] ?? null), 'case ' . $index);
		}

	}//end testAWriteStepRecordsOnlyAWellFormedArtefact()
}//end class
