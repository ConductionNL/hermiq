<?php

/**
 * Hermiq MirrorTaskCompletion.
 *
 * Completes an approval's mirror task in OpenRegister on behalf of the
 * deciding reviewer, so a decision taken outside the task inbox still lands
 * on the one decision path: the task completes, OpenRegister raises its
 * terminal event, and hermiq's TaskTerminalListener applies the decision.
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
 * @spec openspec/changes/approval-task-convergence/specs/approval-task-convergence/spec.md#requirement-a-talk-reaction-completes-the-mirror-task
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Approval;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Task\TaskAnswer;
use OCA\OpenRegister\Service\Task\TaskService;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Complete the mirror task of a pending approval as its reviewer.
 *
 * OpenRegister is reached by class-name string, like ApprovalTaskBridge:
 * hermiq loads and decides without it, and every refusal or absence answers
 * false so the caller applies the decision on hermiq's own path instead.
 *
 * @spec openspec/changes/approval-task-convergence/specs/approval-task-convergence/spec.md#requirement-a-talk-reaction-completes-the-mirror-task
 */
class MirrorTaskCompletion {

	/**
	 * OpenRegister's answer value object for a completing verb.
	 *
	 * @var string
	 */
	public const TASK_ANSWER_CLASS = 'OCA\\OpenRegister\\Service\\Task\\TaskAnswer';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves OpenRegister's TaskService at call time.
	 * @param LoggerInterface    $logger    Why a completion fell back to hermiq.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Complete the approval's mirror task with $outcome as $deciderUid.
	 *
	 * A task offered to a group is claimed for the decider first, because only
	 * the assignee may complete a task. Answers false, having changed nothing
	 * hermiq relies on, when the approval has no mirror, OpenRegister has no
	 * task surface, or OpenRegister refuses either verb.
	 *
	 * @param ObjectEntity $approval   The pending approval.
	 * @param string       $outcome    `approved` or `rejected`.
	 * @param string       $deciderUid The reviewer deciding.
	 * @param string|null  $comment    Why, mandatory for a rejecting outcome.
	 *
	 * @return bool True when the mirror task was completed.
	 *
	 * @spec openspec/changes/approval-task-convergence/specs/approval-task-convergence/spec.md#requirement-a-talk-reaction-completes-the-mirror-task
	 */
	public function complete(ObjectEntity $approval, string $outcome, string $deciderUid, ?string $comment): bool {
		$taskUuid = trim((string)($approval->getObject()['taskUuid'] ?? ''));
		if ($taskUuid === '' || $deciderUid === '') {
			return false;
		}

		$tasks = $this->taskService();
		if ($tasks === null) {
			return false;
		}

		$answer = new TaskAnswer(outcome: $outcome, resultText: null, comment: $comment);

		try {
			$tasks->completeWithAnswer(uuid: $taskUuid, answer: $answer, actor: $deciderUid);
			return true;
		} catch (Throwable $first) {
			return $this->claimAndComplete(tasks: $tasks, task: new MirrorTaskRef(uuid: $taskUuid, actor: $deciderUid), answer: $answer, first: $first);
		}
	}//end complete()

	/**
	 * Claim an offered task for the decider and complete it.
	 *
	 * @param TaskService   $tasks  OpenRegister's task service.
	 * @param MirrorTaskRef $task   The mirror task and the deciding reviewer.
	 * @param TaskAnswer    $answer The outcome and comment.
	 * @param Throwable     $first  Why the direct completion was refused.
	 *
	 * @return bool True when the claim and the completion both succeeded.
	 */
	private function claimAndComplete(TaskService $tasks, MirrorTaskRef $task, TaskAnswer $answer, Throwable $first): bool {
		try {
			$tasks->claim(uuid: $task->uuid, actor: $task->actor);
			$tasks->completeWithAnswer(uuid: $task->uuid, answer: $answer, actor: $task->actor);
			return true;
		} catch (Throwable $second) {
			$this->logger->info(
				'Hermiq decides approval task ' . $task->uuid . ' on its own path: OpenRegister refused the completion ('
				. $first->getMessage() . ') and the claim (' . $second->getMessage() . ').'
			);
			return false;
		}
	}//end claimAndComplete()

	/**
	 * OpenRegister's TaskService, or null when it is absent.
	 *
	 * @return TaskService|null
	 */
	private function taskService(): ?TaskService {
		if (class_exists(ApprovalTaskBridge::TASK_SERVICE_CLASS) === false || class_exists(self::TASK_ANSWER_CLASS) === false) {
			return null;
		}

		try {
			$service = $this->container->get(ApprovalTaskBridge::TASK_SERVICE_CLASS);
		} catch (Throwable $e) {
			$this->logger->info('Hermiq decides approvals on its own path: ' . $e->getMessage());
			return null;
		}

		// An OpenRegister without the completing verbs throws on the call, which complete() catches.
		if (($service instanceof TaskService) === false) {
			return null;
		}

		return $service;
	}//end taskService()
}//end class
