<?php

/**
 * Hermiq ApprovalTaskBridge.
 *
 * The ONE file on hermiq's approval write-path that touches OpenRegister's
 * task surface (approval-task convergence, phase 1). Every pending Approval
 * is mirrored as exactly one OpenRegister task on the trusted
 * `TaskService::import()` path, so the shared task inbox, task notifications
 * and the CalDAV VTODO projection become decision surfaces for hermiq's
 * approval gate; a decision made in hermiq releases the mirror so it never
 * dangles in anyone's inbox.
 *
 * `TaskService` is resolved from the container by FQN string at CALL time,
 * never constructor-injected: an older OpenRegister that ships
 * `ObjectService` but no `Task` namespace must not fatal hermiq's approval
 * gate. Absence degrades to a logged no-op and the Approval object remains
 * authoritative (design.md Decision 3). This is not a fail-open
 * authorization path: no decision is ever derived from the bridge's absence;
 * the pending Approval is created before the bridge is consulted and stays
 * decidable on hermiq's own guarded surface.
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
 * @spec openspec/changes/approval-task-convergence/specs/approval-task-convergence/spec.md#requirement-every-pending-approval-is-mirrored-as-one-openregister-task
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Approval;

use OCA\Hermiq\Kernel\Contracts\ApprovalTaskBridgeInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Task\TaskService;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Mirrors pending Approvals as OpenRegister tasks and releases the mirrors.
 *
 * @spec openspec/changes/approval-task-convergence/specs/approval-task-convergence/spec.md#requirement-every-pending-approval-is-mirrored-as-one-openregister-task
 */
class ApprovalTaskBridge implements ApprovalTaskBridgeInterface {

	/**
	 * FQN of OpenRegister's task service, as a string on purpose: a cross-app
	 * class name is a runtime lookup, and an import would hard-couple hermiq's
	 * boot to a namespace an older OpenRegister does not ship.
	 *
	 * @var string
	 */
	public const TASK_SERVICE_CLASS = 'OCA\\OpenRegister\\Service\\Task\\TaskService';

	/**
	 * The system seat recorded as the mirror task's requester. Never the
	 * reviewer: OpenRegister's separation-of-duties guard must not mistake
	 * the initiating system for the decider (design.md Decision 3).
	 *
	 * @var string
	 */
	public const REQUESTER = 'hermiq:approval-gate';

