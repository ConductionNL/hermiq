<?php

/**
 * Hermiq ApprovalService.
 *
 * The human-approval gate write-path (EU AI Act Art. 14). When the dispatcher meets a
 * schedule that requires approval it asks this service to ensure exactly ONE pending
 * `Approval` OpenRegister object exists for that schedule (idempotent — never one per
 * tick), routed to the schedule's resolved reviewer, and notifies that reviewer via
 * DeliveryService. A reviewer (or an instance admin) later approves or denies: approve
 * transitions the object to `approved` and executes the gated run by reusing
 * ScheduleService::runNow() with the approval gate bypassed for that authorised
 * occurrence; deny transitions to `denied` and never runs. Every decision is written
 * to OpenRegister's hash-chained AuditTrail after redaction.
 *
 * This is a recognised ADR-031 imperative exception: a side-effecting governance
 * service, not a derived value or declarative lifecycle. All persistence flows through
 * OpenRegister's ObjectService single write-path (ADR-001, ADR-004), so tenancy and
 * the audit trail are inherited. Hermiq owns no LLM/tool engine.
 *
 * @category Service
 * @package  OCA\Hermiq\Service
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
 * @spec openspec/changes/human-approval-gate-enforcement/tasks.md#1-approvalservice-create-pending-apply-decision
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Creates, routes, and decides Hermiq approval-gate objects via OpenRegister.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Coordinates several OR/NC services.
 *
 * @spec openspec/changes/human-approval-gate-enforcement/tasks.md#1-approvalservice-create-pending-apply-decision
 */
class ApprovalService
{

    /**
     * OpenRegister register slug that holds Hermiq objects.
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
     * OpenRegister schema slug for schedule objects.
     *
     * @var string
     */
    private const SCHEDULE_SCHEMA = 'schedule';

    /**
     * Constructor.
     *
     * @param ObjectService      $objectService    OpenRegister object read/write (single write-path).
     * @param IUserSession       $userSession      Session used to impersonate the schedule owner on save.
     * @param IUserManager       $userManager      Resolves the owner UID to an IUser.
     * @param IGroupManager      $groupManager     Resolves reviewer-group membership + instance-admin.
     * @param DeliveryService    $deliveryService  Notifies the resolved reviewer (Talk/Notifications).
     * @param AuditTrailMapper   $auditTrailMapper OR audit write-path for the decision entry.
     * @param RedactionService   $redactionService Masks the (user-supplied) reason before the audit write.
     * @param ContainerInterface $container        Server container for lazy ScheduleService resolution.
     * @param LoggerInterface    $logger           PSR-3 logger (non-fatal gate diagnostics).
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) Constructor DI: each parameter is
     *   a distinct injected collaborator, not a logic-bearing argument list.
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly IUserSession $userSession,
        private readonly IUserManager $userManager,
        private readonly IGroupManager $groupManager,
        private readonly DeliveryService $deliveryService,
        private readonly AuditTrailMapper $auditTrailMapper,
        private readonly RedactionService $redactionService,
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Idempotently ensure a single pending Approval exists for a gated schedule.
     *
     * If a pending Approval already exists for this schedule, this is a no-op — the
     * dispatcher calls it every tick while the schedule stays due, so it must NOT
     * create (or re-notify) a duplicate. Otherwise it creates one pending Approval,
     * routed to the schedule's resolved reviewer, and notifies that reviewer.
     *
     * @param ObjectEntity $schedule The gated schedule due to run.
     *
     * @return void
     *
     * @spec openspec/changes/human-approval-gate-enforcement/tasks.md#task-1-1
     * @spec openspec/changes/human-approval-gate-enforcement/tasks.md#task-1-4
     */
    public function ensurePendingApproval(ObjectEntity $schedule): void
    {
        $scheduleId = (string) $schedule->getUuid();
        if ($scheduleId === '') {
            return;
        }

        // Idempotency: never enqueue a second pending Approval for the same schedule.
        if ($this->findPendingApprovalForSchedule(scheduleId: $scheduleId) !== null) {
            return;
        }

        [$reviewer, $reviewerType] = $this->resolveReviewer(schedule: $schedule);

        $data  = $schedule->getObject();
        $owner = (string) ($schedule->getOwner() ?? '');

        $payload = [
            'status'       => 'pending',
            'scheduleId'   => $scheduleId,
            'agentId'      => (string) ($data['agentId'] ?? ''),
            'prompt'       => (string) ($data['prompt'] ?? ''),
            'requestedAt'  => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c'),
            'reviewer'     => $reviewer,
            'reviewerType' => $reviewerType,
            'decidedAt'    => null,
            'decidedBy'    => null,
            'reason'       => null,
        ];

        $approval = $this->persistApproval(data: $payload, uuid: null, owner: $owner);

        // Notify the resolved reviewer(s). Never fatal to the dispatch tick.
        try {
            $this->deliveryService->deliverApprovalRequest(
                schedule: $schedule,
                approval: $approval,
                reviewerUids: $this->reviewerUids(reviewer: $reviewer, reviewerType: $reviewerType)
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                'Hermiq could not notify reviewer for approval '.((string) $approval->getUuid()).': '.$e->getMessage(),
                ['exception' => $e]
            );
        }

    }//end ensurePendingApproval()

