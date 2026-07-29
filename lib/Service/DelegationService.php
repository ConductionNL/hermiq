<?php

/**
 * Hermiq DelegationService (sub-agent-delegation).
 *
 * The governed dispatcher behind the `hermiq.delegateAgent` tool: a fixed,
 * ordered set of synchronous refusal checks (self/cycle → allowlist → depth/
 * fan-out → same-organisation → tenant-model-policy → kill-switch → budget →
 * target-requires-approval), read from the request-scoped `DelegationContext`
 * — never from the LLM's own tool-call arguments — before ever invoking the
 * target agent via the EXISTING `ScheduleService::runAgentAsOwner()` path (the
 * same one a scheduled tick, Run-now, or a flow-triggered run already calls).
 *
 * This is a NEW entry point into EXISTING governance rails, not a second
 * enforcement path: the kill-switch read (`ScheduleService::isOrganisationEngaged()`),
 * the budget hard-cap/soft-threshold check (`BudgetService`), and the tenant
 * model-policy check (`TenantModelPolicyService`) are the SAME services every
 * other governed run already calls.
 *
 * Never throws: every refusal returns a structured `{error:{code,message}}`
 * envelope, mirroring `HermiqToolProvider`'s own contract — a delegation
 * refusal degrades to a clear, LLM-visible error message, never an aborted
 * parent turn.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Hermiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/sub-agent-delegation/specs/sub-agent-delegation/spec.md#requirement-self-delegation-and-delegation-cycles-are-refused
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Hermiq\AppInfo\Application;
use OCA\Hermiq\Service\Engine\DelegationContext;
use OCA\Hermiq\Service\Engine\DelegationFrame;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Governed dispatcher for `hermiq.delegateAgent` (sub-agent-delegation).
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Coordinates the SAME
 *   governance services every other Hermiq entry point already depends on
 *   (kill-switch, budget, model-policy) — this is the point of reuse, not
 *   incidental complexity.
 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Constructor DI: each
 *   parameter is a distinct injected collaborator, not a logic-bearing
 *   argument list.
 * @SuppressWarnings(PHPMD.LongVariable)           `$tenantModelPolicyService` is a promoted
 *   constructor collaborator named after its class (TenantModelPolicyService) —
 *   shortening it would obscure which service is injected.
 *
 * @spec openspec/changes/sub-agent-delegation/specs/sub-agent-delegation/spec.md#requirement-self-delegation-and-delegation-cycles-are-refused
 */
class DelegationService
{

    /**
     * OpenRegister register slug that holds Hermiq objects.
     *
     * @var string
     */
    private const REGISTER_SLUG = 'hermiq';

    /**
     * OpenRegister schema slug for agent objects.
     *
     * @var string
     */
    private const AGENT_SCHEMA = 'agent';

    /**
     * The audit action written for a delegated sub-run — the SAME action
     * `ScheduleService::writeRunAudit()` uses, so a delegated run anchored to
     * a Schedule lands inside `BudgetService::currentUsageTokens()`'s
     * EXISTING `action='run'` aggregation window with zero changes to
     * `BudgetService` itself (design.md Decision 5).
     *
     * @var string
     */
    private const RUN_ACTION = 'run';

    /**
     * IAppConfig key (app `hermiq`) for the instance-wide maximum delegation
     * chain depth.
     *
     * @var string
     */
    private const MAX_DEPTH_KEY = 'delegation.maxDepth';

    /**
     * IAppConfig key (app `hermiq`) for the instance-wide maximum number of
     * delegate calls one agent turn may make.
     *
     * @var string
     */
    private const MAX_FAN_OUT_KEY = 'delegation.maxFanOut';

    /**
     * Default maximum delegation chain depth when `delegation.maxDepth` is unset.
     *
     * @var string
     */
    private const DEFAULT_MAX_DEPTH = '2';

