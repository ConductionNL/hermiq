<?php

/**
 * Tests for TaskTerminalListener (approval-task convergence, phase 1).
 *
 * A completed OpenRegister mirror task decides the linked hermiq Approval:
 * approving outcomes apply approve(), rejecting outcomes apply deny() with
 * the task's comment as reason, and everything else (uncommitted dispatches,
 * non-completed terminal states, foreign tasks, tasks without a completing
 * identity) is ignored.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Listener
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
 * @spec openspec/changes/approval-task-convergence/specs/approval-task-convergence/spec.md#requirement-a-task-decision-is-an-approval-decision
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Listener;

use OCA\Hermiq\Listener\TaskTerminalListener;
use OCA\Hermiq\Service\ApprovalService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Event\TaskTerminalEvent;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests the terminal-task-to-approval translation.
 *
 * @spec openspec/changes/approval-task-convergence/specs/approval-task-convergence/spec.md#requirement-a-task-decision-is-an-approval-decision
 */
class TaskTerminalListenerTest extends TestCase {

	/**
	 * Mock approval service.
	 *
	 * @var ApprovalService&MockObject
	 */
	private ApprovalService $approvalService;

	/**
	 * Wire fresh mocks before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->approvalService = $this->createMock(ApprovalService::class);
	}//end setUp()

	/**
	 * Build the listener with the current mocks.
	 *
	 * @return TaskTerminalListener
	 */
	private function listener(): TaskTerminalListener {
		return new TaskTerminalListener(
			$this->approvalService,
			$this->createMock(LoggerInterface::class),
		);
	}//end listener()

	/**
	 * Build a completed hermiq mirror task.
	 *
	 * @param string $outcome The terminal outcome.
	 * @param string|null $completedBy The completing identity.
	 *
	 * @return Task
	 */
	private function completedTask(string $outcome = 'approved', ?string $completedBy = 'carol'): Task {
		$task = new Task();
		$task->setUuid('task-1');
		$task->setState('completed');
		$task->setOutcome($outcome);
		$task->setCompletedBy($completedBy);
		$task->setComment('Because I said so');
		$task->setMetadata(['hermiq' => ['approvalUuid' => 'appr-1', 'sourceType' => 'schedule']]);

		return $task;
	}//end completedTask()

	/**
	 * Build the pending Approval the mirror resolves to.
	 *
	 * @return ObjectEntity
	 */
	private function pendingApproval(): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('appr-1');
		$entity->setObject(['status' => 'pending', 'sourceType' => 'schedule']);

		return $entity;
	}//end pendingApproval()

	/**
	 * A committed completion with an approving outcome applies approve(),
	 * attributed to the task's completer.
	 *
	 * @return void
	 */
	public function testApprovingOutcomeApproves(): void {
		$approval = $this->pendingApproval();
		$this->approvalService->method('loadApproval')->with('appr-1')->willReturn($approval);
		$this->approvalService->expects($this->once())
			->method('approve')
			->with($approval, 'carol')
			->willReturn(['status' => 'approved', 'ran' => true]);
		$this->approvalService->expects($this->never())->method('deny');

		$this->listener()->handle(new TaskTerminalEvent(task: $this->completedTask(), committed: true));
	}//end testApprovingOutcomeApproves()

	/**
	 * A rejecting outcome applies deny() with the task's comment as reason.
	 *
	 * @return void
	 */
	public function testRejectingOutcomeDeniesWithTheComment(): void {
		$approval = $this->pendingApproval();
		$this->approvalService->method('loadApproval')->with('appr-1')->willReturn($approval);
		$this->approvalService->expects($this->once())
			->method('deny')
			->with($approval, 'carol', 'Because I said so');
		$this->approvalService->expects($this->never())->method('approve');

		$this->listener()->handle(
			new TaskTerminalEvent(task: $this->completedTask(outcome: 'rejected'), committed: true)
		);
	}//end testRejectingOutcomeDeniesWithTheComment()

	/**
	 * The in-transaction dispatch (committed=false) never decides: only the
	 * after-commit dispatch may resume work.
	 *
	 * @return void
	 */
	public function testUncommittedDispatchIsIgnored(): void {
		$this->approvalService->expects($this->never())->method('loadApproval');

		$this->listener()->handle(new TaskTerminalEvent(task: $this->completedTask(), committed: false));
	}//end testUncommittedDispatchIsIgnored()

	/**
	 * A terminated (cancelled/moot) task is not a decision: the Approval
	 * stays pending and decidable in hermiq.
	 *
	 * @return void
	 */
	public function testTerminatedStateIsIgnored(): void {
		$task = $this->completedTask(outcome: 'terminated');
		$task->setState('terminated');
		$this->approvalService->expects($this->never())->method('loadApproval');

		$this->listener()->handle(new TaskTerminalEvent(task: $task, committed: true));
	}//end testTerminatedStateIsIgnored()

	/**
	 * A task without the hermiq metadata backlink is foreign and skipped.
	 *
	 * @return void
	 */
	public function testForeignTaskIsIgnored(): void {
		$task = $this->completedTask();
		$task->setMetadata(['someoneElse' => true]);
		$this->approvalService->expects($this->never())->method('loadApproval');

		$this->listener()->handle(new TaskTerminalEvent(task: $task, committed: true));
	}//end testForeignTaskIsIgnored()

	/**
	 * Without a completing identity the assignee decides; without either the
	 * event is skipped: never decide as nobody.
	 *
	 * @return void
	 */
	public function testAssigneeFallsBackAndNobodyNeverDecides(): void {
		$approval = $this->pendingApproval();
		$this->approvalService->method('loadApproval')->willReturn($approval);

		$task = $this->completedTask(completedBy: null);
		$task->setAssignee('dave');
		$this->approvalService->expects($this->once())
			->method('approve')
			->with($approval, 'dave')
			->willReturn(['status' => 'approved', 'ran' => false]);
		$this->listener()->handle(new TaskTerminalEvent(task: $task, committed: true));

		$anonymous = $this->completedTask(completedBy: null);
		$anonymous->setAssignee(null);
		$this->approvalService->expects($this->never())->method('deny');
		$this->listener()->handle(new TaskTerminalEvent(task: $anonymous, committed: true));
	}//end testAssigneeFallsBackAndNobodyNeverDecides()

	/**
	 * A mirror naming an approval that no longer resolves is logged and
	 * skipped, never fatal.
	 *
	 * @return void
	 */
	public function testUnresolvableApprovalIsSkipped(): void {
		$this->approvalService->method('loadApproval')->willReturn(null);
		$this->approvalService->expects($this->never())->method('approve');
		$this->approvalService->expects($this->never())->method('deny');

		$this->listener()->handle(new TaskTerminalEvent(task: $this->completedTask(), committed: true));
	}//end testUnresolvableApprovalIsSkipped()
}//end class
