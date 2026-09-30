<?php

/**
 * integriq's agent tools: Hermiq passes the acting agent and raises the
 * approval that keeps a staged batch's binding (approval-verification-contract).
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
 * @spec openspec/changes/approval-verification-contract/specs/human-approval-gate/spec.md#requirement-a-staged-batch-raises-an-approval-that-keeps-its-binding-req-apver-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Engine;

use OCA\Hermiq\Service\ApprovalService;
use OCA\Hermiq\Service\Engine\FacadeToolInvoker;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Mcp\ToolRegistryFacade;
use PHPUnit\Framework\TestCase;

/**
 * Tests the integriq agent tool path through the real invoker.
 *
 * @spec openspec/changes/approval-verification-contract/specs/human-approval-gate/spec.md#requirement-a-staged-batch-raises-an-approval-that-keeps-its-binding-req-apver-002
 */
class FacadeToolInvokerIntegriqTest extends TestCase {

	private const BINDING = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

	/**
	 * The six integriq agent tool ids.
	 *
	 * @return array<string, array{string}>
	 */
	public static function integriqTools(): array {
		$tools = [];
		foreach (['runSynchronization', 'replayDeadLetters', 'discardDeadLetters', 'testSynchronization', 'testSource', 'listDeadLetters'] as $tool) {
			$tools[$tool] = ['integriq.' . $tool];
		}

		return $tools;

	}//end integriqTools()

	/**
	 * The run's agent id reaches every integriq agent tool, and a model-supplied
	 * one is overwritten.
	 *
	 * @param string $toolId The integriq tool id.
	 *
	 * @return void
	 *
	 * @dataProvider integriqTools
	 *
	 * @spec openspec/changes/approval-verification-contract/specs/human-approval-gate/spec.md#requirement-hermiq-passes-the-acting-agent-to-integriqs-agent-tools-req-apver-003
	 */
	public function testTheActingAgentIsInjected(string $toolId): void {
		$name   = str_replace('.', '_', $toolId);
		$facade = $this->createMock(ToolRegistryFacade::class);
		$facade->expects($this->once())
			->method('invokeTool')
			->with($name, ['id' => 'x', 'agentId' => 'g1'])
			->willReturn(['result' => ['status' => 'executed'], 'isError' => false]);

		$invoker = new FacadeToolInvoker(facade: $facade, agentId: 'g1', mcpIdByName: [$name => $toolId]);
		$invoker->$name(id: 'x', agentId: 'g2');

	}//end testTheActingAgentIsInjected()

	/**
	 * A staged batch raises one pending approval with its binding, and the agent
	 * gets the approval id back in the result.
	 *
	 * @return void
	 */
	public function testAStagedBatchRaisesAnApprovalAndReturnsItsId(): void {
		$staged = [
			'status' => 'staged',
			'proposal' => 'p1',
			'tool' => 'integriq.replayDeadLetters',
			'targetIds' => ['d1', 'd2'],
			'binding' => self::BINDING,
			'message' => 'Nothing ran.',
		];
		$facade = $this->createMock(ToolRegistryFacade::class);
		$facade->method('invokeTool')->willReturn(['result' => $staged, 'isError' => false]);

		$approval = new ObjectEntity();
		$approval->setUuid('a1');
		$approvals = $this->createMock(ApprovalService::class);
		$approvals->expects($this->once())
			->method('ensurePendingApprovalForStagedBatch')
			->with('g1', 'integriq.replayDeadLetters', 'p1', self::BINDING, ['d1', 'd2'])
			->willReturn($approval);

		$invoker = new FacadeToolInvoker(
			facade: $facade,
			approvalService: $approvals,
			agentId: 'g1',
			mcpIdByName: ['integriq_replayDeadLetters' => 'integriq.replayDeadLetters']
		);
		$result = json_decode($invoker->integriq_replayDeadLetters(deadLetterIds: ['d1', 'd2']), true);

		$this->assertSame('a1', $result['approvalId']);
		$this->assertSame(self::BINDING, $result['binding']);

	}//end testAStagedBatchRaisesAnApprovalAndReturnsItsId()

	/**
	 * An executed result, an error, a staged answer without a binding, or a
	 * staged answer from a tool that is not integriq's raise nothing.
	 *
	 * @return array<string, array{string, array<string, mixed>, bool}>
	 */
	public static function notStaged(): array {
		return [
			'executed' => ['integriq.replayDeadLetters', ['status' => 'executed', 'proposal' => 'p1'], false],
			'error' => ['integriq.replayDeadLetters', ['status' => 'staged', 'proposal' => 'p1', 'binding' => self::BINDING], true],
			'no binding' => ['integriq.replayDeadLetters', ['status' => 'staged', 'proposal' => 'p1'], false],
			'another app' => ['other.replay', ['status' => 'staged', 'proposal' => 'p1', 'binding' => self::BINDING], false],
		];

	}//end notStaged()

	/**
	 * Nothing is raised when the result is not an integriq staging.
	 *
	 * @param string               $toolId  The tool id.
	 * @param array<string, mixed> $result  The tool result.
	 * @param bool                 $isError Whether the facade reports an error.
	 *
	 * @return void
	 *
	 * @dataProvider notStaged
	 */
	public function testNothingIsRaisedForAnythingElse(string $toolId, array $result, bool $isError): void {
		$name   = str_replace('.', '_', $toolId);
		$facade = $this->createMock(ToolRegistryFacade::class);
		$facade->method('invokeTool')->willReturn(['result' => $result, 'isError' => $isError]);

		$approvals = $this->createMock(ApprovalService::class);
		$approvals->expects($this->never())->method('ensurePendingApprovalForStagedBatch');

		$invoker = new FacadeToolInvoker(facade: $facade, approvalService: $approvals, agentId: 'g1', mcpIdByName: [$name => $toolId]);
		$answer  = json_decode($invoker->$name(), true);

		$this->assertArrayNotHasKey('approvalId', $answer);

	}//end testNothingIsRaisedForAnythingElse()
}//end class