    /**
     * Default maximum per-turn fan-out when `delegation.maxFanOut` is unset.
     *
     * @var string
     */
    private const DEFAULT_MAX_FAN_OUT = '3';

    /**
     * Constructor.
     *
     * @param ObjectService            $objectService            Resolves caller/target Agent objects.
     * @param IAppConfig               $appConfig                Reads `delegation.maxDepth`/`delegation.maxFanOut`.
     * @param DelegationContext        $delegationContext        Trusted, request-scoped call-stack — the
     *                                                           ONLY source of depth/ancestry/fan-out/anchor.
     * @param TenantModelPolicyService $tenantModelPolicyService GATE 4: the target agent's provider/model
     *                                                           against the organisation's effective
     *                                                           ModelPolicy.
     * @param ScheduleService          $scheduleService          GATE 5 (kill-switch, reused) and the actual
     *                                                           governed dispatch (`runAgentAsOwner()`,
     *                                                           `getLastRunId()`).
     * @param BudgetService            $budgetService            GATE 6: the organisation's/target agent's
     *                                                           budget hard cap + soft-threshold warning.
     * @param AuditTrailMapper         $auditTrailMapper         OR audit write-path for the delegated sub-run's
     *                                                           own `AuditTrail` entry.
     * @param RedactionService         $redactionService         Masks secrets/PII before the audit write.
     * @param IUserSession             $userSession              The CURRENTLY impersonated user —
     *                                                           since `delegate()` only ever runs
     *                                                           mid-turn, inside the parent's own
     *                                                           impersonation, this IS the parent's
     *                                                           already-resolved acting uid.
     * @param LoggerInterface          $logger                   PSR-3 logger (fail-open diagnostics, non-fatal
     *                                                           warnings).
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly IAppConfig $appConfig,
        private readonly DelegationContext $delegationContext,
        private readonly TenantModelPolicyService $tenantModelPolicyService,
        private readonly ScheduleService $scheduleService,
        private readonly BudgetService $budgetService,
        private readonly AuditTrailMapper $auditTrailMapper,
        private readonly RedactionService $redactionService,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Delegate a bounded sub-task from `$callerAgentId` to `$targetAgentId`.
     *
     * Never throws: every refusal is a structured `{error:{code,message}}`
     * envelope; on success `{targetAgentId, result}` is returned.
     *
     * @param string $callerAgentId The delegating agent's own UUID (server-injected —
     *                              see `Engine\FacadeToolInvoker::withAgentId()` — NEVER
     *                              read from the tool call's own arguments).
     * @param string $targetAgentId The agent UUID to delegate to (LLM-supplied).
     * @param string $task          The bounded task/prompt to hand to the target (LLM-supplied).
     *
     * @return array<string, mixed> `{targetAgentId, result}` on success, or
     *                               `{error: {code, message}}` on refusal/failure.
     *
     * @spec openspec/changes/sub-agent-delegation/specs/sub-agent-delegation/spec.md#requirement-self-delegation-and-delegation-cycles-are-refused
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) A fixed, ordered gate sequence
     *   (self/cycle → allowlist → depth/fan-out → same-organisation → model-policy →
     *   kill-switch → budget → approval) — each gate's OWN logic already lives in its
     *   own small, independently-tested private method; this method is the orchestrator
     *   deciding whether to proceed to the next one, mirroring
     *   `ScheduleService::dispatch()`'s identical shape for the same reason.
     * @SuppressWarnings(PHPMD.NPathComplexity)      Same reasoning as above.
     */
    public function delegate(string $callerAgentId, string $targetAgentId, string $task): array
    {
        $callerFrame        = $this->delegationContext->current();
        $callerOrganisation = ($callerFrame?->organisation ?? '');

        $refusal = $this->checkSelfAndCycle(callerAgentId: $callerAgentId, targetAgentId: $targetAgentId);
        if ($refusal !== null) {
            return $refusal;
        }

        $callerAgent = $this->findAgent(agentId: $callerAgentId);
        $refusal     = $this->checkAllowlist(callerAgent: $callerAgent, targetAgentId: $targetAgentId);
        if ($refusal !== null) {
            return $refusal;
        }

        $refusal = $this->checkBounds(frame: $callerFrame);
        if ($refusal !== null) {
            return $refusal;
        }

        $targetAgent = $this->findAgent(agentId: $targetAgentId);
        if ($targetAgent === null) {
            return $this->refuse(
                code: 'delegation_target_not_found',
                message: "Delegation refused: target agent '{$targetAgentId}' does not exist."
            );
        }

        $targetData         = $targetAgent->getObject();
        $targetOrganisation = (string) ($targetAgent->getOrganisation() ?? '');
        if ($targetOrganisation !== $callerOrganisation) {
            return $this->refuse(
                code: 'delegation_cross_organisation',
                message: 'Delegation refused: the target agent belongs to a different organisation.'
            );
        }

        $refusal = $this->checkModelPolicy(organisation: $callerOrganisation, targetData: $targetData);
        if ($refusal !== null) {
            return $refusal;
        }

        if ($this->scheduleService->isOrganisationEngaged(organisation: $callerOrganisation) === true) {
            return $this->refuse(
                code: 'delegation_killswitch',
                message: 'Delegation refused: the organisation kill-switch is engaged.'
            );
        }

        $refusal = $this->checkBudget(organisation: $callerOrganisation, targetAgentId: $targetAgentId);
        if ($refusal !== null) {
            return $refusal;
        }

        if ((bool) ($targetData['requiresApproval'] ?? false) === true) {
            return $this->refuse(
                code: 'delegation_requires_approval',
                message: 'Delegation refused: the target agent requires human approval and cannot be reached '
                    .'via delegation. It remains runnable via its own schedule or flow trigger.'
            );
        }

        return $this->runDelegatedTurn(
            targetAgent: $targetAgent,
            task: $task,
            organisation: $callerOrganisation,
            callerFrame: $callerFrame
        );

    }//end delegate()

