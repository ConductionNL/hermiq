<?php

/**
 * Hermiq BudgetService.
 *
 * Per-organisation/per-agent spend guardrail (cost-guardrails): computes current-period
 * usage from the SAME `action='run'` OpenRegister AuditTrail entries `AnalyticsService`
 * already aggregates (no stored counter, no new telemetry pipeline), exposes a hard-cap
 * gate check (`isBlocked()`) for `ScheduleService::dispatch()`/`FlowAgentRunService`, a
 * soft-threshold warning delivery (`checkAndDeliverWarnings()`/`recordWarningIfDue()`, one
 * per period), tenant-scoped CRUD + status for `BudgetController`, and a pre-run cost
 * estimate (`estimateNextRun()`) derived from `AnalyticsService::computeAnalytics()`.
 *
 * Two distinct read postures are used deliberately:
 *   - The dispatch-path reads (`isBlocked()`, `checkAndDeliverWarnings()`) are SYSTEM-WIDE
 *     (`_rbac: false, _multitenancy: false`), exactly like
 *     `ScheduleService::loadEngagedOrganisations()` — a tick is not a user request, and a
 *     read failure fails OPEN (logs, treats as "nothing blocked").
 *   - The user-facing reads (`listForCaller()`, `statusForScope()`) are tenant-scoped
 *     (RBAC/multitenancy ON, the default) — a caller-supplied `organisation`/`agentId`
 *     never itself grants visibility; OpenRegister's own tenant filtering is the boundary
 *     (mirrors `AnalyticsController`/`TenantOpsController`).
 *
 * This is a recognised ADR-031 imperative exception: a side-effecting governance service,
 * not a derived value or declarative lifecycle. All persistence flows through
 * OpenRegister's ObjectService single write-path (ADR-001, ADR-004).
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
 * @spec openspec/changes/cost-guardrails/tasks.md#task-2-1
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use OCA\Hermiq\AppInfo\Application;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Computes budget status, gates dispatch on the hard cap, warns on the soft threshold,
 * and derives the pre-run cost estimate — all over OpenRegister `Budget` objects.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Coordinates several OR/Hermiq services.
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)   One cohesive guardrail surface (status
 *   computation, gate check, warning delivery, CRUD, estimate) intentionally kept in one
 *   service rather than split, mirroring TenantOpsService's single-surface shape.
 *
 * @spec openspec/changes/cost-guardrails/tasks.md#task-2-1
 */
class BudgetService
{

    /**
     * OpenRegister register slug that holds Hermiq objects.
     *
     * @var string
     */
    private const REGISTER_SLUG = 'hermiq';

    /**
     * OpenRegister schema slug for Budget objects. Namespaced (`agentbudget`, not
     * `budget`) because OpenRegister resolves schema slugs GLOBALLY across all
     * registers — the generic `budget` slug collided with another app's Budget
     * schema, making every write fail validation against foreign required
     * properties (same fix as `agentsession`/`agentskill`).
     *
     * @var string
     */
    private const BUDGET_SCHEMA = 'agentbudget';

    /**
     * OpenRegister schema slug for schedule objects.
     *
     * @var string
     */
    private const SCHEDULE_SCHEMA = 'schedule';

    /**
     * OpenRegister schema slug for EvalRun objects (agent-evals). An eval run's
     * token usage counts toward the SAME per-org/per-agent budget a scheduled
     * run does — no separate spend meter — so its UUIDs are unioned into scope
     * resolution alongside Schedule's, exactly like Schedule's are.
     *
     * @var string
     */
    private const EVALRUN_SCHEMA = 'evalrun';

    /**
     * The OpenRegister schema slug for skill consolidation drafts
     * (skill-self-improvement): a consolidation pass's LLM usage is recorded as an
     * `action='run'` AuditTrail entry on the draft, so draft UUIDs join the same
     * scope union eval runs did — one usage-aggregation code path, no separate
     * spend meter.
     *
     * @var string
     */
    private const SKILLDRAFT_SCHEMA = 'agentskilldraft';

    /**
     * The audit action written per run by ScheduleService/FlowAgentRunService.
     *
     * @var string
     */
    private const RUN_ACTION = 'run';

    /**
     * IAppConfig key (app `hermiq`) for the instance-wide EUR-per-1000-tokens
     * conversion rate. Unset by default — EUR budgets/estimates stay unavailable.
     *
     * @var string
     */
    private const EUR_RATE_KEY = 'budget.eurPer1kTokens';

    /**
     * Default soft-threshold percentage when a Budget omits it.
     *
     * @var int
     */
    private const DEFAULT_SOFT_THRESHOLD = 80;

