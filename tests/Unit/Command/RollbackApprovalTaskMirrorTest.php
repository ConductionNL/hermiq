<?php

/**
 * Tests for the RollbackApprovalTaskMirror occ command
 * (approval-task convergence, phase 1).
 *
 * The reverse of the mirror pass: every still-pending Approval with a
 * `taskUuid` gets its mirror terminated as moot and the backlink cleared;
 * decided approvals are untouched; `--dry-run` reports without writing.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Command
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

namespace OCA\Hermiq\Tests\Unit\Command;

use OCA\Hermiq\Command\RollbackApprovalTaskMirror;
use OCA\Hermiq\Service\Approval\ApprovalTaskBridge;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Tests the mirror rollback.
 *
 * @spec openspec/changes/approval-task-convergence/specs/approval-task-convergence/spec.md#requirement-in-flight-approvals-migrate-and-roll-back
 */
class RollbackApprovalTaskMirrorTest extends TestCase {

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
	 * Build a tester around the command with the current mocks.
	 *
	 * @return CommandTester
	 */
	private function tester(): CommandTester {
		return new CommandTester(
			new RollbackApprovalTaskMirror(
				$this->objectService,
				$this->taskBridge,
				$this->createMock(LoggerInterface::class),
			)
		);
	}//end tester()

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
	 * A mirrored pending approval is released and its backlink cleared; an
	 * unmirrored pending one and a decided one are untouched.
	 *
	 * @return void
	 */
	public function testRollsBackOnlyMirroredPendingApprovals(): void {
		$mirrored = $this->approval('appr-1', ['status' => 'pending', 'taskUuid' => 'task-1']);
		$bare = $this->approval('appr-2', ['status' => 'pending']);
		$decided = $this->approval('appr-3', ['status' => 'approved', 'taskUuid' => 'task-3']);

		$this->objectService->method('findAll')->willReturn([$mirrored, $bare, $decided]);

		$this->taskBridge->expects($this->once())
			->method('releaseFor')
			->with($mirrored, 'rolled-back', 'occ:rollback-task-mirror');

		$saved = [];
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object, ...$rest) use (&$saved): ObjectEntity {
				unset($rest);
				$saved[] = $object;
				return new ObjectEntity();
			}
		);

		$tester = $this->tester();
		$this->assertSame(0, $tester->execute([]));

		$this->assertCount(1, $saved, 'Only the mirrored pending approval is rewritten.');
		$this->assertNull($saved[0]['taskUuid'], 'The backlink must be cleared.');
		$this->assertStringContainsString('1 approvals rolled back', $tester->getDisplay());

	}//end testRollsBackOnlyMirroredPendingApprovals()

	/**
	 * A dry run reports without releasing or writing anything.
	 *
	 * @return void
	 */
	public function testDryRunWritesNothing(): void {
		$this->objectService->method('findAll')->willReturn(
			[$this->approval('appr-1', ['status' => 'pending', 'taskUuid' => 'task-1'])]
		);

		$this->taskBridge->expects($this->never())->method('releaseFor');
		$this->objectService->expects($this->never())->method('saveObject');

		$tester = $this->tester();
		$this->assertSame(0, $tester->execute(['--dry-run' => true]));
		$this->assertStringContainsString('Would roll back approval appr-1', $tester->getDisplay());

	}//end testDryRunWritesNothing()

	/**
	 * When the approvals cannot be read the command says so and exits 1,
	 * touching no task.
	 *
	 * @return void
	 */
	public function testAnUnreadableApprovalListFailsTheCommand(): void {
		$this->objectService->method('findAll')->willThrowException(new \RuntimeException('register missing'));
		$this->taskBridge->expects($this->never())->method('releaseFor');
		$this->objectService->expects($this->never())->method('saveObject');

		$tester = $this->tester();
		$this->assertSame(1, $tester->execute([]));
		$this->assertStringContainsString('Could not read approvals: register missing', $tester->getDisplay());
	}//end testAnUnreadableApprovalListFailsTheCommand()

	/**
	 * A backlink that cannot be cleared is reported and logged, the other
	 * approvals still roll back, and the command exits 1.
	 *
	 * @return void
	 */
	public function testAFailedClearIsReportedAndFailsTheCommand(): void {
		$broken = $this->approval('appr-1', ['status' => 'pending', 'taskUuid' => 'task-1', 'locked' => true]);
		$fine = $this->approval('appr-2', ['status' => 'pending', 'taskUuid' => 'task-2']);
		$this->objectService->method('findAll')->willReturn(['not-an-entity', $broken, $fine]);

		$this->taskBridge->expects($this->exactly(2))->method('releaseFor');
		$this->objectService->method('saveObject')->willReturnCallback(
			static function (array $object, ...$rest): ObjectEntity {
				unset($rest);
				if (isset($object['locked']) === true) {
					throw new \RuntimeException('locked');
				}

				return new ObjectEntity();
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('warning')
			->with('RollbackApprovalTaskMirror could not clear taskUuid on approval appr-1: locked');

		$tester = new CommandTester(new RollbackApprovalTaskMirror($this->objectService, $this->taskBridge, $logger));
		$this->assertSame(1, $tester->execute([]));

		$display = $tester->getDisplay();
		$this->assertStringContainsString('Could not clear the backlink on approval appr-1: locked', $display);
		$this->assertStringContainsString('Rolled back approval appr-2 (task task-2).', $display);
		$this->assertStringContainsString('Rollback: 1 approvals rolled back, 1 failed.', $display);
	}//end testAFailedClearIsReportedAndFailsTheCommand()
}//end class