    /**
     * GATE 0 — self-delegation and delegation cycles, checked BEFORE the
     * allowlist (spec: refused regardless of what any allowlist permits).
     *
     * @param string $callerAgentId The delegating agent's UUID.
     * @param string $targetAgentId The requested target's UUID.
     *
     * @return array<string, mixed>|null The refusal envelope, or null when neither applies.
     *
     * @spec openspec/changes/sub-agent-delegation/specs/sub-agent-delegation/spec.md#requirement-self-delegation-and-delegation-cycles-are-refused
     */
    private function checkSelfAndCycle(string $callerAgentId, string $targetAgentId): ?array
    {
        if ($targetAgentId === $callerAgentId) {
            return $this->refuse(code: 'delegation_self', message: 'Delegation refused: an agent cannot delegate to itself.');
        }

        if (in_array($targetAgentId, $this->delegationContext->ancestorAgentIds(), true) === true) {
            return $this->refuse(
                code: 'delegation_cycle',
                message: 'Delegation refused: the target agent already appears in this delegation chain.'
            );
        }

        return null;

    }//end checkSelfAndCycle()

    /**
     * GATE 1 — the target must be explicitly named on the caller's
     * `delegationAllowlist`. A caller that could not be resolved is treated
     * as an empty allowlist (fail closed).
     *
     * @param ObjectEntity|null $callerAgent   The resolved caller Agent, or null.
     * @param string            $targetAgentId The requested target's UUID.
     *
     * @return array<string, mixed>|null The refusal envelope, or null when allowed.
     *
     * @spec openspec/changes/sub-agent-delegation/specs/sub-agent-delegation/spec.md#requirement-delegation-is-refused-by-default-until-explicitly-allowlisted
     */
    private function checkAllowlist(?ObjectEntity $callerAgent, string $targetAgentId): ?array
    {
        $allowlist = [];
        if ($callerAgent !== null) {
            $allowlist = (array) ($callerAgent->getObject()['delegationAllowlist'] ?? []);
        }

        if (in_array($targetAgentId, $allowlist, true) === false) {
            return $this->refuse(
                code: 'delegation_not_allowed',
                message: 'Delegation refused: the target agent is not on the calling agent\'s delegation allowlist.'
            );
        }

        return null;

    }//end checkAllowlist()

