<?php

/**
 * Tests for ApprovalService deciding through the approval's mirror task.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/approval-task-convergence/tasks.md#phase-3-thin-delegate-staged
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Service\Approval\ApprovalTaskBridge;
use OCA\Hermiq\Service\Approval\MirrorTaskCompletion;
use OCA\Hermiq\Service\ApprovalService;
use OCA\Hermiq\Service\DeliveryService;
use OCA\Hermiq\Service\RedactionService;
use OCA\Hermiq\Service\ScheduleService;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * approve()/deny() complete the mirror task; applyApproval()/applyDenial() decide.
 *
 * @spec openspec/changes/approval-task-convergence/specs/approval-task-convergence/spec.md#requirement-a-hermiq-decision-completes-the-mirror-task
 */
class ApprovalServiceTaskDelegateTest extends TestCase {

	/**
	 * Everything that happened, in order.
	 *
	 * @var array<int, string>
	 */
	private array $calls = [];

	/**
	 * The approval as re-read after the task completed (null: unreadable).
	 *
	 * @var ObjectEntity|null
	 */
	private ?ObjectEntity $reloaded = null;

	/**
	 * Whether the mirror task completes.
	 *
	 * @var bool
	 */
	private bool $completes = true;

	/**
	 * Run inside the completion, as OpenRegister's terminal event would.
	 *
	 * @var callable|null
	 */
	private $onComplete = null;

	/**
	 * Build the service with a task completion, a schedule runner and recording mocks.
	 *
	 * @return ApprovalService
	 */
	private function service(): ApprovalService {
		$objects = $this->createMock(originalClassName: ObjectService::class);
		$objects->method('find')->willReturnCallback(
			function (string $id): ?ObjectEntity {
				$this->calls[] = 'find ' . $id;
				if ($id === 'sched-1') {
					$schedule = new ObjectEntity();
					$schedule->setUuid('sched-1');
					$schedule->setObject(['agentId' => 'agent-1']);
					return $schedule;
				}

				return $this->reloaded;
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object): ObjectEntity {
				$this->calls[] = 'save ' . (string)($object['status'] ?? '') . ' '
					. (string)($object['decidedBy'] ?? '') . ' ' . (string)($object['reason'] ?? '');
				return new ObjectEntity();
			}
		);

		$completion = $this->createMock(originalClassName: MirrorTaskCompletion::class);
		$completion->method('complete')->willReturnCallback(
			function (ObjectEntity $approval, string $outcome, string $deciderUid, ?string $comment): bool {
				$this->calls[] = 'task ' . $outcome . ' ' . $deciderUid . ' ' . var_export($comment, true);
				if ($this->completes === true && $this->onComplete !== null) {
					($this->onComplete)();
				}

				return $this->completes;
			}
		);

		$scheduleService = $this->createMock(originalClassName: ScheduleService::class);
		$scheduleService->method('runNow')->willReturnCallback(
			function (): void {
				$this->calls[] = 'run';
			}
		);

		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($completion, $scheduleService): mixed {
				$this->calls[] = 'resolve ' . substr($id, (int)strrpos($id, '\\') + 1);
				if ($id === MirrorTaskCompletion::class) {
					return $completion;
				}

				return $scheduleService;
			}
		);

		$bridge = $this->createMock(originalClassName: ApprovalTaskBridge::class);
		$bridge->method('releaseFor')->willReturnCallback(
			function (ObjectEntity $approval, string $decision): void {
				$this->calls[] = 'release ' . $decision;
			}
		);

		$userManager = $this->createMock(originalClassName: IUserManager::class);
		$userManager->method('get')->willReturn($this->createMock(originalClassName: IUser::class));

