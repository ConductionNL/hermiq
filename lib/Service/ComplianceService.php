<?php

/**
 * Hermiq ComplianceService.
 *
 * The compliance-control-packs mapping layer: a seeded, source-cited
 * `ControlFramework`/`Control` catalogue (EU AI Act, ISO/IEC 42001, NIST AI RMF)
 * whose per-control status (`satisfied`/`partial`/`unevidenced`) is COMPUTED at read
 * time from Hermiq's own existing governance data — never a stored/hand-ticked field,
 * mirroring `BudgetService`'s "computed on read, never a stored counter" precedent.
 * Each `evidenceSource` dispatches to the ONE existing service method that is
 * authoritative for that class of evidence:
 * `TenantOpsService::exportAuditTrail()`/`accessReviewList()`/`getRetentionMonths()`,
 * `ApprovalService` decision records, `TenantControlService::getForOrganisation()`,
 * `TenantModelPolicyService::effectivePolicyFor()`, and `AiFeatureService` data. This
 * class introduces zero new governance primitives — it only reads and aggregates ones
 * that already exist (multi-tenant-ops, human-approval-gate-enforcement,
 * agent-lifecycle-governance, tenant-model-policy, ai-feature-governance-register).
 *
 * The AI factsheet's Agent → AiFeature association is a best-effort match on
 * `Agent.type` against `AiFeature.slug` — at HEAD neither schema carries a field
 * linking a specific Agent to a specific AiFeature row (AiFeature is a design-time
 * inventory of FEATURE KINDS, e.g. "chat-companion", not a per-agent registration),
 * and this change adds no new field to either schema (design.md: "no new fields are
 * added to any of those schemas"). When no AiFeature's slug matches the agent's own
 * `type` value, `aiFeature` is `null` in the factsheet — an honest "not registered",
 * never a fabricated association.
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
 * @spec openspec/changes/compliance-control-packs/tasks.md#task-3-complianceservice-computed-evidence-mapping
 * @spec openspec/changes/compliance-control-packs/tasks.md#task-4-complianceservice-dashboard-export-and-factsheet-aggregation
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use Throwable;

/**
 * Computes compliance-control status from live governance data and assembles the
 * dashboard, auditor's-pack export, and per-agent AI factsheet.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Coordinates the five existing
 *   evidence-seam services, mirroring TenantOpsService's own multi-seam coordinator
 *   shape — each evidence branch is independently simple.
 *
 * @spec openspec/changes/compliance-control-packs/tasks.md#task-3-complianceservice-computed-evidence-mapping
 */
class ComplianceService
{

    /**
     * OpenRegister register slug that holds Hermiq objects.
     *
     * @var string
     */
    private const REGISTER_SLUG = 'hermiq';

    /**
     * Schema slug for ControlFramework objects.
     *
     * @var string
     */
    private const FRAMEWORK_SCHEMA = 'agentcontrolframework';

    /**
     * Schema slug for Control objects.
     *
     * @var string
     */
    private const CONTROL_SCHEMA = 'agentcompliancecontrol';

    /**
     * Schema slug for Agent objects.
     *
     * @var string
     */
    private const AGENT_SCHEMA = 'agent';

    /**
     * Schema slug for Incident objects.
     *
     * @var string
     */
    private const INCIDENT_SCHEMA = 'incident';