    /**
     * List the pending Approvals routed to the given user as reviewer.
     *
     * Returns approvals where the user is the reviewer user, or a member of the
     * reviewer group. Instance-admin visibility is deliberately NOT included — this
     * is the "routed to me" inbox, not a global queue.
     *
     * @param string $uid The requesting user's UID.
     *
     * @return array<int, array<string, mixed>> Compact pending-approval records.
     *
     * @spec openspec/changes/human-approval-gate-enforcement/tasks.md#task-4-1
     */
    public function listPendingForReviewer(string $uid): array
    {
        if ($uid === '') {
            return [];
        }

        $objects = $this->objectService
            ->setRegister(self::REGISTER_SLUG)
            ->setSchema(self::APPROVAL_SCHEMA)
            ->findAll(
                config: ['filters' => ['status' => 'pending']],
                _rbac: false,
                _multitenancy: false
            );

        $records = [];
        foreach ($objects as $object) {
            if (($object instanceof ObjectEntity) === false) {
                continue;
            }

            $data = $object->getObject();
            if ((string) ($data['status'] ?? '') !== 'pending') {
                continue;
            }

            if ($this->userMatchesReviewer(data: $data, uid: $uid) === false) {
                continue;
            }

            $records[] = [
                'id'           => (string) $object->getUuid(),
                'scheduleId'   => (string) ($data['scheduleId'] ?? ''),
                'agentId'      => (string) ($data['agentId'] ?? ''),
                'prompt'       => (string) ($data['prompt'] ?? ''),
                'requestedAt'  => ($data['requestedAt'] ?? null),
                'reviewer'     => (string) ($data['reviewer'] ?? ''),
                'reviewerType' => (string) ($data['reviewerType'] ?? 'user'),
                'status'       => 'pending',
            ];
        }//end foreach

        return $records;

    }//end listPendingForReviewer()

    /**
     * Load an Approval object by UUID without RBAC (the caller applies the guard).
     *
     * The Approval is owned by the schedule owner, but the decider is the reviewer —
     * a different user — so an RBAC read would hide it. It is loaded RBAC-off and the
     * caller MUST authorise via isReviewer() before acting (IDOR guard).
     *
     * @param string $uuid The Approval object UUID.
     *
     * @return ObjectEntity|null The approval, or null when absent.
     *
     * @spec openspec/changes/human-approval-gate-enforcement/tasks.md#task-4-1
     */
    public function loadApproval(string $uuid): ?ObjectEntity
    {
        if ($uuid === '') {
            return null;
        }

        $approval = $this->objectService->find(
            id: $uuid,
            register: self::REGISTER_SLUG,
            schema: self::APPROVAL_SCHEMA,
            _rbac: false,
            _multitenancy: false
        );

        if (($approval instanceof ObjectEntity) === false) {
            return null;
        }

        return $approval;

    }//end loadApproval()