    /**
     * GATE 2 — depth/fan-out, read from the trusted `DelegationContext`
     * frame (never the tool call's own arguments).
     *
     * @param DelegationFrame|null $frame The calling agent's own current frame.
     *
     * @return array<string, mixed>|null The refusal envelope, or null when within bounds.
     *
     * @spec openspec/changes/sub-agent-delegation/specs/sub-agent-delegation/spec.md#requirement-delegation-depth-and-fan-out-are-bounded
     */
    private function checkBounds(?DelegationFrame $frame): ?array
    {
        $depth = ($frame?->depth ?? 0);
        if (($depth + 1) > $this->maxDepth()) {
            return $this->refuse(
                code: 'delegation_depth_exceeded',
                message: sprintf('Delegation refused: maximum delegation depth (%d) reached.', $this->maxDepth())
            );
        }

        $fanOut = ($frame?->fanOutCount ?? 0);
        if (($fanOut + 1) > $this->maxFanOut()) {
            return $this->refuse(
                code: 'delegation_fanout_exceeded',
                message: sprintf('Delegation refused: maximum delegation fan-out (%d) per turn reached.', $this->maxFanOut())
            );
        }

        return null;

    }//end checkBounds()

    /**
     * GATE 4 — the target agent's provider/model against the organisation's
     * effective ModelPolicy. Only checked when the target Agent explicitly
     * sets BOTH fields: an unset provider/model resolves against the
     * instance's default LLM configuration deep inside
     * `ProviderFactory::createChatDriver()`, which this pre-check cannot see
     * without duplicating that resolution — the SAME policy is still
     * enforced there, defense-in-depth, for every run regardless (see
     * `runDelegatedTurn()`'s catch-all).
     *
     * @param string               $organisation The caller's (and hence the target's,
     *                                           post cross-organisation check) organisation.
     * @param array<string, mixed> $targetData   The target Agent's object data.
     *
     * @return array<string, mixed>|null The refusal envelope, or null when allowed/unchecked.
     *
     * @spec openspec/changes/sub-agent-delegation/specs/sub-agent-delegation/spec.md#requirement-delegation-is-refused-when-gated-by-kill-switch-budget-or-a-target-requiring-approval
     */
    private function checkModelPolicy(string $organisation, array $targetData): ?array
    {
        $provider = trim((string) ($targetData['provider'] ?? ''));
        $model    = trim((string) ($targetData['model'] ?? ''));

        if ($provider === '' || $model === '') {
            return null;
        }

        if ($this->tenantModelPolicyService->isAllowed(organisation: $organisation, provider: $provider, model: $model) === true) {
            return null;
        }

        return $this->refuse(
            code: 'delegation_model_policy',
            message: 'Delegation refused: the target agent\'s provider/model is outside this organisation\'s effective model policy.'
        );

    }//end checkModelPolicy()