    /**
     * Constructor.
     *
     * @param ObjectService            $objectService        OpenRegister object read (catalogue +
     *                                                       agent/incident lookups).
     * @param TenantOpsService         $tenantOpsService     Art. 12 audit export, access review,
     *                                                       retention (existing evidence seams).
     * @param ApprovalService          $approvalService      Human-approval-gate decision records.
     * @param TenantControlService     $tenantControlService Per-org kill-switch (stop mechanism).
     * @param TenantModelPolicyService $modelPolicyService   Per-org model-provider allowlist.
     * @param AiFeatureService         $aiFeatureService     DPO-ack design-time AI-feature gate.
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) Constructor DI: each parameter is a
     *   distinct injected collaborator (one per evidence seam), not a logic-bearing argument list.
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly TenantOpsService $tenantOpsService,
        private readonly ApprovalService $approvalService,
        private readonly TenantControlService $tenantControlService,
        private readonly TenantModelPolicyService $modelPolicyService,
        private readonly AiFeatureService $aiFeatureService,
    ) {
    }//end __construct()

    /**
     * The org-scoped compliance dashboard: per-framework coverage percentage and the
     * full gap list (every control not `satisfied`).
     *
     * @param string $organisation The organisation identifier (may be '' for the
     *                             instance-default scope, mirrors ModelPolicy).
     *
     * @return array<string, mixed> `{ frameworks: [...], gaps: [...] }`.
     *
     * @spec openspec/changes/compliance-control-packs/tasks.md#task-4-complianceservice-dashboard-export-and-factsheet-aggregation
     */
    public function dashboard(string $organisation): array
    {
        $byFramework = [];
        foreach ($this->loadFrameworks() as $framework) {
            $byFramework[$framework['slug']] = [
                'slug'     => $framework['slug'],
                'name'     => $framework['name'],
                'controls' => [],
            ];
        }

        $gaps = [];
        foreach ($this->loadControls() as $control) {
            $frameworkSlug = (string) ($control['frameworkSlug'] ?? '');
            if (isset($byFramework[$frameworkSlug]) === false) {
                // A control referencing a framework that no longer exists (or was
                // never seeded) contributes nothing rather than crashing the read.
                continue;
            }

            $status = $this->computeControlStatus(control: $control, organisation: $organisation);
            $row    = [
                'controlId' => (string) ($control['controlId'] ?? ''),
                'title'     => (string) ($control['title'] ?? ''),
                'status'    => $status['status'],
                'detail'    => $status['detail'],
                'sourceUrl' => (string) ($control['sourceUrl'] ?? ''),
            ];

            $byFramework[$frameworkSlug]['controls'][] = $row;

            if ($status['status'] !== 'satisfied') {
                $gaps[] = ($row + ['frameworkSlug' => $frameworkSlug]);
            }
        }//end foreach

        $frameworks = [];
        foreach ($byFramework as $framework) {
            $frameworks[] = [
                'slug'            => $framework['slug'],
                'name'            => $framework['name'],
                'coveragePercent' => $this->coveragePercent(controls: $framework['controls']),
                'controls'        => $framework['controls'],
            ];
        }

        return [
            'frameworks' => $frameworks,
            'gaps'       => $gaps,
        ];

    }//end dashboard()

    /**
     * The auditor's-pack export: the existing, unmodified `exportAuditTrail()`
     * output nested alongside the same per-control coverage data the dashboard
     * shows. Never replaces or alters the existing Art. 12-only export endpoint.
     *
     * @param string $organisation The organisation identifier (may be '').
     *
     * @return array<string, mixed> `{ auditTrail, complianceCoverage, generatedAt }`.
     *
     * @spec openspec/changes/compliance-control-packs/tasks.md#task-4-complianceservice-dashboard-export-and-factsheet-aggregation
     */
    public function auditorPack(string $organisation): array
    {
        return [
            'auditTrail'         => $this->tenantOpsService->exportAuditTrail(),
            'complianceCoverage' => $this->dashboard(organisation: $organisation),
            'generatedAt'        => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c'),
        ];

    }//end auditorPack()

