<?php

/**
 * Hermiq WebhookAgentRunService.
 *
 * Turns a verified inbound webhook trigger (`WebhookTriggerController` →
 * `WebhookAgentRunJob`) into a governed agent run through the SAME oversight
 * rails `FlowAgentRunService` uses for OpenRegister's flow-triggered runs —
 * the webhook-triggered sibling, per `agent-webhook-trigger`'s design:
 *
 *   - GATE 1 (kill-switch): `ScheduleService::isOrganisationEngaged()`.
 *   - GATE 2 (budget hard cap, cost-guardrails): `BudgetService`, the SAME
 *     hard-cap block + soft-threshold warning a scheduled tick / flow-run applies.
 *   - GATE 3 (human approval, Art. 14): `ApprovalService`, generalised to a
 *     THIRD `sourceType: "webhook"` alongside `"schedule"`/`"flow"`.
 *   - The agent turn itself: `ScheduleService::runAgentAsOwner()` — the SAME
 *     method a scheduled run and a flow-triggered run both call, so model-
 *     policy enforcement (tenant-model-policy) and the engine-routing dual
 *     path (agent-engine-port) are inherited for free, not re-implemented.
 *   - The redacted per-run audit write-path (AuditTrailMapper + RedactionService).
 *
 * Unlike a flow-triggered run, a webhook trigger has no "triggering OR object"
 * to write a result back to — the closest analogue is the Agent itself, so the
 * `agent-run` AuditTrail entry is written against the Agent's own ObjectEntity
 * (design.md Decision 4). The inbound webhook payload becomes the run's prompt
 * (there is no separate "configured prompt" field on `AgentWebhook` — the
 * payload IS the input, design.md's Trade-offs), left UNREDACTED when handed to
 * the agent (redacting it there would defeat the endpoint's purpose), but
 * redacted before it is written to ANY persisted record — the audit entry's
 * `payload` context, or a pending Approval's stored `webhookContext.payload`
 * (design.md Decision 3 / the payload-redaction requirement).
 *
 * This is a recognised ADR-031 imperative exception, exactly like
 * `FlowAgentRunService`: a side-effecting governance orchestrator, not a
 * derived value or declarative lifecycle. Hermiq owns no LLM/tool engine.
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
 * @spec openspec/changes/agent-webhook-trigger/tasks.md#task-4-webhookagentrunjob-webhookagentrunservice-governed-dispatch
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Hermiq\Service\AgentVersionService;
use OCA\OpenRegister\Db\Agent;
use OCA\OpenRegister\Db\AgentMapper;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Executes a governed agent run requested by a verified inbound webhook trigger.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Coordinates several OR/Hermiq services.
 *
 * @spec openspec/changes/agent-webhook-trigger/tasks.md#task-4-webhookagentrunjob-webhookagentrunservice-governed-dispatch
 */
class WebhookAgentRunService
{

    /**
     * OpenRegister register slug that holds Hermiq objects.
     *
     * @var string
     */
    private const REGISTER_SLUG = 'hermiq';

    /**
     * OpenRegister schema slug for agent objects (agent-engine-port).
     *
     * @var string
     */
    private const AGENT_SCHEMA = 'agent';