    /**
     * GATE 6 — the SAME budget hard-cap/soft-threshold check a scheduled or
     * flow-triggered run already applies, scoped to the target agent within
     * the caller's organisation.
     *
     * @param string $organisation  The caller's organisation.
     * @param string $targetAgentId The target agent's UUID.
     *
     * @return array<string, mixed>|null The refusal envelope, or null when not blocked.
     *
     * @spec openspec/changes/sub-agent-delegation/specs/sub-agent-delegation/spec.md#requirement-delegation-is-refused-when-gated-by-kill-switch-budget-or-a-target-requiring-approval
     */
    private function checkBudget(string $organisation, string $targetAgentId): ?array
    {
        try {
            $this->budgetService->checkAndDeliverWarnings(organisation: $organisation, agentId: $targetAgentId);
        } catch (Throwable $e) {
            $this->logger->warning(
                'Hermiq delegation budget soft-threshold check failed: '.$e->getMessage(),
                ['exception' => $e]
            );
        }

        if ($this->budgetService->isBlocked(organisation: $organisation, agentId: $targetAgentId) === true) {
            return $this->refuse(
                code: 'delegation_budget_exhausted',
                message: 'Delegation refused: the budget for this scope has reached its hard cap.'
            );
        }

        return null;

    }//end checkBudget()

    /**
     * Every gate passed — actually invoke the target agent via the EXISTING
     * `ScheduleService::runAgentAsOwner()` path, with `forceOwner: true` (so
     * the target's own `actingUser` cannot launder attribution) and the
     * caller's own anchor (so `BudgetService`'s existing aggregation counts
     * this sub-run against the SAME budget the whole tree counts against).
     * Writes its own `AuditTrail` entry, `runId`/`parentRunId` populated,
     * anchored the same way — never throws.
     *
     * @param ObjectEntity         $targetAgent  The resolved target Agent object.
     * @param string               $task         The bounded task/prompt (LLM-supplied).
     * @param string               $organisation The caller's organisation.
     * @param DelegationFrame|null $callerFrame  The calling agent's own current frame
     *                                           (its `runId` becomes this sub-run's
     *                                           `parentRunId`; its `anchor` is reused
     *                                           verbatim).
     *
     * @return array<string, mixed> `{targetAgentId, result}` on success, or a
     *                               `delegation_failed` error envelope.
     *
     * @spec openspec/changes/sub-agent-delegation/specs/sub-agent-delegation/spec.md#requirement-delegated-runs-inherit-the-parents-acting-user-attribution
     * @spec openspec/changes/sub-agent-delegation/specs/sub-agent-delegation/spec.md#requirement-delegation-is-traceable-as-one-auditable-tree
     */
    private function runDelegatedTurn(
        ObjectEntity $targetAgent,
        string $task,
        string $organisation,
        ?DelegationFrame $callerFrame
    ): array {
        $targetAgentId = (string) $targetAgent->getUuid();

        $owner = (string) ($this->userSession->getUser()?->getUID() ?? '');
        if ($owner === '') {
            return $this->refuse(
                code: 'delegation_failed',
                message: 'Delegation refused: no acting user identity is available for this run.'
            );
        }

        // Only a gate-PASSED, actually-attempted call counts toward fan-out —
        // a refused call above never reaches this line (spec).
        $this->delegationContext->incrementFanOut();

        $anchor    = $callerFrame?->anchor;
        $startedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $status = 'ok';
        $result = '';
        try {
            $result = $this->scheduleService->runAgentAsOwner(
                owner: $owner,
                agentId: $targetAgentId,
                prompt: $task,
                organisation: $organisation,
                forceOwner: true,
                anchor: $anchor
            );
        } catch (Throwable $e) {
            $status = 'error';
            $result = 'error: '.$e->getMessage();
            $this->logger->warning('Hermiq delegated agent run failed: '.$e->getMessage(), ['exception' => $e]);
        }

        $this->writeDelegationAudit(
            auditTarget: ($anchor ?? $targetAgent),
            targetAgentId: $targetAgentId,
            status: $status,
            summary: $result,
            runId: $this->scheduleService->getLastRunId(),
            parentRunId: $callerFrame?->runId,
            startedAt: $startedAt
        );

        if ($status === 'error') {
            return $this->refuse(code: 'delegation_failed', message: 'The delegated agent run failed.');
        }

        return ['targetAgentId' => $targetAgentId, 'result' => $result];

    }//end runDelegatedTurn()

