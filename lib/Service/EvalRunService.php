<?php

/**
 * Hermiq EvalRunService.
 *
 * Orchestrates one EvalRun (agent-evals): loads the EvalDataset's cases, applies
 * the SAME kill-switch/budget-hard-cap gates a scheduled tick applies
 * (`ScheduleService::isOrganisationEngaged()`, `BudgetService::isBlocked()`), then
 * executes each case through `ScheduleService::runAgentAsOwner()` — the SAME
 * impersonation and Engine/ChatService dual-path a schedule tick or "Run now" uses
 * — but NEVER calls `DeliveryService` (non-delivering: no Talk message, no
 * notification is ever sent for an eval case). Each case is scored via
 * `EvalScoringService` (deterministic or LLM-as-judge), the aggregate pass-rate is
 * compared against the immediately preceding completed EvalRun for the same
 * dataset+agent as a regression gate, and one redacted `action='run'` AuditTrail
 * entry is written so the run's usage rolls into the SAME budget total a
 * scheduled run's does (`BudgetService::loadScheduleUuidsForScope()` widening).
 *
 * This is a recognised ADR-031 imperative exception, exactly like ScheduleService
 * and FlowAgentRunService: a side-effecting governance orchestrator, not a derived
 * value or declarative lifecycle. Hermiq owns no LLM/tool engine of its own beyond
 * the already-shipped in-app Engine (agent-engine-port) — this adds NO new
 * execution path, only the scoring layer and run/dataset bookkeeping.
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
 * @spec openspec/changes/agent-evals/tasks.md#task-6-evalrunservice-orchestration
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Hermiq\AppInfo\Application;
use OCA\OpenRegister\Db\Agent;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Executes an EvalRun: gate checks, per-case execution + scoring, regression gate,
 * persistence, and the redacted audit write.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Coordinates several OR/Hermiq services.
 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Constructor DI: each parameter is a
 *   distinct injected collaborator, not a logic-bearing argument list.
 *
 * @spec openspec/changes/agent-evals/tasks.md#task-6-evalrunservice-orchestration
 */
class EvalRunService
{

    /**
     * OpenRegister register slug that holds Hermiq objects.
     *
     * @var string
     */
    private const REGISTER_SLUG = 'hermiq';

    /**
     * OpenRegister schema slug for EvalRun objects.
     *
     * @var string
     */
    private const EVALRUN_SCHEMA = 'evalrun';

    /**
     * The audit action written per run — the SAME action
     * `BudgetService::loadScheduleUuidsForScope()` unions EvalRun UUIDs against, so
     * eval spend rolls into the same budget total a Schedule's usage does.
     *
     * @var string
     */
    private const RUN_ACTION = 'run';

    /**
     * IAppConfig key (app `hermiq`) for the instance-wide regression-gate threshold
     * (percentage points of pass-rate drop). A per-trigger request may override it.
     *
     * @var string
     */
    private const REGRESSION_THRESHOLD_KEY = 'eval.regressionThresholdPercent';

    /**
     * Default regression-gate threshold (percentage points) when unset.
     *
     * @var int
     */
    private const DEFAULT_REGRESSION_THRESHOLD = 10;

