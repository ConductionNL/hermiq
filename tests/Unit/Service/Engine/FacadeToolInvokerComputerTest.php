<?php

/**
 * Every computer action meets a person whatever the organisation's policy or an
 * owner's waiver says; a screenshot does not; the run keeps the action with its
 * approval and never the screen's image.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/tools-browser-and-computer-use/specs/computer-use/spec.md#requirement-every-computer-action-passes-the-approval-gate-req-computer-003
 * @spec openspec/changes/tools-browser-and-computer-use/specs/computer-use/spec.md#requirement-the-run-records-actions-and-never-stores-screenshots-req-computer-004
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Engine;

use DateTimeImmutable;
use OCA\Hermiq\Service\ApprovalService;
use OCA\Hermiq\Service\Engine\FacadeToolInvoker;
use OCA\Hermiq\Service\Engine\RunTraceCollector;
use OCA\Hermiq\Service\Engine\StreamYieldChannel;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Mcp\Service\Mcp\ToolRegistryFacade;
use OCA\OpenRegister\Service\Capability\ToolGrantResolver;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Hermiq\Service\Engine\FacadeToolInvoker
 * @uses \OCA\Hermiq\Service\ComputerUse\ComputerActionRecord
 * @uses \OCA\Hermiq\Mcp\ComputerToolDescriptors
 */
final class FacadeToolInvokerComputerTest extends TestCase {

	/**
	 * The LLPhant-safe names of the two computer tools these tests call.
	 *
	 * @var array<string, string>
	 */
	private const IDS = [
		'hermiq_computerClick' => 'hermiq.computerClick',
		'hermiq_computerScreenshot' => 'hermiq.computerScreenshot',
	];

	/**
	 * Whether the facade was reached.
	 *
	 * @var bool
	 */
	private bool $invoked = false;

	/**
	 * A facade that answers with a screenshot and records that it ran.
	 *
	 * @return ToolRegistryFacade
	 */
	private function facade(): ToolRegistryFacade {
		$facade = $this->createMock(ToolRegistryFacade::class);
		$facade->method('invokeTool')->willReturnCallback(
			function (): array {
				$this->invoked = true;
				return ['result' => ['target' => 'ct-1', 'image' => base64_encode('PNG-BYTES'), 'mimeType' => 'image/png'], 'isError' => false];
			}
		);

		return $facade;
	}

	/**
	 * An approval-shaped entity with a uuid.
	 *
	 * @param string $uuid The uuid.
	 *
	 * @return ObjectEntity
	 */
	private static function approval(string $uuid): ObjectEntity {
		$approval = new ObjectEntity();
		$approval->setUuid($uuid);

		return $approval;
	}

	/**
	 * An approval service with nothing approved or pending yet.
	 *
	 * @return ApprovalService
	 */
	private function pendingApprovals(): ApprovalService {
		$approvals = $this->createMock(ApprovalService::class);
		$approvals->method('findApprovedUnconsumedToolCallApproval')->willReturn(null);
		$approvals->method('findPendingApprovalForToolCall')->willReturn(null);
		$approvals->expects(self::once())->method('ensurePendingApprovalForToolCall')
			->with(agentId: 'agent-1', toolId: 'hermiq.computerClick', arguments: ['target' => 'ct-1', 'x' => 5, 'y' => 6], correlationId: self::anything())
			->willReturn(self::approval(uuid: '00000000-0000-0000-0000-0000000000aa'));

		return $approvals;
	}

	public function testAClickNeedsApprovalWithNoPolicyAndWithAnAutoPolicy(): void {
		foreach ([[], ['hermiq.computerClick' => 'auto']] as $policy) {
			$this->invoked = false;
			$invoker = new FacadeToolInvoker(
				facade: $this->facade(),
				approvalService: $this->pendingApprovals(),
				agentId: 'agent-1',
				mcpIdByName: self::IDS,
				toolPolicy: $policy
			);

			$decoded = json_decode($invoker->hermiq_computerClick(target: 'ct-1', x: 5, y: 6), true);

			self::assertFalse($this->invoked, 'a click must not reach the machine before approval');
			self::assertTrue($decoded['isError']);
		}
	}