    /**
     * Constructor.
     *
     * @param ObjectService      $objectService      OpenRegister object read/write (single write-path).
     * @param AuditTrailMapper   $auditTrailMapper   OpenRegister audit read (run entries).
     * @param IAppConfig         $appConfig          Reads the EUR-per-1k-tokens conversion rate.
     * @param OrganisationMapper $organisationMapper Resolves the organisation owner (warning recipient).
     * @param DeliveryService    $deliveryService    Delivers the soft-threshold warning (Talk/Notification).
     * @param AnalyticsService   $analyticsService   Supplies the per-agent aggregation the estimate reuses.
     * @param LoggerInterface    $logger             PSR-3 logger (fail-open diagnostics, non-fatal warnings).
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) Constructor DI: each parameter is
     *   a distinct injected collaborator, not a logic-bearing argument list.
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly AuditTrailMapper $auditTrailMapper,
        private readonly IAppConfig $appConfig,
        private readonly OrganisationMapper $organisationMapper,
        private readonly DeliveryService $deliveryService,
        private readonly AnalyticsService $analyticsService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * GATE 2 — whether the organisation's or agent's budget is at (or beyond) its hard
     * cap for the current period. Read system-wide (fails open on error, exactly like
     * `ScheduleService::loadEngagedOrganisations()`), so a transient OpenRegister read
     * error never halts every tenant's runs.
     *
     * @param string      $organisation The schedule's/run's organisation identifier.
     * @param string|null $agentId      The bound agent UUID, when known.
     *
     * @return bool True when at least one matching, enabled budget has reached its cap.
     *
     * @spec openspec/changes/cost-guardrails/tasks.md#task-2-1
     */
    public function isBlocked(string $organisation, ?string $agentId=null): bool
    {
        try {
            $budgets = $this->matchingBudgetsSystemWide(organisation: $organisation, agentId: $agentId);
        } catch (Throwable $e) {
            $this->logger->error(
                'Hermiq could not load budgets for the dispatch gate: '.$e->getMessage(),
                ['exception' => $e]
            );
            return false;
        }

        foreach ($budgets as $budget) {
            $status = $this->status(budget: $budget);
            if ($status['hardCapReached'] === true) {
                $this->recordHardBlock(budget: $budget);
                return true;
            }
        }

        return false;

    }//end isBlocked()

    /**
     * Check every matching, enabled budget for a first-in-period soft-threshold
     * crossing and deliver exactly one warning per period. Never fatal to the dispatch
     * tick/run: a delivery or read failure is logged, not raised.
     *
     * @param string      $organisation The schedule's/run's organisation identifier.
     * @param string|null $agentId      The bound agent UUID, when known.
     *
     * @return void
     *
     * @spec openspec/changes/cost-guardrails/tasks.md#task-3-1
     */
    public function checkAndDeliverWarnings(string $organisation, ?string $agentId=null): void
    {
        try {
            $budgets = $this->matchingBudgetsSystemWide(organisation: $organisation, agentId: $agentId);
        } catch (Throwable $e) {
            $this->logger->warning(
                'Hermiq could not load budgets for the soft-threshold check: '.$e->getMessage(),
                ['exception' => $e]
            );
            return;
        }

        foreach ($budgets as $budget) {
            try {
                $recipient = $this->recordWarningIfDue(budget: $budget);
                if ($recipient === null || $recipient === '') {
                    continue;
                }

                $this->deliveryService->deliverBudgetWarning(budget: $budget, recipientUids: [$recipient]);
            } catch (Throwable $e) {
                $this->logger->warning(
                    'Hermiq could not deliver a budget soft-threshold warning: '.$e->getMessage(),
                    ['exception' => $e]
                );
            }
        }

    }//end checkAndDeliverWarnings()

    /**
     * Idempotently (once per period) record + return the recipient for a Budget that
     * has just crossed its soft threshold.
     *
     * @param ObjectEntity $budget The budget to check.
     *
     * @return string|null The organisation owner uid to notify, or null when no
     *                      warning is due (below threshold, or already warned this period).
     *
     * @spec openspec/changes/cost-guardrails/tasks.md#task-2-1
     */
    public function recordWarningIfDue(ObjectEntity $budget): ?string
    {
        $status = $this->status(budget: $budget);
        if ($status['softThresholdReached'] !== true) {
            return null;
        }

        $data          = $budget->getObject();
        $periodKey     = (string) $status['periodKey'];
        $warnedAlready = (string) ($data['warnedPeriodKey'] ?? '');
        if ($warnedAlready === $periodKey) {
            return null;
        }

        $organisation = (string) ($budget->getOrganisation() ?? '');
        $recipient    = $this->resolveOrgOwner(organisation: $organisation);

        $data['warnedPeriodKey'] = $periodKey;
        try {
            $this->objectService->saveObject(
                object: $data,
                register: self::REGISTER_SLUG,
                schema: self::BUDGET_SCHEMA,
                uuid: (string) $budget->getUuid(),
                _rbac: false,
                _multitenancy: false
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                'Hermiq could not persist warnedPeriodKey for budget '.((string) $budget->getUuid()).': '.$e->getMessage(),
                ['exception' => $e]
            );
        }

        if ($recipient === '') {
            return null;
        }

        return $recipient;

    }//end recordWarningIfDue()