	/**
	 * The actor recorded when hermiq releases a mirror after its own decision.
	 *
	 * @var string
	 */
	public const RELEASE_SOURCE = 'hermiq:approval-gate';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Lazy, call-time TaskService resolution.
	 * @param LoggerInterface $logger PSR-3 logger (degraded-surface diagnostics).
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Ensure the OpenRegister mirror task for a pending Approval, returning
	 * its uuid (the caller persists it onto the Approval as `taskUuid`).
	 *
	 * Idempotent through the caller's guard: an Approval already carrying a
	 * `taskUuid` is returned as-is without touching the task surface. Never
	 * throws: a missing or older task surface logs a warning and returns
	 * null, and the approval write-path proceeds unmirrored.
	 *
	 * @param ObjectEntity $approval The just-persisted pending Approval.
	 *
	 * @return string|null The mirror task's uuid, or null when unmirrored.
	 *
	 * @spec openspec/changes/approval-task-convergence/specs/approval-task-convergence/spec.md#requirement-every-pending-approval-is-mirrored-as-one-openregister-task
	 */
	public function ensureTaskFor(ObjectEntity $approval): ?string {
		$data = $approval->getObject();
		$existing = trim((string)($data['taskUuid'] ?? ''));
		if ($existing !== '') {
			return $existing;
		}

		$approvalUuid = $this->pendingApprovalUuid(approval: $approval);
		if ($approvalUuid === null) {
			return null;
		}

		try {
			return $this->importTask(approvalUuid: $approvalUuid, data: $data);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Hermiq could not mirror approval ' . $approvalUuid
				. ' as an OpenRegister task: ' . $e->getMessage(),
				['exception' => $e]
			);

			return null;
		}//end try

	}//end ensureTaskFor()

	/**
	 * The Approval's uuid when it is pending and has one, otherwise null.
	 *
	 * @param ObjectEntity $approval The Approval.
	 *
	 * @return string|null
	 */
	private function pendingApprovalUuid(ObjectEntity $approval): ?string {
		if ((string)($approval->getObject()['status'] ?? '') !== 'pending') {
			return null;
		}

		$approvalUuid = (string)$approval->getUuid();
		if ($approvalUuid === '') {
			return null;
		}

		return $approvalUuid;
	}//end pendingApprovalUuid()

	/**
	 * Import the mirror task on the trusted path and answer its uuid, or null when
	 * there is no task surface or the task came back without one.
	 *
	 * @param string $approvalUuid The Approval's uuid.
	 * @param array<string,mixed> $data The Approval's payload.
	 *
	 * @return string|null
	 */
	private function importTask(string $approvalUuid, array $data): ?string {
		$tasks = $this->taskService();
		if ($tasks === null) {
			return null;
		}

		$task = $tasks->import(
			data: $this->taskData(approvalUuid: $approvalUuid, data: $data),
			actor: self::REQUESTER
		);

		$uuid = (string)$task->getUuid();
		if ($uuid === '') {
			return null;
		}

		return $uuid;
	}//end importTask()

	/**
	 * Release an Approval's mirror after a decision on hermiq's own surface:
	 * the task is terminated as moot so it leaves every OpenRegister inbox.
	 *
	 * Idempotent both ways: no `taskUuid` is a no-op, and OpenRegister's
	 * `terminateAsMoot()` returns an already-terminal task untouched, which
	 * is exactly the case when the decision CAME from the task (design.md
	 * Decision 1). Never throws.
	 *
	 * @param ObjectEntity $approval The decided Approval.
	 * @param string $decision The decision applied (`approved`|`denied`).
	 * @param string $deciderUid The deciding user.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/approval-task-convergence/specs/approval-task-convergence/spec.md#requirement-a-hermiq-decision-releases-the-mirror
	 */
	public function releaseFor(ObjectEntity $approval, string $decision, string $deciderUid): void {
		$data = $approval->getObject();
		$taskUuid = trim((string)($data['taskUuid'] ?? ''));
		if ($taskUuid === '') {
			return;
		}

		try {
			$tasks = $this->taskService();
			if ($tasks === null) {
				return;
			}

			$tasks->terminateAsMoot(
				uuid: $taskUuid,
				reason: 'Decided in hermiq: ' . $decision . ' by ' . $deciderUid,
				source: self::RELEASE_SOURCE
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Hermiq could not release mirror task ' . $taskUuid
				. ' for approval ' . ((string)$approval->getUuid()) . ': ' . $e->getMessage(),
				['exception' => $e]
			);
		}

	}//end releaseFor()

	/**
	 * Build the mirror task's creation payload (the UserTaskConfig
	 * conventions: an assigned user reviewer makes an `active` task, a group
	 * reviewer an `enabled` one offered to the group).
	 *
	 * @param string $approvalUuid The Approval's uuid (backlink + anchor).
	 * @param array<string,mixed> $data The Approval's payload.
	 *
	 * @return array<string,mixed> The `TaskService::import()` data.
	 *
	 * @spec openspec/changes/approval-task-convergence/specs/approval-task-convergence/spec.md#requirement-every-pending-approval-is-mirrored-as-one-openregister-task
	 */
	private function taskData(string $approvalUuid, array $data): array {
		$sourceType = (string)($data['sourceType'] ?? 'schedule');
		$routing = $this->reviewerRouting(data: $data);

		return [
			'title' => $this->taskTitle(data: $data),
			'description' => $this->taskDescription(data: $data),
			'state' => $routing['state'],
			'performerType' => $routing['performerType'],
			'priority' => 'high',
			'assignee' => $routing['assignee'],
			'candidateGroups' => $routing['candidateGroups'],
			'requester' => self::REQUESTER,
			'objectUuid' => $approvalUuid,
			'metadata' => $this->taskMetadata(approvalUuid: $approvalUuid, sourceType: $sourceType),
		];

	}//end taskData()

	/**
	 * The mirror task's hermiq backlink.
	 *
	 * @param string $approvalUuid The Approval's uuid.
	 * @param string $sourceType The gated action's source type.
	 *
	 * @return array{hermiq: array{approvalUuid: string, sourceType: string}}
	 */
	private function taskMetadata(string $approvalUuid, string $sourceType): array {
		return [
			'hermiq' => [
				'approvalUuid' => $approvalUuid,
				'sourceType' => $sourceType,
			],
		];
	}//end taskMetadata()

	/**
	 * Who the mirror task goes to: an assigned user reviewer makes an `active` task
	 * for that user, a group reviewer an `enabled` one offered to the group, and no
	 * reviewer an `enabled` group task offered to nobody.
	 *
	 * @param array<string,mixed> $data The Approval's payload.
	 *
	 * @return array{state: string, performerType: string, assignee: string|null, candidateGroups: array<int, string>|null}
	 */
	private function reviewerRouting(array $data): array {
		$reviewer = trim((string)($data['reviewer'] ?? ''));
		$reviewerType = (string)($data['reviewerType'] ?? 'user');

		if ($reviewer === '') {
			return ['state' => 'enabled', 'performerType' => 'group', 'assignee' => null, 'candidateGroups' => null];
		}

		if ($reviewerType === 'group') {
			return ['state' => 'enabled', 'performerType' => 'group', 'assignee' => null, 'candidateGroups' => [$reviewer]];
		}

		return ['state' => 'active', 'performerType' => 'user', 'assignee' => $reviewer, 'candidateGroups' => null];
	}//end reviewerRouting()

	/**
	 * The mirror task's title: the source type, and the agent when there is one.
	 *
	 * @param array<string,mixed> $data The Approval's payload.
	 *
	 * @return string
	 */
	private function taskTitle(array $data): string {
		$title = 'Hermiq approval: ' . (string)($data['sourceType'] ?? 'schedule');
		$agentId = trim((string)($data['agentId'] ?? ''));
		if ($agentId !== '') {
			$title .= ' (agent ' . $agentId . ')';
		}

		return $title;
	}//end taskTitle()

	/**
	 * The mirror task's description: the gated action, and the prompt when there is one.
	 *
	 * @param array<string,mixed> $data The Approval's payload.
	 *
	 * @return string
	 */
	private function taskDescription(array $data): string {
		$description = 'A gated hermiq ' . (string)($data['sourceType'] ?? 'schedule') . ' action awaits your approval.';
		$prompt = trim((string)($data['prompt'] ?? ''));
		if ($prompt !== '') {
			$description .= "\n\n" . $prompt;
		}

		return $description;
	}//end taskDescription()

	/**
	 * Resolve OpenRegister's TaskService, or null when this OpenRegister does
	 * not ship the task surface. Deliberately quiet on ContainerInterface
	 * misses (the degraded install IS the documented branch); anything else
	 * bubbles to the callers' logged catch.
	 *
	 * @return TaskService|null The task service, or null when unavailable.
	 *
	 * @spec openspec/changes/approval-task-convergence/specs/approval-task-convergence/spec.md#requirement-every-pending-approval-is-mirrored-as-one-openregister-task
	 */
	private function taskService(): ?TaskService {
		if (class_exists(self::TASK_SERVICE_CLASS) === false) {
			$this->logger->warning(
				'Hermiq approval-task mirroring is inactive: this OpenRegister does not ship '
				. self::TASK_SERVICE_CLASS . '.'
			);

			return null;
		}

		$service = $this->container->get(self::TASK_SERVICE_CLASS);
		if (($service instanceof TaskService) === false) {
			return null;
		}

		return $service;

	}//end taskService()
}//end class
