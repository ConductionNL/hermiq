<?php

/**
 * Hermiq ApprovalInbox.
 *
 * The reviewer's side of the human-approval gate: which pending Approvals are
 * routed to a user (the inbox), and whether a user may decide a given Approval
 * (separation of duties, ADR-004 Art. 14). Creating, approving and denying
 * Approvals stays with ApprovalService.
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
 * @spec openspec/changes/archive/2026-09-06-human-approval-gate-enforcement/tasks.md#4-approve-deny-endpoints-reviewer-admin-guarded
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Approval;

use OCA\Hermiq\Service\ApprovalPreviewBuilder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IGroupManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Lists a reviewer's pending Approvals and decides who may decide one.
 *
 * Not final, like its neighbours in this namespace: the controllers, listeners
 * and services that consult it mock it in their unit tests.
 *
 * @spec openspec/changes/archive/2026-09-06-human-approval-gate-enforcement/tasks.md#4-approve-deny-endpoints-reviewer-admin-guarded
 */
class ApprovalInbox {

	/**
	 * OpenRegister register slug that holds Hermiq approval objects.
	 *
	 * @var string
	 */
	private const REGISTER_SLUG = 'hermiq';

	/**
	 * OpenRegister schema slug for approval objects.
	 *
	 * @var string
	 */
	private const APPROVAL_SCHEMA = 'approval';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OpenRegister object reads (single read-path).
	 * @param IGroupManager $groupManager Admin check and reviewer-group membership.
	 * @param ContainerInterface $container Lazy ApprovalPreviewBuilder resolution.
	 * @param LoggerInterface $logger PSR-3 logger for an unavailable preview builder.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly IGroupManager $groupManager,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * List the pending Approvals routed to the given user as reviewer.
	 *
	 * Returns approvals where the user is the reviewer user, or a member of the
	 * reviewer group. Instance-admin visibility is deliberately NOT included — this
	 * is the "routed to me" inbox, not a global queue.
	 *
	 * The routing comes from OpenRegister's task inbox: a mirrored Approval is
	 * listed when its open mirror task is assigned to the user or offered to a
	 * group they are in, in the task inbox's order, with its `taskUuid`. An
	 * Approval without a mirror is listed by hermiq's own routing after those,
	 * and without the task inbox every Approval is.
	 *
	 * @param string $uid The requesting user's UID.
	 *
	 * @return array<int, array<string, mixed>> Compact pending-approval records.
	 *
	 * @spec openspec/changes/archive/2026-09-06-human-approval-gate-enforcement/tasks.md#4-approve-deny-endpoints-reviewer-admin-guarded
	 * @spec openspec/changes/approval-task-convergence/specs/approval-task-convergence/spec.md#requirement-the-reviewer-inbox-reads-pending-work-from-the-openregister-task-inbox
	 */
	public function listPendingForReviewer(string $uid): array {
		if ($uid === '') {
			return [];
		}

		$objects = array_values(array_filter($this->pendingApprovals(), static fn (mixed $row): bool => ($row instanceof ObjectEntity)));
		$previews = $this->previewBuilder();
		$routed = $this->taskInbox()?->routedApprovals(uid: $uid);
		if ($routed === null) {
			return $this->locallyRouted(objects: $objects, uid: $uid, previews: $previews);
		}

		$records = $this->taskRouted(
			objects: $objects,
			routed: $routed,
			reader: new ApprovalInboxReader(uid: $uid, previews: $previews)
		);

		// An Approval the bridge could not mirror has no task to route it, and
		// stays decidable on hermiq's own surface: list it from hermiq's own
		// routing. A mirrored one the task inbox did not answer is not routed here.
		$unmirrored = array_filter(
			$objects,
			static fn (ObjectEntity $object): bool => (trim((string)($object->getObject()['taskUuid'] ?? '')) === '')
		);

		return array_merge($records, $this->locallyRouted(objects: array_values($unmirrored), uid: $uid, previews: $previews));
	}//end listPendingForReviewer()

