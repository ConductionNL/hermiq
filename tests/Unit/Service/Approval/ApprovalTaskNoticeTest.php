<?php

/**
 * Tests for ApprovalTaskNotice.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Approval
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/approval-task-convergence/specs/approval-task-convergence/spec.md#requirement-the-reviewer-a-mirror-task-is-assigned-to-is-notified-once-by-openregister
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Approval;

use OCA\Hermiq\Service\Approval\ApprovalTaskNotice;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Which reviewer OpenRegister's mirror task already notifies.
 *
 * @spec openspec/changes/approval-task-convergence/specs/approval-task-convergence/spec.md#requirement-the-reviewer-a-mirror-task-is-assigned-to-is-notified-once-by-openregister
 */
class ApprovalTaskNoticeTest extends TestCase {

	/**
	 * An approval with the given data.
	 *
	 * @param array<string, mixed> $data The approval data.
	 *
	 * @return ObjectEntity
	 */
	private function approval(array $data): ObjectEntity {
		$approval = new ObjectEntity();
		$approval->setUuid('appr-1');
		$approval->setObject($data);
		return $approval;
	}//end approval()

	/**
	 * A mirrored approval routed to one user is notified by the task.
	 *
	 * @return void
	 */
	public function testAMirroredUserApprovalIsNotifiedByTheTask(): void {
		$notice = new ApprovalTaskNotice(notifierClass: stdClass::class);

		$this->assertSame('bob', $notice->notifiedAssignee($this->approval(['taskUuid' => 't-1', 'reviewer' => 'bob', 'reviewerType' => 'user'])));
		$this->assertSame('bob', $notice->notifiedAssignee($this->approval(['taskUuid' => 't-1', 'reviewer' => 'bob'])));
	}//end testAMirroredUserApprovalIsNotifiedByTheTask()

	/**
	 * Without a mirror, a named user reviewer, or OpenRegister's task notices, hermiq notifies.
	 *
	 * @return void
	 */
	public function testHermiqKeepsNotifyingOtherwise(): void {
		$notice = new ApprovalTaskNotice(notifierClass: stdClass::class);
		$cases = [
			'no mirror' => ['reviewer' => 'bob', 'reviewerType' => 'user'],
			'empty mirror' => ['taskUuid' => '', 'reviewer' => 'bob'],
			'non-string mirror' => ['taskUuid' => 7, 'reviewer' => 'bob'],
			'group reviewer' => ['taskUuid' => 't-1', 'reviewer' => 'admins', 'reviewerType' => 'group'],
			'no reviewer' => ['taskUuid' => 't-1', 'reviewer' => ''],
			'non-string reviewer' => ['taskUuid' => 't-1', 'reviewer' => ['bob']],
		];
		foreach ($cases as $name => $data) {
			$this->assertNull($notice->notifiedAssignee($this->approval($data)), $name);
		}

		$absent = new ApprovalTaskNotice(notifierClass: 'OCA\\Nowhere\\TaskNotificationListener');
		$this->assertNull($absent->notifiedAssignee($this->approval(['taskUuid' => 't-1', 'reviewer' => 'bob'])));
	}//end testHermiqKeepsNotifyingOtherwise()

	/**
	 * The default probe names OpenRegister's task notification listener.
	 *
	 * @return void
	 */
	public function testTheDefaultProbeIsOpenRegistersTaskNotifier(): void {
		$this->assertSame('OCA\\OpenRegister\\Listener\\TaskNotificationListener', ApprovalTaskNotice::TASK_NOTIFIER);
	}//end testTheDefaultProbeIsOpenRegistersTaskNotifier()
}//end class