    /**
     * Constructor.
     *
     * @param ObjectService       $objectService       Resolves the Agent ObjectEntity to audit against
     *                                                 (AuditTrailMapper requires an ObjectEntity, not
     *                                                 the plain Doctrine `Agent` entity).
     * @param AgentMapper         $agentMapper         Resolves the OR-native `Agent` entity, for its
     *                                                 `owner`/`organisation` getters.
     * @param LoggerInterface     $logger              Logs gate skips + run failures (never fatal).
     * @param AuditTrailMapper    $auditTrailMapper    OR audit write-path for the redacted per-run entry.
     * @param RedactionService    $redactionService    Masks secrets/PII before ANY persisted write.
     * @param ScheduleService     $scheduleService     Reused kill-switch check (isOrganisationEngaged) AND
     *                                                 the reused agent-turn dispatch (runAgentAsOwner) —
     *                                                 the SAME ScheduleService/Engine path a scheduled or
     *                                                 flow-triggered run uses.
     * @param ApprovalService     $approvalService     Reused human-approval gate.
     * @param BudgetService       $budgetService       Reused budget hard-cap gate + soft-threshold warning.
     * @param AgentVersionService $agentVersionService Resolves the executing agent's current version
     *                                                 identifier, pinned onto the run-audit context
     *                                                 (agent-versioning).
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) Constructor DI: each parameter is
     *   a distinct injected collaborator, not a logic-bearing argument list.
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly AgentMapper $agentMapper,
        private readonly LoggerInterface $logger,
        private readonly AuditTrailMapper $auditTrailMapper,
        private readonly RedactionService $redactionService,
        private readonly ScheduleService $scheduleService,
        private readonly ApprovalService $approvalService,
        private readonly BudgetService $budgetService,
        private readonly AgentVersionService $agentVersionService,
    ) {
    }//end __construct()

    /**
     * Run one webhook-triggered agent-run request, applying the synchronous
     * oversight gates first — mirrors `FlowAgentRunService::run()`'s contract:
     * never throws, returns whether the agent actually ran.
     *
     * @param array<string,mixed> $context            The webhook trigger context
     *                                                (agentId/payload/correlationId/
     *                                                requiresApproval/reviewer/reviewerType).
     * @param bool                $bypassApprovalGate When true, skip the requiresApproval gate for
     *                                                this authorised occurrence (an approval-run).
     *
     * @return bool Whether the agent run actually executed (false when gated/skipped/failed).
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The bypass is a genuine two-mode
     *   authorisation input (normal dispatch vs. an already-approved occurrence),
     *   mirroring FlowAgentRunService::run()'s identical parameter.
     *
     * @spec openspec/changes/agent-webhook-trigger/tasks.md#task-4-webhookagentrunjob-webhookagentrunservice-governed-dispatch
     */
    public function run(array $context, bool $bypassApprovalGate=false): bool
    {
        try {
            return $this->dispatch(context: $context, bypassApprovalGate: $bypassApprovalGate);
        } catch (Throwable $e) {
            $this->logger->warning(
                'Hermiq webhook-agent run failed: '.$e->getMessage(),
                ['exception' => $e]
            );
            return false;
        }

    }//end run()

    /**
     * Resolve the agent (both representations — see class docblock) and apply
     * GATE 1 (kill-switch), GATE 2 (budget hard cap) and GATE 3 (human approval)
     * before ever invoking the agent.
     *
     * @param array<string,mixed> $context            The webhook trigger context.
     * @param bool                $bypassApprovalGate Skip the requiresApproval gate (approval-run).
     *
     * @return bool Whether the agent run executed.
     *
     * @spec openspec/changes/agent-webhook-trigger/specs/agent-webhook-trigger/spec.md#requirement-a-triggered-run-reuses-the-existing-governed-dispatch-rails
     */
    private function dispatch(array $context, bool $bypassApprovalGate): bool
    {
        $agentId = (string) ($context['agentId'] ?? '');
        if ($agentId === '') {
            $this->logger->warning('Hermiq webhook-agent run missing agentId; skipping.');
            return false;
        }

        $resolved = $this->resolveAgentAndObject(agentId: $agentId);
        if ($resolved === null) {
            return false;
        }

        $agent        = $resolved['agent'];
        $agentObject  = $resolved['agentObject'];
        $organisation = (string) ($agent->getOrganisation() ?? '');

        // GATE 1 — KILL-SWITCH (same TenantControl data source ScheduleService reads).
        if ($organisation !== '' && $this->scheduleService->isOrganisationEngaged(organisation: $organisation) === true) {
            $this->writeRunAudit(agentObject: $agentObject, status: 'skipped_killswitch', summary: '', context: $context);
            return false;
        }

        // GATE 2 — BUDGET HARD CAP (cost-guardrails). Mirrors FlowAgentRunService's
        // identical gate: the soft-threshold check is unconditional (never fatal), and the
        // hard-cap block applies even to an authorised approval-bypass occurrence.
        if ($this->isBudgetBlocked(organisation: $organisation, agentId: $agentId) === true) {
            $this->writeRunAudit(agentObject: $agentObject, status: 'skipped_budget', summary: '', context: $context);
            return false;
        }

        // GATE 3 — HUMAN APPROVAL (Art. 14). A gated, unauthorised occurrence does not
        // run: ensure a single pending Approval (idempotent) and mark awaiting_approval.
        if ($this->applyApprovalGate(agent: $agent, agentObject: $agentObject, context: $context, bypassApprovalGate: $bypassApprovalGate) === true) {
            return false;
        }

        $owner = (string) ($agent->getOwner() ?? '');
        if ($owner === '') {
            $this->logger->warning(
                sprintf('Hermiq webhook-agent run: agent "%s" has no owner to act as; skipping.', $agentId)
            );
            return false;
        }

        return $this->runAgentAndAudit(agentObject: $agentObject, context: $context, owner: $owner, organisation: $organisation);

    }//end dispatch()