    /**
     * Compute one Budget's current-period status: usage windowed to its period from the
     * same run AuditTrail entries `AnalyticsService` aggregates — never a stored counter.
     *
     * @param ObjectEntity $budget The budget to evaluate.
     *
     * @return array<string, mixed> The status payload (scope/agentId/period/periodKey/
     *                               tokens/eur/softThresholdReached/hardCapReached).
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Single-pass threshold evaluation
     *   across the token AND (optional) EUR dimensions; splitting would duplicate the
     *   period-window/usage computation.
     *
     * @spec openspec/changes/cost-guardrails/tasks.md#task-2-1
     */
    public function status(ObjectEntity $budget): array
    {
        $data         = $budget->getObject();
        $scope        = (string) ($data['scope'] ?? 'organisation');
        $period       = (string) ($data['period'] ?? 'monthly');
        $organisation = (string) ($budget->getOrganisation() ?? '');
        $agentId      = (string) ($data['agentId'] ?? '');

        $now    = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $window = $this->periodWindow(period: $period, now: $now);

        $scheduleUuids = $this->loadScheduleUuidsForScope(scope: $scope, organisation: $organisation, agentId: $agentId);
        $tokensUsed    = $this->currentUsageTokens(scheduleUuids: $scheduleUuids, start: $window['start'], end: $window['end']);

        $tokenLimit = null;
        if (isset($data['tokenLimit']) === true && $data['tokenLimit'] !== null && $data['tokenLimit'] !== '') {
            $tokenLimit = (int) $data['tokenLimit'];
        }

        $eurLimit = null;
        if (isset($data['eurLimit']) === true && $data['eurLimit'] !== null && $data['eurLimit'] !== '') {
            $eurLimit = (float) $data['eurLimit'];
        }

        $softThresholdPercent = (int) ($data['softThresholdPercent'] ?? self::DEFAULT_SOFT_THRESHOLD);

        $tokenPercent = null;
        if ($tokenLimit !== null && $tokenLimit > 0) {
            $tokenPercent = round((($tokensUsed / $tokenLimit) * 100), 1);
        }

        $hardCapReached = ($tokenLimit !== null && $tokenLimit > 0 && $tokensUsed >= $tokenLimit);
        $softReached    = ($tokenPercent !== null && $tokenPercent >= $softThresholdPercent);

        $rate         = $this->eurRate();
        $eurAvailable = ($rate !== null);
        $eurUsed      = null;
        $eurPercent   = null;
        if ($eurAvailable === true) {
            $eurUsed = round((($tokensUsed * $rate) / 1000), 4);
            if ($eurLimit !== null && $eurLimit > 0) {
                $eurPercent = round((($eurUsed / $eurLimit) * 100), 1);
                if ($eurUsed >= $eurLimit) {
                    $hardCapReached = true;
                }

                if ($eurPercent >= $softThresholdPercent) {
                    $softReached = true;
                }
            }
        }

        $agentIdOrNull = null;
        if ($agentId !== '') {
            $agentIdOrNull = $agentId;
        }

        return [
            'scope'                => $scope,
            'agentId'              => $agentIdOrNull,
            'period'               => $period,
            'periodKey'            => $window['key'],
            'tokens'               => [
                'used'    => $tokensUsed,
                'limit'   => $tokenLimit,
                'percent' => $tokenPercent,
            ],
            'eur'                  => [
                'available' => $eurAvailable,
                'used'      => $eurUsed,
                'limit'     => $eurLimit,
                'percent'   => $eurPercent,
            ],
            'softThresholdReached' => $softReached,
            'hardCapReached'       => $hardCapReached,
        ];

    }//end status()