    /**
     * The per-agent AI factsheet: a read-only envelope assembled live from the
     * `Agent`, `AiFeature` (best-effort match, see class docblock), `Approval`, and
     * `Incident` data — no new persisted object.
     *
     * @param string $agentId The agent UUID.
     *
     * @return array<string, mixed>|null The factsheet, or null when the agent does not exist.
     *
     * @spec openspec/changes/compliance-control-packs/tasks.md#task-4-complianceservice-dashboard-export-and-factsheet-aggregation
     */
    public function factsheet(string $agentId): ?array
    {
        $agent = $this->findAgent(agentId: $agentId);
        if ($agent === null) {
            return null;
        }

        $data = $agent->getObject();

        return [
            'agent'          => [
                'id'         => (string) $agent->getUuid(),
                'name'       => (string) ($data['name'] ?? ''),
                'provider'   => (string) ($data['provider'] ?? ''),
                'model'      => (string) ($data['model'] ?? ''),
                'tools'      => $this->normaliseArray(value: ($data['tools'] ?? null)),
                'owner'      => (string) ($agent->getOwner() ?? ''),
                'actingUser' => ($data['actingUser'] ?? null),
            ],
            'aiFeature'      => $this->factsheetAiFeature(agentType: (string) ($data['type'] ?? '')),
            'approvals'      => array_map(
                [$this, 'shapeApprovalForFactsheet'],
                $this->approvalService->listForAgent(agentId: $agentId)
            ),
            'incidents'      => array_map(
                [$this, 'shapeIncidentForFactsheet'],
                $this->loadIncidentsForAgent(agentId: $agentId)
            ),
            'lastReviewedAt' => ($data['reviewedAt'] ?? null),
        ];

    }//end factsheet()

    /**
     * Load an Agent by UUID, system-wide (RBAC-off — the controller applies its own
     * ownership/action-auth IDOR guard before calling `factsheet()`).
     *
     * @param string $agentId The agent UUID.
     *
     * @return ObjectEntity|null The agent, or null when absent.
     *
     * @spec openspec/changes/compliance-control-packs/tasks.md#task-5-compliancecontroller-routes-action-auth-gating
     */
    public function findAgent(string $agentId): ?ObjectEntity
    {
        if ($agentId === '') {
            return null;
        }

        return $this->objectService->find(
            id: $agentId,
            register: self::REGISTER_SLUG,
            schema: self::AGENT_SCHEMA,
            _rbac: false,
            _multitenancy: false
        );

    }//end findAgent()

    /**
     * Compute a control's status by dispatching on its `evidenceSource` to the one
     * existing seam that is authoritative for that class of evidence — never a
     * generic "has any data" check.
     *
     * @param array<string, mixed> $control      The Control payload.
     * @param string               $organisation The organisation identifier.
     *
     * @return array{status: string, detail: string} The computed status + explanation.
     *
     * @spec openspec/changes/compliance-control-packs/tasks.md#task-3-complianceservice-computed-evidence-mapping
     */
    private function computeControlStatus(array $control, string $organisation): array
    {
        return match ($control['evidenceSource'] ?? '') {
            'audit-trail-recordkeeping' => $this->evidenceFromAuditTrail(),
            'approval-gate-oversight' => $this->evidenceFromApprovals(),
            'kill-switch-stop-mechanism' => $this->evidenceFromKillSwitch(organisation: $organisation),
            'model-policy-risk-control' => $this->evidenceFromModelPolicy(organisation: $organisation),
            'capability-review-least-privilege' => $this->evidenceFromAccessReview(),
            'dpo-ack-design-time-gate' => $this->evidenceFromAiFeatures(),
            default => ['status' => 'unevidenced', 'detail' => 'No evidence source mapped for this control.'],
        };

    }//end computeControlStatus()

    /**
     * Art. 12 record-keeping evidence: `TenantOpsService::exportAuditTrail()`.
     *
     * @return array{status: string, detail: string}
     */
    private function evidenceFromAuditTrail(): array
    {
        $export = $this->tenantOpsService->exportAuditTrail();
        $count  = (int) ($export['recordCount'] ?? 0);

        if ($count > 0) {
            return [
                'status' => 'satisfied',
                'detail' => sprintf('%d audited run record(s) found for this organisation.', $count),
            ];
        }

        return [
            'status' => 'unevidenced',
            'detail' => 'No audited run records exist yet for this organisation.',
        ];

    }//end evidenceFromAuditTrail()