    /**
     * Run the agent turn (via ScheduleService::runAgentAsOwner — the same
     * ScheduleService/Engine path a scheduled or flow-triggered run uses) and
     * write the redacted per-run audit entry against the Agent's ObjectEntity.
     *
     * @param ObjectEntity        $agentObject  The Agent's ObjectEntity (audit target).
     * @param array<string,mixed> $context      The webhook trigger context.
     * @param string              $owner        The agent's owner (acting-user impersonation target).
     * @param string              $organisation The agent's organisation — threaded to
     *                                          `runAgentAsOwner()` so its defense-in-depth
     *                                          output-filter re-check resolves the correct
     *                                          GuardrailPolicy (agent-guardrails).
     *
     * @return bool Whether the run completed successfully.
     *
     * @spec openspec/changes/agent-webhook-trigger/tasks.md#task-4-webhookagentrunjob-webhookagentrunservice-governed-dispatch
     * @spec openspec/changes/agent-guardrails/tasks.md#task-4-wire-inputoutput-filters-into-scheduleservicerunagentasowner
     * @spec openspec/changes/sub-agent-delegation/specs/multi-tenant-ops/spec.md#requirement-per-scope-budget-guardrails--soft-threshold-and-hard-cap
     */
    private function runAgentAndAudit(ObjectEntity $agentObject, array $context, string $owner, string $organisation=''): bool
    {
        $agentId = (string) ($context['agentId'] ?? '');
        $prompt  = $this->buildPrompt(context: $context);

        $startedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $status    = 'ok';
        $summary   = '';

        try {
            // Reused verbatim — same impersonation + feature-flagged engine branch
            // (OR ChatService / in-app Engine) a scheduled or flow-triggered run
            // dispatches through, including tenant-model-policy enforcement (the
            // Agent's own `organisation` is resolved INSIDE this call chain — no
            // separate organisation parameter needs threading through here).
            $summary = $this->scheduleService->runAgentAsOwner(
                owner: $owner,
                agentId: $agentId,
                prompt: $prompt,
                organisation: $organisation,
                anchor: $agentObject
            );
        } catch (Throwable $e) {
            $status  = 'error';
            $summary = 'error: '.$e->getMessage();
            $this->logger->warning(
                sprintf('Hermiq webhook-agent run failed for agent %s: %s', $agentId, $e->getMessage()),
                ['exception' => $e]
            );
        }//end try

        $this->writeRunAudit(agentObject: $agentObject, status: $status, summary: $summary, context: $context, startedAt: $startedAt);

        return $status === 'ok';

    }//end runAgentAndAudit()

    /**
     * Build the agent's run input from the webhook trigger context. There is no
     * separate "configured prompt" field on `AgentWebhook` — the payload IS the
     * input (design.md's Trade-offs) — so the RAW (unredacted) payload is folded
     * in verbatim; redacting it here would defeat the endpoint's purpose.
     *
     * @param array<string,mixed> $context The webhook trigger context.
     *
     * @return string The prompt handed to ScheduleService::runAgentAsOwner().
     *
     * @spec openspec/changes/agent-webhook-trigger/specs/agent-webhook-trigger/spec.md#requirement-the-webhook-payload-becomes-run-input-redacted-before-persistence
     */
    private function buildPrompt(array $context): string
    {
        $payload = $context['payload'] ?? [];
        if (is_array($payload) === false) {
            $payload = [];
        }

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            $json = '{}';
        }

