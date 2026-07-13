<?php

/**
 * Hermiq ApprovalService.
 *
 * The human-approval gate write-path (EU AI Act Art. 14). Gates two kinds of run:
 *
 * - A **scheduled** run (`sourceType: "schedule"`, the original shape): when the
 *   dispatcher meets a schedule that requires approval it asks this service to
 *   ensure exactly ONE pending `Approval` OpenRegister object exists for that
 *   schedule (idempotent — never one per tick), routed to the schedule's
 *   resolved reviewer. Approval re-runs via `ScheduleService::runNow()` with the
 *   approval gate bypassed.
 * - A **flow-triggered** run (`sourceType: "flow"`, from OpenRegister's
 *   `AgentRunRequestedEvent` — ADR-041): `FlowAgentRunService` asks this service
 *   to ensure a pending Approval carrying the run's resume context
 *   (`flowContext`), keyed by the event's `correlationId` for idempotency.
 *   Approval re-runs via `FlowAgentRunService::run()` with the gate bypassed.
 * - An **un-granted destructive tool invocation** (`sourceType: "tool"`,
 *   agent-tool-governance-and-disclosure): `FacadeToolInvoker` asks this service
 *   to ensure a pending Approval for a specific (agentId, toolId) pair mid-run,
 *   keyed by that pair for idempotency. There is no run to auto-resume on
 *   approval (the chat turn that attempted the call has already returned) — the
 *   decision simply flips to `approved`/`denied` so the NEXT invocation attempt
 *   of that (agentId, toolId) pair — `FacadeToolInvoker::handleGatedInvocation()`
 *   — proceeds or is blocked permanently.
 *
 * Either way, a reviewer (or an instance admin) later approves or denies:
 * approve transitions the object to `approved`, audits the decision, and
 * dispatches the resume path matching `sourceType`; deny transitions to
 * `denied` and never runs. Every decision is written to OpenRegister's
 * hash-chained AuditTrail after redaction.
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
     * OpenRegister schema slug for agent objects (tool-invocation approvals'
     * owner-impersonation lookup — agent-tool-governance-and-disclosure).
     *
     * @var string
     */
    private const AGENT_SCHEMA = 'agent';

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
            'sourceType'   => 'schedule',
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
     * Idempotently ensure a single pending Approval exists for a gated
     * flow-triggered agent run (OpenRegister's `AgentRunRequestedEvent`, ADR-041).
     *
     * Mirrors `ensurePendingApproval()` for the schedule case, but the run has no
     * "schedule owner" to impersonate or notify through the same reviewer-resolution
     * path — the reviewer defaults to the agent's own `owner` (the same account the
     * run itself acts as; see `FlowAgentRunService`), falling back to the `admin`
     * group when the agent has no owner. Idempotency is keyed by the event's
     * `correlationId` rather than a `scheduleId` (there is no Schedule object here).
     *
     * @param array<string,mixed> $context    The flow-run payload (subjectUuid/subjectRegister/
     *                                        subjectSchema/agent/skill/prompt/resultField/mode/
     *                                        flowName/correlationId) from
     *                                        AgentRunRequestedEvent::getPayload().
     * @param string              $agentOwner The agent's acting user (reviewer default + impersonation).
     *
     * @return ObjectEntity The pending (or already-pending) Approval.
     *
     * @spec openspec/changes/flow-agent-listener/tasks.md#task-3-1
     */
    public function ensurePendingApprovalForFlowRun(array $context, string $agentOwner): ObjectEntity
    {
        $correlationId = (string) ($context['correlationId'] ?? '');

        if ($correlationId !== '') {
            $existing = $this->findPendingApprovalForCorrelation(correlationId: $correlationId);
            if ($existing !== null) {
                return $existing;
            }
        }

        $reviewer     = $agentOwner;
        $reviewerType = 'user';
        if ($reviewer === '') {
            $reviewer     = 'admin';
            $reviewerType = 'group';
        }

        $payload = [
            'status'        => 'pending',
            'sourceType'    => 'flow',
            'correlationId' => $correlationId,
            'flowContext'   => $context,
            'agentId'       => (string) ($context['agent'] ?? ''),
            'prompt'        => (string) ($context['prompt'] ?? ''),
            'requestedAt'   => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c'),
            'reviewer'      => $reviewer,
            'reviewerType'  => $reviewerType,
            'decidedAt'     => null,
            'decidedBy'     => null,
            'reason'        => null,
        ];

        $approval = $this->persistApproval(data: $payload, uuid: null, owner: $agentOwner);

        // Notify the resolved reviewer(s). Never fatal to the run.
        try {
            $this->deliveryService->deliverApprovalRequestForFlowRun(
                approval: $approval,
                reviewerUids: $this->reviewerUids(reviewer: $reviewer, reviewerType: $reviewerType)
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                'Hermiq could not notify reviewer for flow-run approval '
                .((string) $approval->getUuid()).': '.$e->getMessage(),
                ['exception' => $e]
            );
        }

        return $approval;

    }//end ensurePendingApprovalForFlowRun()

    /**
     * Idempotently ensure a single pending Approval exists for an un-granted
     * destructive tool invocation attempted mid-run (agent-tool-governance-and-disclosure,
     * EU AI Act Art. 14). Mirrors `ensurePendingApprovalForFlowRun()`'s idempotent-ensure
     * shape, keyed by the (agentId, toolId) pair instead of a correlation id — a
     * repeated attempt of the SAME tool by the SAME agent while a decision is
     * still pending returns the SAME Approval, never a duplicate.
     *
     * The reviewer defaults to the agent's own owner (mirrors the flow-run
     * default), falling back to the `admin` group when the agent has no owner
     * or cannot be resolved.
     *
     * @param string               $agentId   The agent UUID attempting the invocation.
     * @param string               $toolId    The full namespaced tool id (dotted `mcpId` form).
     * @param array<string, mixed> $arguments The invocation's arguments (never persisted
     *                                        verbatim — only a short summary is stored,
     *                                        matching the ADR-063 audit's "never raw
     *                                        payloads" posture).
     *
     * @return ObjectEntity The pending (or already-pending) Approval.
     *
     * @spec openspec/changes/agent-tool-governance-and-disclosure/specs/human-approval-gate/spec.md#scenario-an-agent-attempts-an-un-granted-destructive-tool-call
     */
    public function ensurePendingApprovalForToolInvocation(string $agentId, string $toolId, array $arguments): ObjectEntity
    {
        $existing = $this->findPendingApprovalForToolInvocation(agentId: $agentId, toolId: $toolId);
        if ($existing !== null) {
            return $existing;
        }

        $owner        = $this->resolveAgentOwner(agentId: $agentId);
        $reviewer     = $owner;
        $reviewerType = 'user';
        if ($reviewer === '') {
            $reviewer     = 'admin';
            $reviewerType = 'group';
        }

        $payload = [
            'status'       => 'pending',
            'sourceType'   => 'tool',
            'agentId'      => $agentId,
            'toolId'       => $toolId,
            'prompt'       => $this->summarizeArguments(arguments: $arguments),
            'requestedAt'  => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c'),
            'reviewer'     => $reviewer,
            'reviewerType' => $reviewerType,
            'decidedAt'    => null,
            'decidedBy'    => null,
            'reason'       => null,
        ];

        $approval = $this->persistApproval(data: $payload, uuid: null, owner: $owner);

        try {
            $this->deliveryService->deliverApprovalRequestForToolInvocation(
                approval: $approval,
                reviewerUids: $this->reviewerUids(reviewer: $reviewer, reviewerType: $reviewerType)
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                'Hermiq could not notify reviewer for tool-invocation approval '
                .((string) $approval->getUuid()).': '.$e->getMessage(),
                ['exception' => $e]
            );
        }

        return $approval;

    }//end ensurePendingApprovalForToolInvocation()

    /**
     * Find the most recently DECIDED (`approved` or `denied`) Approval for a
     * (agentId, toolId) tool-invocation pair, if one exists — consulted by
     * `FacadeToolInvoker` before creating a new pending Approval, so an already
     * `approved` pair proceeds and an already `denied` pair blocks permanently
     * without re-prompting a reviewer.
     *
     * @param string $agentId The agent UUID.
     * @param string $toolId  The full namespaced tool id.
     *
     * @return ObjectEntity|null The most recent decided approval, or null when none exists.
     *
     * @spec openspec/changes/agent-tool-governance-and-disclosure/specs/human-approval-gate/spec.md#scenario-an-explicitly-granted-destructive-tool-call-is-not-re-gated
     */
    public function findDecidedApprovalForToolInvocation(string $agentId, string $toolId): ?ObjectEntity
    {
        $candidates = $this->toolInvocationApprovals(agentId: $agentId, toolId: $toolId);

        $latest     = null;
        $latestTime = '';
        foreach ($candidates as $object) {
            $data   = $object->getObject();
            $status = (string) ($data['status'] ?? '');
            if ($status !== 'approved' && $status !== 'denied') {
                continue;
            }

            $decidedAt = (string) ($data['decidedAt'] ?? '');
            if ($latest === null || $decidedAt > $latestTime) {
                $latest     = $object;
                $latestTime = $decidedAt;
            }
        }

        return $latest;

    }//end findDecidedApprovalForToolInvocation()

    /**
     * Find the open pending Approval for a (agentId, toolId) tool-invocation
     * pair, if one exists — the tool-invocation counterpart to
     * `findPendingApprovalForSchedule()`/`findPendingApprovalForCorrelation()`.
     *
     * @param string $agentId The agent UUID.
     * @param string $toolId  The full namespaced tool id.
     *
     * @return ObjectEntity|null The pending approval, or null.
     */
    private function findPendingApprovalForToolInvocation(string $agentId, string $toolId): ?ObjectEntity
    {
        foreach ($this->toolInvocationApprovals(agentId: $agentId, toolId: $toolId) as $object) {
            if ((string) ($object->getObject()['status'] ?? '') === 'pending') {
                return $object;
            }
        }

        return null;

    }//end findPendingApprovalForToolInvocation()

    /**
     * Load every Approval recorded for a (agentId, toolId) tool-invocation pair
     * (any status), RBAC-off (the caller applies whatever guard it needs).
     *
     * @param string $agentId The agent UUID.
     * @param string $toolId  The full namespaced tool id.
     *
     * @return array<int, ObjectEntity>
     */
    private function toolInvocationApprovals(string $agentId, string $toolId): array
    {
        if ($agentId === '' || $toolId === '') {
            return [];
        }

        $objects = $this->objectService
            ->setRegister(self::REGISTER_SLUG)
            ->setSchema(self::APPROVAL_SCHEMA)
            ->findAll(
                config: ['filters' => ['agentId' => $agentId, 'toolId' => $toolId, 'sourceType' => 'tool']],
                _rbac: false,
                _multitenancy: false
            );

        $matches = [];
        foreach ($objects as $object) {
            if (($object instanceof ObjectEntity) === false) {
                continue;
            }

            $data = $object->getObject();
            if ((string) ($data['agentId'] ?? '') === $agentId
                && (string) ($data['toolId'] ?? '') === $toolId
                && (string) ($data['sourceType'] ?? '') === 'tool'
            ) {
                $matches[] = $object;
            }
        }

        return $matches;

    }//end toolInvocationApprovals()

    /**
     * Resolve an agent's owner UID (RBAC-off — this is the reviewer-default
     * lookup, not a data-access check), or `''` when the agent cannot be found.
     *
     * @param string $agentId The agent UUID.
     *
     * @return string
     */
    private function resolveAgentOwner(string $agentId): string
    {
        if ($agentId === '') {
            return '';
        }

        $agent = $this->objectService->find(
            id: $agentId,
            register: self::REGISTER_SLUG,
            schema: self::AGENT_SCHEMA,
            _rbac: false,
            _multitenancy: false
        );

        if (($agent instanceof ObjectEntity) === false) {
            return '';
        }

        return (string) ($agent->getOwner() ?? '');

    }//end resolveAgentOwner()

    /**
     * A short, non-fabricated summary of tool-invocation arguments for the
     * Approval's `prompt` field — argument NAMES only (never values, which may
     * carry PII/secrets), truncated.
     *
     * @param array<string, mixed> $arguments The invocation's arguments.
     *
     * @return string
     */
    private function summarizeArguments(array $arguments): string
    {
        if ($arguments === []) {
            return '(no arguments)';
        }

        return 'arguments: '.implode(', ', array_keys($arguments));

    }//end summarizeArguments()

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
     * decision, then resumes the gated run matching `sourceType`: a Schedule via
     * `ScheduleService::runNow()` (the original path), or a flow-triggered agent run
     * via `FlowAgentRunService::run()` — both with the approval gate bypassed for this
     * authorised occurrence, and neither loops back into another pending Approval. A
     * non-pending Approval is a no-op (no run).
     *
     * @param ObjectEntity $approval   The pending approval to authorise.
     * @param string       $deciderUid The reviewer/admin making the decision.
     *
     * @return array{status:string, ran:bool} The resulting status and whether a run fired.
     *
     * @spec openspec/changes/human-approval-gate-enforcement/tasks.md#task-4-2
     * @spec openspec/changes/flow-agent-listener/tasks.md#task-3-3
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

        $sourceType = (string) ($data['sourceType'] ?? 'schedule');
        if ($sourceType === 'flow') {
            $flowContext = $data['flowContext'] ?? [];
            if (is_array($flowContext) === false) {
                $flowContext = [];
            }

            $ran = $this->runApprovedFlowRun(flowContext: $flowContext);
        } else if ($sourceType === 'tool') {
            // No run to resume: the chat turn that attempted the call has
            // already returned. Flipping status to `approved` is the whole
            // effect — the NEXT invocation attempt of this (agentId, toolId)
            // pair finds the decided Approval and proceeds.
            $ran = false;
        } else {
            $ran = $this->runApprovedSchedule(scheduleId: (string) ($data['scheduleId'] ?? ''));
        }

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
     * Find the open pending Approval for a flow-triggered run's correlation id,
     * if one exists — the flow-run counterpart to `findPendingApprovalForSchedule()`.
     *
     * @param string $correlationId The AgentRunRequestedEvent dispatch's correlation id.
     *
     * @return ObjectEntity|null The pending approval, or null.
     *
     * @spec openspec/changes/flow-agent-listener/tasks.md#task-3-1
     */
    private function findPendingApprovalForCorrelation(string $correlationId): ?ObjectEntity
    {
        $objects = $this->objectService
            ->setRegister(self::REGISTER_SLUG)
            ->setSchema(self::APPROVAL_SCHEMA)
            ->findAll(
                config: ['filters' => ['correlationId' => $correlationId, 'status' => 'pending']],
                _rbac: false,
                _multitenancy: false
            );

        foreach ($objects as $object) {
            if (($object instanceof ObjectEntity) === false) {
                continue;
            }

            $data = $object->getObject();
            if ((string) ($data['correlationId'] ?? '') === $correlationId
                && (string) ($data['status'] ?? '') === 'pending'
            ) {
                return $object;
            }
        }

        return null;

    }//end findPendingApprovalForCorrelation()

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
     * Run an approved flow-triggered agent run via `FlowAgentRunService`, bypassing
     * the gate — the flow-run counterpart to `runApprovedSchedule()`.
     *
     * `FlowAgentRunService` is resolved lazily from the server container, mirroring
     * `ScheduleService`'s lazy resolution above, so the two services need no
     * circular constructor dependency. The bypass runs THIS authorised occurrence
     * without re-creating a pending Approval; the kill-switch still applies inside
     * `FlowAgentRunService::run()`.
     *
     * @param array<string,mixed> $flowContext The approval's stored resume context
     *                                         (subjectUuid/subjectRegister/subjectSchema/
     *                                         agent/skill/prompt/resultField/mode/flowName/
     *                                         correlationId).
     *
     * @return bool Whether the agent run actually executed.
     *
     * @spec openspec/changes/flow-agent-listener/tasks.md#task-3-3
     */
    private function runApprovedFlowRun(array $flowContext): bool
    {
        if ($flowContext === []) {
            $this->logger->warning('Hermiq approved flow-run has no stored flowContext to resume.');
            return false;
        }

        $flowAgentRunService = $this->container->get(FlowAgentRunService::class);

        return $flowAgentRunService->run(payload: $flowContext, bypassApprovalGate: true);

    }//end runApprovedFlowRun()

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