    /**
     * Human-oversight evidence: `ApprovalService` decision records.
     *
     * @return array{status: string, detail: string}
     */
    private function evidenceFromApprovals(): array
    {
        $approvals = $this->approvalService->listForOrganisation();
        if ($approvals === []) {
            return [
                'status' => 'unevidenced',
                'detail' => 'No human-approval-gate requests exist yet for this organisation.',
            ];
        }

        $decided = 0;
        foreach ($approvals as $approval) {
            $data = $approval->getObject();
            if ((string) ($data['decidedBy'] ?? '') !== '' && (string) ($data['decidedAt'] ?? '') !== '') {
                $decided++;
            }
        }

        if ($decided > 0) {
            return [
                'status' => 'satisfied',
                'detail' => sprintf('%d human-oversight decision(s) recorded for this organisation.', $decided),
            ];
        }

        return [
            'status' => 'partial',
            'detail' => 'Approval requests exist for this organisation but none has been decided yet.',
        ];

    }//end evidenceFromApprovals()

    /**
     * Stop-mechanism evidence: `TenantControlService::getForOrganisation()`.
     *
     * @param string $organisation The organisation identifier.
     *
     * @return array{status: string, detail: string}
     */
    private function evidenceFromKillSwitch(string $organisation): array
    {
        $control = $this->tenantControlService->getForOrganisation(organisation: $organisation);
        if ($control !== null) {
            return [
                'status' => 'satisfied',
                'detail' => 'The organisation has a provisioned stop mechanism (kill-switch).',
            ];
        }

        return [
            'status' => 'unevidenced',
            'detail' => 'No stop mechanism (kill-switch) has been provisioned for this organisation yet.',
        ];

    }//end evidenceFromKillSwitch()

    /**
     * Model-risk-control evidence: `TenantModelPolicyService::effectivePolicyFor()`.
     *
     * @param string $organisation The organisation identifier.
     *
     * @return array{status: string, detail: string}
     */
    private function evidenceFromModelPolicy(string $organisation): array
    {
        $policy  = $this->modelPolicyService->effectivePolicyFor(organisation: $organisation);
        $source  = $policy['source'];
        $allowed = $policy['allowed'];

        if ($source === 'organisation' && $allowed !== []) {
            return [
                'status' => 'satisfied',
                'detail' => 'The organisation has its own model-provider allowlist.',
            ];
        }

        if ($source === 'instance') {
            return [
                'status' => 'partial',
                'detail' => 'Only the instance-wide default model policy applies — this organisation has none of its own.',
            ];
        }

        if ($source === 'organisation') {
            return [
                'status' => 'partial',
                'detail' => 'The organisation has a model policy, but it allows no providers.',
            ];
        }

        return [
            'status' => 'unevidenced',
            'detail' => 'No model policy exists anywhere — any provider/model is currently unrestricted.',
        ];

    }//end evidenceFromModelPolicy()

    /**
     * Least-privilege evidence: `TenantOpsService::accessReviewList()` +
     * `getRetentionMonths()`.
     *
     * @return array{status: string, detail: string}
     */
    private function evidenceFromAccessReview(): array
    {
        $agents = ($this->tenantOpsService->accessReviewList()['agents'] ?? []);
        $total  = count($agents);

        if ($total === 0) {
            return [
                'status' => 'unevidenced',
                'detail' => 'No agents are registered for this organisation yet.',
            ];
        }

        $cutoff = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify('-'.$this->tenantOpsService->getRetentionMonths().' months');

        $reviewed = 0;
        foreach ($agents as $agent) {
            if ($this->isReviewedWithinWindow(agent: $agent, cutoff: $cutoff) === true) {
                $reviewed++;
            }
        }

        if ($reviewed === $total) {
            return [
                'status' => 'satisfied',
                'detail' => sprintf('All %d agent(s) have a current periodic access review.', $total),
            ];
        }

        if ($reviewed > 0) {
            return [
                'status' => 'partial',
                'detail' => sprintf('%d of %d agent(s) have a current periodic access review.', $reviewed, $total),
            ];
        }

        return [
            'status' => 'unevidenced',
            'detail' => sprintf('None of the %d agent(s) has ever been reviewed.', $total),
        ];

    }//end evidenceFromAccessReview()

