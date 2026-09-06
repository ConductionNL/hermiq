<?php

/**
 * Tests for the MirrorPendingApprovalsToTasks repair step
 * (approval-task convergence, phase 1).
 *
 * The upgrade migration for in-flight approvals: pending Approvals without a
 * `taskUuid` get a mirror task and the backlink written back; already
 * mirrored and already decided approvals are untouched, so a second run
 * changes nothing; a bridge failure is counted and named, never raised.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Repair
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
 * @spec openspec/changes/approval-task-convergence/specs/approval-task-convergence/spec.md#requirement-in-flight-approvals-migrate-and-roll-back
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Repair;

use OCA\Hermiq\Repair\MirrorPendingApprovalsToTasks;
use OCA\Hermiq\Service\Approval\ApprovalTaskBridge;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests the in-flight approval mirror pass.
 *
 * @spec openspec/changes/approval-task-convergence/specs/approval-task-convergence/spec.md#requirement-in-flight-approvals-migrate-and-roll-back
 */
class MirrorPendingApprovalsToTasksTest extends TestCase {

	/**
	 * Mock ObjectService.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService $objectService;

	/**
	 * Mock bridge.
	 *
	 * @var ApprovalTaskBridge&MockObject
	 */
	private ApprovalTaskBridge $taskBridge;

	/**
	 * Wire fresh mocks before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objectService = $this->createMock(ObjectService::class);
		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnSelf();
		$this->taskBridge = $this->createMock(ApprovalTaskBridge::class);
	}//end setUp()

	/**
	 * Build the step with the current mocks.
	 *
	 * @return MirrorPendingApprovalsToTasks
	 */
	private function step(): MirrorPendingApprovalsToTasks {
		return new MirrorPendingApprovalsToTasks(
			$this->objectService,
			$this->taskBridge,
			$this->createMock(LoggerInterface::class),
		);
	}//end step()

	/**
	 * Build an approval entity.
	 *
	 * @param string $uuid The uuid.
	 * @param array<string,mixed> $payload The payload.
	 *
	 * @return ObjectEntity
	 */
	private function approval(string $uuid, array $payload): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($payload);

		return $entity;
	}//end approval()

	/**
	 * Only unmirrored pending approvals are mirrored and backlinked; already
	 * mirrored ones are skipped, which is what makes a second run a no-op.
	 *
	 * @return void
	 */
	public function testMirrorsOnlyUnmirroredPendingApprovals(): void {
		$fresh = $this->approval('appr-1', ['status' => 'pending']);
		$mirrored = $this->approval('appr-2', ['status' => 'pending', 'taskUuid' => 'task-old']);

		$this->objectService->method('findAll')->willReturn([$fresh, $mirrored]);

		$this->taskBridge->expects($this->once())
			->method('ensureTaskFor')
			->with($fresh)
			->willReturn('task-new');

		$saved = [];
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object, ...$rest) use (&$saved): ObjectEntity {
				unset($rest);
				$saved[] = $object;
				return new ObjectEntity();
			}
		);

		$this->step()->run($this->createMock(IOutput::class));

		$this->assertCount(1, $saved, 'Only the unmirrored approval gets a backlink write.');
		$this->assertSame('task-new', $saved[0]['taskUuid']);

	}//end testMirrorsOnlyUnmirroredPendingApprovals()

	/**
	 * A bridge that cannot mirror (degraded surface) is a warned count, never
	 * an exception: the upgrade must not abort over an unmirrorable approval.
	 *
	 * @return void
	 */
	public function testBridgeFailureIsCountedNotRaised(): void {
		$this->objectService->method('findAll')->willReturn(
			[$this->approval('appr-1', ['status' => 'pending'])]
		);
		$this->taskBridge->method('ensureTaskFor')->willReturn(null);
		$this->objectService->expects($this->never())->method('saveObject');

		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('warning')
			->with($this->stringContains('appr-1'));

		$this->step()->run($output);

	}//end testBridgeFailureIsCountedNotRaised()

	/**
	 * An unreadable register (fresh install, descriptor not yet imported) is
	 * an informational skip, never an aborted upgrade.
	 *
	 * @return void
	 */
	public function testUnreadableRegisterSkips(): void {
		$this->objectService->method('findAll')
			->willThrowException(new RuntimeException('no register'));
		$this->taskBridge->expects($this->never())->method('ensureTaskFor');

		$this->step()->run($this->createMock(IOutput::class));
		// Reaching this line IS the assertion: nothing escaped.
		$this->addToAssertionCount(1);

	}//end testUnreadableRegisterSkips()
}//end class