	public function testAnOwnersWaiverDoesNotAnswerAComputerAction(): void {
		$grants = ['hermiq.computerClick' . ToolGrantResolver::WAIVER_FRAGMENT];
		$resolver = new ToolGrantResolver();
		$invoker = new FacadeToolInvoker(
			facade: $this->facade(),
			approvalService: $this->pendingApprovals(),
			agentId: 'agent-1',
			mcpIdByName: self::IDS,
			toolPolicy: ['hermiq.computerClick' => 'confirm'],
			argumentConstraints: $resolver->argumentConstraints(grants: $grants),
			ownerUid: 'alice',
			waivedConstraintSets: $resolver->waivedConstraintSets(grants: $grants)
		);

		$invoker->hermiq_computerClick(target: 'ct-1', x: 5, y: 6);

		self::assertFalse($this->invoked);
	}

	public function testAnOrganisationDenyStillRefusesOutright(): void {
		$approvals = $this->createMock(ApprovalService::class);
		$approvals->expects(self::never())->method('ensurePendingApprovalForToolCall');
		$invoker = new FacadeToolInvoker(
			facade: $this->facade(),
			approvalService: $approvals,
			agentId: 'agent-1',
			mcpIdByName: self::IDS,
			toolPolicy: ['hermiq.computerClick' => 'deny']
		);

		$decoded = json_decode($invoker->hermiq_computerClick(target: 'ct-1', x: 5, y: 6), true);

		self::assertFalse($this->invoked);
		self::assertTrue($decoded['isError']);
	}

	public function testAScreenshotNeedsNoApprovalAndIsTracedAsAHash(): void {
		$approvals = $this->createMock(ApprovalService::class);
		$approvals->expects(self::never())->method('ensurePendingApprovalForToolCall');
		$trace = new RunTraceCollector();
		$streamed = [];
		$channel = new StreamYieldChannel();
		$channel->onToolResult(function (array $payload) use (&$streamed): void {
			$streamed[] = $payload['result'];
		});
		$invoker = new FacadeToolInvoker(
			facade: $this->facade(),
			channel: $channel,
			trace: $trace,
			approvalService: $approvals,
			agentId: 'agent-1',
			mcpIdByName: self::IDS
		);

		$encoded = $invoker->hermiq_computerScreenshot(target: 'ct-1');

		self::assertTrue($this->invoked);
		self::assertSame(base64_encode('PNG-BYTES'), json_decode($encoded, true)['image'], 'the model gets the image');
		$hash = ['sha256' => hash('sha256', 'PNG-BYTES'), 'bytes' => 9];
		self::assertSame(['target' => 'ct-1', 'action' => 'screenshot', 'screenshot' => $hash], $trace->toArray()[0]['computer']);
		self::assertSame($hash, $streamed[0]['image'], 'the stream carries the hash, not the image');
		self::assertStringNotContainsString(base64_encode('PNG-BYTES'), (string)json_encode($trace->toArray()));
	}

	public function testAnApprovedClickRunsOnceAndItsStepNamesTheApproval(): void {
		$approved = self::approval(uuid: '00000000-0000-0000-0000-0000000000bb');
		$approvals = $this->createMock(ApprovalService::class);
		$approvals->method('findApprovedUnconsumedToolCallApproval')->willReturn($approved);
		$approvals->expects(self::once())->method('markToolCallApprovalConsumed')->with(approval: $approved);
		$trace = new RunTraceCollector();
		$invoker = new FacadeToolInvoker(
			facade: $this->facade(),
			trace: $trace,
			approvalService: $approvals,
			agentId: 'agent-1',
			mcpIdByName: self::IDS
		);

		$invoker->hermiq_computerClick(target: 'ct-1', x: 5, y: 6);

		self::assertTrue($this->invoked);
		$step = $trace->toArray()[0];
		self::assertSame('invoked_after_approval', $step['outcome']);
		self::assertSame('00000000-0000-0000-0000-0000000000bb', $step['computer']['approvalId']);
		self::assertSame([5, 6], [$step['computer']['x'], $step['computer']['y']]);
	}