    /**
     * Constructor.
     *
     * @param ObjectService      $objectService    Loads the dataset's cases source-of-truth
     *                                             and persists the EvalRun (single
     *                                             write-path).
     * @param ScheduleService    $scheduleService  Reused kill-switch check
     *                                             (`isOrganisationEngaged()`)
     *                                             AND the reused
     *                                             agent-turn dispatch
     *                                             (`runAgentAsOwner()`)
     *                                             — the SAME
     *                                             ScheduleService/Engine
     *                                             path a scheduled run
     *                                             uses.
     * @param BudgetService      $budgetService    Reused budget hard-cap gate + soft-threshold
     *                                             warning (cost-guardrails).
     * @param EvalScoringService $scoringService   Deterministic + LLM-as-judge case scoring.
     * @param AuditTrailMapper   $auditTrailMapper OR audit write-path for the redacted
     *                                             per-run entry.
     * @param RedactionService   $redactionService Masks secrets/PII before the audit write.
     * @param IAppConfig         $appConfig        Reads the instance-wide regression-gate
     *                                             threshold default.
     * @param LoggerInterface    $logger           Logs gate skips + non-fatal failures.
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly ScheduleService $scheduleService,
        private readonly BudgetService $budgetService,
        private readonly EvalScoringService $scoringService,
        private readonly AuditTrailMapper $auditTrailMapper,
        private readonly RedactionService $redactionService,
        private readonly IAppConfig $appConfig,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Run the given EvalDataset against the given Agent, applying the same
     * governance gates a scheduled tick applies before ever invoking the agent.
     *
     * @param ObjectEntity $dataset                     The EvalDataset (already owner-guarded
     *                                                  by the controller).
     * @param Agent        $agent                       The target Agent (already owner-guarded
     *                                                  by the controller).
     * @param string|null  $agentVersionId              Reserved for agent-versioning
     *                                                  (not yet built) — accepted
     *                                                  and stored verbatim, never
     *                                                  resolved or validated.
     * @param int|null     $regressionThresholdOverride A per-trigger override (percentage
     *                                                  points) for the regression-gate
     *                                                  threshold; null uses the
     *                                                  instance-wide IAppConfig default.
     *
     * @return array{evalRunId:string,status:string,passRate:float,regressionGateResult:string,previousPassRate:?float}
     *
     * @spec openspec/changes/agent-evals/specs/agent-evals/spec.md#requirement-kill-switch-and-budget-hard-cap-gate-an-eval-run-exactly-as-they-gate-a-schedule-tick
     */
    public function run(
        ObjectEntity $dataset,
        Agent $agent,
        ?string $agentVersionId=null,
        ?int $regressionThresholdOverride=null
    ): array {
        $organisation = (string) ($agent->getOrganisation() ?? '');
        $agentId      = (string) $agent->getUuid();
        $datasetId    = (string) $dataset->getUuid();
        $owner        = (string) ($agent->getOwner() ?? '');
        $startedAt    = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        // GATE 1 — KILL-SWITCH (highest priority, exactly like ScheduleService::dispatch()).
        if ($organisation !== '' && $this->scheduleService->isOrganisationEngaged(organisation: $organisation) === true) {
            return $this->persistGateSkip(
                dataset: $dataset,
                agentId: $agentId,
                organisation: $organisation,
                agentVersionId: $agentVersionId,
                status: 'blocked_killswitch',
                startedAt: $startedAt
            );
        }

        // GATE 2 — BUDGET HARD CAP (cost-guardrails). Soft-threshold check is
        // unconditional (never fatal), mirroring ScheduleService::dispatch().
        try {
            $this->budgetService->checkAndDeliverWarnings(organisation: $organisation, agentId: $agentId);
        } catch (Throwable $e) {
            $this->logger->warning(
                'Hermiq eval-run budget soft-threshold check failed: '.$e->getMessage(),
                ['exception' => $e]
            );
        }

        if ($this->budgetService->isBlocked(organisation: $organisation, agentId: $agentId) === true) {
            return $this->persistGateSkip(
                dataset: $dataset,
                agentId: $agentId,
                organisation: $organisation,
                agentVersionId: $agentVersionId,
                status: 'blocked_budget',
                startedAt: $startedAt
            );
        }

        return $this->executeCases(
            dataset: $dataset,
            agent: $agent,
            organisation: $organisation,
            agentId: $agentId,
            datasetId: $datasetId,
            owner: $owner,
            agentVersionId: $agentVersionId,
            regressionThresholdOverride: $regressionThresholdOverride,
            startedAt: $startedAt
        );

    }//end run()