    /**
     * Whether an access-review row's `reviewedAt` is set and within the
     * organisation's retention window (not stale).
     *
     * @param array<string, mixed> $agent  The access-review row.
     * @param DateTimeImmutable    $cutoff The earliest still-current review timestamp.
     *
     * @return bool
     */
    private function isReviewedWithinWindow(array $agent, DateTimeImmutable $cutoff): bool
    {
        $reviewedAt = (string) ($agent['reviewedAt'] ?? '');
        if ($reviewedAt === '') {
            return false;
        }

        try {
            $reviewedDate = new DateTimeImmutable($reviewedAt);
        } catch (Throwable $e) {
            return false;
        }

        return $reviewedDate >= $cutoff;

    }//end isReviewedWithinWindow()

    /**
     * DPO-ack design-time-gate evidence: `AiFeatureService::listFeatures()`.
     *
     * @return array{status: string, detail: string}
     */
    private function evidenceFromAiFeatures(): array
    {
        $features = $this->aiFeatureService->listFeatures();
        if ($features === []) {
            return [
                'status' => 'unevidenced',
                'detail' => 'No AI features are registered for this organisation yet.',
            ];
        }

        $enabled = 0;
        foreach ($features as $feature) {
            if ((string) ($feature->getObject()['lifecycle'] ?? '') === 'enabled') {
                $enabled++;
            }
        }

        if ($enabled > 0) {
            return [
                'status' => 'satisfied',
                'detail' => sprintf('%d AI feature(s) enabled after a recorded DPO acknowledgement.', $enabled),
            ];
        }

        return [
            'status' => 'partial',
            'detail' => 'AI features are registered but none has been enabled yet.',
        ];

    }//end evidenceFromAiFeatures()

    /**
     * The coverage percentage for a framework's already-computed control rows: the
     * share reported `satisfied`, rounded to the nearest integer.
     *
     * @param array<int, array<string, mixed>> $controls The framework's control rows.
     *
     * @return int The coverage percentage (0 when the framework has no controls).
     */
    private function coveragePercent(array $controls): int
    {
        $total = count($controls);
        if ($total === 0) {
            return 0;
        }

        $satisfied = 0;
        foreach ($controls as $control) {
            if (($control['status'] ?? '') === 'satisfied') {
                $satisfied++;
            }
        }

        return (int) round(($satisfied / $total) * 100);

    }//end coveragePercent()

    /**
     * Load every seeded ControlFramework, system-wide (instance-wide reference
     * data, not tenant-scoped).
     *
     * @return array<int, array{slug: string, name: string}>
     */
    private function loadFrameworks(): array
    {
        $objects = $this->objectService
            ->setRegister(self::REGISTER_SLUG)
            ->setSchema(self::FRAMEWORK_SCHEMA)
            ->findAll(config: ['limit' => 50], _rbac: false, _multitenancy: false);

        $out = [];
        foreach ($objects as $object) {
            if (($object instanceof ObjectEntity) === false) {
                continue;
            }

            $data  = $object->getObject();
            $out[] = [
                'slug' => (string) ($data['slug'] ?? ''),
                'name' => (string) ($data['name'] ?? ''),
            ];
        }

        return $out;

    }//end loadFrameworks()