    /**
     * Whether the given user may decide the given Approval (separation of duties).
     *
     * Admits the resolved reviewer user, a member of the reviewer group, or a
     * Nextcloud instance admin. The schedule owner is admitted only when they are
     * themselves the reviewer (Art. 14 separation of duties).
     *
     * @param ObjectEntity $approval The approval object.
     * @param string       $uid      The requesting user's UID.
     *
     * @return bool
     *
     * @spec openspec/changes/human-approval-gate-enforcement/tasks.md#task-1-2
     */
    public function isReviewer(ObjectEntity $approval, string $uid): bool
    {
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
     * Approve a pending Approval and execute the gated run.
     *
     * Transitions the Approval to `approved` (decidedBy/decidedAt), audits the
     * decision, then runs the bound schedule by reusing ScheduleService::runNow() with
     * the approval gate bypassed for this authorised occurrence — it does NOT loop
     * back into another pending Approval. A non-pending Approval is a no-op (no run).
     *
     * @param ObjectEntity $approval   The pending approval to authorise.
     * @param string       $deciderUid The reviewer/admin making the decision.
     *
     * @return array{status:string, ran:bool} The resulting status and whether a run fired.
     *
     * @spec openspec/changes/human-approval-gate-enforcement/tasks.md#task-4-2
     */
    public function approve(ObjectEntity $approval, string $deciderUid): array
    {
        $data = $approval->getObject();
        if ((string) ($data['status'] ?? '') !== 'pending') {
            return ['status' => (string) ($data['status'] ?? ''), 'ran' => false];
        }

        $data['status']    = 'approved';
        $data['decidedBy'] = $deciderUid;
        $data['decidedAt'] = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c');

        $this->persistApproval(
            data: $data,
            uuid: (string) $approval->getUuid(),
            owner: (string) ($approval->getOwner() ?? '')
        );
        $this->writeDecisionAudit(approval: $approval, action: 'approve', reason: '');

        $ran = $this->runApprovedSchedule(scheduleId: (string) ($data['scheduleId'] ?? ''));

        return ['status' => 'approved', 'ran' => $ran];

    }//end approve()

    /**
     * Deny a pending Approval — the gated run never executes.
     *
     * Transitions the Approval to `denied` (decidedBy/decidedAt/reason) and audits the
     * decision (reason redacted before the append-only write). A non-pending Approval
     * is a no-op.
     *
     * @param ObjectEntity $approval   The pending approval to deny.
     * @param string       $deciderUid The reviewer/admin making the decision.
     * @param string|null  $reason     Optional free-text denial reason.
     *
     * @return void
     *
     * @spec openspec/changes/human-approval-gate-enforcement/tasks.md#task-4-3
     */
    public function deny(ObjectEntity $approval, string $deciderUid, ?string $reason): void
    {
        $data = $approval->getObject();
        if ((string) ($data['status'] ?? '') !== 'pending') {
            return;
        }

        $cleanReason = (string) ($reason ?? '');

        $reasonValue = null;
        if ($cleanReason !== '') {
            $reasonValue = $cleanReason;
        }

        $data['status']    = 'denied';
        $data['decidedBy'] = $deciderUid;
        $data['decidedAt'] = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c');
        $data['reason']    = $reasonValue;

        $this->persistApproval(
            data: $data,
            uuid: (string) $approval->getUuid(),
            owner: (string) ($approval->getOwner() ?? '')
        );
        $this->writeDecisionAudit(approval: $approval, action: 'deny', reason: $cleanReason);

    }//end deny()

    /**
     * Find the open pending Approval for a schedule, if one exists.
     *
     * @param string $scheduleId The schedule UUID.
     *
     * @return ObjectEntity|null The pending approval, or null.
     *
     * @spec openspec/changes/human-approval-gate-enforcement/tasks.md#task-1-4
     */
    private function findPendingApprovalForSchedule(string $scheduleId): ?ObjectEntity
    {
        $objects = $this->objectService
            ->setRegister(self::REGISTER_SLUG)
            ->setSchema(self::APPROVAL_SCHEMA)
            ->findAll(
                config: ['filters' => ['scheduleId' => $scheduleId, 'status' => 'pending']],
                _rbac: false,
                _multitenancy: false
            );

        foreach ($objects as $object) {
            if (($object instanceof ObjectEntity) === false) {
                continue;
            }

            $data = $object->getObject();
            if ((string) ($data['scheduleId'] ?? '') === $scheduleId
                && (string) ($data['status'] ?? '') === 'pending'
            ) {
                return $object;
            }
        }

        return null;

    }//end findPendingApprovalForSchedule()

    /**
     * Resolve the reviewer for a schedule: [reviewer, reviewerType].
     *
     * Uses the schedule's `reviewer`/`reviewerType`; an empty reviewer defaults to the
     * schedule owner as a `user` reviewer (backward compatible). An unknown
     * `reviewerType` collapses to `user`.
     *
     * @param ObjectEntity $schedule The gated schedule.
     *
     * @return array{0:string, 1:string} The [reviewer, reviewerType] pair.
     *
     * @spec openspec/changes/human-approval-gate-enforcement/tasks.md#task-1-2
     */
    private function resolveReviewer(ObjectEntity $schedule): array
    {
        $data     = $schedule->getObject();
        $reviewer = trim((string) ($data['reviewer'] ?? ''));
        $type     = (string) ($data['reviewerType'] ?? 'user');

        if ($reviewer === '') {
            $reviewer = (string) ($schedule->getOwner() ?? '');
            $type     = 'user';
        }

        if ($type !== 'group') {
            $type = 'user';
        }

        return [$reviewer, $type];

    }//end resolveReviewer()

    /**
     * Expand a reviewer designation into the set of user ids to notify.
     *
     * @param string $reviewer     The reviewer user id or group id.
     * @param string $reviewerType The reviewer type (`user`|`group`).
     *
     * @return array<int, string> The reviewer user ids.
     *
     * @spec openspec/changes/human-approval-gate-enforcement/tasks.md#task-2-2
     */
    private function reviewerUids(string $reviewer, string $reviewerType): array
    {
        if ($reviewerType === 'group') {
            $group = $this->groupManager->get($reviewer);
            if ($group === null) {
                return [];
            }

            $uids = [];
            foreach ($group->getUsers() as $user) {
                $uids[] = $user->getUID();
            }

            return $uids;
        }

        if ($reviewer === '') {
            return [];
        }

        return [$reviewer];

    }//end reviewerUids()

    /**
     * Whether a user matches an approval's stored reviewer designation.
     *
     * @param array<string,mixed> $data The approval payload.
     * @param string              $uid  The user UID.
     *
     * @return bool
     */
    private function userMatchesReviewer(array $data, string $uid): bool
    {
        $reviewer = (string) ($data['reviewer'] ?? '');
        $type     = (string) ($data['reviewerType'] ?? 'user');

        if ($reviewer === '') {
            return false;
        }

        if ($type === 'group') {
            return $this->groupManager->isInGroup($uid, $reviewer);
        }

        return $reviewer === $uid;

    }//end userMatchesReviewer()

    /**
     * Run an approved schedule via the shared dispatch path, bypassing the gate.
     *
     * ScheduleService is resolved lazily from the server container so the two
     * services need no circular constructor dependency (mirrors DeliveryService's
     * lazy Talk resolution). The bypass runs THIS authorised occurrence without
     * re-creating a pending Approval; the kill-switch still applies inside dispatch().
     *
     * @param string $scheduleId The bound schedule UUID.
     *
     * @return bool Whether a run was dispatched.
     *
     * @spec openspec/changes/human-approval-gate-enforcement/tasks.md#task-4-2
     */
    private function runApprovedSchedule(string $scheduleId): bool
    {
        if ($scheduleId === '') {
            return false;
        }

        $schedule = $this->objectService->find(
            id: $scheduleId,
            register: self::REGISTER_SLUG,
            schema: self::SCHEDULE_SCHEMA,
            _rbac: false,
            _multitenancy: false
        );

        if (($schedule instanceof ObjectEntity) === false) {
            $this->logger->warning(
                'Hermiq approved schedule '.$scheduleId.' could not be loaded to run.'
            );
            return false;
        }

        $scheduleService = $this->container->get(ScheduleService::class);
        $scheduleService->runNow(schedule: $schedule, bypassApprovalGate: true);
        return true;

    }//end runApprovedSchedule()

    /**
     * Persist an Approval payload through OpenRegister, impersonating the owner.
     *
     * The Approval must be owned by (and tenant-scoped to) the schedule owner, so the
     * owner is impersonated around the save exactly as the dispatcher impersonates the
     * owner around the agent turn. The prior session user is always restored.
     *
     * @param array<string,mixed> $data  The approval payload.
     * @param string|null         $uuid  The target UUID (null to create).
     * @param string              $owner The schedule owner UID to impersonate.
     *
     * @return ObjectEntity The persisted approval.
     *
     * @spec openspec/changes/human-approval-gate-enforcement/tasks.md#task-1-1
     */
    private function persistApproval(array $data, ?string $uuid, string $owner): ObjectEntity
    {
        $priorUser = $this->userSession->getUser();

        $user = null;
        if ($owner !== '') {
            $user = $this->userManager->get($owner);
        }

        if ($user !== null) {
            $this->userSession->setUser($user);
        }

        try {
            return $this->objectService->saveObject(
                object: $data,
                register: self::REGISTER_SLUG,
                schema: self::APPROVAL_SCHEMA,
                uuid: $uuid,
                _rbac: false,
                _multitenancy: false
            );
        } finally {
            $this->userSession->setUser($priorUser);
        }

    }//end persistApproval()

    /**
     * Write the decision AuditTrail entry via OpenRegister (redaction-before-persist).
     *
     * The (user-supplied) reason is redacted before it enters the append-only,
     * hash-chained trail. Non-fatal by contract: an audit failure is logged, not
     * raised, so it never fails a decision.
     *
     * @param ObjectEntity $approval The approval the decision is about.
     * @param string       $action   The audit action (`approve`|`deny`).
     * @param string       $reason   The raw reason (redacted here).
     *
     * @return void
     *
     * @spec openspec/changes/human-approval-gate-enforcement/tasks.md#task-1-3
     */
    private function writeDecisionAudit(ObjectEntity $approval, string $action, string $reason): void
    {
        try {
            $status = 'denied';
            if ($action === 'approve') {
                $status = 'approved';
            }

            $context = [
                'status'    => $status,
                'decidedAt' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c'),
            ];

            if ($reason !== '') {
                // REDACTION-BEFORE-PERSIST: mask secrets/PII before the append-only write.
                $context['reason'] = $this->redactionService->redact($reason);
            }

            $this->auditTrailMapper->createAuditTrailEntry(
                object: $approval,
                action: $action,
                context: $context
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                'Hermiq could not write decision audit for approval '
                .((string) $approval->getUuid()).': '.$e->getMessage(),
                ['exception' => $e]
            );
        }//end try

    }//end writeDecisionAudit()
}//end class