    /**
     * Execute every case in the dataset sequentially (never in parallel —
     * `IUserSession::setUser()` impersonation is not concurrency-safe, spec.md
     * Non-Functional Requirements), score each, compute the regression gate, persist
     * the EvalRun, and write the redacted audit entry.
     *
     * @param ObjectEntity      $dataset                     The EvalDataset.
     * @param Agent             $agent                       The target Agent.
     * @param string            $organisation                The agent's organisation.
     * @param string            $agentId                     The agent UUID.
     * @param string            $datasetId                   The dataset UUID.
     * @param string            $owner                       The agent's owner uid (impersonation
     *                                                       target).
     * @param string|null       $agentVersionId              Inert, forward-compatible field.
     * @param int|null          $regressionThresholdOverride Per-trigger threshold override.
     * @param DateTimeImmutable $startedAt                   When this run began.
     *
     * @return array{evalRunId:string,status:string,passRate:float,regressionGateResult:string,previousPassRate:?float}
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) Threaded call-context, not a
     *   logic-bearing argument list — every value is already resolved by run().
     *
     * @spec openspec/changes/agent-evals/specs/agent-evals/spec.md#requirement-an-evalrun-executes-each-case-through-the-agents-real-engine-path
     * @spec openspec/changes/agent-evals/specs/agent-evals/spec.md#requirement-eval-runs-are-non-delivering
     */
    private function executeCases(
        ObjectEntity $dataset,
        Agent $agent,
        string $organisation,
        string $agentId,
        string $datasetId,
        string $owner,
        ?string $agentVersionId,
        ?int $regressionThresholdOverride,
        DateTimeImmutable $startedAt
    ): array {
        $data  = $dataset->getObject();
        $cases = $data['cases'] ?? [];
        if (is_array($cases) === false) {
            $cases = [];
        }

        $results        = [];
        $passedCount    = 0;
        $hadInfraError  = false;
        $aggregateUsage = [
            'promptTokens'     => 0,
            'completionTokens' => 0,
        ];

        foreach (array_values($cases) as $index => $case) {
            if (is_array($case) === false) {
                continue;
            }

            $caseResult = $this->executeCase(
                case: $case,
                caseIndex: $index,
                owner: $owner,
                agentId: $agentId,
                organisation: $organisation,
                aggregateUsage: $aggregateUsage
            );

            if ($caseResult['infraError'] === true) {
                $hadInfraError = true;
            }

            if ($caseResult['passed'] === true) {
                $passedCount++;
            }

            unset($caseResult['infraError']);
            $results[] = $caseResult;
        }//end foreach

        $totalCases = count($results);
        $passRate   = 0.0;
        if ($totalCases > 0) {
            $passRate = ($passedCount / $totalCases);
        }

        $thresholdPercent = $regressionThresholdOverride;
        if ($thresholdPercent === null) {
            $thresholdPercent = (int) $this->appConfig->getValueString(
                Application::APP_ID,
                self::REGRESSION_THRESHOLD_KEY,
                (string) self::DEFAULT_REGRESSION_THRESHOLD
            );
        }

        [$regressionGateResult, $previousPassRate] = $this->evaluateRegressionGate(
            datasetId: $datasetId,
            agentId: $agentId,
            passRate: $passRate,
            thresholdPercent: $thresholdPercent
        );

        $status = 'completed';
        if ($hadInfraError === true) {
            $status = 'failed';
        }

        $endedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $saved = $this->persistEvalRun(
            datasetId: $datasetId,
            agentId: $agentId,
            organisation: $organisation,
            agentVersionId: $agentVersionId,
            status: $status,
            startedAt: $startedAt,
            endedAt: $endedAt,
            results: $results,
            passRate: $passRate,
            regressionGateResult: $regressionGateResult,
            previousPassRate: $previousPassRate,
            regressionThresholdPercent: $thresholdPercent
        );

        $this->writeRunAudit(
            evalRun: $saved,
            status: $status,
            usage: $aggregateUsage,
            passRate: $passRate,
            startedAt: $startedAt
        );

        return [
            'evalRunId'            => (string) $saved->getUuid(),
            'status'               => $status,
            'passRate'             => $passRate,
            'regressionGateResult' => $regressionGateResult,
            'previousPassRate'     => $previousPassRate,
        ];

    }//end executeCases()