    /**
     * Pre-run rough cost estimate for one agent — the trailing average tokens per run,
     * derived from `AnalyticsService::computeAnalytics()`'s existing aggregation. NEVER
     * read by the enforcement gate (see `isBlocked()`) — advisory-only.
     *
     * @param string $agentId The agent UUID to estimate for.
     *
     * @return array<string, mixed> The estimate payload (design.md shape).
     *
     * @spec openspec/changes/cost-guardrails/tasks.md#task-2-1
     */
    public function estimateNextRun(string $agentId): array
    {
        $metrics   = $this->analyticsService->computeAnalytics(agentId: $agentId);
        $totalRuns = (int) ($metrics['totalRuns'] ?? 0);
        $available = ($totalRuns > 0 && ($metrics['tokens']['available'] ?? false) === true);

        if ($available === false) {
            return [
                'agentId'             => $agentId,
                'available'           => false,
                'sampleSize'          => 0,
                'avgPromptTokens'     => null,
                'avgCompletionTokens' => null,
                'avgTotalTokens'      => null,
                'avgCostEur'          => null,
                'label'               => 'not enough run history yet',
            ];
        }

        $avgPrompt     = (int) round(((int) $metrics['tokens']['prompt']) / $totalRuns);
        $avgCompletion = (int) round(((int) $metrics['tokens']['completion']) / $totalRuns);
        $avgTotal      = (int) round(((int) $metrics['tokens']['total']) / $totalRuns);

        $avgCostEur = null;
        $rate       = $this->eurRate();
        if ($rate !== null) {
            $avgCostEur = round((($avgTotal * $rate) / 1000), 4);
        }

        return [
            'agentId'             => $agentId,
            'available'           => true,
            'sampleSize'          => $totalRuns,
            'avgPromptTokens'     => $avgPrompt,
            'avgCompletionTokens' => $avgCompletion,
            'avgTotalTokens'      => $avgTotal,
            'avgCostEur'          => $avgCostEur,
            'label'               => sprintf('estimate — trailing average over last %d runs', $totalRuns),
        ];

    }//end estimateNextRun()

    /**
     * List budgets visible to the caller (RBAC/multitenancy ON — tenant-scoped),
     * optionally narrowed to one organisation (defense-in-depth; RBAC already
     * restricts to the caller's own accessible objects).
     *
     * @param string $organisation Optional organisation filter.
     *
     * @return array<int, array<string, mixed>> The shaped budget list.
     *
     * @spec openspec/changes/cost-guardrails/tasks.md#task-4-1
     */
    public function listForCaller(string $organisation=''): array
    {
        $objects = $this->objectService
            ->setRegister(self::REGISTER_SLUG)
            ->setSchema(self::BUDGET_SCHEMA)
            ->findAll(config: ['limit' => 1000]);

        $out = [];
        foreach ($objects as $object) {
            if (($object instanceof ObjectEntity) === false) {
                continue;
            }

            if ($organisation !== '' && (string) ($object->getOrganisation() ?? '') !== $organisation) {
                continue;
            }

            $out[] = $this->shape(budget: $object);
        }

        return $out;

    }//end listForCaller()

    /**
     * Current-period status for one scope, tenant-scoped (RBAC/multitenancy ON) — a
     * caller-supplied organisation/agentId never itself grants visibility; OpenRegister's
     * own tenant filtering is the boundary.
     *
     * @param string      $organisation The organisation identifier.
     * @param string|null $agentId      Optional agent UUID (agent-scoped budget).
     *
     * @return array<string, mixed> The status payload, or a "not configured" default.
     *
     * @spec openspec/changes/cost-guardrails/tasks.md#task-4-1
     */
    public function statusForScope(string $organisation, ?string $agentId=null): array
    {
        $budget = $this->findBudgetTenantScoped(organisation: $organisation, agentId: $agentId);
        if ($budget === null) {
            $scope = 'organisation';
            if ($agentId !== null && $agentId !== '') {
                $scope = 'agent';
            }

            return [
                'scope'                => $scope,
                'agentId'              => $agentId,
                'period'               => null,
                'periodKey'            => null,
                'tokens'               => ['available' => false],
                'eur'                  => ['available' => false],
                'softThresholdReached' => false,
                'hardCapReached'       => false,
                'configured'           => false,
            ];
        }

        return array_merge($this->status(budget: $budget), ['configured' => true]);

    }//end statusForScope()

    /**
     * Find a single Budget object by UUID, system-wide (used by the controller's
     * update/delete authorization check — it needs the budget's organisation before it
     * can decide whether the caller may administer it).
     *
     * @param string $budgetId The Budget object UUID.
     *
     * @return ObjectEntity|null The budget, or null when not found.
     *
     * @spec openspec/changes/cost-guardrails/tasks.md#task-4-1
     */
    public function findById(string $budgetId): ?ObjectEntity
    {
        if ($budgetId === '') {
            return null;
        }

        return $this->objectService->find(
            id: $budgetId,
            register: self::REGISTER_SLUG,
            schema: self::BUDGET_SCHEMA,
            _rbac: false,
            _multitenancy: false
        );

    }//end findById()

