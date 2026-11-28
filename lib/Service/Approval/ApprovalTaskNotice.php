<?php

/**
 * Hermiq ApprovalTaskNotice.
 *
 * Decides whether OpenRegister already tells an approval's reviewer about it
 * through the approval's mirror task, so hermiq does not raise a second
 * Nextcloud notification for the same decision.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Approval
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
 * @spec openspec/changes/approval-task-convergence/specs/approval-task-convergence/spec.md#requirement-the-reviewer-a-mirror-task-is-assigned-to-is-notified-once-by-openregister
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Approval;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * The reviewer notice of a mirrored approval belongs to OpenRegister.
 *
 * OpenRegister's task rule set notifies a task's assignee when the task is
 * created (`taskAssignedToYou`, action `create`). The mirror of an approval
 * routed to one user is created assigned, so that user already hears about it.
 * A group-routed mirror is created as an unassigned pool task without an
 * `offer` transition, which OpenRegister does not announce, so hermiq keeps
 * notifying the group's members. Without the mirror or without OpenRegister's
 * task notifications, hermiq notifies as before.
 *
 * @spec openspec/changes/approval-task-convergence/specs/approval-task-convergence/spec.md#requirement-the-reviewer-a-mirror-task-is-assigned-to-is-notified-once-by-openregister
 */
final class ApprovalTaskNotice {

	/**
	 * OpenRegister's listener that turns task transitions into notifications.
	 *
	 * Named by string so hermiq loads without OpenRegister's task surface.
	 *
	 * @var string
	 */
	public const TASK_NOTIFIER = 'OCA\\OpenRegister\\Listener\\TaskNotificationListener';

	/**
	 * Build the decision.
	 *
	 * @param string $notifierClass The class whose presence proves OpenRegister
	 *                              notifies on task transitions.
	 */
	public function __construct(
		private readonly string $notifierClass = self::TASK_NOTIFIER,
	) {
	}//end __construct()

	/**
	 * The reviewer OpenRegister notifies through this approval's mirror task.
	 *
	 * The approval's reviewer when the approval carries a mirror task uuid, is
	 * routed to a single named user (so the mirror was created assigned to that
	 * user), and OpenRegister's task notifications are installed; null otherwise.
	 *
	 * @param ObjectEntity $approval The pending approval.
	 *
	 * @return string|null The user hermiq must not notify a second time, or null.
	 *
	 * @spec openspec/changes/approval-task-convergence/specs/approval-task-convergence/spec.md#requirement-the-reviewer-a-mirror-task-is-assigned-to-is-notified-once-by-openregister
	 */
	public function notifiedAssignee(ObjectEntity $approval): ?string {
		$data = $approval->getObject();

		$taskUuid = $data['taskUuid'] ?? null;
		if (is_string($taskUuid) === false || $taskUuid === '') {
			return null;
		}

		$reviewer = $data['reviewer'] ?? null;
		if (is_string($reviewer) === false || $reviewer === '') {
			return null;
		}

		if ((string)($data['reviewerType'] ?? 'user') !== 'user' || class_exists($this->notifierClass) === false) {
			return null;
		}

		return $reviewer;
	}//end notifiedAssignee()
}//end class
