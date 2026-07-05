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

use OCA\Hermiq\AppInfo\Application;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;

/**
 * Per-org quota reporting + per-tenant AI Act audit export over OpenRegister.
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
     * Constructor.
     *
     * @param ObjectService    $objectService    OpenRegister object read (tenant-scoped).
     * @param AuditTrailMapper $auditTrailMapper OpenRegister audit read.
     * @param IAppConfig       $appConfig        App config (per-org quota limits).
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly AuditTrailMapper $auditTrailMapper,
        private readonly IAppConfig $appConfig,
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

        return [
            'export'      => 'eu-ai-act-audit',
            'recordCount' => count($records),
            'records'     => $records,
        ];

    }//end exportAuditTrail()

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