    /**
     * Create a Budget for an organisation (admin/owner-gated by the controller).
     *
     * @param array<string, mixed> $payload      The requested budget fields.
     * @param string               $organisation The organisation to pin the budget to.
     *
     * @return array<string, mixed> The shaped, created budget.
     *
     * @throws InvalidArgumentException When the payload fails cross-field validation.
     *
     * @spec openspec/changes/cost-guardrails/tasks.md#task-4-1
     */
    public function create(array $payload, string $organisation): array
    {
        $this->validatePayload(payload: $payload);

        $scope   = (string) $payload['scope'];
        $agentId = '';
        if ($scope === 'agent') {
            $agentId = (string) $payload['agentId'];
        }

        $data = [
            'scope'                => $scope,
            'agentId'              => $agentId,
            'period'               => (string) $payload['period'],
            'tokenLimit'           => $this->intOrNull(value: ($payload['tokenLimit'] ?? null)),
            'eurLimit'             => $this->floatOrNull(value: ($payload['eurLimit'] ?? null)),
            'softThresholdPercent' => (int) ($payload['softThresholdPercent'] ?? self::DEFAULT_SOFT_THRESHOLD),
            'enabled'              => ($payload['enabled'] ?? true) !== false,
            'warnedPeriodKey'      => '',
            'lastHardBlockAt'      => null,
        ];

        // Pin the budget to the TARGET organisation, not the actor's active organisation
        // (mirrors TenantControlService::toggle()'s @self.organisation trick).
        $data['@self'] = ['organisation' => $organisation];

        $budget = $this->objectService->saveObject(
            object: $data,
            register: self::REGISTER_SLUG,
            schema: self::BUDGET_SCHEMA,
            _rbac: false,
            _multitenancy: false
        );

        return $this->shape(budget: $budget);

    }//end create()

    /**
     * Update a Budget (admin/owner-gated by the controller).
     *
     * @param string               $budgetId The Budget object UUID.
     * @param array<string, mixed> $payload  The fields to update.
     *
     * @return array<string, mixed> The shaped, updated budget.
     *
     * @throws RuntimeException        When the budget cannot be found.
     * @throws InvalidArgumentException When the merged payload fails validation.
     *
     * @spec openspec/changes/cost-guardrails/tasks.md#task-4-1
     */
    public function update(string $budgetId, array $payload): array
    {
        $existing = $this->findById(budgetId: $budgetId);
        if ($existing === null) {
            throw new RuntimeException("Budget '{$budgetId}' does not exist");
        }

        $data = $existing->getObject();
        foreach (['scope', 'agentId', 'period', 'tokenLimit', 'eurLimit', 'softThresholdPercent', 'enabled'] as $field) {
            if (array_key_exists($field, $payload) === true) {
                $data[$field] = $payload[$field];
            }
        }

        $this->validatePayload(payload: $data);
        if (($data['scope'] ?? '') !== 'agent') {
            $data['agentId'] = '';
        }

        $data['tokenLimit'] = $this->intOrNull(value: ($data['tokenLimit'] ?? null));
        $data['eurLimit']   = $this->floatOrNull(value: ($data['eurLimit'] ?? null));

        $budget = $this->objectService->saveObject(
            object: $data,
            register: self::REGISTER_SLUG,
            schema: self::BUDGET_SCHEMA,
            uuid: $budgetId,
            _rbac: false,
            _multitenancy: false
        );

        return $this->shape(budget: $budget);

    }//end update()

    /**
     * Delete a Budget (admin/owner-gated by the controller).
     *
     * @param string $budgetId The Budget object UUID.
     *
     * @return void
     *
     * @spec openspec/changes/cost-guardrails/tasks.md#task-4-1
     */
    public function delete(string $budgetId): void
    {
        $this->objectService->deleteObject(
            uuid: $budgetId,
            register: self::REGISTER_SLUG,
            schema: self::BUDGET_SCHEMA,
            _rbac: false,
            _multitenancy: false
        );

    }//end delete()

    /**
     * Shape a Budget ObjectEntity into the API response payload (design.md shape).
     *
     * @param ObjectEntity $budget The budget object.
     *
     * @return array<string, mixed> The shaped payload.
     *
     * @spec openspec/changes/cost-guardrails/tasks.md#task-4-1
     */
    public function shape(ObjectEntity $budget): array
    {
        $data    = $budget->getObject();
        $agentId = (string) ($data['agentId'] ?? '');

        $agentIdOrNull = null;
        if ($agentId !== '') {
            $agentIdOrNull = $agentId;
        }

        return [
            'id'                   => (string) $budget->getUuid(),
            'scope'                => (string) ($data['scope'] ?? 'organisation'),
            'agentId'              => $agentIdOrNull,
            'period'               => (string) ($data['period'] ?? 'monthly'),
            'tokenLimit'           => ($data['tokenLimit'] ?? null),
            'eurLimit'             => ($data['eurLimit'] ?? null),
            'softThresholdPercent' => (int) ($data['softThresholdPercent'] ?? self::DEFAULT_SOFT_THRESHOLD),
            'enabled'              => (bool) ($data['enabled'] ?? true),
        ];

    }//end shape()

