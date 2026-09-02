<?php

/**
 * Tests for ApprovalTaskBridge (approval-task convergence, phase 1).
 *
 * The mirror write-path: a pending Approval becomes exactly one OpenRegister
 * task on the trusted import() path, an existing backlink short-circuits,
 * the degraded install (no task surface, or a throwing one) degrades to
 * null without raising, and a hermiq decision releases the mirror as moot.
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
 * @spec openspec/changes/approval-task-convergence/specs/approval-task-convergence/spec.md#requirement-every-pending-approval-is-mirrored-as-one-openregister-task
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Approval;

use OCA\Hermiq\Service\Approval\ApprovalTaskBridge;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Service\Task\TaskService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests the mirror creation and release paths.
 *
 * @spec openspec/changes/approval-task-convergence/specs/approval-task-convergence/spec.md#requirement-every-pending-approval-is-mirrored-as-one-openregister-task
 */
class ApprovalTaskBridgeTest extends TestCase {

	/**
	 * Mock container.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface $container;

	/**
	 * Mock task service (resolved from the container by FQN string).
	 *
	 * @var TaskService&MockObject
	 */
	private TaskService $taskService;

	/**
	 * Wire fresh mocks before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->taskService = $this->createMock(TaskService::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->container->method('get')
			->with(ApprovalTaskBridge::TASK_SERVICE_CLASS)
			->willReturn($this->taskService);
	}//end setUp()

	/**
	 * Build the bridge with the current mocks.
	 *
	 * @return ApprovalTaskBridge
	 */
	private function bridge(): ApprovalTaskBridge {
		return new ApprovalTaskBridge(
			$this->container,
			$this->createMock(LoggerInterface::class),
		);
	}//end bridge()

	/**
	 * Build a pending Approval entity.
	 *
	 * @param array<string,mixed> $payload Payload overrides.
	 *
	 * @return ObjectEntity
	 */
	private function approval(array $payload = []): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('appr-1');
		$entity->setOwner('alice');
		$entity->setObject(
			array_merge(
				[
					'status' => 'pending',
					'sourceType' => 'schedule',
					'reviewer' => 'bob',
					'reviewerType' => 'user',
					'agentId' => 'agent-1',
					'prompt' => 'Summarise the week',
				],
				$payload
			)
		);

