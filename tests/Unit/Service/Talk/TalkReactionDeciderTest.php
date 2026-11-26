<?php

/**
 * Tests for TalkReactionDecider.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Talk
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/approval-task-convergence/specs/approval-task-convergence/spec.md#requirement-a-talk-reaction-completes-the-mirror-task
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Talk;

use OCA\Hermiq\Service\Approval\MirrorTaskCompletion;
use OCA\Hermiq\Service\ApprovalService;
use OCA\Hermiq\Service\Talk\TalkReactionDecider;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;

/**
 * A reaction completes the mirror task, and hermiq decides only what the task did not.
 *
 * @spec openspec/changes/approval-task-convergence/specs/approval-task-convergence/spec.md#requirement-a-talk-reaction-completes-the-mirror-task
 */
class TalkReactionDeciderTest extends TestCase {

	/**
	 * Everything called, in order.
	 *
	 * @var array<int, string>
	 */
	private array $calls = [];

	/**
	 * A decider whose task completion answers $completed and whose re-read answers $reloaded.
	 *
	 * @param bool              $completed Whether the mirror task completes.
	 * @param ObjectEntity|null $reloaded  The approval as re-read after the completion.
	 *
	 * @return TalkReactionDecider
	 */
	private function decider(bool $completed, ?ObjectEntity $reloaded): TalkReactionDecider {
		$this->calls = [];

		$completion = $this->createMock(originalClassName: MirrorTaskCompletion::class);
		$completion->method('complete')->willReturnCallback(
			function (ObjectEntity $approval, string $outcome, string $deciderUid, ?string $comment) use ($completed): bool {
				$this->calls[] = 'task ' . $outcome . ' ' . $deciderUid . ' ' . (string)$comment;
				return $completed;
			}
		);

		$approvals = $this->createMock(originalClassName: ApprovalService::class);
		$approvals->method('loadApproval')->willReturnCallback(
			function (string $uuid) use ($reloaded): ?ObjectEntity {
				$this->calls[] = 'load ' . $uuid;
				return $reloaded;
			}
		);
		$approvals->method('approve')->willReturnCallback(
			function (ObjectEntity $approval, string $deciderUid): array {
				$this->calls[] = 'approve ' . (string)($approval->getObject()['version'] ?? '') . ' ' . $deciderUid;
				return ['status' => 'approved', 'ran' => true];
			}
		);
		$approvals->method('deny')->willReturnCallback(
			function (ObjectEntity $approval, string $deciderUid, ?string $reason): void {
				$this->calls[] = 'deny ' . (string)($approval->getObject()['version'] ?? '') . ' ' . $deciderUid . ' ' . (string)$reason;
			}
		);

		return new TalkReactionDecider(approvalService: $approvals, taskCompletion: $completion);
	}//end decider()

	/**
	 * An approval in $status, marked with $version so the test sees which copy was decided.
	 *
	 * @param string $status  The approval status.
	 * @param string $version Which copy this is.
	 *
	 * @return ObjectEntity
	 */
	private function approval(string $status, string $version): ObjectEntity {
		$approval = new ObjectEntity();
		$approval->setUuid('appr-1');
		$approval->setObject(['status' => $status, 'taskUuid' => 'task-1', 'version' => $version]);
		return $approval;
	}//end approval()

	/**
	 * When the task's terminal event decided the approval, hermiq decides nothing more.
	 *
	 * @return void
	 */
	public function testTheCompletedTaskDecides(): void {
		$decider = $this->decider(completed: true, reloaded: $this->approval(status: 'approved', version: 'fresh'));
		$decider->approve(approval: $this->approval(status: 'pending', version: 'stale'), reactor: 'bob');
		$this->assertSame(expected: ['task approved bob ', 'load appr-1'], actual: $this->calls);

		$decider = $this->decider(completed: true, reloaded: $this->approval(status: 'denied', version: 'fresh'));
		$decider->deny(approval: $this->approval(status: 'pending', version: 'stale'), reactor: 'bob', reason: 'Denied by reaction in Talk');
		$this->assertSame(expected: ['task rejected bob Denied by reaction in Talk', 'load appr-1'], actual: $this->calls);
	}//end testTheCompletedTaskDecides()

	/**
	 * A completed task whose approval is still pending is decided on hermiq's path, from the fresh copy.
	 *
	 * @return void
	 */
	public function testAStillPendingApprovalIsDecidedFromTheFreshCopy(): void {
		$decider = $this->decider(completed: true, reloaded: $this->approval(status: 'pending', version: 'fresh'));
		$decider->approve(approval: $this->approval(status: 'pending', version: 'stale'), reactor: 'bob');
		$this->assertSame(expected: ['task approved bob ', 'load appr-1', 'approve fresh bob'], actual: $this->calls);

		$decider = $this->decider(completed: true, reloaded: $this->approval(status: 'pending', version: 'fresh'));
		$decider->deny(approval: $this->approval(status: 'pending', version: 'stale'), reactor: 'bob', reason: 'No');
		$this->assertSame(expected: ['task rejected bob No', 'load appr-1', 'deny fresh bob No'], actual: $this->calls);
	}//end testAStillPendingApprovalIsDecidedFromTheFreshCopy()

	/**
	 * A completed task whose approval can no longer be read is never decided a second time.
	 *
	 * @return void
	 */
	public function testAnUnreadableApprovalIsNotDecidedTwice(): void {
		$decider = $this->decider(completed: true, reloaded: null);
		$decider->approve(approval: $this->approval(status: 'pending', version: 'stale'), reactor: 'bob');
		$this->assertSame(expected: ['task approved bob ', 'load appr-1'], actual: $this->calls);
	}//end testAnUnreadableApprovalIsNotDecidedTwice()

	/**
	 * Without a completed task, hermiq decides directly, as before.
	 *
	 * @return void
	 */
	public function testNoTaskMeansHermiqDecides(): void {
		$decider = $this->decider(completed: false, reloaded: null);
		$decider->approve(approval: $this->approval(status: 'pending', version: 'original'), reactor: 'bob');
		$this->assertSame(expected: ['task approved bob ', 'approve original bob'], actual: $this->calls);

		$decider = $this->decider(completed: false, reloaded: null);
		$decider->deny(approval: $this->approval(status: 'pending', version: 'original'), reactor: 'bob', reason: 'No');
		$this->assertSame(expected: ['task rejected bob No', 'deny original bob No'], actual: $this->calls);
	}//end testNoTaskMeansHermiqDecides()
}//end class