    /**
     * Validate the cross-field constraints a JSON-Schema `oneOf` cannot express on
     * this project's OpenRegister tooling (design.md's documented gap).
     *
     * @param array<string, mixed> $payload The candidate budget payload.
     *
     * @return void
     *
     * @throws InvalidArgumentException When scope/agentId/period/limits are invalid.
     */
    private function validatePayload(array $payload): void
    {
        $scope = (string) ($payload['scope'] ?? '');
        if (in_array($scope, ['organisation', 'agent'], true) === false) {
            throw new InvalidArgumentException('scope must be "organisation" or "agent"');
        }

        if ($scope === 'agent' && trim((string) ($payload['agentId'] ?? '')) === '') {
            throw new InvalidArgumentException('agentId is required when scope=agent');
        }

        $period = (string) ($payload['period'] ?? '');
        if (in_array($period, ['daily', 'weekly', 'monthly'], true) === false) {
            throw new InvalidArgumentException('period must be "daily", "weekly" or "monthly"');
        }

        $tokenLimit = ($payload['tokenLimit'] ?? null);
        $eurLimit   = ($payload['eurLimit'] ?? null);
        $hasToken   = ($tokenLimit !== null && $tokenLimit !== '');
        $hasEur     = ($eurLimit !== null && $eurLimit !== '');
        if ($hasToken === false && $hasEur === false) {
            throw new InvalidArgumentException('At least one of tokenLimit or eurLimit is required');
        }

    }//end validatePayload()

    /**
     * Load enabled budgets matching a scope, system-wide (dispatch-path posture).
     *
     * @param string      $organisation The organisation identifier.
     * @param string|null $agentId      The bound agent UUID, when known.
     *
     * @return array<int, ObjectEntity> The matching, enabled budgets.
     */
    private function matchingBudgetsSystemWide(string $organisation, ?string $agentId): array
    {
        $objects = $this->objectService
            ->setRegister(self::REGISTER_SLUG)
            ->setSchema(self::BUDGET_SCHEMA)
            ->findAll(
                config: ['filters' => ['enabled' => true]],
                _rbac: false,
                _multitenancy: false
            );

        $matches = [];
        foreach ($objects as $object) {
            if (($object instanceof ObjectEntity) === false) {
                continue;
            }

            // Defensive re-check in PHP (mirrors loadEngagedOrganisations()'s own
            // pattern): the 'enabled' filter above is an OR-side hint, not a guarantee.
            $data = $object->getObject();
            if (($data['enabled'] ?? true) === false) {
                continue;
            }

            if ($this->matchesScope(budget: $object, organisation: $organisation, agentId: $agentId) === true) {
                $matches[] = $object;
            }
        }

        return $matches;

    }//end matchingBudgetsSystemWide()

    /**
     * Find the caller's own budget for a scope, tenant-scoped (RBAC/multitenancy ON).
     * Prefers an agent-scoped budget over the organisation-scoped one when both exist
     * and an agentId was supplied.
     *
     * @param string      $organisation The organisation identifier.
     * @param string|null $agentId      Optional agent UUID.
     *
     * @return ObjectEntity|null The matching budget, or null when none is configured.
     */
    private function findBudgetTenantScoped(string $organisation, ?string $agentId): ?ObjectEntity
    {
        $objects = $this->objectService
            ->setRegister(self::REGISTER_SLUG)
            ->setSchema(self::BUDGET_SCHEMA)
            ->findAll(config: ['limit' => 1000]);

        $agentMatch = null;
        $orgMatch   = null;
        foreach ($objects as $object) {
            if (($object instanceof ObjectEntity) === false) {
                continue;
            }

            $data  = $object->getObject();
            $scope = (string) ($data['scope'] ?? '');

            if ($organisation !== '' && (string) ($object->getOrganisation() ?? '') !== $organisation) {
                continue;
            }

            if ($agentId !== null && $agentId !== '' && $scope === 'agent' && (string) ($data['agentId'] ?? '') === $agentId) {
                $agentMatch = $object;
            } else if ($scope === 'organisation') {
                $orgMatch = $object;
            }
        }

        return ($agentMatch ?? $orgMatch);

    }//end findBudgetTenantScoped()

    /**
     * Whether a budget object matches the given organisation/agent scope.
     *
     * @param ObjectEntity $budget       The budget object.
     * @param string       $organisation The organisation identifier.
     * @param string|null  $agentId      The bound agent UUID, when known.
     *
     * @return bool
     */
    private function matchesScope(ObjectEntity $budget, string $organisation, ?string $agentId): bool
    {
        $data  = $budget->getObject();
        $scope = (string) ($data['scope'] ?? '');

        if ($scope === 'organisation') {
            return ($organisation !== '' && (string) ($budget->getOrganisation() ?? '') === $organisation);
        }

        if ($scope === 'agent') {
            return ($agentId !== null && $agentId !== '' && (string) ($data['agentId'] ?? '') === $agentId);
        }

        return false;

    }//end matchesScope()

