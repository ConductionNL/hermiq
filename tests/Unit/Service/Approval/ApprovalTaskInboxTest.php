<?php

/**
 * Unit tests for ApprovalTaskInbox (approval-task-convergence, phase 2.1).
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Approval
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
 * @spec openspec/changes/approval-task-convergence/specs/approval-task-convergence/spec.md#requirement-the-reviewer-inbox-reads-pending-work-from-the-openregister-task-inbox
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Approval;

use OCA\Hermiq\Service\Approval\ApprovalTaskBridge;
use OCA\Hermiq\Service\Approval\ApprovalTaskInbox;
use OCA\OpenRegister\Db\TaskInboxCriteria;
use OCA\OpenRegister\Service\Task\TaskInboxPage;
use OCA\OpenRegister\Service\Task\TaskInboxService;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for ApprovalTaskInbox.
 */
class ApprovalTaskInboxTest extends TestCase {

	/**
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * @var IUserManager&MockObject
	 */
	private IUserManager&MockObject $userManager;

	/**
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groupManager;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Set up fresh mocks; bob is in the `reviewers` group.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->container = $this->createMock(originalClassName: ContainerInterface::class);
		$this->userManager = $this->createMock(originalClassName: IUserManager::class);
		$this->groupManager = $this->createMock(originalClassName: IGroupManager::class);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);

		$bob = $this->createMock(originalClassName: IUser::class);
		$this->userManager->method('get')->willReturnCallback(
			static function (string $uid) use ($bob): ?IUser {
				if ($uid === 'bob') {
					return $bob;
				}

				return null;
			}
		);
		$this->groupManager->method('getUserGroupIds')->willReturn(['reviewers']);
	}//end setUp()

	/**
	 * The service under test.
	 *
	 * @return ApprovalTaskInbox
	 */
	private function taskInbox(): ApprovalTaskInbox {
		return new ApprovalTaskInbox(
			container: $this->container,
			userManager: $this->userManager,
			groupManager: $this->groupManager,
			logger: $this->logger
		);
	}//end taskInbox()

	/**
	 * A mirror task row as the task inbox answers it.
	 *
	 * @param string $taskUuid     The task's uuid.
	 * @param string $approvalUuid The mirrored approval's uuid.
	 *
	 * @return array<string, mixed>
	 */
	private static function mirrorRow(string $taskUuid, string $approvalUuid): array {
		return [
			'uuid' => $taskUuid,
			'requester' => ApprovalTaskBridge::REQUESTER,
			'objectUuid' => $approvalUuid,
			'metadata' => ['hermiq' => ['approvalUuid' => $approvalUuid, 'sourceType' => 'toolcall']],
		];
	}//end mirrorRow()

	/**
	 * Assigned tasks come first, then pooled ones offered to the reviewer's
	 * groups; only open tasks are asked for, a task in both scopes is listed
	 * once, and a task hermiq's approval gate did not request is ignored.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/approval-task-convergence/specs/approval-task-convergence/spec.md#requirement-the-reviewer-inbox-reads-pending-work-from-the-openregister-task-inbox
	 */
	public function testAssignedThenPooledMirrorTasks(): void {
		$service = $this->createMock(originalClassName: TaskInboxService::class);
		$asked = [];
		$service->method('inbox')->willReturnCallback(
			function (TaskInboxCriteria $criteria, ?TaskInboxPage $page) use (&$asked): array {
				$asked[] = [$criteria->scope, $criteria->uid, $criteria->groupIds, $criteria->isTerminal, $page?->offset];
				if ($criteria->scope === TaskInboxCriteria::SCOPE_ASSIGNED) {
					$rows = [self::mirrorRow(taskUuid: 't-a', approvalUuid: 'appr-a'), ['uuid' => 't-x', 'requester' => 'someone-else', 'objectUuid' => 'obj-x']];
				} else {
					$rows = [self::mirrorRow(taskUuid: 't-g', approvalUuid: 'appr-g'), self::mirrorRow(taskUuid: 't-a', approvalUuid: 'appr-a')];
				}

				return ['results' => $rows, 'total' => count($rows), 'limit' => 100, 'offset' => 0];
			}
		);
		$this->container->method('get')->with(ApprovalTaskInbox::INBOX_SERVICE_CLASS)->willReturn($service);

		$routed = $this->taskInbox()->routedApprovals(uid: 'bob');

		$this->assertSame(
			expected: [
				['approvalUuid' => 'appr-a', 'taskUuid' => 't-a'],
				['approvalUuid' => 'appr-g', 'taskUuid' => 't-g'],
			],
			actual: $routed
		);
		$this->assertSame(
			expected: [
				[TaskInboxCriteria::SCOPE_ASSIGNED, 'bob', ['reviewers'], false, 0],
				[TaskInboxCriteria::SCOPE_POOLED, 'bob', ['reviewers'], false, 0],
			],
			actual: $asked
		);
	}//end testAssignedThenPooledMirrorTasks()