    /**
     * Execute and score one case. Never throws — an agent-turn failure (an
     * infrastructure-level error, e.g. no LLM provider configured) is recorded as a
     * failed case with `infraError: true` so the caller can distinguish it from a
     * normal failed assertion, but the run continues to the next case regardless
     * (spec.md "one bad case does not abort the run").
     *
     * @param array<string,mixed> $case           The EvalCase.
     * @param int                 $caseIndex      The case's 0-based index.
     * @param string              $owner          The agent's owner uid (impersonation target).
     * @param string              $agentId        The agent UUID.
     * @param string              $organisation   The agent's organisation.
     * @param array<string,int>   $aggregateUsage Running prompt/completion token totals,
     *                                            accumulated in place across cases.
     *
     * @return array{caseIndex:int,prompt:string,expectationType:string,actualOutput:string,passed:bool,errorMessage:?string,score:?float,judgeRationale:?string,infraError:bool}
     */
    private function executeCase(
        array $case,
        int $caseIndex,
        string $owner,
        string $agentId,
        string $organisation,
        array &$aggregateUsage
    ): array {
        $prompt          = (string) ($case['prompt'] ?? '');
        $expectationType = (string) ($case['expectationType'] ?? '');

        try {
            // Reused verbatim — the SAME impersonation + feature-flagged engine
            // branch a scheduled run dispatches through. Never calls
            // DeliveryService: that call lives in ScheduleService::dispatch()'s
            // own delivery step, not inside runAgentAsOwner() itself, so an eval
            // case is non-delivering by construction, not by a special flag.
            $actualOutput = $this->scheduleService->runAgentAsOwner(
                owner: $owner,
                agentId: $agentId,
                prompt: $prompt,
                organisation: $organisation
            );

            $usage = $this->scheduleService->getLastRunUsage();
            $aggregateUsage['promptTokens']     += (int) ($usage['promptTokens'] ?? 0);
            $aggregateUsage['completionTokens'] += (int) ($usage['completionTokens'] ?? 0);
        } catch (Throwable $e) {
            $this->logger->warning(
                sprintf('Hermiq eval case %d failed to execute: %s', $caseIndex, $e->getMessage()),
                ['exception' => $e]
            );

            return [
                'caseIndex'       => $caseIndex,
                'prompt'          => $prompt,
                'expectationType' => $expectationType,
                'actualOutput'    => '',
                'passed'          => false,
                'errorMessage'    => 'Agent run failed: '.$e->getMessage(),
                'score'           => null,
                'judgeRationale'  => null,
                'infraError'      => true,
            ];
        }//end try

        $scored = $this->scoringService->score(case: $case, actualOutput: $actualOutput, organisation: $organisation);

        return [
            'caseIndex'       => $caseIndex,
            'prompt'          => $prompt,
            'expectationType' => $expectationType,
            'actualOutput'    => $actualOutput,
            'passed'          => $scored['passed'],
            'errorMessage'    => $scored['errorMessage'],
            'score'           => $scored['score'],
            'judgeRationale'  => $scored['judgeRationale'],
            'infraError'      => false,
        ];

    }//end executeCase()

    /**
     * Compare `$passRate` against the immediately preceding completed EvalRun for
     * the same dataset+agent.
     *
     * @param string $datasetId        The dataset UUID.
     * @param string $agentId          The agent UUID.
     * @param float  $passRate         This run's aggregate pass-rate.
     * @param int    $thresholdPercent The effective regression threshold (percentage
     *                                 points of pass-rate drop).
     *
     * @return array{0:string,1:?float} `[regressionGateResult, previousPassRate]`.
     *
     * @spec openspec/changes/agent-evals/specs/agent-evals/spec.md#requirement-regression-gate-compares-aggregate-pass-rate-against-the-previous-run
     */
    private function evaluateRegressionGate(string $datasetId, string $agentId, float $passRate, int $thresholdPercent): array
    {
        $previous = $this->findPreviousCompletedRun(datasetId: $datasetId, agentId: $agentId);
        if ($previous === null) {
            return ['not_applicable', null];
        }

        $previousData      = $previous->getObject();
        $previousPassRate  = (float) ($previousData['passRate'] ?? 0.0);
        $dropPercentPoints = (($previousPassRate - $passRate) * 100);

        if ($dropPercentPoints > $thresholdPercent) {
            return ['failed', $previousPassRate];
        }

        return ['passed', $previousPassRate];

    }//end evaluateRegressionGate()