    /**
     * Write the delegated sub-run's own `AuditTrail` entry — never fatal by
     * contract.
     *
     * @param ObjectEntity      $auditTarget   The object this entry is written against
     *                                         (the shared anchor, or the target agent
     *                                         itself when no anchor is available).
     * @param string            $targetAgentId The delegated-to agent's UUID.
     * @param string            $status        `ok`|`error`.
     * @param string            $summary       The raw run output/error (redacted here).
     * @param string            $runId         This sub-run's own fresh run identifier.
     * @param string|null       $parentRunId   The calling run's own `runId`.
     * @param DateTimeImmutable $startedAt     When the delegated turn began.
     *
     * @return void
     *
     * @spec openspec/changes/sub-agent-delegation/specs/sub-agent-delegation/spec.md#requirement-delegation-is-traceable-as-one-auditable-tree
     */
    private function writeDelegationAudit(
        ObjectEntity $auditTarget,
        string $targetAgentId,
        string $status,
        string $summary,
        string $runId,
        ?string $parentRunId,
        DateTimeImmutable $startedAt
    ): void {
        try {
            $endedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));

            $context = [
                'status'      => $status,
                'agentId'     => $targetAgentId,
                'delegated'   => true,
                'runId'       => $runId,
                'parentRunId' => $parentRunId,
                'startedAt'   => $startedAt->format('c'),
                'endedAt'     => $endedAt->format('c'),
                // REDACTION-BEFORE-PERSIST: mask secrets/PII before the append-only write.
                'summary'     => $this->redactionService->redact($summary),
            ];

            $this->auditTrailMapper->createAuditTrailEntry(
                object: $auditTarget,
                action: self::RUN_ACTION,
                context: $context
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                sprintf('Hermiq could not write delegation audit for agent %s: %s', $targetAgentId, $e->getMessage()),
                ['exception' => $e]
            );
        }//end try

    }//end writeDelegationAudit()

    /**
     * Resolve an Agent object system-wide (mirrors
     * `ScheduleService::resolveActingUser()`'s read posture — this runs
     * mid-turn, with no fresh RBAC context of its own to re-derive).
     *
     * @param string $agentId The Agent UUID to resolve.
     *
     * @return ObjectEntity|null The resolved Agent, or null when unresolvable.
     */
    private function findAgent(string $agentId): ?ObjectEntity
    {
        if ($agentId === '') {
            return null;
        }

        try {
            return $this->objectService->find(
                id: $agentId,
                register: self::REGISTER_SLUG,
                schema: self::AGENT_SCHEMA,
                _rbac: false,
                _multitenancy: false
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                sprintf('Hermiq delegation could not resolve agent %s: %s', $agentId, $e->getMessage()),
                ['exception' => $e]
            );
            return null;
        }

    }//end findAgent()

    /**
     * The instance-wide maximum delegation chain depth (`delegation.maxDepth`,
     * default 2).
     *
     * @return int
     */
    private function maxDepth(): int
    {
        return max(1, (int) $this->appConfig->getValueString(Application::APP_ID, self::MAX_DEPTH_KEY, self::DEFAULT_MAX_DEPTH));

    }//end maxDepth()

    /**
     * The instance-wide maximum per-turn fan-out (`delegation.maxFanOut`,
     * default 3).
     *
     * @return int
     */
    private function maxFanOut(): int
    {
        return max(1, (int) $this->appConfig->getValueString(Application::APP_ID, self::MAX_FAN_OUT_KEY, self::DEFAULT_MAX_FAN_OUT));

    }//end maxFanOut()

    /**
     * Build a structured refusal envelope (never throws — mirrors
     * `HermiqToolProvider`'s own contract).
     *
     * @param string $code    The machine error code.
     * @param string $message The human-readable message.
     *
     * @return array<string, mixed> The error envelope.
     */
    private function refuse(string $code, string $message): array
    {
        return ['error' => ['code' => $code, 'message' => $message]];

    }//end refuse()
}//end class
