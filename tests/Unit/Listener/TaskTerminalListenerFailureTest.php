<?php

/**
 * Characterisation of TaskTerminalListener's guards and failure handling
 * that TaskTerminalListenerTest leaves open: other events, tasks without
 * readable metadata, and a decision that cannot be applied, which is logged
 * with the task and approval and never thrown into OpenRegister's dispatch.
 *
 * @category Tests
 * @package  OCA\Hermiq\Tests\Unit\Listener
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Listener;

use OCA\Hermiq\Listener\TaskTerminalListener;
use OCA\Hermiq\Service\ApprovalService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Event\TaskTerminalEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Guards and failure logging of the task-terminal listener.
 *
 * @covers \OCA\Hermiq\Listener\TaskTerminalListener
 */
final class TaskTerminalListenerFailureTest extends TestCase {

	/**
	 * A completed mirror task for approval appr-1.
	 *
	 * @return Task
	 */
	private static function completedTask(): Task {
		$task = new Task();
		$task->setUuid('task-1');
		$task->setState('completed');
		$task->setOutcome('approved');
		$task->setCompletedBy('carol');
		$task->setMetadata(['hermiq' => ['approvalUuid' => ' appr-1 ']]);
		return $task;
	}//end completedTask()

	/**
	 * Any event other than the terminal-task event is ignored.
	 *
	 * @return void
	 */
	public function testOtherEventsAreIgnored(): void {
		$approvals = $this->createMock(ApprovalService::class);
		$approvals->expects($this->never())->method('loadApproval');

		(new TaskTerminalListener($approvals, $this->createMock(LoggerInterface::class)))->handle(new Event());
	}//end testOtherEventsAreIgnored()

	/**
	 * A task without metadata, with non-array hermiq metadata, or whose
	 * metadata cannot be read, is treated as foreign.
	 *
	 * @return void
	 */
	public function testTasksWithoutUsableMetadataAreForeign(): void {
		$approvals = $this->createMock(ApprovalService::class);
		$approvals->expects($this->never())->method('loadApproval');
		$listener = new TaskTerminalListener($approvals, $this->createMock(LoggerInterface::class));

		$none = self::completedTask();
		$none->setMetadata(null);
		$scalar = self::completedTask();
		$scalar->setMetadata(['hermiq' => 'appr-1']);
		$blank = self::completedTask();
		$blank->setMetadata(['hermiq' => ['approvalUuid' => '  ']]);
		$unreadable = new class extends Task {
			/**
			 * Metadata that cannot be read.
			 *
			 * @return array|null
			 */
			public function getMetadata(): ?array {
				throw new RuntimeException('corrupt json');
			}
		};
		$unreadable->setState('completed');

		foreach ([$none, $scalar, $blank, $unreadable] as $task) {
			$listener->handle(new TaskTerminalEvent(task: $task, committed: true));
		}
	}//end testTasksWithoutUsableMetadataAreForeign()

	/**
	 * A decision that cannot be applied is logged with task, approval and cause.
	 *
	 * @return void
	 */
	public function testAFailingDecisionIsLoggedNotThrown(): void {
		$approval = new ObjectEntity();
		$approval->setUuid('appr-1');
		$approvals = $this->createMock(ApprovalService::class);
		$approvals->expects($this->once())->method('loadApproval')->with('appr-1')->willReturn($approval);
		$approvals->method('approve')->willThrowException(new RuntimeException('already decided'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('error')
			->with(
				'Hermiq could not apply the decision of mirror task task-1 onto approval appr-1: already decided',
				$this->arrayHasKey('exception')
			);

		(new TaskTerminalListener($approvals, $logger))->handle(new TaskTerminalEvent(task: self::completedTask(), committed: true));
	}//end testAFailingDecisionIsLoggedNotThrown()
}//end class