    /**
     * Load the most recent completed EvalRun for the same dataset+agent, system-wide
     * (fails open to null on a read error — a regression-gate comparison is never
     * worth breaking the run over).
     *
     * @param string $datasetId The dataset UUID.
     * @param string $agentId   The agent UUID.
     *
     * @return ObjectEntity|null The most recent completed run, or null when none exists.
     */
    private function findPreviousCompletedRun(string $datasetId, string $agentId): ?ObjectEntity
    {
        try {
            $objects = $this->objectService
                ->setRegister(self::REGISTER_SLUG)
                ->setSchema(self::EVALRUN_SCHEMA)
                ->findAll(config: ['limit' => 1000], _rbac: false, _multitenancy: false);
        } catch (Throwable $e) {
            $this->logger->warning(
                'Hermiq could not load prior eval runs for the regression gate: '.$e->getMessage(),
                ['exception' => $e]
            );
            return null;
        }

        $matching = [];
        foreach ($objects as $object) {
            if (($object instanceof ObjectEntity) === false) {
                continue;
            }

            $data = $object->getObject();
            if ((string) ($data['datasetId'] ?? '') !== $datasetId) {
                continue;
            }

            if ((string) ($data['agentId'] ?? '') !== $agentId) {
                continue;
            }

            if ((string) ($data['status'] ?? '') !== 'completed') {
                continue;
            }

            $matching[] = $object;
        }//end foreach

        if ($matching === []) {
            return null;
        }

        usort(
            $matching,
            function (ObjectEntity $left, ObjectEntity $right): int {
                $leftCreated  = ($left->getCreated() ?? new \DateTime('@0'));
                $rightCreated = ($right->getCreated() ?? new \DateTime('@0'));
                return ($rightCreated <=> $leftCreated);
            }
        );

        return $matching[0];

    }//end findPreviousCompletedRun()

    /**
     * Persist the completed (or failed) EvalRun object via OpenRegister's single
     * write-path, pinned to the target agent's organisation (mirrors
     * `BudgetService::create()`'s `@self.organisation` trick) rather than the
     * acting user's own active organisation.
     *
     * @param string                         $datasetId                  The dataset UUID.
     * @param string                         $agentId                    The agent UUID.
     * @param string                         $organisation               The agent's organisation.
     * @param string|null                    $agentVersionId             Inert field.
     * @param string                         $status                     completed|failed.
     * @param DateTimeImmutable              $startedAt                  When the run began.
     * @param DateTimeImmutable              $endedAt                    When the run finished.
     * @param array<int,array<string,mixed>> $results                    Per-case results.
     * @param float                          $passRate                   Aggregate pass-rate.
     * @param string                         $regressionGateResult       passed|failed|not_applicable.
     * @param float|null                     $previousPassRate           The compared-against prior run's pass-rate.
     * @param int                            $regressionThresholdPercent The effective threshold applied.
     *
     * @return ObjectEntity The persisted EvalRun.
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) Every field is a distinct EvalRun
     *   column being persisted, not a logic-bearing argument list.
     */
    private function persistEvalRun(
        string $datasetId,
        string $agentId,
        string $organisation,
        ?string $agentVersionId,
        string $status,
        DateTimeImmutable $startedAt,
        DateTimeImmutable $endedAt,
        array $results,
        float $passRate,
        string $regressionGateResult,
        ?float $previousPassRate,
        int $regressionThresholdPercent
    ): ObjectEntity {
        $evalRunData = [
            'datasetId'                  => $datasetId,
            'agentId'                    => $agentId,
            'agentVersionId'             => $agentVersionId,
            'status'                     => $status,
            'startedAt'                  => $startedAt->format('c'),
            'endedAt'                    => $endedAt->format('c'),
            'results'                    => $results,
            'passRate'                   => $passRate,
            'regressionGateResult'       => $regressionGateResult,
            'previousPassRate'           => $previousPassRate,
            'regressionThresholdPercent' => $regressionThresholdPercent,
            '@self'                      => ['organisation' => $organisation],
        ];

        return $this->objectService->saveObject(
            object: $evalRunData,
            register: self::REGISTER_SLUG,
            schema: self::EVALRUN_SCHEMA,
            _rbac: false,
            _multitenancy: false
        );

    }//end persistEvalRun()

