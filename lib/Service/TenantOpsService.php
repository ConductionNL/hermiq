<?php

/**
 * Hermiq TenantOpsService.
 *
 * MSP/organisation operational controls (multi-tenant-ops): per-org quota reporting and a
 * per-tenant EU AI Act audit export, built on OpenRegister's native
 * organisation/owner/groups multi-tenancy (ADR-001 Option C+, ADR-004 governance). Both
 * surfaces scope to the caller by loading their own Hermiq objects through ObjectService
 * (RBAC + multitenancy ON) first, so no cross-tenant data leaks. The create-time hard quota
 * reject and the authoritative agent inventory are OpenRegister seams (object creation flows
 * through OR's object API, not Hermiq) — Hermiq surfaces + advises.
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
 * @spec openspec/changes/multi-tenant-ops/tasks.md#1-tenantopsservice
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use OCA\Hermiq\AppInfo\Application;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use OCP\IUserManager;
use RuntimeException;

/**
 * Per-org quota reporting + per-tenant AI Act audit export over OpenRegister,
 * plus (agent-lifecycle-governance) periodic access review + attestation, agent
 * reassignment, incident records, and the retention-period setting.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Class complexity is the sum of
 * many small single-purpose tenant-ops methods (quota, audit export, access review,
 * incidents, retention), each independently simple — the same coordinator shape as
 * ScheduleService/DeliveryService/ApprovalService in this app.
 *
 * @spec openspec/changes/multi-tenant-ops/tasks.md#1-tenantopsservice
 */
class TenantOpsService
{

    /**
     * OpenRegister register slug that holds Hermiq objects.
     *
     * @var string
     */
    private const REGISTER_SLUG = 'hermiq';

    /**
     * Schema slug for schedule objects.
     *
     * @var string
     */
    private const SCHEDULE_SCHEMA = 'schedule';

    /**
     * Schema slug for approval objects.
     *
     * @var string
     */
    private const APPROVAL_SCHEMA = 'approval';

    /**
     * Schema slug for agent objects (agent-lifecycle-governance access review).
     *
     * @var string
     */
    private const AGENT_SCHEMA = 'agent';

    /**
     * Schema slug for incident objects (agent-lifecycle-governance).
     *
     * @var string
     */
    private const INCIDENT_SCHEMA = 'incident';

    /**
     * Schema slug for tenant-control objects (kill-switch + retention policy).
     *
     * @var string
     */
    private const TENANT_CONTROL_SCHEMA = 'tenantcontrol';

    /**
     * Default per-organisation schedule quota.
     *
     * @var int
     */
    private const DEFAULT_SCHEDULE_QUOTA = 100;

    /**
     * Default per-organisation agent quota.
     *
     * @var int
     */
    private const DEFAULT_AGENT_QUOTA = 50;

    /**
     * Default / minimum EU AI Act Art. 12 retention period, in months
     * (agent-lifecycle-governance).
     *
     * @var int
     */
    private const DEFAULT_RETENTION_MONTHS = 6;