		return $entity;
	}//end approval()

	/**
	 * A user-reviewer approval imports an ACTIVE, assigned mirror task with
	 * the hermiq backlink in its metadata and the system seat as requester.
	 *
	 * @return void
	 */
	public function testEnsureTaskForImportsAssignedTaskForUserReviewer(): void {
		$task = new Task();
		$task->setUuid('task-1');

		$this->taskService->expects($this->once())
			->method('import')
			->with(
				$this->callback(
					function (array $data): bool {
						$this->assertSame('active', $data['state']);
						$this->assertSame('bob', $data['assignee']);
						$this->assertNull($data['candidateGroups']);
						$this->assertSame('user', $data['performerType']);
						$this->assertSame(ApprovalTaskBridge::REQUESTER, $data['requester']);
						$this->assertSame('appr-1', $data['objectUuid']);
						$this->assertSame('appr-1', $data['metadata']['hermiq']['approvalUuid']);
						$this->assertSame('schedule', $data['metadata']['hermiq']['sourceType']);
						$this->assertStringContainsString('agent-1', (string)$data['title']);
						$this->assertStringContainsString('Summarise the week', (string)$data['description']);

						return true;
					}
				),
				ApprovalTaskBridge::REQUESTER
			)
			->willReturn($task);

		$this->assertSame('task-1', $this->bridge()->ensureTaskFor(approval: $this->approval()));
	}//end testEnsureTaskForImportsAssignedTaskForUserReviewer()

	/**
	 * A group-reviewer approval imports an ENABLED task offered to the group.
	 *
	 * @return void
	 */
	public function testEnsureTaskForOffersEnabledTaskToGroupReviewer(): void {
		$task = new Task();
		$task->setUuid('task-2');

		$this->taskService->expects($this->once())
			->method('import')
			->with(
				$this->callback(
					function (array $data): bool {
						$this->assertSame('enabled', $data['state']);
						$this->assertNull($data['assignee']);
						$this->assertSame(['reviewers'], $data['candidateGroups']);
						$this->assertSame('group', $data['performerType']);

						return true;
					}
				),
				ApprovalTaskBridge::REQUESTER
			)
			->willReturn($task);

		$uuid = $this->bridge()->ensureTaskFor(
			approval: $this->approval(payload: ['reviewer' => 'reviewers', 'reviewerType' => 'group'])
		);
		$this->assertSame('task-2', $uuid);
	}//end testEnsureTaskForOffersEnabledTaskToGroupReviewer()

	/**
	 * An Approval already carrying a taskUuid is returned as-is: the task
	 * surface is never touched (the idempotency guard).
	 *
	 * @return void
	 */
	public function testEnsureTaskForShortCircuitsOnExistingBacklink(): void {
		$this->taskService->expects($this->never())->method('import');

		$uuid = $this->bridge()->ensureTaskFor(
			approval: $this->approval(payload: ['taskUuid' => 'task-existing'])
		);
		$this->assertSame('task-existing', $uuid);
	}//end testEnsureTaskForShortCircuitsOnExistingBacklink()

	/**
	 * A non-pending Approval is never mirrored.
	 *
	 * @return void
	 */
	public function testEnsureTaskForRefusesNonPendingApproval(): void {
		$this->taskService->expects($this->never())->method('import');

		$this->assertNull(
			$this->bridge()->ensureTaskFor(approval: $this->approval(payload: ['status' => 'approved']))
		);
	}//end testEnsureTaskForRefusesNonPendingApproval()

	/**
	 * A throwing task surface degrades to null (logged), never to an
	 * exception on the approval write-path.
	 *
	 * @return void
	 */
	public function testEnsureTaskForDegradesToNullWhenTheSurfaceThrows(): void {
		$this->taskService->method('import')
			->willThrowException(new RuntimeException('no task tables yet'));

		$this->assertNull($this->bridge()->ensureTaskFor(approval: $this->approval()));
	}//end testEnsureTaskForDegradesToNullWhenTheSurfaceThrows()

	/**
	 * A hermiq decision terminates the mirror as moot, naming the decision.
	 *
	 * @return void
	 */
	public function testReleaseForTerminatesTheMirrorAsMoot(): void {
		$this->taskService->expects($this->once())
			->method('terminateAsMoot')
			->with(
				'task-1',
				$this->stringContains('approved by carol'),
				ApprovalTaskBridge::RELEASE_SOURCE
			)
			->willReturn(new Task());

		$this->bridge()->releaseFor(
			approval: $this->approval(payload: ['taskUuid' => 'task-1']),
			decision: 'approved',
			deciderUid: 'carol'
		);
	}//end testReleaseForTerminatesTheMirrorAsMoot()

	/**
	 * An unmirrored Approval releases nothing.
	 *
	 * @return void
	 */
	public function testReleaseForIsANoOpWithoutABacklink(): void {
		$this->taskService->expects($this->never())->method('terminateAsMoot');

		$this->bridge()->releaseFor(approval: $this->approval(), decision: 'denied', deciderUid: 'carol');
	}//end testReleaseForIsANoOpWithoutABacklink()

	/**
	 * A throwing release is logged, never raised: the hermiq decision stands.
	 *
	 * @return void
	 */
	public function testReleaseForSwallowsAThrowingSurface(): void {
		$this->taskService->method('terminateAsMoot')
			->willThrowException(new RuntimeException('gone'));

		$this->bridge()->releaseFor(
			approval: $this->approval(payload: ['taskUuid' => 'task-1']),
			decision: 'approved',
			deciderUid: 'carol'
		);
		// Reaching this line IS the assertion: nothing escaped.
		$this->addToAssertionCount(1);
	}//end testReleaseForSwallowsAThrowingSurface()
}//end class