    /**
     * Best-effort persist of `lastHardBlockAt` for admin visibility. Never fatal to the
     * gate check — a failure here must not affect the block decision already made.
     *
     * @param ObjectEntity $budget The budget whose hard cap was just reached.
     *
     * @return void
     */
    private function recordHardBlock(ObjectEntity $budget): void
    {
        try {
            $data = $budget->getObject();
            $data['lastHardBlockAt'] = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c');
            $this->objectService->saveObject(
                object: $data,
                register: self::REGISTER_SLUG,
                schema: self::BUDGET_SCHEMA,
                uuid: (string) $budget->getUuid(),
                _rbac: false,
                _multitenancy: false
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                'Hermiq could not persist lastHardBlockAt for budget '.((string) $budget->getUuid()).': '.$e->getMessage(),
                ['exception' => $e]
            );
        }

    }//end recordHardBlock()

    /**
     * Load the schedule UUIDs in scope for a budget's usage window: every schedule in
     * the organisation (scope=organisation) or every schedule bound to the agent
     * (scope=agent) — system-wide, mirroring `AnalyticsService::loadScheduleUuidToAgent()`
     * but keyed for the dispatch-gate's unauthenticated read posture.
     *
     * Agent-evals: ALSO unions in `EvalRun` UUIDs matching the same scope, so an eval
     * run's `action='run'` AuditTrail usage rolls into the SAME budget total a
     * scheduled run's does — one usage-aggregation code path, no separate spend
     * meter (proposal.md Risk 1). Additive: when no EvalRun exists yet (or the
     * schema is absent) this returns exactly the Schedule-only UUID set as before.
     *
     * @param string $scope        `organisation`|`agent`.
     * @param string $organisation The organisation identifier.
     * @param string $agentId      The agent UUID (scope=agent only).
     *
     * @return array<int, string> The matching schedule + eval-run UUIDs.
     */
    private function loadScheduleUuidsForScope(string $scope, string $organisation, string $agentId): array
    {
        $uuids = $this->loadInScopeUuidsForSchema(
            schema: self::SCHEDULE_SCHEMA,
            scope: $scope,
            organisation: $organisation,
            agentId: $agentId
        );

        $evalRunUuids = $this->loadInScopeUuidsForSchema(
            schema: self::EVALRUN_SCHEMA,
            scope: $scope,
            organisation: $organisation,
            agentId: $agentId
        );

        // Skill-self-improvement: consolidation drafts join the union so the
        // consolidation LLM pass's `action='run'` usage rolls into the SAME budget
        // total (the agent-evals precedent). Additive: no drafts ⇒ unchanged set.
        $draftUuids = $this->loadInScopeUuidsForSchema(
            schema: self::SKILLDRAFT_SCHEMA,
            scope: $scope,
            organisation: $organisation,
            agentId: $agentId
        );

        return array_merge($uuids, $evalRunUuids, $draftUuids);

    }//end loadScheduleUuidsForScope()

    /**
     * Load the in-scope UUIDs for one OpenRegister schema in the `hermiq` register —
     * every object in the organisation (scope=organisation) or bound to the agent via
     * its `agentId` field (scope=agent) — system-wide (fails open to an empty result
     * on a read error, never fatal to the budget gate).
     *
     * Extracted from `loadScheduleUuidsForScope()` (agent-evals) so Schedule and
     * EvalRun share the identical matching logic instead of two near-duplicate loops.
     *
     * @param string $schema       The OpenRegister schema slug to scan.
     * @param string $scope        `organisation`|`agent`.
     * @param string $organisation The organisation identifier.
     * @param string $agentId      The agent UUID (scope=agent only).
     *
     * @return array<int, string> The matching object UUIDs.
     */
    private function loadInScopeUuidsForSchema(string $schema, string $scope, string $organisation, string $agentId): array
    {
        try {
            $objects = $this->objectService
                ->setRegister(self::REGISTER_SLUG)
                ->setSchema($schema)
                ->findAll(config: ['limit' => 1000], _rbac: false, _multitenancy: false);
        } catch (Throwable $e) {
            // Fails open (empty scope) rather than breaking budget resolution for every
            // OTHER schema — e.g. an 'evalrun' scan on an instance whose register
            // re-import has not yet landed the agent-evals schema must never take down
            // the pre-existing Schedule-only budget gate.
            $this->logger->warning(
                sprintf("Hermiq could not load '%s' objects for the budget scope scan: %s", $schema, $e->getMessage()),
                ['exception' => $e]
            );
            return [];
        }

        $uuids = [];
        foreach ($objects as $object) {
            if (($object instanceof ObjectEntity) === false) {
                continue;
            }

            if ($scope === 'organisation') {
                if ($organisation !== '' && (string) ($object->getOrganisation() ?? '') === $organisation) {
                    $uuids[] = (string) $object->getUuid();
                }

                continue;
            }

            $data = $object->getObject();
            if ($agentId !== '' && (string) ($data['agentId'] ?? '') === $agentId) {
                $uuids[] = (string) $object->getUuid();
            }
        }

        return $uuids;

    }//end loadInScopeUuidsForSchema()