    /**
     * Constructor.
     *
     * @param ObjectService    $objectService    OpenRegister object read (tenant-scoped).
     * @param AuditTrailMapper $auditTrailMapper OpenRegister audit read.
     * @param IAppConfig       $appConfig        App config (per-org quota limits).
     * @param IUserManager     $userManager      Validates a reassignment target user exists/is active
     *                                           (agent-lifecycle-governance).
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly AuditTrailMapper $auditTrailMapper,
        private readonly IAppConfig $appConfig,
        private readonly IUserManager $userManager,
    ) {
    }//end __construct()

    /**
     * Report the caller's organisation quota usage against the configured limits.
     *
     * @return array<string, mixed> The quota status payload.
     *
     * @spec openspec/changes/multi-tenant-ops/tasks.md#task-1-1
     */
    public function quotaStatus(): array
    {
        $schedules = $this->loadSchedules();

        $scheduleCount = count($schedules);
        $agentIds      = [];
        foreach ($schedules as $schedule) {
            $agentId = (string) ($schedule->getObject()['agentId'] ?? '');
            if ($agentId !== '') {
                $agentIds[$agentId] = true;
            }
        }

        $scheduleLimit = $this->appConfig->getValueInt(Application::APP_ID, 'scheduleQuota', self::DEFAULT_SCHEDULE_QUOTA);
        $agentLimit    = $this->appConfig->getValueInt(Application::APP_ID, 'agentQuota', self::DEFAULT_AGENT_QUOTA);
        $agentCount    = count($agentIds);

        return [
            'schedules' => [
                'count'   => $scheduleCount,
                'limit'   => $scheduleLimit,
                'atLimit' => ($scheduleCount >= $scheduleLimit),
            ],
            'agents'    => [
                'count'   => $agentCount,
                'limit'   => $agentLimit,
                'atLimit' => ($agentCount >= $agentLimit),
                'note'    => 'Agents in use across the org\'s schedules; the authoritative inventory + create-reject live in OpenRegister.',
            ],
        ];

    }//end quotaStatus()

    /**
     * Produce a per-tenant EU AI Act audit export scoped to the caller's own objects.
     *
     * @return array<string, mixed> The export payload.
     *
     * @spec openspec/changes/multi-tenant-ops/tasks.md#task-1-2
     */
    public function exportAuditTrail(): array
    {
        $records = [];

        foreach ($this->loadSchedules() as $schedule) {
            $this->appendRecords(records: $records, objectType: 'schedule', uuid: (string) $schedule->getUuid());
        }

        foreach ($this->loadApprovals() as $approval) {
            $this->appendRecords(records: $records, objectType: 'approval', uuid: (string) $approval->getUuid());
        }

        // Agent-lifecycle-governance: incident records carry their own narrative
        // fields (description/impact/actionsTaken/linked run+agent refs) that the
        // generic appendRecords() helper (which only surfaces action/status/user/
        // created from the object's OWN AuditTrail log) cannot express — so each
        // incident contributes one record built directly from its own fields,
        // alongside (not instead of) the run/approval entries above.
        $this->appendIncidentRecords(records: $records);

        return [
            'export'      => 'eu-ai-act-audit',
            'recordCount' => count($records),
            'records'     => $records,
        ];

    }//end exportAuditTrail()

    /**
     * List every Agent in the caller's organisation with owner, actingUser,
     * last-run timestamp, a tool/RAG capability summary, and the reassignment/
     * review-attestation state (agent-lifecycle-governance periodic access review).
     *
     * @return array<string, mixed> `{ agents: [...] }`, tenant-scoped to the caller.
     *
     * @spec openspec/changes/agent-lifecycle-governance/specs/agent-lifecycle-governance/spec.md#requirement-periodic-access-review-with-capability-summary
     */
    public function accessReviewList(): array
    {
        $agents    = $this->loadAgents();
        $schedules = $this->loadSchedules();

        $scheduleUuidsByAgent = [];
        foreach ($schedules as $schedule) {
            $agentId = (string) ($schedule->getObject()['agentId'] ?? '');
            if ($agentId === '') {
                continue;
            }

            $scheduleUuidsByAgent[$agentId][] = (string) $schedule->getUuid();
        }

        $out = [];
        foreach ($agents as $agent) {
            $out[] = $this->shapeAgentForReview(
                agent: $agent,
                scheduleUuids: ($scheduleUuidsByAgent[(string) $agent->getUuid()] ?? [])
            );
        }

        return ['agents' => $out];

    }//end accessReviewList()