	/**
	 * The records of the routed approvals, in the task inbox's order, each with
	 * its mirror's uuid. A routed approval that is not pending for the reader by
	 * hermiq's own check is left out: a task never widens it.
	 *
	 * @param array<int, ObjectEntity>                                  $objects The pending Approvals.
	 * @param array<int, array{approvalUuid: string, taskUuid: string}> $routed  The task inbox's routing.
	 * @param ApprovalInboxReader                                       $reader  Who reads, and the preview builder.
	 *
	 * @return array<int, array<string, mixed>> The records.
	 *
	 * @spec openspec/changes/approval-task-convergence/specs/approval-task-convergence/spec.md#requirement-the-reviewer-inbox-reads-pending-work-from-the-openregister-task-inbox
	 */
	private function taskRouted(array $objects, array $routed, ApprovalInboxReader $reader): array {
		$byUuid = [];
		foreach ($objects as $object) {
			$byUuid[(string)$object->getUuid()] = $object;
		}

		$records = [];
		foreach ($routed as $entry) {
			$object = ($byUuid[$entry['approvalUuid']] ?? null);
			if ($object === null || $this->isPendingFor(object: $object, uid: $reader->uid) === false) {
				continue;
			}

			$record = $this->inboxRecord(approval: $object, previews: $reader->previews);
			$record['taskUuid'] = $entry['taskUuid'];
			$records[] = $record;
		}

		return $records;
	}//end taskRouted()

	/**
	 * OpenRegister's task inbox reader, or null when it cannot be resolved.
	 *
	 * @return ApprovalTaskInbox|null The reader, or null.
	 */
	private function taskInbox(): ?ApprovalTaskInbox {
		try {
			$taskInbox = $this->container->get(ApprovalTaskInbox::class);
		} catch (Throwable $e) {
			$this->logger->warning('Hermiq approval inbox reads hermiq\'s own routing: ' . $e->getMessage());
			return null;
		}

		if (($taskInbox instanceof ApprovalTaskInbox) === false) {
			return null;
		}

		return $taskInbox;
	}//end taskInbox()

	/**
	 * The pending Approvals hermiq's own reviewer routing sends to a user: the
	 * inbox source when OpenRegister's task inbox cannot be read.
	 *
	 * @param array<int, mixed> $objects The pending Approval rows.
	 * @param string $uid The requesting user's UID.
	 * @param ApprovalPreviewBuilder|null $previews The preview builder, when available.
	 *
	 * @return array<int, array<string, mixed>> Compact pending-approval records.
	 *
	 * @spec openspec/changes/approval-task-convergence/specs/approval-task-convergence/spec.md#requirement-the-reviewer-inbox-reads-pending-work-from-the-openregister-task-inbox
	 */
	private function locallyRouted(array $objects, string $uid, ?ApprovalPreviewBuilder $previews): array {
		$records = [];
		foreach ($objects as $object) {
			if ($this->isPendingFor(object: $object, uid: $uid) === true) {
				$records[] = $this->inboxRecord(approval: $object, previews: $previews);
			}
		}

		return $records;
	}//end locallyRouted()

	/**
	 * Whether the given user may decide the given Approval (separation of duties).
	 *
	 * Admits the resolved reviewer user, a member of the reviewer group, or a
	 * Nextcloud instance admin. The schedule owner is admitted only when they are
	 * themselves the reviewer (Art. 14 separation of duties).
	 *
	 * @param ObjectEntity $approval The approval object.
	 * @param string $uid The requesting user's UID.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/archive/2026-09-06-human-approval-gate-enforcement/tasks.md#1-approvalservice-create-pending-apply-decision
	 */
	public function isReviewer(ObjectEntity $approval, string $uid): bool {
		if ($uid === '') {
			return false;
		}

		// Instance admins may always decide.
		if ($this->groupManager->isAdmin($uid) === true) {
			return true;
		}

		return $this->userMatchesReviewer(data: $approval->getObject(), uid: $uid);
	}//end isReviewer()

	/**
	 * Every pending Approval, system-wide; the reviewer filter is applied here.
	 *
	 * @return array<int, mixed> The pending Approval rows.
	 */
	private function pendingApprovals(): array {
		return $this->objectService
			->setRegister(self::REGISTER_SLUG)
			->setSchema(self::APPROVAL_SCHEMA)
			->findAll(
				config: ['filters' => ['status' => 'pending']],
				_rbac: false,
				_multitenancy: false
			);
	}//end pendingApprovals()

	/**
	 * Whether a row is a pending Approval routed to the given user.
	 *
	 * @param mixed $object One row of the pending-approval query.
	 * @param string $uid The requesting user's UID.
	 *
	 * @return bool
	 */
	private function isPendingFor(mixed $object, string $uid): bool {
		if (($object instanceof ObjectEntity) === false) {
			return false;
		}

		$data = $object->getObject();
		if ((string)($data['status'] ?? '') !== 'pending') {
			return false;
		}

		return $this->userMatchesReviewer(data: $data, uid: $uid);
	}//end isPendingFor()

