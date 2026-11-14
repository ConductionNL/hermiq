<?php

/**
 * Unit tests for ApprovalTaskBridge when the container's task service is not
 * an OpenRegister TaskService, and for the mirror task it imports.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Approval
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Approval;

use OCA\Hermiq\Service\Approval\ApprovalTaskBridge;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Service\Task\TaskService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use stdClass;

/**
 * A container entry that is not a TaskService mirrors nothing and releases nothing, quietly.
 */
#[CoversClass(ApprovalTaskBridge::class)]
final class ApprovalTaskBridgeServiceShapeTest extends TestCase {

	private ContainerInterface&MockObject $container;
	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		$this->container = $this->createMock(ContainerInterface::class);
		$this->logger    = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The bridge under test.
	 *
	 * @return ApprovalTaskBridge
	 */
	private function bridge(): ApprovalTaskBridge {
		return new ApprovalTaskBridge($this->container, $this->logger);
	}//end bridge()

	/**
	 * An approval object.
	 *
	 * @param array<string, mixed> $payload The approval payload.
	 * @param string $uuid The approval uuid.
	 *
	 * @return ObjectEntity
	 */
	private function approval(array $payload, string $uuid = 'appr-1'): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($payload);
		return $entity;
	}//end approval()

	public function testAPendingApprovalIsNotMirroredWhenTheServiceIsNotATaskService(): void {
		$this->container->expects($this->once())->method('get')->with(ApprovalTaskBridge::TASK_SERVICE_CLASS)->willReturn(new stdClass());
		$this->logger->expects($this->never())->method('warning');

		$this->assertNull($this->bridge()->ensureTaskFor($this->approval(['status' => 'pending'])));
	}//end testAPendingApprovalIsNotMirroredWhenTheServiceIsNotATaskService()

	public function testReleaseIsANoOpWhenTheServiceIsNotATaskService(): void {
		$this->container->expects($this->once())->method('get')->willReturn(new stdClass());
		$this->logger->expects($this->never())->method('warning');

		$this->bridge()->releaseFor($this->approval(['taskUuid' => 'task-1']), 'approved', 'bob');
	}//end testReleaseIsANoOpWhenTheServiceIsNotATaskService()

	public function testAnApprovalWithoutAUuidIsNotMirrored(): void {
		$this->container->expects($this->never())->method('get');

		$this->assertNull($this->bridge()->ensureTaskFor($this->approval(['status' => 'pending'], '')));
	}//end testAnApprovalWithoutAUuidIsNotMirrored()

	public function testAnImportedTaskWithoutAUuidCountsAsNotMirrored(): void {
		$tasks = $this->createMock(TaskService::class);
		$tasks->method('import')->willReturn(new Task());
		$this->container->method('get')->willReturn($tasks);

		$this->assertNull($this->bridge()->ensureTaskFor($this->approval(['status' => 'pending'])));
	}//end testAnImportedTaskWithoutAUuidCountsAsNotMirrored()

	public function testAGroupReviewedApprovalWithoutAgentOrPromptBecomesAGroupTask(): void {
		$task = new Task();
		$task->setUuid('task-9');
		$tasks = $this->createMock(TaskService::class);
		$tasks->expects($this->once())
			->method('import')
			->with(
				[
					'title' => 'Hermiq approval: toolcall',
					'description' => 'A gated hermiq toolcall action awaits your approval.',
					'state' => 'enabled',
					'performerType' => 'group',
					'priority' => 'high',
					'assignee' => null,
					'candidateGroups' => ['reviewers'],
					'requester' => 'hermiq:approval-gate',
					'objectUuid' => 'appr-1',
					'metadata' => ['hermiq' => ['approvalUuid' => 'appr-1', 'sourceType' => 'toolcall']],
				],
				'hermiq:approval-gate'
			)
			->willReturn($task);
		$this->container->method('get')->willReturn($tasks);

		$this->assertSame(
			'task-9',
			$this->bridge()->ensureTaskFor(
				$this->approval(['status' => 'pending', 'sourceType' => 'toolcall', 'reviewer' => ' reviewers ', 'reviewerType' => 'group', 'agentId' => ' ', 'prompt' => ''])
			)
		);
	}//end testAGroupReviewedApprovalWithoutAgentOrPromptBecomesAGroupTask()
}//end class