    /**
     * Persist a gate-skipped EvalRun (kill-switch or budget hard cap blocked every
     * case) and write its audit entry, mirroring `ScheduleService::dispatch()`'s
     * `recordGateSkip()`.
     *
     * @param ObjectEntity      $dataset        The EvalDataset.
     * @param string            $agentId        The agent UUID.
     * @param string            $organisation   The agent's organisation.
     * @param string|null       $agentVersionId Inert field.
     * @param string            $status         blocked_killswitch|blocked_budget.
     * @param DateTimeImmutable $startedAt      When the (skipped) run was triggered.
     *
     * @return array{evalRunId:string,status:string,passRate:float,regressionGateResult:string,previousPassRate:?float}
     */
    private function persistGateSkip(
        ObjectEntity $dataset,
        string $agentId,
        string $organisation,
        ?string $agentVersionId,
        string $status,
        DateTimeImmutable $startedAt
    ): array {
        $endedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $saved = $this->persistEvalRun(
            datasetId: (string) $dataset->getUuid(),
            agentId: $agentId,
            organisation: $organisation,
            agentVersionId: $agentVersionId,
            status: $status,
            startedAt: $startedAt,
            endedAt: $endedAt,
            results: [],
            passRate: 0.0,
            regressionGateResult: 'not_applicable',
            previousPassRate: null,
            regressionThresholdPercent: 0
        );

        $this->writeRunAudit(
            evalRun: $saved,
            status: $status,
            usage: [],
            passRate: 0.0,
            startedAt: $startedAt
        );

        return [
            'evalRunId'            => (string) $saved->getUuid(),
            'status'               => $status,
            'passRate'             => 0.0,
            'regressionGateResult' => 'not_applicable',
            'previousPassRate'     => null,
        ];

    }//end persistGateSkip()

    /**
     * Write one redacted `action='run'` AuditTrail entry on the persisted EvalRun —
     * mirrors `ScheduleService::writeRunAudit()`'s redaction-before-persist contract
     * and `action` value, so `BudgetService::loadScheduleUuidsForScope()`'s widened
     * scan counts this run's usage toward the same budget total a Schedule's does.
     * Non-fatal: an audit-write failure never fails the eval run itself.
     *
     * @param ObjectEntity      $evalRun   The persisted EvalRun.
     * @param string            $status    completed|failed|blocked_killswitch|blocked_budget.
     * @param array<string,int> $usage     Aggregated prompt/completion token usage across cases.
     * @param float             $passRate  The run's aggregate pass-rate (for the audit summary).
     * @param DateTimeImmutable $startedAt When the run began.
     *
     * @return void
     */
    private function writeRunAudit(ObjectEntity $evalRun, string $status, array $usage, float $passRate, DateTimeImmutable $startedAt): void
    {
        try {
            $endedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $summary = sprintf('Eval run %s — pass rate %.2f', $status, $passRate);

            $context = [
                'status'     => $status,
                'startedAt'  => $startedAt->format('c'),
                'endedAt'    => $endedAt->format('c'),
                'durationMs' => (((int) $endedAt->format('U') - (int) $startedAt->format('U')) * 1000),
                // Run-analytics/cost-guardrails: the SAME shape ScheduleService::writeRunAudit()
                // records, so BudgetService's existing `currentUsageTokens()` reader needs no
                // eval-specific branch.
                'usage'      => $usage,
                // REDACTION-BEFORE-PERSIST: mask secrets/PII before the append-only write
                // (ADR-004). The raw, unredacted per-case output only ever lives on the
                // EvalRun object itself (tenant/RBAC-scoped like any other Hermiq object).
                'summary'    => $this->redactionService->redact($summary),
            ];

            $this->auditTrailMapper->createAuditTrailEntry(
                object: $evalRun,
                action: self::RUN_ACTION,
                context: $context
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                sprintf(
                    'Hermiq could not write eval-run audit for %s: %s',
                    (string) $evalRun->getUuid(),
                    $e->getMessage()
                ),
                ['exception' => $e]
            );
        }//end try

    }//end writeRunAudit()
}//end class