	/**
	 * The compact inbox record of one pending Approval.
	 *
	 * @param ObjectEntity $approval The pending approval.
	 * @param ApprovalPreviewBuilder|null $previews The preview builder, when available.
	 *
	 * @return array<string, mixed> The record: request fields, then tool fields and preview.
	 */
	private function inboxRecord(ObjectEntity $approval, ?ApprovalPreviewBuilder $previews): array {
		$data = $approval->getObject();
		$toolFields = [
			'toolId' => (string)($data['toolId'] ?? ''),
			// As stored: redacted before persistence, never re-read unredacted.
			'toolArguments' => $this->storedToolArguments(data: $data),
			'preview' => $previews?->build(approval: $data),
		];

		$record = array_merge($this->requestFields(approval: $approval), $toolFields);

		// Oversight-ask-and-hold: a question carries its suggested answers, a
		// draft its text and recipients, so the inbox can answer or release it.
		// Only on those two source types: every other record keeps its shape.
		$sourceType = (string)($data['sourceType'] ?? '');
		$held = $data['draftPayload'] ?? [];
		if (is_array($held) === true && $sourceType === 'question') {
			$record['question'] = (array)($held['question'] ?? []);
		}

		if (is_array($held) === true && $sourceType === 'draft') {
			$record['draft'] = $held;
		}

		return $record;
	}//end inboxRecord()

	/**
	 * The request part of an inbox record: who asked what, routed to whom.
	 *
	 * @param ObjectEntity $approval The pending approval.
	 *
	 * @return array<string, mixed> The request fields, in record order.
	 */
	private function requestFields(ObjectEntity $approval): array {
		$data = $approval->getObject();
		return [
			'id' => (string)$approval->getUuid(),
			'scheduleId' => (string)($data['scheduleId'] ?? ''),
			'agentId' => (string)($data['agentId'] ?? ''),
			'prompt' => (string)($data['prompt'] ?? ''),
			'requestedAt' => ($data['requestedAt'] ?? null),
			'reviewer' => (string)($data['reviewer'] ?? ''),
			'reviewerType' => (string)($data['reviewerType'] ?? 'user'),
			'status' => 'pending',
			'sourceType' => (string)($data['sourceType'] ?? 'schedule'),
		];
	}//end requestFields()

	/**
	 * The stored tool arguments when they are an array, otherwise null.
	 *
	 * @param array<string,mixed> $data The approval payload.
	 *
	 * @return array<string,mixed>|null
	 */
	private function storedToolArguments(array $data): ?array {
		if (is_array($data['toolArguments'] ?? null) === true) {
			return $data['toolArguments'];
		}

		return null;
	}//end storedToolArguments()

	/**
	 * The preview builder, or null when it cannot be had (the inbox then lists
	 * the approvals without their preview rather than failing).
	 *
	 * @return ApprovalPreviewBuilder|null The builder.
	 *
	 * @spec openspec/specs/human-approval-gate/spec.md#requirement-a-reviewer-sees-what-a-held-action-will-do-req-apprev-001
	 */
	private function previewBuilder(): ?ApprovalPreviewBuilder {
		try {
			$builder = $this->container->get(ApprovalPreviewBuilder::class);
		} catch (Throwable $e) {
			$this->logger->warning('[ApprovalService] approval preview unavailable: ' . $e->getMessage());
			return null;
		}

		if (($builder instanceof ApprovalPreviewBuilder) === false) {
			return null;
		}

		return $builder;
	}//end previewBuilder()

	/**
	 * Whether a user matches an approval's stored reviewer designation.
	 *
	 * @param array<string,mixed> $data The approval payload.
	 * @param string $uid The user UID.
	 *
	 * @return bool
	 */
	private function userMatchesReviewer(array $data, string $uid): bool {
		$reviewer = (string)($data['reviewer'] ?? '');
		$type = (string)($data['reviewerType'] ?? 'user');

		if ($reviewer === '') {
			return false;
		}

		if ($type === 'group') {
			return $this->groupManager->isInGroup($uid, $reviewer);
		}

		return $reviewer === $uid;
	}//end userMatchesReviewer()
}//end class
