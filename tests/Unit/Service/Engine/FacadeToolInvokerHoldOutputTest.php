<?php

/**
 * Unit tests for FacadeToolInvoker's held output (oversight-ask-and-hold): an
 * agent whose owner holds its output writes a mail as a draft Approval
 * instead of sending it; without holdOutput, or for a tool that sends
 * nothing, the call goes to the tool as before.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/oversight-ask-and-hold/specs/human-approval-gate/spec.md#requirement-written-output-can-be-held-until-a-reviewer-releases-it-req-askhold-003
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Engine;

use OCA\Hermiq\Service\ApprovalService;
use OCA\Hermiq\Service\Engine\FacadeToolInvoker;
use OCA\Hermiq\Service\Engine\RunTraceCollector;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Mcp\Service\Mcp\ToolRegistryFacade;
use PHPUnit\Framework\TestCase;

class FacadeToolInvokerHoldOutputTest extends TestCase {

	private const MAIL = ['to' => 'council@example.org', 'subject' => 'Weekly', 'body' => 'Open tickets: 41'];

	/**
	 * A held draft Approval.
	 *
	 * @return ObjectEntity
	 */
	private function draft(): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('appr-draft');
		return $entity;
	}//end draft()

	/**
	 * An invoker for agent-1.
	 *
	 * @param ToolRegistryFacade $facade The facade.
	 * @param ApprovalService|null $approvals The approval service.
	 * @param bool $holdOutput Whether the owner holds the agent's output.
	 * @param array<string, array<string, mixed>> $descriptors Descriptors by function name.
	 *
	 * @return FacadeToolInvoker
	 */
	private function invoker(ToolRegistryFacade $facade, ?ApprovalService $approvals, bool $holdOutput, array $descriptors = []): FacadeToolInvoker {
		return new FacadeToolInvoker(
			facade: $facade,
			trace: new RunTraceCollector(),
			approvalService: $approvals,
			agentId: 'agent-1',
			mcpIdByName: ['hermiq_sendMail' => 'hermiq.sendMail', 'talk_post' => 'talk.post', 'hermiq_listFiles' => 'hermiq.listFiles'],
			descriptorsByName: $descriptors,
			holdOutput: $holdOutput
		);
	}//end invoker()

	public function testAHeldAgentsMailBecomesADraftAndIsNotSent(): void {
		$facade = $this->createMock(ToolRegistryFacade::class);
		$facade->expects($this->never())->method('invokeTool');
		$approvals = $this->createMock(ApprovalService::class);
		$approvals->expects($this->once())->method('ensurePendingToolDraft')
			->with('agent-1', 'hermiq.sendMail', self::MAIL, ['subject', 'body'])
			->willReturn($this->draft());

		$result = json_decode($this->invoker(facade: $facade, approvals: $approvals, holdOutput: true)->hermiq_sendMail(...self::MAIL), true);

		$this->assertTrue($result['isError']);
		$this->assertSame('held', $result['status']);
		$this->assertSame('appr-draft', $result['approvalId']);
		$this->assertStringStartsWith('Draft held for review.', $result['message']);
	}

	public function testAToolThatDeclaresSendsMessageIsHeldWithItsEditableFields(): void {
		$facade = $this->createMock(ToolRegistryFacade::class);
		$facade->expects($this->never())->method('invokeTool');
		$approvals = $this->createMock(ApprovalService::class);
		$approvals->expects($this->once())->method('ensurePendingToolDraft')
			->with('agent-1', 'talk.post', ['room' => 'r1', 'message' => 'Hi'], ['message'])
			->willReturn($this->draft());
		$descriptors = ['talk_post' => ['sendsMessage' => true, 'editable' => ['message']]];

		$this->invoker(facade: $facade, approvals: $approvals, holdOutput: true, descriptors: $descriptors)->talk_post(room: 'r1', message: 'Hi');
	}

	public function testWithoutHoldOutputTheMailIsSent(): void {
		$facade = $this->createMock(ToolRegistryFacade::class);
		$facade->expects($this->once())->method('invokeTool')->willReturn(['result' => ['sent' => true], 'isError' => false]);
		$approvals = $this->createMock(ApprovalService::class);
		$approvals->expects($this->never())->method('ensurePendingToolDraft');

		$this->invoker(facade: $facade, approvals: $approvals, holdOutput: false)->hermiq_sendMail(...self::MAIL);
	}

	public function testAToolThatSendsNothingIsNotHeld(): void {
		$facade = $this->createMock(ToolRegistryFacade::class);
		$facade->expects($this->once())->method('invokeTool')->willReturn(['result' => [], 'isError' => false]);
		$approvals = $this->createMock(ApprovalService::class);
		$approvals->expects($this->never())->method('ensurePendingToolDraft');

		$this->invoker(facade: $facade, approvals: $approvals, holdOutput: true)->hermiq_listFiles(path: '/');
	}

	public function testAHeldMailWithoutAnApprovalServiceIsRefusedNotSent(): void {
		$facade = $this->createMock(ToolRegistryFacade::class);
		$facade->expects($this->never())->method('invokeTool');

		$result = json_decode($this->invoker(facade: $facade, approvals: null, holdOutput: true)->hermiq_sendMail(...self::MAIL), true);

		$this->assertTrue($result['isError']);
		$this->assertSame('unavailable', $result['status']);
	}
}//end class