    /**
     * Record a "reviewed" attestation for one Agent (idempotent — re-attesting
     * updates the timestamp/reviewer rather than duplicating a record).
     *
     * @param string $uuid        The Agent UUID.
     * @param string $reviewerUid The reviewing user's id.
     *
     * @return array<string, mixed> The updated agent's access-review row.
     *
     * @throws RuntimeException When the Agent does not exist (in the caller's tenant scope).
     *
     * @spec openspec/changes/agent-lifecycle-governance/specs/agent-lifecycle-governance/spec.md#requirement-reviewed-attestation-is-recorded-and-auditable
     */
    public function attestAgentReviewed(string $uuid, string $reviewerUid): array
    {
        $agent = $this->objectService->find(id: $uuid, register: self::REGISTER_SLUG, schema: self::AGENT_SCHEMA);
        if ($agent === null) {
            throw new RuntimeException('Agent not found');
        }

        $data = $agent->getObject();
        $data['reviewedAt'] = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c');
        $data['reviewedBy'] = $reviewerUid;

        $saved = $this->objectService->saveObject(
            object: $data,
            register: self::REGISTER_SLUG,
            schema: self::AGENT_SCHEMA,
            uuid: $uuid
        );

        return $this->shapeAgentForReview(agent: $saved, scheduleUuids: []);

    }//end attestAgentReviewed()

    /**
     * Reassign a flagged Agent's `actingUser` to a new, existing, active
     * Nextcloud user, clearing the reassignment flag. Never re-enables any
     * Schedule the offboarding pause disabled — that stays a separate, explicit,
     * auditable org-admin action (proposal.md Open Question 2).
     *
     * @param string $uuid          The Agent UUID.
     * @param string $newActingUser The target user id.
     *
     * @return array<string, mixed> The updated agent's access-review row.
     *
     * @throws RuntimeException        When the Agent does not exist (in the caller's tenant scope).
     * @throws InvalidArgumentException When the target user does not exist or is not active.
     *
     * @spec openspec/changes/agent-lifecycle-governance/specs/agent-lifecycle-governance/spec.md#requirement-org-admin-reassignment-flow-for-flagged-agents
     */
    public function reassignAgent(string $uuid, string $newActingUser): array
    {
        $agent = $this->objectService->find(id: $uuid, register: self::REGISTER_SLUG, schema: self::AGENT_SCHEMA);
        if ($agent === null) {
            throw new RuntimeException('Agent not found');
        }

        $target = $this->userManager->get($newActingUser);
        if ($target === null || $target->isEnabled() === false) {
            throw new InvalidArgumentException('Target user does not exist or is not active');
        }

        $data = $agent->getObject();
        $data['actingUser']       = $newActingUser;
        $data['reassignmentFlag'] = false;

        $saved = $this->objectService->saveObject(
            object: $data,
            register: self::REGISTER_SLUG,
            schema: self::AGENT_SCHEMA,
            uuid: $uuid
        );

        return $this->shapeAgentForReview(agent: $saved, scheduleUuids: []);

    }//end reassignAgent()

    /**
     * List the caller's organisation's incident records, newest first.
     *
     * @return array<string, mixed> `{ incidents: [...] }`, tenant-scoped to the caller.
     *
     * @spec openspec/changes/agent-lifecycle-governance/specs/agent-lifecycle-governance/spec.md#requirement-incident-records-linked-to-runs-and-agents
     */
    public function listIncidents(): array
    {
        $incidents = $this->loadIncidents();

        usort(
            $incidents,
            static function (ObjectEntity $a, ObjectEntity $b): int {
                $aCreated = (string) ($a->getObject()['createdAt'] ?? '');
                $bCreated = (string) ($b->getObject()['createdAt'] ?? '');
                return ($bCreated <=> $aCreated);
            }
        );

        return ['incidents' => array_map([$this, 'shapeIncident'], $incidents)];

    }//end listIncidents()