	/**
	 * REQ-COMPUTER-003: a reviewer's pre-authorisation of this machine for this run lets the click through, named on the step.
	 *
	 * @spec openspec/changes/tools-browser-and-computer-use/specs/computer-use/spec.md#requirement-every-computer-action-passes-the-approval-gate-req-computer-003
	 */
	public function testAPreAuthorisedMachineRunsTheClickWithoutANewApproval(): void {
		$approvals = $this->createMock(ApprovalService::class);
		$approvals->expects(self::once())->method('computerRunPreAuthorisation')
			->with('agent-1', 'conversation:c-1', 'ct-1', self::isInstanceOf(DateTimeImmutable::class))
			->willReturn(['uuid' => '00000000-0000-0000-0000-0000000000cc', 'decidedBy' => 'carol', 'expiresAt' => '2026-10-20T10:15:00+00:00']);
		$approvals->expects(self::never())->method('ensurePendingApprovalForToolCall');
		$approvals->expects(self::never())->method('markToolCallApprovalConsumed');
		$trace = new RunTraceCollector();
		$invoker = new FacadeToolInvoker(
			facade: $this->facade(),
			trace: $trace,
			approvalService: $approvals,
			agentId: 'agent-1',
			mcpIdByName: self::IDS,
			runId: 'conversation:c-1'
		);

		$invoker->hermiq_computerClick(target: 'ct-1', x: 5, y: 6);

		self::assertTrue($this->invoked);
		$step = $trace->toArray()[0];
		self::assertSame('invoked_under_preauthorisation', $step['outcome']);
		self::assertSame('00000000-0000-0000-0000-0000000000cc', $step['computer']['approvalId']);
	}

	/**
	 * Without a pre-authorisation the click asks for approval, and the request names its run.
	 *
	 * @spec openspec/changes/tools-browser-and-computer-use/specs/computer-use/spec.md#requirement-every-computer-action-passes-the-approval-gate-req-computer-003
	 */
	public function testWithoutAPreAuthorisationTheClickAsksWithItsRun(): void {
		$approvals = $this->createMock(ApprovalService::class);
		$approvals->method('computerRunPreAuthorisation')->willReturn(null);
		$approvals->method('findApprovedUnconsumedToolCallApproval')->willReturn(null);
		$approvals->method('findPendingApprovalForToolCall')->willReturn(null);
		$approvals->expects(self::once())->method('ensurePendingApprovalForToolCall')
			->with(agentId: 'agent-1', toolId: 'hermiq.computerClick', arguments: ['target' => 'ct-1', 'x' => 5, 'y' => 6], correlationId: self::anything(), runId: 'conversation:c-1')
			->willReturn(self::approval(uuid: '00000000-0000-0000-0000-0000000000aa'));
		$invoker = new FacadeToolInvoker(facade: $this->facade(), approvalService: $approvals, agentId: 'agent-1', mcpIdByName: self::IDS, runId: 'conversation:c-1');

		$decoded = json_decode($invoker->hermiq_computerClick(target: 'ct-1', x: 5, y: 6), true);

		self::assertFalse($this->invoked);
		self::assertTrue($decoded['isError']);
	}

	/**
	 * A screenshot never looks up a pre-authorisation, and a turn without a run offers none.
	 *
	 * @spec openspec/changes/tools-browser-and-computer-use/specs/computer-use/spec.md#requirement-every-computer-action-passes-the-approval-gate-req-computer-003
	 */
	public function testNoRunOrAScreenshotLooksUpNoPreAuthorisation(): void {
		$approvals = $this->pendingApprovals();
		$approvals->expects(self::never())->method('computerRunPreAuthorisation');

		(new FacadeToolInvoker(facade: $this->facade(), approvalService: $approvals, agentId: 'agent-1', mcpIdByName: self::IDS))
			->hermiq_computerClick(target: 'ct-1', x: 5, y: 6);
		self::assertFalse($this->invoked);

		$screens = $this->createMock(ApprovalService::class);
		$screens->expects(self::never())->method('computerRunPreAuthorisation');
		(new FacadeToolInvoker(facade: $this->facade(), approvalService: $screens, agentId: 'agent-1', mcpIdByName: self::IDS, runId: 'conversation:c-1'))
			->hermiq_computerScreenshot(target: 'ct-1');
		self::assertTrue($this->invoked);
	}
}