		return new ApprovalService(
			objectService: $objects,
			userSession: $this->createMock(originalClassName: IUserSession::class),
			userManager: $userManager,
			groupManager: $this->createMock(originalClassName: IGroupManager::class),
			deliveryService: $this->createMock(originalClassName: DeliveryService::class),
			auditTrailMapper: $this->createMock(originalClassName: AuditTrailMapper::class),
			redactionService: new RedactionService(config: $this->createMock(originalClassName: IConfig::class)),
			container: $container,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			taskBridge: $bridge,
		);
	}//end service()

	/**
	 * An approval of a gated schedule.
	 *
	 * @param string $status   The approval's status.
	 * @param string $taskUuid Its mirror task, empty for none.
	 *
	 * @return ObjectEntity
	 */
	private function approval(string $status, string $taskUuid = 'task-9'): ObjectEntity {
		$payload = ['status' => $status, 'scheduleId' => 'sched-1', 'sourceType' => 'schedule'];
		if ($taskUuid !== '') {
			$payload['taskUuid'] = $taskUuid;
		}

		$entity = new ObjectEntity();
		$entity->setUuid('appr-1');
		$entity->setOwner('alice');
		$entity->setObject($payload);
		return $entity;
	}//end approval()

	/**
	 * When OpenRegister's terminal event applies the decision in the same request,
	 * approve() reports that applied result and decides nothing itself.
	 *
	 * @return void
	 */
	public function testApproveCompletesTheTaskAndReportsTheListenersDecision(): void {
		$service = $this->service();
		$this->onComplete = function () use ($service): void {
			$this->calls[] = 'listener';
			$service->applyApproval(approval: $this->approval(status: 'pending'), deciderUid: 'bob');
			$this->reloaded = $this->approval(status: 'approved');
		};

		$result = $service->approve(approval: $this->approval(status: 'pending'), deciderUid: 'bob');

		$this->assertSame(expected: ['status' => 'approved', 'ran' => true], actual: $result);
		$this->assertSame(
			expected: [
				'resolve MirrorTaskCompletion',
				'task approved bob NULL',
				'listener',
				'save approved bob ',
				'release approved',
				'find sched-1',
				'resolve ScheduleService',
				'run',
				'find appr-1',
			],
			actual: $this->calls
		);
	}//end testApproveCompletesTheTaskAndReportsTheListenersDecision()

	/**
	 * A completed task whose approval is already decided elsewhere is not decided again.
	 *
	 * @return void
	 */
	public function testAnApprovalTheTaskAlreadyDecidedIsNotDecidedAgain(): void {
		$this->reloaded = $this->approval(status: 'approved');

		$result = $this->service()->approve(approval: $this->approval(status: 'pending'), deciderUid: 'bob');

		$this->assertSame(expected: ['status' => 'approved', 'ran' => false], actual: $result);
		$this->assertSame(expected: ['resolve MirrorTaskCompletion', 'task approved bob NULL', 'find appr-1'], actual: $this->calls);
	}//end testAnApprovalTheTaskAlreadyDecidedIsNotDecidedAgain()

	/**
	 * An approval that cannot be re-read after the completion is left alone.
	 *
	 * @return void
	 */
	public function testAnUnreadableApprovalIsLeftAlone(): void {
		$this->reloaded = null;

		$result = $this->service()->approve(approval: $this->approval(status: 'pending'), deciderUid: 'bob');

		$this->assertSame(expected: ['status' => 'approved', 'ran' => false], actual: $result);
		$this->assertNotContains(needle: 'save approved bob ', haystack: $this->calls);
		$this->assertNotContains(needle: 'run', haystack: $this->calls);
	}//end testAnUnreadableApprovalIsLeftAlone()

	/**
	 * A completed task whose terminal event never reached hermiq is applied once, from the re-read approval.
	 *
	 * @return void
	 */
	public function testAStillPendingApprovalIsAppliedFromTheReRead(): void {
		$this->reloaded = $this->approval(status: 'pending');

		$result = $this->service()->approve(approval: $this->approval(status: 'pending'), deciderUid: 'bob');

		$this->assertSame(expected: ['status' => 'approved', 'ran' => true], actual: $result);
		$this->assertSame(expected: 1, actual: count(array_keys($this->calls, 'save approved bob ', true)));
		$this->assertSame(expected: 1, actual: count(array_keys($this->calls, 'run', true)));
	}//end testAStillPendingApprovalIsAppliedFromTheReRead()

	/**
	 * A refused completion decides on hermiq's own path, which releases the mirror.
	 *
	 * @return void
	 */
	public function testARefusedCompletionApprovesOnHermiqsPath(): void {
		$this->completes = false;

		$result = $this->service()->approve(approval: $this->approval(status: 'pending'), deciderUid: 'bob');

		$this->assertSame(expected: ['status' => 'approved', 'ran' => true], actual: $result);
		$this->assertSame(
			expected: [
				'resolve MirrorTaskCompletion',
				'task approved bob NULL',
				'save approved bob ',
				'release approved',
				'find sched-1',
				'resolve ScheduleService',
				'run',
			],
			actual: $this->calls
		);
	}//end testARefusedCompletionApprovesOnHermiqsPath()

	/**
	 * An approval without a mirror never looks for the task surface.
	 *
	 * @return void
	 */
	public function testAnUnmirroredApprovalDecidesWithoutTheTask(): void {
		$result = $this->service()->approve(approval: $this->approval(status: 'pending', taskUuid: ''), deciderUid: 'bob');

		$this->assertSame(expected: ['status' => 'approved', 'ran' => true], actual: $result);
		$this->assertNotContains(needle: 'resolve MirrorTaskCompletion', haystack: $this->calls);
	}//end testAnUnmirroredApprovalDecidesWithoutTheTask()

	/**
	 * A decided approval and a question never complete the task.
	 *
	 * @return void
	 */
	public function testWhatApproveRefusesNeverReachesTheTask(): void {
		$service = $this->service();

		$decided = $service->approve(approval: $this->approval(status: 'denied'), deciderUid: 'bob');
		$this->assertSame(expected: ['status' => 'denied', 'ran' => false], actual: $decided);

		$question = $this->approval(status: 'pending');
		$question->setObject(array_merge($question->getObject(), ['sourceType' => 'question']));
		$this->assertSame(expected: ['status' => 'pending', 'ran' => false], actual: $service->approve(approval: $question, deciderUid: 'bob'));

		$this->assertSame(expected: [], actual: $this->calls);
	}//end testWhatApproveRefusesNeverReachesTheTask()

	/**
	 * deny() completes the task as rejected with the reason as its comment.
	 *
	 * @return void
	 */
	public function testDenyCompletesTheTaskWithTheReason(): void {
		$this->reloaded = $this->approval(status: 'denied');

		$this->service()->deny(approval: $this->approval(status: 'pending'), deciderUid: 'bob', reason: '  not now ');

		$this->assertSame(expected: ['resolve MirrorTaskCompletion', "task rejected bob 'not now'", 'find appr-1'], actual: $this->calls);
	}//end testDenyCompletesTheTaskWithTheReason()

	/**
	 * Without a reason the task gets no comment, and a still-pending re-read is denied once.
	 *
	 * @return void
	 */
	public function testDenyWithoutAReasonAppliesWhatTheListenerDidNot(): void {
		$this->reloaded = $this->approval(status: 'pending');

		$this->service()->deny(approval: $this->approval(status: 'pending'), deciderUid: 'bob', reason: null);

		$this->assertSame(
			expected: ['resolve MirrorTaskCompletion', 'task rejected bob NULL', 'find appr-1', 'save denied bob ', 'release denied'],
			actual: $this->calls
		);
	}//end testDenyWithoutAReasonAppliesWhatTheListenerDidNot()

	/**
	 * A refused completion denies on hermiq's own path.
	 *
	 * @return void
	 */
	public function testARefusedCompletionDeniesOnHermiqsPath(): void {
		$this->completes = false;

		$this->service()->deny(approval: $this->approval(status: 'pending'), deciderUid: 'bob', reason: 'no');

		$this->assertSame(
			expected: ['resolve MirrorTaskCompletion', "task rejected bob 'no'", 'save denied bob no', 'release denied'],
			actual: $this->calls
		);
	}//end testARefusedCompletionDeniesOnHermiqsPath()

	/**
	 * A non-pending approval is never denied, through the task or otherwise.
	 *
	 * @return void
	 */
	public function testDenyIgnoresADecidedApproval(): void {
		$this->service()->deny(approval: $this->approval(status: 'approved'), deciderUid: 'bob', reason: 'no');

		$this->assertSame(expected: [], actual: $this->calls);
	}//end testDenyIgnoresADecidedApproval()

	/**
	 * The appliers never touch the task: they are what the task's terminal event calls.
	 *
	 * @return void
	 */
	public function testTheAppliersDecideWithoutTheTask(): void {
		$service = $this->service();

		$applied = $service->applyApproval(approval: $this->approval(status: 'pending'), deciderUid: 'bob');
		$this->assertSame(expected: ['status' => 'approved', 'ran' => true], actual: $applied);
		$service->applyDenial(approval: $this->approval(status: 'pending'), deciderUid: 'bob', reason: 'no');

		$this->assertNotContains(needle: 'resolve MirrorTaskCompletion', haystack: $this->calls);
		$this->assertContains(needle: 'save denied bob no', haystack: $this->calls);
	}//end testTheAppliersDecideWithoutTheTask()
}//end class
