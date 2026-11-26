<?php

/**
 * Tests for MirrorTaskCompletion.
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
 * @spec openspec/changes/approval-task-convergence/specs/approval-task-convergence/spec.md#requirement-a-talk-reaction-completes-the-mirror-task
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Approval;

use OCA\Hermiq\Service\Approval\ApprovalTaskBridge;
use OCA\Hermiq\Service\Approval\MirrorTaskCompletion;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Service\Task\TaskAnswer;
use OCA\OpenRegister\Service\Task\TaskService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use stdClass;

/**
 * Completing an approval's mirror task as its reviewer.
 *
 * @spec openspec/changes/approval-task-convergence/specs/approval-task-convergence/spec.md#requirement-a-talk-reaction-completes-the-mirror-task
 */
class MirrorTaskCompletionTest extends TestCase {

	/**
	 * The task verbs called, in order, as "verb uuid actor [outcome comment]".
	 *
	 * @var array<int, string>
	 */
	private array $calls = [];

	/**
	 * The container ids resolved.
	 *
	 * @var array<int, string>
	 */
	private array $resolved = [];

	/**
	 * A completion over a task service that refuses the verbs named in $refuse.
	 *
	 * @param array<int, string> $refuse The verbs that throw.
	 * @param object|null        $service What the container answers; the recording task service when null.
	 *
	 * @return MirrorTaskCompletion
	 */
	private function completion(array $refuse = [], ?object $service = null): MirrorTaskCompletion {
		$this->calls = [];
		$this->resolved = [];

		if ($service === null) {
			$service = $this->taskService(refuse: $refuse);
		}

		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($service): object {
				$this->resolved[] = $id;
				return $service;
			}
		);

		return new MirrorTaskCompletion(container: $container, logger: $this->createMock(originalClassName: LoggerInterface::class));
	}//end completion()

	/**
	 * A task service that records claim and completeWithAnswer, refusing the named verbs.
	 *
	 * @param array<int, string> $refuse The verbs that throw.
	 *
	 * @return TaskService
	 */
	private function taskService(array $refuse): TaskService {
		$tasks = $this->createMock(originalClassName: TaskService::class);
		$tasks->method('claim')->willReturnCallback(
			function (string $uuid, ?string $actor) use ($refuse): Task {
				$this->calls[] = 'claim ' . $uuid . ' ' . $actor;
				if (in_array('claim', $refuse, true) === true) {
					throw new RuntimeException('not a candidate');
				}

				return new Task();
			}
		);
		$tasks->method('completeWithAnswer')->willReturnCallback(
			function (string $uuid, TaskAnswer $answer, ?string $actor) use ($refuse): Task {
				$this->calls[] = 'complete ' . $uuid . ' ' . $actor . ' ' . $answer->outcome . ' ' . (string)$answer->comment;
				if (in_array('complete', $refuse, true) === true && count($this->calls) === 1) {
					throw new RuntimeException('not the assignee');
				}

				if (in_array('complete-always', $refuse, true) === true) {
					throw new RuntimeException('already terminal');
				}

				return new Task();
			}
		);

		return $tasks;
	}//end taskService()

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
	 * Complete the mirror task `task-1` of a pending approval.
	 *
	 * @param MirrorTaskCompletion $completion The completion under test.
	 * @param string               $outcome    The outcome.
	 * @param string               $decider    The deciding reviewer.
	 * @param string|null          $comment    The comment.
	 *
	 * @return bool
	 */
	private function completeMirror(MirrorTaskCompletion $completion, string $outcome, string $decider, ?string $comment = null): bool {
		return $completion->complete(approval: $this->approval(data: ['taskUuid' => 'task-1']), outcome: $outcome, deciderUid: $decider, comment: $comment);
	}//end completeMirror()

	/**
	 * An assigned mirror task is completed directly, with the outcome and comment.
	 *
	 * @return void
	 */
	public function testTheAssigneeCompletesTheTask(): void {
		$completion = $this->completion();
		$approved = $this->completeMirror(completion: $completion, outcome: 'approved', decider: 'bob');

		$this->assertTrue(condition: $approved);
		$this->assertSame(expected: ['complete task-1 bob approved '], actual: $this->calls);
		$this->assertSame(expected: [ApprovalTaskBridge::TASK_SERVICE_CLASS], actual: $this->resolved);

		$completion = $this->completion();
		$rejected = $this->completeMirror(completion: $completion, outcome: 'rejected', decider: 'bob', comment: 'Denied by reaction in Talk');

		$this->assertTrue(condition: $rejected);
		$this->assertSame(expected: ['complete task-1 bob rejected Denied by reaction in Talk'], actual: $this->calls);
	}//end testTheAssigneeCompletesTheTask()

	/**
	 * A pool task the decider may not complete yet is claimed for them, then completed.
	 *
	 * @return void
	 */
	public function testAnOfferedTaskIsClaimedThenCompleted(): void {
		$completion = $this->completion(refuse: ['complete']);
		$done = $this->completeMirror(completion: $completion, outcome: 'approved', decider: 'carol');

		$this->assertTrue(condition: $done);
		$this->assertSame(
			expected: ['complete task-1 carol approved ', 'claim task-1 carol', 'complete task-1 carol approved '],
			actual: $this->calls
		);
	}//end testAnOfferedTaskIsClaimedThenCompleted()

	/**
	 * When OpenRegister refuses the completion and the claim, hermiq decides itself.
	 *
	 * @return void
	 */
	public function testARefusedTaskFallsBack(): void {
		$completion = $this->completion(refuse: ['complete', 'claim']);
		$this->assertFalse(condition: $this->completeMirror(completion: $completion, outcome: 'approved', decider: 'carol'));
		$this->assertSame(expected: ['complete task-1 carol approved ', 'claim task-1 carol'], actual: $this->calls);

		$completion = $this->completion(refuse: ['complete-always']);
		$this->assertFalse(condition: $this->completeMirror(completion: $completion, outcome: 'approved', decider: 'carol'));
	}//end testARefusedTaskFallsBack()

	/**
	 * No mirror, no decider, no task service and an unexpected service all answer false.
	 *
	 * @return void
	 */
	public function testNothingToCompleteAnswersFalse(): void {
		$completion = $this->completion();
		$this->assertFalse(condition: $completion->complete(approval: $this->approval(data: []), outcome: 'approved', deciderUid: 'bob', comment: null));
		$blank = $this->approval(data: ['taskUuid' => ' ']);
		$this->assertFalse(condition: $completion->complete(approval: $blank, outcome: 'approved', deciderUid: 'bob', comment: null));
		$this->assertFalse(condition: $this->completeMirror(completion: $completion, outcome: 'approved', decider: ''));
		$this->assertSame(expected: [], actual: $this->resolved);

		$completion = $this->completion(service: new stdClass());
		$this->assertFalse(condition: $this->completeMirror(completion: $completion, outcome: 'approved', decider: 'bob'));

		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('no task service'));
		$completion = new MirrorTaskCompletion(container: $container, logger: $this->createMock(originalClassName: LoggerInterface::class));
		$this->assertFalse(condition: $this->completeMirror(completion: $completion, outcome: 'approved', decider: 'bob'));
	}//end testNothingToCompleteAnswersFalse()
}//end class