        return sprintf("Webhook payload:\n%s", $json);

    }//end buildPrompt()

    /**
     * A copy of the webhook context with its `payload` redacted — used ONLY for
     * what gets PERSISTED (a pending Approval's stored `webhookContext`, or the
     * audit entry's context — see writeRunAudit()) — never for what is handed to
     * the agent itself (buildPrompt() reads the RAW context).
     *
     * @param array<string,mixed> $context The raw webhook trigger context.
     *
     * @return array<string,mixed> The context with a redacted `payload`.
     *
     * @spec openspec/changes/agent-webhook-trigger/specs/agent-webhook-trigger/spec.md#requirement-the-webhook-payload-becomes-run-input-redacted-before-persistence
     */
    private function redactedContext(array $context): array
    {
        $safe            = $context;
        $safe['payload'] = $this->redactedPayload(context: $context);

        return $safe;

    }//end redactedContext()

    /**
     * The webhook payload, JSON-encoded, redacted, and decoded back to an array
     * where possible (falling back to a `_redacted` wrapper string when the
     * redacted text is no longer valid JSON — redaction masks values in place,
     * so this should not normally happen, but a persisted record must never be
     * an unparsed, partially-redacted blob passed off as structured data).
     *
     * @param array<string,mixed> $context The raw webhook trigger context.
     *
     * @return array<string,mixed> The redacted payload.
     *
     * @spec openspec/changes/agent-webhook-trigger/specs/agent-webhook-trigger/spec.md#requirement-the-webhook-payload-becomes-run-input-redacted-before-persistence
     */
    private function redactedPayload(array $context): array
    {
        $payload = $context['payload'] ?? [];
        if (is_array($payload) === false) {
            $payload = [];
        }

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            $json = '{}';
        }

        $redactedJson = $this->redactionService->redact($json);
        $decoded      = json_decode($redactedJson, true);
        if (is_array($decoded) === true) {
            return $decoded;
        }

        return ['_redacted' => $redactedJson];

    }//end redactedPayload()

    /**
     * Resolve BOTH agent representations (design.md Decision 4) — the OR-native
     * `Agent` (owner/organisation) and the hermiq-register `agent` ObjectEntity
     * (the audit target) — collapsed into one early-return helper so
     * `dispatch()` itself only has ONE failure branch to handle for either.
     *
     * @param string $agentId The agent UUID.
     *
     * @return array{agent:Agent, agentObject:ObjectEntity}|null Both representations,
     *                                                           or null when either
     *                                                           is unresolvable.
     *
     * @spec openspec/changes/agent-webhook-trigger/tasks.md#task-4-webhookagentrunjob-webhookagentrunservice-governed-dispatch
     */
    private function resolveAgentAndObject(string $agentId): ?array
    {
        $agent = $this->resolveAgent(agentId: $agentId);
        if ($agent === null) {
            $this->logger->warning(
                sprintf('Hermiq webhook-agent run: agent "%s" could not be resolved; skipping.', $agentId)
            );
            return null;
        }

        $agentObject = $this->objectService->find(
            id: $agentId,
            register: self::REGISTER_SLUG,
            schema: self::AGENT_SCHEMA,
            _rbac: false,
            _multitenancy: false
        );

        if (($agentObject instanceof ObjectEntity) === false) {
            $this->logger->warning(
                sprintf('Hermiq webhook-agent run: agent object %s not found; skipping.', $agentId)
            );
            return null;
        }

        return ['agent' => $agent, 'agentObject' => $agentObject];

    }//end resolveAgentAndObject()

    /**
     * GATE 2 — BUDGET HARD CAP (cost-guardrails), extracted so `dispatch()` has
     * a single conditional to handle. The soft-threshold check is unconditional
     * (never fatal) and runs every time this is called; the hard-cap block
     * applies even to an authorised approval-bypass occurrence — mirrors
     * `FlowAgentRunService`'s identical gate.
     *
     * @param string $organisation The agent's organisation.
     * @param string $agentId      The agent UUID.
     *
     * @return bool True when the run must be blocked (budget hard cap reached).
     *
     * @spec openspec/changes/agent-webhook-trigger/specs/agent-webhook-trigger/spec.md#requirement-a-triggered-run-reuses-the-existing-governed-dispatch-rails
     */
    private function isBudgetBlocked(string $organisation, string $agentId): bool
    {
        try {
            $this->budgetService->checkAndDeliverWarnings(organisation: $organisation, agentId: $agentId);
        } catch (Throwable $e) {
            $this->logger->warning(
                'Hermiq webhook-agent budget soft-threshold check failed: '.$e->getMessage(),
                ['exception' => $e]
            );
        }

        return $this->budgetService->isBlocked(organisation: $organisation, agentId: $agentId) === true;

    }//end isBudgetBlocked()

    /**
     * GATE 3 — HUMAN APPROVAL (Art. 14), extracted so `dispatch()` has a single
     * conditional to handle. A gated, unauthorised occurrence does not run:
     * ensures a single pending Approval (idempotent, with a REDACTED payload —
     * redaction-before-persist) and marks `awaiting_approval`.
     *
     * @param Agent               $agent              The resolved OR-native agent.
     * @param ObjectEntity        $agentObject        The Agent's ObjectEntity (audit target).
     * @param array<string,mixed> $context            The webhook trigger context.
     * @param bool                $bypassApprovalGate Skip the gate (an authorised approval-run).
     *
     * @return bool True when the run was gated (must not proceed); false to continue.
     *
     * @spec openspec/changes/agent-webhook-trigger/specs/agent-webhook-trigger/spec.md#requirement-a-triggered-run-reuses-the-existing-governed-dispatch-rails
     */
    private function applyApprovalGate(Agent $agent, ObjectEntity $agentObject, array $context, bool $bypassApprovalGate): bool
    {
        $requiresApproval = (bool) ($context['requiresApproval'] ?? false);
        if ($bypassApprovalGate === true || $requiresApproval === false) {
            return false;
        }

        $owner = (string) ($agent->getOwner() ?? '');

        try {
            // REDACTION-BEFORE-PERSIST: the Approval's stored webhookContext gets the
            // redacted payload, never the raw one (payload-redaction requirement).
            $this->approvalService->ensurePendingApprovalForWebhookRun(
                context: $this->redactedContext(context: $context),
                agentOwner: $owner
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                'Hermiq webhook-agent approval gate setup failed: '.$e->getMessage(),
                ['exception' => $e]
            );
        }

        $this->writeRunAudit(agentObject: $agentObject, status: 'awaiting_approval', summary: '', context: $context);
        return true;

    }//end applyApprovalGate()

    /**
     * Resolve the OR-native `Agent` entity by uuid.
     *
     * @param string $agentId The agent UUID.
     *
     * @return Agent|null The resolved agent, or null when unresolvable.
     *
     * @spec openspec/changes/agent-webhook-trigger/tasks.md#task-4-webhookagentrunjob-webhookagentrunservice-governed-dispatch
     */
    private function resolveAgent(string $agentId): ?Agent
    {
        if ($agentId === '') {
            return null;
        }

        try {
            return $this->agentMapper->findByUuid($agentId);
        } catch (Throwable $e) {
            return null;
        }

    }//end resolveAgent()

    /**
     * Write a redacted, explicit per-run AuditTrail entry against the Agent's
     * ObjectEntity — mirrors ScheduleService/FlowAgentRunService's
     * redaction-before-persist contract. Non-fatal by design.
     *
     * @param ObjectEntity           $agentObject The Agent's ObjectEntity (audit target).
     * @param string                 $status      The run outcome (ok|error|skipped_killswitch|
     *                                            skipped_budget|awaiting_approval).
     * @param string                 $summary     The raw run output/error (redacted here).
     * @param array<string,mixed>    $context     The webhook trigger context (for agentId/
     *                                            correlationId diagnostics + the redacted payload).
     * @param DateTimeImmutable|null $startedAt   When the agent turn began, if it ran (UTC).
     *
     * @return void
     *
     * @spec openspec/changes/agent-webhook-trigger/specs/agent-webhook-trigger/spec.md#requirement-the-webhook-payload-becomes-run-input-redacted-before-persistence
     */
    private function writeRunAudit(
        ObjectEntity $agentObject,
        string $status,
        string $summary,
        array $context,
        ?DateTimeImmutable $startedAt=null
    ): void {
        try {
            $endedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $started = ($startedAt ?? $endedAt);
            $agentId = (string) ($context['agentId'] ?? '');

            $auditContext = [
                'status'        => $status,
                'agentId'       => $agentId,
                'correlationId' => (string) ($context['correlationId'] ?? ''),
                'startedAt'     => $started->format('c'),
                'endedAt'       => $endedAt->format('c'),
                // REDACTION-BEFORE-PERSIST: mask secrets/PII before the append-only write.
                'summary'       => $this->redactionService->redact($summary),
                'payload'       => $this->redactedPayload(context: $context),
                // Agent-versioning: the version of the agent config that actually ran
                // this occurrence (null when unresolvable — never fatal to the run).
                'agentVersion'  => $this->agentVersionService->currentVersionId(agentUuid: $agentId),
            ];

            $this->auditTrailMapper->createAuditTrailEntry(
                object: $agentObject,
                action: 'agent-run',
                context: $auditContext
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                sprintf(
                    'Hermiq could not write webhook-agent run audit for agent %s: %s',
                    (string) $agentObject->getUuid(),
                    $e->getMessage()
                ),
                ['exception' => $e]
            );
        }//end try

    }//end writeRunAudit()
}//end class
