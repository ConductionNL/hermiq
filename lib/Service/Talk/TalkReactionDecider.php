<?php

/**
 * Hermiq TalkReactionDecider.
 *
 * Applies a reviewer's Talk reaction to an approval through the approval's
 * mirror task, so a reaction and the task inbox converge on one decision
 * path; hermiq's own approve/deny path remains the fallback.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Talk
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
 * @spec openspec/changes/approval-task-convergence/specs/approval-task-convergence/spec.md#requirement-a-talk-reaction-completes-the-mirror-task
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Talk;

use OCA\Hermiq\Kernel\Contracts\ApprovalServiceInterface;
use OCA\Hermiq\Service\Approval\MirrorTaskCompletion;
use OCA\OpenRegister\Db\ObjectEntity;

/**
 * A reaction completes the TASK; the approval follows from the task.
 *
 * When the mirror task completes, OpenRegister's terminal event reaches
 * hermiq's TaskTerminalListener, which approves or denies the approval as the
 * task's completer. The approval is then re-read: if it is still pending (an
 * OpenRegister that completed the task without dispatching the event to
 * hermiq), the decision is applied on hermiq's own path, whose release of the
 * already-terminal mirror is a no-op; an approval that can no longer be read
 * is left alone rather than decided twice. Without a mirror, or when OpenRegister
 * refuses the completion, hermiq's own path decides directly, as before.
 *
 * @spec openspec/changes/approval-task-convergence/specs/approval-task-convergence/spec.md#requirement-a-talk-reaction-completes-the-mirror-task
 */
class TalkReactionDecider {

	/**
	 * Constructor.
	 *
	 * @param ApprovalServiceInterface $approvalService The decision path the inbox uses, and the re-read.
	 * @param MirrorTaskCompletion     $taskCompletion  Completes the approval's mirror task as the reviewer.
	 */
	public function __construct(
		private readonly ApprovalServiceInterface $approvalService,
		private readonly MirrorTaskCompletion $taskCompletion,
	) {
	}//end __construct()

	/**
	 * Approve by reaction.
	 *
	 * @param ObjectEntity $approval The pending approval.
	 * @param string       $reactor  The reviewer who reacted.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/approval-task-convergence/specs/approval-task-convergence/spec.md#requirement-a-talk-reaction-completes-the-mirror-task
	 */
	public function approve(ObjectEntity $approval, string $reactor): void {
		$pending = $this->throughTask(approval: $approval, outcome: 'approved', reactor: $reactor, comment: null);
		if ($pending === null) {
			return;
		}

		$this->approvalService->approve(approval: $pending, deciderUid: $reactor);
	}//end approve()

	/**
	 * Deny by reaction.
	 *
	 * @param ObjectEntity $approval The pending approval.
	 * @param string       $reactor  The reviewer who reacted.
	 * @param string       $reason   Why, recorded on the approval and as the task's comment.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/approval-task-convergence/specs/approval-task-convergence/spec.md#requirement-a-talk-reaction-completes-the-mirror-task
	 */
	public function deny(ObjectEntity $approval, string $reactor, string $reason): void {
		$pending = $this->throughTask(approval: $approval, outcome: 'rejected', reactor: $reactor, comment: $reason);
		if ($pending === null) {
			return;
		}

		$this->approvalService->deny(approval: $pending, deciderUid: $reactor, reason: $reason);
	}//end deny()

	/**
	 * Complete the mirror task, and answer the approval hermiq must still decide itself.
	 *
	 * @param ObjectEntity $approval The pending approval.
	 * @param string       $outcome  The task outcome (`approved`|`rejected`).
	 * @param string       $reactor  The reviewer who reacted.
	 * @param string|null  $comment  The task comment.
	 *
	 * @return ObjectEntity|null Null when the task decided it; otherwise the approval to decide on hermiq's path.
	 */
	private function throughTask(ObjectEntity $approval, string $outcome, string $reactor, ?string $comment): ?ObjectEntity {
		if ($this->taskCompletion->complete(approval: $approval, outcome: $outcome, deciderUid: $reactor, comment: $comment) === false) {
			return $approval;
		}

		// Never decide from the stale entity once the task completed: the
		// terminal listener may already have released the run, and a second
		// release would run the gated action twice.
		$current = $this->approvalService->loadApproval(uuid: (string)$approval->getUuid());
		if ($current === null || (string)($current->getObject()['status'] ?? '') !== 'pending') {
			return null;
		}

		return $current;
	}//end throughTask()
}//end class