    /**
     * Open a new incident record, scoped to the caller's organisation.
     *
     * @param string             $description   What happened.
     * @param string             $impact        The incident's impact.
     * @param string             $actionsTaken  The remedial actions taken.
     * @param string|null        $linkedAgentId Optional linked Agent UUID.
     * @param array<int, string> $linkedRunIds  Optional linked run (AuditTrail entry) uuids.
     * @param string             $createdBy     The org admin opening the incident.
     *
     * @return array<string, mixed> The created incident.
     *
     * @spec openspec/changes/agent-lifecycle-governance/specs/agent-lifecycle-governance/spec.md#requirement-incident-records-linked-to-runs-and-agents
     */
    public function createIncident(
        string $description,
        string $impact,
        string $actionsTaken,
        ?string $linkedAgentId,
        array $linkedRunIds,
        string $createdBy
    ): array {
        $data = [
            'description'   => $description,
            'impact'        => $impact,
            'actionsTaken'  => $actionsTaken,
            'linkedAgentId' => $linkedAgentId,
            'linkedRunIds'  => array_values($linkedRunIds),
            'createdAt'     => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c'),
            'createdBy'     => $createdBy,
        ];

        $saved = $this->objectService->saveObject(
            object: $data,
            register: self::REGISTER_SLUG,
            schema: self::INCIDENT_SCHEMA
        );

        return $this->shapeIncident(incident: $saved);

    }//end createIncident()

    /**
     * The caller's organisation's currently configured retention period, in
     * months (EU AI Act Art. 12), defaulting to 6 when unconfigured.
     *
     * @return int
     *
     * @spec openspec/changes/agent-lifecycle-governance/specs/multi-tenant-ops/spec.md#requirement-per-organisation-retention-period-configuration
     */
    public function getRetentionMonths(): int
    {
        $control = $this->loadTenantControl();
        if ($control === null) {
            return self::DEFAULT_RETENTION_MONTHS;
        }

        $months = (int) ($control->getObject()['retentionMonths'] ?? self::DEFAULT_RETENTION_MONTHS);
        if ($months < self::DEFAULT_RETENTION_MONTHS) {
            return self::DEFAULT_RETENTION_MONTHS;
        }

        return $months;

    }//end getRetentionMonths()

    /**
     * Configure the caller's organisation's retention period. Rejects any value
     * below the Art. 12 minimum (6 months) — the stored value is left unchanged.
     *
     * @param int $months The new retention period, in months.
     *
     * @return int The persisted retention period.
     *
     * @throws InvalidArgumentException When `$months` is below the 6-month minimum.
     *
     * @spec openspec/changes/agent-lifecycle-governance/specs/multi-tenant-ops/spec.md#requirement-per-organisation-retention-period-configuration
     */
    public function setRetentionMonths(int $months): int
    {
        if ($months < self::DEFAULT_RETENTION_MONTHS) {
            throw new InvalidArgumentException('retentionMonths must be at least 6');
        }

        $control = $this->loadTenantControl();

        $data = [];
        $uuid = null;
        if ($control !== null) {
            $data = $control->getObject();
            $uuid = (string) $control->getUuid();
        }

        // `engaged` is a required TenantControl field; a control created here for
        // the first time (no prior kill-switch use) defaults to disengaged.
        if (isset($data['engaged']) === false) {
            $data['engaged'] = false;
        }

        $data['retentionMonths'] = $months;

        $this->objectService->saveObject(
            object: $data,
            register: self::REGISTER_SLUG,
            schema: self::TENANT_CONTROL_SCHEMA,
            uuid: $uuid
        );

        return $months;

    }//end setRetentionMonths()

    /**
     * Append an object's AuditTrail entries to the export record list.
     *
     * @param array<int, array<string, mixed>> $records    The export record list (by reference).
     * @param string                           $objectType The object type label.
     * @param string                           $uuid       The object UUID.
     *
     * @return void
     */
    private function appendRecords(array &$records, string $objectType, string $uuid): void
    {
        if ($uuid === '') {
            return;
        }

        $logs = $this->auditTrailMapper->findAll(filters: ['object_uuid' => $uuid]);
        foreach ($logs as $log) {
            $context = ($log->getChanged() ?? []);
            $created = $log->getCreated();

            $createdIso = null;
            if ($created !== null) {
                $createdIso = $created->format('c');
            }

            $records[] = [
                'objectType' => $objectType,
                'objectUuid' => $uuid,
                'action'     => $log->getAction(),
                'status'     => ($context['status'] ?? null),
                'user'       => $log->getUser(),
                'created'    => $createdIso,
            ];
        }

    }//end appendRecords()