	/**
	 * A full page reads the next one until the total is reached; a row without
	 * metadata falls back to the task's object anchor, one without either is skipped.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/approval-task-convergence/specs/approval-task-convergence/spec.md#requirement-the-reviewer-inbox-reads-pending-work-from-the-openregister-task-inbox
	 */
	public function testPagesAreReadUntilTheTotal(): void {
		$full = [];
		for ($i = 0; $i < ApprovalTaskInbox::PAGE_SIZE; $i++) {
			$full[] = self::mirrorRow(taskUuid: 't' . $i, approvalUuid: 'a' . $i);
		}

		$offsets = [];
		$service = $this->createMock(originalClassName: TaskInboxService::class);
		$service->method('inbox')->willReturnCallback(
			function (TaskInboxCriteria $criteria, ?TaskInboxPage $page) use ($full, &$offsets): array {
				if ($criteria->scope !== TaskInboxCriteria::SCOPE_ASSIGNED) {
					return ['results' => [], 'total' => 0, 'limit' => 100, 'offset' => 0];
				}

				$offsets[] = $page?->offset;
				$rows = $full;
				if ($page?->offset !== 0) {
					$rows = [
						['uuid' => 't-anchor', 'requester' => ApprovalTaskBridge::REQUESTER, 'objectUuid' => 'a-anchor'],
						['uuid' => 't-none', 'requester' => ApprovalTaskBridge::REQUESTER],
					];
				}

				return ['results' => $rows, 'total' => (ApprovalTaskInbox::PAGE_SIZE + 2), 'limit' => 100, 'offset' => 0];
			}
		);
		$this->container->method('get')->willReturn($service);

		$routed = $this->taskInbox()->routedApprovals(uid: 'bob');

		$this->assertSame(expected: [0, ApprovalTaskInbox::PAGE_SIZE], actual: $offsets);
		$this->assertCount(expectedCount: (ApprovalTaskInbox::PAGE_SIZE + 1), haystack: (array)$routed);
		$routed = (array)$routed;
		$this->assertSame(expected: ['approvalUuid' => 'a-anchor', 'taskUuid' => 't-anchor'], actual: end($routed));
	}//end testPagesAreReadUntilTheTotal()

	/**
	 * A task inbox that throws is reported as unreadable (null) and logged,
	 * so the caller falls back to its own routing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/approval-task-convergence/specs/approval-task-convergence/spec.md#requirement-the-reviewer-inbox-reads-pending-work-from-the-openregister-task-inbox
	 */
	public function testAThrowingTaskInboxIsUnreadable(): void {
		$service = $this->createMock(originalClassName: TaskInboxService::class);
		$service->method('inbox')->willThrowException(new RuntimeException(message: 'db down'));
		$this->container->method('get')->willReturn($service);
		$this->logger->expects($this->once())->method('warning')->with($this->stringContains(string: 'db down'));

		$this->assertNull(actual: $this->taskInbox()->routedApprovals(uid: 'bob'));
	}//end testAThrowingTaskInboxIsUnreadable()

	/**
	 * A container that cannot resolve the task inbox, or resolves something
	 * else, is unreadable too; an empty uid has nothing routed and asks nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/approval-task-convergence/specs/approval-task-convergence/spec.md#requirement-the-reviewer-inbox-reads-pending-work-from-the-openregister-task-inbox
	 */
	public function testAnUnresolvableTaskInboxIsUnreadable(): void {
		$this->container->expects($this->exactly(count: 2))->method('get')->willReturnOnConsecutiveCalls(
			$this->throwException(exception: new RuntimeException(message: 'not registered')),
			new \stdClass()
		);
		$this->logger->expects($this->once())->method('warning')->with($this->stringContains(string: 'not registered'));

		$inbox = $this->taskInbox();
		$this->assertSame(expected: [], actual: $inbox->routedApprovals(uid: ''));
		$this->assertNull(actual: $inbox->routedApprovals(uid: 'bob'));
		$this->assertNull(actual: $inbox->routedApprovals(uid: 'bob'));
	}//end testAnUnresolvableTaskInboxIsUnreadable()

	/**
	 * An unknown user has no groups: only tasks assigned to their uid can be routed.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/approval-task-convergence/specs/approval-task-convergence/spec.md#requirement-the-reviewer-inbox-reads-pending-work-from-the-openregister-task-inbox
	 */
	public function testAnUnknownUserHasNoGroups(): void {
		$groups = [];
		$service = $this->createMock(originalClassName: TaskInboxService::class);
		$service->method('inbox')->willReturnCallback(
			function (TaskInboxCriteria $criteria) use (&$groups): array {
				$groups[] = $criteria->groupIds;
				return ['results' => [], 'total' => 0, 'limit' => 100, 'offset' => 0];
			}
		);
		$this->container->method('get')->willReturn($service);

		$this->assertSame(expected: [], actual: $this->taskInbox()->routedApprovals(uid: 'ghost'));
		$this->assertSame(expected: [[], []], actual: $groups);
	}//end testAnUnknownUserHasNoGroups()
}//end class