    /**
     * Sum recorded token usage (`action='run'` AuditTrail entries) for a set of
     * schedule UUIDs within a period window.
     *
     * @param array<int, string> $scheduleUuids The in-scope schedule UUIDs.
     * @param DateTimeImmutable  $start         The period start (inclusive, UTC).
     * @param DateTimeImmutable  $end           The period end (exclusive, UTC).
     *
     * @return int Total prompt+completion tokens recorded in the window.
     */
    private function currentUsageTokens(array $scheduleUuids, DateTimeImmutable $start, DateTimeImmutable $end): int
    {
        if ($scheduleUuids === []) {
            return 0;
        }

        $inScope = array_flip($scheduleUuids);
        $logs    = $this->auditTrailMapper->findAll(filters: ['action' => self::RUN_ACTION]);

        $total = 0;
        foreach ($logs as $log) {
            $objectUuid = (string) $log->getObjectUuid();
            if (isset($inScope[$objectUuid]) === false) {
                continue;
            }

            $created = $log->getCreated();
            if ($created === null || $created < $start || $created >= $end) {
                continue;
            }

            $context = ($log->getChanged() ?? []);
            $usage   = ($context['usage'] ?? null);
            if (is_array($usage) === false) {
                continue;
            }

            $total += (int) ($usage['promptTokens'] ?? 0);
            $total += (int) ($usage['completionTokens'] ?? 0);
        }

        return $total;

    }//end currentUsageTokens()

    /**
     * Compute the [start, end) UTC window and period key for a period kind, anchored
     * to `$now`.
     *
     * @param string            $period `daily`|`weekly`|`monthly`.
     * @param DateTimeImmutable $now    The current UTC moment.
     *
     * @return array{start: DateTimeImmutable, end: DateTimeImmutable, key: string}
     */
    private function periodWindow(string $period, DateTimeImmutable $now): array
    {
        if ($period === 'daily') {
            $start = $now->setTime(0, 0, 0);
            return [
                'start' => $start,
                'end'   => $start->modify('+1 day'),
                'key'   => $start->format('Y-m-d'),
            ];
        }

        if ($period === 'weekly') {
            $start = $now->setTime(0, 0, 0)->modify('monday this week');
            return [
                'start' => $start,
                'end'   => $start->modify('+7 days'),
                'key'   => $now->format('o-\WW'),
            ];
        }

        // Default: monthly.
        $start = $now->setTime(0, 0, 0)->modify('first day of this month');
        return [
            'start' => $start,
            'end'   => $start->modify('+1 month'),
            'key'   => $now->format('Y-m'),
        ];

    }//end periodWindow()

    /**
     * The instance-wide EUR-per-1000-tokens conversion rate, or null when unset/invalid
     * (EUR budgets/estimates stay unavailable — never a fabricated conversion).
     *
     * @return float|null
     */
    private function eurRate(): ?float
    {
        $raw = $this->appConfig->getValueString(Application::APP_ID, self::EUR_RATE_KEY, '');
        if ($raw === '' || is_numeric($raw) === false) {
            return null;
        }

        return (float) $raw;

    }//end eurRate()

    /**
     * Resolve an organisation's owner uid (the soft-threshold warning recipient).
     * Fails open to an empty string (logged by the caller) rather than throwing.
     *
     * @param string $organisation The organisation identifier.
     *
     * @return string The owner uid, or '' when unresolvable.
     */
    private function resolveOrgOwner(string $organisation): string
    {
        if ($organisation === '') {
            return '';
        }

        try {
            $org = $this->organisationMapper->findByUuid($organisation);
        } catch (Throwable $e) {
            return '';
        }

        return (string) ($org->getOwner() ?? '');

    }//end resolveOrgOwner()

    /**
     * Coerce a request-supplied value to an int, or null when empty/absent.
     *
     * @param mixed $value The candidate value.
     *
     * @return int|null
     */
    private function intOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;

    }//end intOrNull()

    /**
     * Coerce a request-supplied value to a float, or null when empty/absent.
     *
     * @param mixed $value The candidate value.
     *
     * @return float|null
     */
    private function floatOrNull(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (float) $value;

    }//end floatOrNull()
}//end class