    /**
     * Append each of the caller's incident records directly to the export record
     * list (agent-lifecycle-governance) — see exportAuditTrail()'s docblock for
     * why this bypasses appendRecords()'s generic AuditTrail-log shape.
     *
     * @param array<int, array<string, mixed>> $records The export record list (by reference).
     *
     * @return void
     *
     * @spec openspec/changes/agent-lifecycle-governance/specs/agent-lifecycle-governance/spec.md#requirement-incident-records-are-included-in-the-art-12-audit-export
     */
    private function appendIncidentRecords(array &$records): void
    {
        foreach ($this->loadIncidents() as $incident) {
            $data = $incident->getObject();

            $records[] = [
                'objectType'    => 'incident',
                'objectUuid'    => (string) $incident->getUuid(),
                'action'        => 'incident_recorded',
                'description'   => (string) ($data['description'] ?? ''),
                'impact'        => (string) ($data['impact'] ?? ''),
                'actionsTaken'  => (string) ($data['actionsTaken'] ?? ''),
                'linkedAgentId' => ($data['linkedAgentId'] ?? null),
                'linkedRunIds'  => $this->normaliseStringArray(value: ($data['linkedRunIds'] ?? null)),
                'user'          => ($data['createdBy'] ?? null),
                'created'       => ($data['createdAt'] ?? null),
            ];
        }

    }//end appendIncidentRecords()

    /**
     * Coerce a possibly-non-array value into an array, defaulting to `[]`.
     *
     * @param mixed $value The candidate value.
     *
     * @return array<int, mixed> The value when it is already an array, otherwise `[]`.
     */
    private function normaliseStringArray(mixed $value): array
    {
        if (is_array($value) === true) {
            return $value;
        }

        return [];

    }//end normaliseStringArray()

    /**
     * Shape an Agent object into an access-review row.
     *
     * @param ObjectEntity      $agent         The Agent object.
     * @param array<int,string> $scheduleUuids UUIDs of this agent's schedules (for last-run lookup).
     *
     * @return array<string, mixed> The access-review row.
     *
     * @spec openspec/changes/agent-lifecycle-governance/specs/agent-lifecycle-governance/spec.md#requirement-periodic-access-review-with-capability-summary
     */
    private function shapeAgentForReview(ObjectEntity $agent, array $scheduleUuids): array
    {
        $data = $agent->getObject();

        $actingUser = ($data['actingUser'] ?? '');
        if ($actingUser === '') {
            $actingUser = null;
        }

        return [
            'uuid'             => (string) $agent->getUuid(),
            'name'             => (string) ($data['name'] ?? ''),
            'owner'            => (string) ($agent->getOwner() ?? ''),
            'actingUser'       => $actingUser,
            'lastRunAt'        => $this->lastRunAt(scheduleUuids: $scheduleUuids),
            'tools'            => $this->normaliseStringArray(value: ($data['tools'] ?? null)),
            'enableRag'        => (bool) ($data['enableRag'] ?? false),
            'ragSearchMode'    => (string) ($data['ragSearchMode'] ?? ''),
            'reassignmentFlag' => (bool) ($data['reassignmentFlag'] ?? false),
            'reviewedAt'       => ($data['reviewedAt'] ?? null),
            'reviewedBy'       => ($data['reviewedBy'] ?? null),
        ];

    }//end shapeAgentForReview()