    /**
     * Load every seeded Control, system-wide (instance-wide reference data, not
     * tenant-scoped).
     *
     * @return array<int, array<string, mixed>>
     */
    private function loadControls(): array
    {
        $objects = $this->objectService
            ->setRegister(self::REGISTER_SLUG)
            ->setSchema(self::CONTROL_SCHEMA)
            ->findAll(config: ['limit' => 200], _rbac: false, _multitenancy: false);

        $out = [];
        foreach ($objects as $object) {
            if ($object instanceof ObjectEntity) {
                $out[] = $object->getObject();
            }
        }

        return $out;

    }//end loadControls()

    /**
     * The factsheet's `aiFeature` slice: a best-effort match of the agent's own
     * `type` against an `AiFeature.slug` (see class docblock) — null when no
     * AiFeature is registered under that slug.
     *
     * @param string $agentType The agent's `type` field value.
     *
     * @return array<string, mixed>|null
     */
    private function factsheetAiFeature(string $agentType): ?array
    {
        if ($agentType === '') {
            return null;
        }

        $feature = $this->aiFeatureService->findBySlug(slug: $agentType);
        if ($feature === null) {
            return null;
        }

        $data = $feature->getObject();

        return [
            'riskCategory' => ($data['riskCategory'] ?? null),
            'lifecycle'    => ($data['lifecycle'] ?? null),
            'dpoAckBy'     => ($data['dpoAckBy'] ?? null),
            'dpoAckAt'     => ($data['dpoAckAt'] ?? null),
        ];

    }//end factsheetAiFeature()

    /**
     * Shape an Approval object into a factsheet decision-history row.
     *
     * @param ObjectEntity $approval The approval object.
     *
     * @return array<string, mixed>
     */
    private function shapeApprovalForFactsheet(ObjectEntity $approval): array
    {
        $data = $approval->getObject();

        return [
            'status'      => (string) ($data['status'] ?? ''),
            'decidedBy'   => ($data['decidedBy'] ?? null),
            'decidedAt'   => ($data['decidedAt'] ?? null),
            'requestedAt' => ($data['requestedAt'] ?? null),
        ];

    }//end shapeApprovalForFactsheet()

    /**
     * Shape an Incident object into a factsheet incident row.
     *
     * @param ObjectEntity $incident The incident object.
     *
     * @return array<string, mixed>
     */
    private function shapeIncidentForFactsheet(ObjectEntity $incident): array
    {
        $data = $incident->getObject();

        return [
            'description' => (string) ($data['description'] ?? ''),
            'impact'      => (string) ($data['impact'] ?? ''),
            'createdAt'   => ($data['createdAt'] ?? null),
        ];

    }//end shapeIncidentForFactsheet()

    /**
     * Load every Incident linking to the given agent, system-wide (RBAC-off — the
     * controller applies the ownership/action-auth IDOR guard).
     *
     * @param string $agentId The agent UUID.
     *
     * @return array<int, ObjectEntity>
     */
    private function loadIncidentsForAgent(string $agentId): array
    {
        if ($agentId === '') {
            return [];
        }

        $objects = $this->objectService
            ->setRegister(self::REGISTER_SLUG)
            ->setSchema(self::INCIDENT_SCHEMA)
            ->findAll(
                config: ['filters' => ['linkedAgentId' => $agentId], 'limit' => 1000],
                _rbac: false,
                _multitenancy: false
            );

        $out = [];
        foreach ($objects as $object) {
            if (($object instanceof ObjectEntity) === false) {
                continue;
            }

            if ((string) ($object->getObject()['linkedAgentId'] ?? '') === $agentId) {
                $out[] = $object;
            }
        }

        return $out;

    }//end loadIncidentsForAgent()

    /**
     * Coerce a possibly-non-array value into an array, defaulting to `[]`.
     *
     * @param mixed $value The candidate value.
     *
     * @return array<int, mixed>
     */
    private function normaliseArray(mixed $value): array
    {
        if (is_array($value) === true) {
            return $value;
        }

        return [];

    }//end normaliseArray()
}//end class
