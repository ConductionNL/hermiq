<?php

/**
 * Unit tests for a custom tool's way through FacadeToolInvoker
 * (tools-custom-tools-without-code): the run owner it carries, the trace step
 * that names it, and the approval gate it passes like every other tool.
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
 * @spec openspec/changes/tools-custom-tools-without-code/specs/custom-tools/spec.md#requirement-a-custom-tool-passes-the-same-governance-as-every-tool-req-custool-004
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Engine;

use OCA\Hermiq\Service\Engine\FacadeToolInvoker;
use OCA\Hermiq\Service\Engine\RunTraceCollector;
use OCA\OpenRegister\Mcp\Service\Mcp\ToolRegistryFacade;
use PHPUnit\Framework\TestCase;

/**
 * A custom tool goes through the same chain as the built-in tools.
 */
final class FacadeToolInvokerCustomToolTest extends TestCase {

	/**
	 * The function name the model calls for the permit check.
	 */
	private const NAME = 'hermiq_custom_vergunningcheck';

	/**
	 * Its tool id.
	 */
	private const TOOL_ID = 'hermiq.custom_vergunningcheck';

	/**
	 * The arguments the facade received, per call.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $captured = [];

	/**
	 * A facade that records the arguments and answers as the invoker does.
	 *
	 * @return ToolRegistryFacade
	 */
	private function facade(): ToolRegistryFacade {
		$facade = $this->createMock(ToolRegistryFacade::class);
		$facade->method('invokeTool')->willReturnCallback(
			function (string $toolId, array $arguments): array {
				$this->captured[] = $arguments;
				return [
					'result' => [
						'result' => ['runUuid' => 'run-1'],
						'customTool' => ['id' => self::TOOL_ID, 'name' => 'Vergunningcheck', 'version' => '1.0.2', 'target' => 'flow:flow-42', 'body' => ['nested']],
					],
					'isError' => false,
				];
			}
		);

		return $facade;
	}//end facade()

	/**
	 * The run's owner travels with the call, and a model-supplied one is overwritten.
	 *
	 * @return void
	 */
	public function testACustomToolCarriesTheRunOwner(): void {
		$invoker = new FacadeToolInvoker(facade: $this->facade(), mcpIdByName: [self::NAME => self::TOOL_ID], ownerUid: 'alice');

		$invoker->{self::NAME}(adres: 'Markt 3', triggeredBy: 'root');

		$this->assertSame(
			expected: ['adres' => 'Markt 3', 'triggeredBy' => 'alice', 'hermiqTurn' => ['agentId' => '', 'conversationId' => '']],
			actual: $this->captured[0]
		);
	}//end testACustomToolCarriesTheRunOwner()

	/**
	 * A custom tool also carries the run's agent and conversation, so a tool that waits for
	 * its workflow's result can open the handoff; a model-supplied turn is overwritten.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flows-hand-off-and-wait/specs/agent-handoffs/spec.md#requirement-a-webhook-workflow-returns-its-result-to-a-one-time-address-req-handoff-004
	 */
	public function testACustomToolCarriesTheRunsAgentAndConversation(): void {
		$invoker = new FacadeToolInvoker(
			facade: $this->facade(),
			agentId: 'agent-1',
			mcpIdByName: [self::NAME => self::TOOL_ID],
			ownerUid: 'alice',
			conversationId: 'conv-1'
		);

		$invoker->{self::NAME}(adres: 'Markt 3', hermiqTurn: ['agentId' => 'other', 'conversationId' => 'elsewhere']);

		$this->assertSame(expected: ['agentId' => 'agent-1', 'conversationId' => 'conv-1'], actual: $this->captured[0]['hermiqTurn']);
	}//end testACustomToolCarriesTheRunsAgentAndConversation()

	/**
	 * Without an owner a model-supplied turn is dropped too.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flows-hand-off-and-wait/specs/agent-handoffs/spec.md#requirement-a-webhook-workflow-returns-its-result-to-a-one-time-address-req-handoff-004
	 */
	public function testWithoutAnOwnerAModelSuppliedTurnIsDropped(): void {
		$invoker = new FacadeToolInvoker(facade: $this->facade(), mcpIdByName: [self::NAME => self::TOOL_ID]);

		$invoker->{self::NAME}(adres: 'Markt 3', hermiqTurn: ['agentId' => 'other', 'conversationId' => 'elsewhere']);

		$this->assertSame(expected: [['adres' => 'Markt 3']], actual: $this->captured);
	}//end testWithoutAnOwnerAModelSuppliedTurnIsDropped()

	/**
	 * Without an owner the call is dispatched, but never with a model-supplied owner;
	 * the invoker then refuses a flow target itself.
	 *
	 * @return void
	 */
	public function testWithoutAnOwnerAModelSuppliedOwnerIsDropped(): void {
		$invoker = new FacadeToolInvoker(facade: $this->facade(), mcpIdByName: [self::NAME => self::TOOL_ID]);

		$invoker->{self::NAME}(adres: 'Markt 3', triggeredBy: 'root');

		$this->assertSame([['adres' => 'Markt 3']], $this->captured);
	}//end testWithoutAnOwnerAModelSuppliedOwnerIsDropped()

	/**
	 * The tool step names the custom tool, its version and its target, scalars only.
	 *
	 * @return void
	 */
	public function testTheStepNamesTheToolItsVersionAndItsTarget(): void {
		$trace   = new RunTraceCollector();
		$invoker = new FacadeToolInvoker(facade: $this->facade(), trace: $trace, mcpIdByName: [self::NAME => self::TOOL_ID], ownerUid: 'alice');

		$invoker->{self::NAME}(adres: 'Markt 3');

		$step = $trace->toArray()[0];
		$this->assertSame(['id' => self::TOOL_ID, 'name' => 'Vergunningcheck', 'version' => '1.0.2', 'target' => 'flow:flow-42'], $step['customTool']);
		$this->assertSame('flow:flow-42', $step['target']);
	}//end testTheStepNamesTheToolItsVersionAndItsTarget()

	/**
	 * A custom tool the organisation classifies `confirm` is held by the approval gate.
	 *
	 * @return void
	 */
	public function testAConfirmClassifiedCustomToolIsHeld(): void {
		$facade = $this->createMock(ToolRegistryFacade::class);
		$facade->expects($this->never())->method('invokeTool');
		$invoker = new FacadeToolInvoker(
			facade: $facade,
			mcpIdByName: [self::NAME => self::TOOL_ID],
			toolPolicy: [self::TOOL_ID => 'confirm'],
			ownerUid: 'alice'
		);

		$out = json_decode($invoker->{self::NAME}(adres: 'Markt 3'), true);

		$this->assertSame('approval_required', $out['error']);
		$this->assertSame(self::TOOL_ID, $out['toolId']);
	}//end testAConfirmClassifiedCustomToolIsHeld()
}//end class