    /**
     * The most recent run timestamp across a set of Schedule uuids, derived from
     * their own AuditTrail entries (no separate "last run" counter is stored).
     *
     * @param array<int,string> $scheduleUuids The schedule uuids to check.
     *
     * @return string|null The latest run's ISO-8601 timestamp, or null when none ran yet.
     *
     * @spec openspec/changes/agent-lifecycle-governance/specs/agent-lifecycle-governance/spec.md#requirement-periodic-access-review-with-capability-summary
     */
    private function lastRunAt(array $scheduleUuids): ?string
    {
        $latest = null;

        foreach ($scheduleUuids as $uuid) {
            if ($uuid === '') {
                continue;
            }

            $logs = $this->auditTrailMapper->findAll(filters: ['object_uuid' => $uuid]);
            foreach ($logs as $log) {
                $created = $log->getCreated();
                if ($created === null) {
                    continue;
                }

                if ($latest === null || $created > $latest) {
                    $latest = $created;
                }
            }
        }

        return $latest?->format('c');

    }//end lastRunAt()

    /**
     * Shape an Incident object into a list/export row.
     *
     * @param ObjectEntity $incident The incident object.
     *
     * @return array<string, mixed> The shaped incident.
     *
     * @spec openspec/changes/agent-lifecycle-governance/specs/agent-lifecycle-governance/spec.md#requirement-incident-records-linked-to-runs-and-agents
     */
    private function shapeIncident(ObjectEntity $incident): array
    {
        $data = $incident->getObject();

        return [
            'uuid'          => (string) $incident->getUuid(),
            'description'   => (string) ($data['description'] ?? ''),
            'impact'        => (string) ($data['impact'] ?? ''),
            'actionsTaken'  => (string) ($data['actionsTaken'] ?? ''),
            'linkedAgentId' => ($data['linkedAgentId'] ?? null),
            'linkedRunIds'  => $this->normaliseStringArray(value: ($data['linkedRunIds'] ?? null)),
            'createdAt'     => ($data['createdAt'] ?? null),
            'createdBy'     => ($data['createdBy'] ?? null),
        ];

    }//end shapeIncident()

    /**
     * Load the caller's schedules (tenant-scoped).
     *
     * @return array<int, ObjectEntity> The schedule objects.
     */
    private function loadSchedules(): array
    {
        return $this->loadObjects(schema: self::SCHEDULE_SCHEMA);

    }//end loadSchedules()

    /**
     * Load the caller's approvals (tenant-scoped).
     *
     * @return array<int, ObjectEntity> The approval objects.
     */
    private function loadApprovals(): array
    {
        return $this->loadObjects(schema: self::APPROVAL_SCHEMA);

    }//end loadApprovals()

    /**
     * Load the caller's agents (tenant-scoped).
     *
     * @return array<int, ObjectEntity> The agent objects.
     */
    private function loadAgents(): array
    {
        return $this->loadObjects(schema: self::AGENT_SCHEMA);

    }//end loadAgents()

    /**
     * Load the caller's incidents (tenant-scoped).
     *
     * @return array<int, ObjectEntity> The incident objects.
     */
    private function loadIncidents(): array
    {
        return $this->loadObjects(schema: self::INCIDENT_SCHEMA);

    }//end loadIncidents()

    /**
     * Load the caller's TenantControl object (tenant-scoped), if one exists.
     *
     * @return ObjectEntity|null The control object, or null when none exists yet.
     */
    private function loadTenantControl(): ?ObjectEntity
    {
        $controls = $this->loadObjects(schema: self::TENANT_CONTROL_SCHEMA);
        return ($controls[0] ?? null);

    }//end loadTenantControl()

    /**
     * Load the caller's objects for a schema (RBAC + multitenancy ON — tenant-scoped).
     *
     * @param string $schema The schema slug.
     *
     * @return array<int, ObjectEntity> The objects.
     */
    private function loadObjects(string $schema): array
    {
        $objects = $this->objectService
            ->setRegister(self::REGISTER_SLUG)
            ->setSchema($schema)
            ->findAll(config: ['limit' => 1000]);

        $out = [];
        foreach ($objects as $object) {
            if ($object instanceof ObjectEntity) {
                $out[] = $object;
            }
        }

        return $out;

    }//end loadObjects()
}//end class
