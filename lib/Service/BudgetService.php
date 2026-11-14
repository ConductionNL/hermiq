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
 *     (mirrors `AnalyticsController`/`OrganisationOpsController`).
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
 * @spec openspec/changes/archive/2026-07-12-cost-guardrails/tasks.md#task-2-budgetservice-status-hard-cap-check-soft-threshold-warn-estimate
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use OCA\Hermiq\Kernel\AppIdentity;
use OCA\Hermiq\Kernel\Contracts\BudgetServiceInterface;
use OCA\OpenRegister\Db\AuditTrail;
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
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Coordinates several OR/Hermiq services.
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)     One cohesive guardrail surface (status
 *   computation, gate check, warning delivery, CRUD, estimate) intentionally kept in one
 *   service rather than split, mirroring OrganisationOpsService's single-surface shape.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Same single-surface trade-off: the
 *   token/EUR dual-dimension checks and period windowing each add branches to one class.
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)     The guardrail surface exposes status,
 *   gate, warn, estimate AND Budget CRUD as separate public entry points by design.
 *
 * @spec openspec/changes/archive/2026-07-12-cost-guardrails/tasks.md#task-2-budgetservice-status-hard-cap-check-soft-threshold-warn-estimate
 */
class BudgetService implements BudgetServiceInterface {

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
	private const DEFAULT_SOFT_THRESHOLD = BudgetLimits::DEFAULT_SOFT_THRESHOLD;

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OpenRegister object read/write (single write-path).
	 * @param AuditTrailMapper $auditTrailMapper OpenRegister audit read (run entries).
	 * @param IAppConfig $appConfig Reads the EUR-per-1k-tokens conversion rate.
	 * @param OrganisationMapper $organisationMapper Resolves the organisation owner (warning recipient).
	 * @param DeliveryService $deliveryService Delivers the soft-threshold warning (Talk/Notification).
	 * @param AnalyticsService $analyticsService Supplies the per-agent aggregation the estimate reuses.
	 * @param LoggerInterface $logger PSR-3 logger (fail-open diagnostics, non-fatal warnings).
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
	 * @param string $organisation The schedule's/run's organisation identifier.
	 * @param string|null $agentId The bound agent UUID, when known.
	 *
	 * @return bool True when at least one matching, enabled budget has reached its cap.
	 *
	 * @spec openspec/changes/archive/2026-07-12-cost-guardrails/tasks.md#task-2-budgetservice-status-hard-cap-check-soft-threshold-warn-estimate
	 */
	public function isBlocked(string $organisation, ?string $agentId = null): bool {
		try {
			$budgets = $this->matchingBudgetsSystemWide(organisation: $organisation, agentId: $agentId);
		} catch (Throwable $e) {
			$this->logger->error(
				'Hermiq could not load budgets for the dispatch gate: ' . $e->getMessage(),
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
	 * @param string $organisation The schedule's/run's organisation identifier.
	 * @param string|null $agentId The bound agent UUID, when known.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-07-12-cost-guardrails/tasks.md#task-3-wire-the-budget-gate-into-the-dispatch-path-soft-threshold-delivery
	 */
	public function checkAndDeliverWarnings(string $organisation, ?string $agentId = null): void {
		try {
			$budgets = $this->matchingBudgetsSystemWide(organisation: $organisation, agentId: $agentId);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Hermiq could not load budgets for the soft-threshold check: ' . $e->getMessage(),
				['exception' => $e]
			);
			return;
		}

		foreach ($budgets as $budget) {
			$this->deliverWarningIfDue(budget: $budget);
		}

	}//end checkAndDeliverWarnings()

	/**
	 * Deliver one budget's soft-threshold warning when it is due; a failure is logged,
	 * never thrown.
	 *
	 * @param ObjectEntity $budget The budget.
	 *
	 * @return void
	 */
	private function deliverWarningIfDue(ObjectEntity $budget): void {
		try {
			$recipient = $this->recordWarningIfDue(budget: $budget);
			if ($recipient === null || $recipient === '') {
				return;
			}

			$this->deliveryService->deliverBudgetWarning(budget: $budget, recipientUids: [$recipient]);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Hermiq could not deliver a budget soft-threshold warning: ' . $e->getMessage(),
				['exception' => $e]
			);
		}

	}//end deliverWarningIfDue()

	/**
	 * Idempotently (once per period) record + return the recipient for a Budget that
	 * has just crossed its soft threshold.
	 *
	 * @param ObjectEntity $budget The budget to check.
	 *
	 * @return string|null The organisation owner uid to notify, or null when no
	 *                     warning is due (below threshold, or already warned this period).
	 *
	 * @spec openspec/changes/archive/2026-07-12-cost-guardrails/tasks.md#task-2-budgetservice-status-hard-cap-check-soft-threshold-warn-estimate
	 */
	public function recordWarningIfDue(ObjectEntity $budget): ?string {
		$status = $this->status(budget: $budget);
		if ($status['softThresholdReached'] !== true) {
			return null;
		}

		$data = $budget->getObject();
		$periodKey = (string)$status['periodKey'];
		$warnedAlready = (string)($data['warnedPeriodKey'] ?? '');
		if ($warnedAlready === $periodKey) {
			return null;
		}

		$organisation = (string)($budget->getOrganisation() ?? '');
		$recipient = $this->resolveOrgOwner(organisation: $organisation);

		$data['warnedPeriodKey'] = $periodKey;
		try {
			$this->objectService->saveObject(
				object: $data,
				register: self::REGISTER_SLUG,
				schema: self::BUDGET_SCHEMA,
				uuid: (string)$budget->getUuid(),
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Hermiq could not persist warnedPeriodKey for budget ' . ((string)$budget->getUuid()) . ': ' . $e->getMessage(),
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
	 *                              tokens/eur/softThresholdReached/hardCapReached).
	 *
	 * @spec openspec/changes/archive/2026-07-12-cost-guardrails/tasks.md#task-2-budgetservice-status-hard-cap-check-soft-threshold-warn-estimate
	 */
	public function status(ObjectEntity $budget): array {
		$data = $budget->getObject();
		$period = (string)($data['period'] ?? 'monthly');
		$window = $this->periodWindow(period: $period, now: new DateTimeImmutable('now', new DateTimeZone('UTC')));
		$tokensUsed = $this->currentUsageTokens(scheduleUuids: $this->loadScheduleUuidsForScope(budget: $budget), window: $window);

		return [
			'scope' => (string)($data['scope'] ?? 'organisation'),
			'agentId' => $this->agentIdOrNull(data: $data),
			'period' => $period,
			'periodKey' => $window['key'],
		] + $this->usageFields(data: $data, tokensUsed: $tokensUsed);

	}//end status()

	/**
	 * A budget's agent id, or null for an organisation budget.
	 *
	 * @param array<string, mixed> $data The budget's fields.
	 *
	 * @return string|null The agent id, or null.
	 */
	private function agentIdOrNull(array $data): ?string {
		$agentId = (string)($data['agentId'] ?? '');
		if ($agentId === '') {
			return null;
		}

		return $agentId;

	}//end agentIdOrNull()

	/**
	 * The token and EUR usage of a budget's limits, and whether either reached its
	 * soft threshold or hard cap.
	 *
	 * @param array<string, mixed> $data The budget's fields.
	 * @param int $tokensUsed The tokens used in the period.
	 *
	 * @return array<string, mixed> The tokens, eur, softThresholdReached and hardCapReached fields.
	 */
	private function usageFields(array $data, int $tokensUsed): array {
		$limits = new BudgetLimits(data: $data);
		$tokens = $limits->tokenUsage(used: $tokensUsed);
		$eur = $limits->eurUsage(tokensUsed: $tokensUsed, rate: $this->eurRate());

		return [
			'tokens' => $tokens['usage'],
			'eur' => $eur['usage'],
			'softThresholdReached' => ($tokens['soft'] || $eur['soft']),
			'hardCapReached' => ($tokens['hard'] || $eur['hard']),
		];

	}//end usageFields()

	/**
	 * Pre-run rough cost estimate for one agent — the trailing average tokens per run,
	 * derived from `AnalyticsService::computeAnalytics()`'s existing aggregation. NEVER
	 * read by the enforcement gate (see `isBlocked()`) — advisory-only.
	 *
	 * @param string $agentId The agent UUID to estimate for.
	 *
	 * @return array<string, mixed> The estimate payload (design.md shape).
	 *
	 * @spec openspec/changes/archive/2026-07-12-cost-guardrails/tasks.md#task-2-budgetservice-status-hard-cap-check-soft-threshold-warn-estimate
	 */
	public function estimateNextRun(string $agentId): array {
		$metrics = $this->analyticsService->computeAnalytics(agentId: $agentId);
		$totalRuns = (int)($metrics['totalRuns'] ?? 0);
		$available = ($totalRuns > 0 && ($metrics['tokens']['available'] ?? false) === true);

		if ($available === false) {
			return [
				'agentId' => $agentId,
				'available' => false,
				'sampleSize' => 0,
				'avgPromptTokens' => null,
				'avgCompletionTokens' => null,
				'avgTotalTokens' => null,
				'avgCostEur' => null,
				'label' => 'not enough run history yet',
			];
		}

		$avgPrompt = (int)round(((int)$metrics['tokens']['prompt']) / $totalRuns);
		$avgCompletion = (int)round(((int)$metrics['tokens']['completion']) / $totalRuns);
		$avgTotal = (int)round(((int)$metrics['tokens']['total']) / $totalRuns);

		$avgCostEur = null;
		$rate = $this->eurRate();
		if ($rate !== null) {
			$avgCostEur = round((($avgTotal * $rate) / 1000), 4);
		}

		return [
			'agentId' => $agentId,
			'available' => true,
			'sampleSize' => $totalRuns,
			'avgPromptTokens' => $avgPrompt,
			'avgCompletionTokens' => $avgCompletion,
			'avgTotalTokens' => $avgTotal,
			'avgCostEur' => $avgCostEur,
			'label' => sprintf('estimate — trailing average over last %d runs', $totalRuns),
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
	 * @spec openspec/changes/archive/2026-07-12-cost-guardrails/tasks.md#task-4-budgetcontroller-routes-crud-status-estimate
	 */
	public function listForCaller(string $organisation = ''): array {
		$objects = $this->objectService
			->setRegister(self::REGISTER_SLUG)
			->setSchema(self::BUDGET_SCHEMA)
			->findAll(config: ['limit' => 1000]);

		$out = [];
		foreach ($objects as $object) {
			if (($object instanceof ObjectEntity) === false) {
				continue;
			}

			if ($organisation !== '' && (string)($object->getOrganisation() ?? '') !== $organisation) {
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
	 * @param string $organisation The organisation identifier.
	 * @param string|null $agentId Optional agent UUID (agent-scoped budget).
	 *
	 * @return array<string, mixed> The status payload, or a "not configured" default.
	 *
	 * @spec openspec/changes/archive/2026-07-12-cost-guardrails/tasks.md#task-4-budgetcontroller-routes-crud-status-estimate
	 */
	public function statusForScope(string $organisation, ?string $agentId = null): array {
		$budget = $this->findBudgetTenantScoped(organisation: $organisation, agentId: $agentId);
		if ($budget === null) {
			$scope = 'organisation';
			if ($agentId !== null && $agentId !== '') {
				$scope = 'agent';
			}

			return [
				'scope' => $scope,
				'agentId' => $agentId,
				'period' => null,
				'periodKey' => null,
				'tokens' => ['available' => false],
				'eur' => ['available' => false],
				'softThresholdReached' => false,
				'hardCapReached' => false,
				'configured' => false,
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
	 * @spec openspec/changes/archive/2026-07-12-cost-guardrails/tasks.md#task-4-budgetcontroller-routes-crud-status-estimate
	 */
	public function findById(string $budgetId): ?ObjectEntity {
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
	 * @param array<string, mixed> $payload The requested budget fields.
	 * @param string $organisation The organisation to pin the budget to.
	 *
	 * @return array<string, mixed> The shaped, created budget.
	 *
	 * @throws InvalidArgumentException When the payload fails cross-field validation.
	 *
	 * @spec openspec/changes/archive/2026-07-12-cost-guardrails/tasks.md#task-4-budgetcontroller-routes-crud-status-estimate
	 */
	public function create(array $payload, string $organisation): array {
		$this->validatePayload(payload: $payload);

		$scope = (string)$payload['scope'];
		$agentId = '';
		if ($scope === 'agent') {
			$agentId = (string)$payload['agentId'];
		}

		$data = [
			'scope' => $scope,
			'agentId' => $agentId,
			'period' => (string)$payload['period'],
			'tokenLimit' => $this->intOrNull(value: ($payload['tokenLimit'] ?? null)),
			'eurLimit' => $this->floatOrNull(value: ($payload['eurLimit'] ?? null)),
			'softThresholdPercent' => (int)($payload['softThresholdPercent'] ?? self::DEFAULT_SOFT_THRESHOLD),
			'enabled' => ($payload['enabled'] ?? true) !== false,
			'warnedPeriodKey' => '',
			'lastHardBlockAt' => null,
		];

		// Pin the budget to the TARGET organisation, not the actor's active organisation
		// (mirrors OrganisationControlService::toggle()'s @self.organisation trick).
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
	 * @param string $budgetId The Budget object UUID.
	 * @param array<string, mixed> $payload The fields to update.
	 *
	 * @return array<string, mixed> The shaped, updated budget.
	 *
	 * @throws RuntimeException When the budget cannot be found.
	 * @throws InvalidArgumentException When the merged payload fails validation.
	 *
	 * @spec openspec/changes/archive/2026-07-12-cost-guardrails/tasks.md#task-4-budgetcontroller-routes-crud-status-estimate
	 */
	public function update(string $budgetId, array $payload): array {
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
		$data['eurLimit'] = $this->floatOrNull(value: ($data['eurLimit'] ?? null));

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
	 * Shape a Budget ObjectEntity into the API response payload (design.md shape).
	 *
	 * @param ObjectEntity $budget The budget object.
	 *
	 * @return array<string, mixed> The shaped payload.
	 *
	 * @spec openspec/changes/archive/2026-07-12-cost-guardrails/tasks.md#task-4-budgetcontroller-routes-crud-status-estimate
	 */
	public function shape(ObjectEntity $budget): array {
		$data = $budget->getObject();
		$agentId = (string)($data['agentId'] ?? '');

		$agentIdOrNull = null;
		if ($agentId !== '') {
			$agentIdOrNull = $agentId;
		}

		return [
			'id' => (string)$budget->getUuid(),
			'scope' => (string)($data['scope'] ?? 'organisation'),
			'agentId' => $agentIdOrNull,
			'period' => (string)($data['period'] ?? 'monthly'),
			'tokenLimit' => ($data['tokenLimit'] ?? null),
			'eurLimit' => ($data['eurLimit'] ?? null),
			'softThresholdPercent' => (int)($data['softThresholdPercent'] ?? self::DEFAULT_SOFT_THRESHOLD),
			'enabled' => (bool)($data['enabled'] ?? true),
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
	private function validatePayload(array $payload): void {
		$this->validateScope(payload: $payload);
		$this->validatePeriod(payload: $payload);
		$this->validateLimits(payload: $payload);

	}//end validatePayload()

	/**
	 * Refuse a scope other than `organisation` or `agent`, and an agent scope without
	 * an agentId.
	 *
	 * @param array<string, mixed> $payload The budget payload.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the scope is invalid.
	 */
	private function validateScope(array $payload): void {
		$scope = (string)($payload['scope'] ?? '');
		if (in_array($scope, ['organisation', 'agent'], true) === false) {
			throw new InvalidArgumentException('scope must be "organisation" or "agent"');
		}

		if ($scope === 'agent' && trim((string)($payload['agentId'] ?? '')) === '') {
			throw new InvalidArgumentException('agentId is required when scope=agent');
		}

	}//end validateScope()

	/**
	 * Refuse a period other than daily, weekly or monthly.
	 *
	 * @param array<string, mixed> $payload The budget payload.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the period is invalid.
	 */
	private function validatePeriod(array $payload): void {
		$period = (string)($payload['period'] ?? '');
		if (in_array($period, ['daily', 'weekly', 'monthly'], true) === false) {
			throw new InvalidArgumentException('period must be "daily", "weekly" or "monthly"');
		}

	}//end validatePeriod()

	/**
	 * Refuse a payload that sets neither a token nor a EUR limit.
	 *
	 * @param array<string, mixed> $payload The budget payload.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When no limit is set.
	 */
	private function validateLimits(array $payload): void {
		$hasToken = $this->isLimitSet(limit: ($payload['tokenLimit'] ?? null));
		$hasEur = $this->isLimitSet(limit: ($payload['eurLimit'] ?? null));
		if ($hasToken === false && $hasEur === false) {
			throw new InvalidArgumentException('At least one of tokenLimit or eurLimit is required');
		}

	}//end validateLimits()

	/**
	 * Whether a limit value is set: anything but null or the empty string (zero counts).
	 *
	 * @param mixed $limit The limit value.
	 *
	 * @return bool True when set.
	 */
	private function isLimitSet(mixed $limit): bool {
		return ($limit !== null && $limit !== '');

	}//end isLimitSet()

	/**
	 * Load enabled budgets matching a scope, system-wide (dispatch-path posture).
	 *
	 * @param string $organisation The organisation identifier.
	 * @param string|null $agentId The bound agent UUID, when known.
	 *
	 * @return array<int, ObjectEntity> The matching, enabled budgets.
	 */
	private function matchingBudgetsSystemWide(string $organisation, ?string $agentId): array {
		$budgets = $this->enabledBudgetsSystemWide();

		$scopes = new BudgetScopes();
		$matches = [];
		foreach ($budgets as $budget) {
			if ($scopes->matches(budget: $budget, organisation: $organisation, agentId: $agentId) === true) {
				$matches[] = $budget;
			}
		}

		return $matches;
	}//end matchingBudgetsSystemWide()

	/**
	 * Every enabled budget, system-wide (RBAC and multitenancy off), in read order.
	 *
	 * @return array<int, ObjectEntity> The enabled budgets.
	 */
	private function enabledBudgetsSystemWide(): array {
		$objects = $this->objectService
			->setRegister(self::REGISTER_SLUG)
			->setSchema(self::BUDGET_SCHEMA)
			->findAll(
				config: ['filters' => ['enabled' => true]],
				_rbac: false,
				_multitenancy: false
			);

		return array_values(array_filter($objects, fn (mixed $object): bool => $this->isEnabledBudget(object: $object)));

	}//end enabledBudgetsSystemWide()

	/**
	 * Whether a read object is a budget that is not switched off.
	 *
	 * A defensive re-check in PHP (mirrors loadEngagedOrganisations()'s own pattern):
	 * the 'enabled' filter on the read is an OR-side hint, not a guarantee.
	 *
	 * @param mixed $object One read object.
	 *
	 * @return bool True for an enabled budget.
	 */
	private function isEnabledBudget(mixed $object): bool {
		if (($object instanceof ObjectEntity) === false) {
			return false;
		}

		return ($object->getObject()['enabled'] ?? true) !== false;

	}//end isEnabledBudget()

	/**
	 * Find the caller's own budget for a scope, tenant-scoped (RBAC/multitenancy ON).
	 * Prefers an agent-scoped budget over the organisation-scoped one when both exist
	 * and an agentId was supplied; within each kind the last one read wins.
	 *
	 * @param string $organisation The organisation identifier ('' = any the caller sees).
	 * @param string|null $agentId Optional agent UUID.
	 *
	 * @return ObjectEntity|null The matching budget, or null when none is configured.
	 */
	private function findBudgetTenantScoped(string $organisation, ?string $agentId): ?ObjectEntity {
		$budgets = $this->tenantBudgets(organisation: $organisation);

		$scopes = new BudgetScopes();

		return ($scopes->lastAgentBudget(budgets: $budgets, agentId: $agentId) ?? $scopes->lastOrganisationBudget(budgets: $budgets));
	}//end findBudgetTenantScoped()

	/**
	 * The budgets the caller sees (RBAC/multitenancy ON), of one organisation when given.
	 *
	 * @param string $organisation The organisation identifier ('' = every organisation the caller sees).
	 *
	 * @return array<int, ObjectEntity> The budgets, in read order.
	 */
	private function tenantBudgets(string $organisation): array {
		$objects = $this->objectService
			->setRegister(self::REGISTER_SLUG)
			->setSchema(self::BUDGET_SCHEMA)
			->findAll(config: ['limit' => 1000]);

		return array_values(
			array_filter(
				$objects,
				static fn (mixed $object): bool => ($object instanceof ObjectEntity) === true
					&& ($organisation === '' || (string)($object->getOrganisation() ?? '') === $organisation)
			)
		);
	}//end tenantBudgets()

	/**
	 * Best-effort persist of `lastHardBlockAt` for admin visibility. Never fatal to the
	 * gate check — a failure here must not affect the block decision already made.
	 *
	 * @param ObjectEntity $budget The budget whose hard cap was just reached.
	 *
	 * @return void
	 */
	private function recordHardBlock(ObjectEntity $budget): void {
		try {
			$data = $budget->getObject();
			$data['lastHardBlockAt'] = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c');
			$this->objectService->saveObject(
				object: $data,
				register: self::REGISTER_SLUG,
				schema: self::BUDGET_SCHEMA,
				uuid: (string)$budget->getUuid(),
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Hermiq could not persist lastHardBlockAt for budget ' . ((string)$budget->getUuid()) . ': ' . $e->getMessage(),
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
	 * @param ObjectEntity $budget The budget whose scope (organisation, or agent) to match.
	 *
	 * @return array<int, string> The matching schedule + eval-run UUIDs.
	 */
	private function loadScheduleUuidsForScope(ObjectEntity $budget): array {
		return array_merge(
			$this->loadInScopeUuidsForSchema(schema: self::SCHEDULE_SCHEMA, budget: $budget),
			$this->loadInScopeUuidsForSchema(schema: self::EVALRUN_SCHEMA, budget: $budget),
			// Skill-self-improvement: consolidation drafts join the union so the
			// consolidation LLM pass's `action='run'` usage rolls into the SAME budget
			// total (the agent-evals precedent). Additive: no drafts ⇒ unchanged set.
			$this->loadInScopeUuidsForSchema(schema: self::SKILLDRAFT_SCHEMA, budget: $budget)
		);
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
	 * @param string $schema The OpenRegister schema slug to scan.
	 * @param ObjectEntity $budget The budget whose scope to match.
	 *
	 * @return array<int, string> The matching object UUIDs.
	 */
	private function loadInScopeUuidsForSchema(string $schema, ObjectEntity $budget): array {
		$uuids = [];
		foreach ($this->scopeScanObjects(schema: $schema) as $object) {
			if ($object instanceof ObjectEntity && $this->isInBudgetScope(object: $object, budget: $budget) === true) {
				$uuids[] = (string)$object->getUuid();
			}
		}

		return $uuids;
	}//end loadInScopeUuidsForSchema()

	/**
	 * Every object of one schema in the `hermiq` register, system-wide; a failed read
	 * is logged and yields none.
	 *
	 * Fails open (empty scope) rather than breaking budget resolution for every
	 * OTHER schema — e.g. an 'evalrun' scan on an instance whose register re-import
	 * has not yet landed the agent-evals schema must never take down the
	 * pre-existing Schedule-only budget gate.
	 *
	 * @param string $schema The OpenRegister schema slug to scan.
	 *
	 * @return array<int, mixed> The objects read.
	 */
	private function scopeScanObjects(string $schema): array {
		try {
			return $this->objectService
				->setRegister(self::REGISTER_SLUG)
				->setSchema($schema)
				->findAll(config: ['limit' => 1000], _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			$this->logger->warning(
				sprintf("Hermiq could not load '%s' objects for the budget scope scan: %s", $schema, $e->getMessage()),
				['exception' => $e]
			);
			return [];
		}

	}//end scopeScanObjects()

	/**
	 * Whether an object falls in a budget's scope: in the budget's organisation for an
	 * organisation budget, else bound to the budget's agent via its `agentId` field.
	 *
	 * @param ObjectEntity $object The scanned object.
	 * @param ObjectEntity $budget The budget.
	 *
	 * @return bool True when the object's usage counts toward the budget.
	 */
	private function isInBudgetScope(ObjectEntity $object, ObjectEntity $budget): bool {
		$data = $budget->getObject();
		if ((string)($data['scope'] ?? 'organisation') === 'organisation') {
			$organisation = (string)($budget->getOrganisation() ?? '');
			return $organisation !== '' && (string)($object->getOrganisation() ?? '') === $organisation;
		}

		$agentId = (string)($data['agentId'] ?? '');
		return $agentId !== '' && (string)($object->getObject()['agentId'] ?? '') === $agentId;

	}//end isInBudgetScope()

	/**
	 * Sum recorded token usage (`action='run'` AuditTrail entries) for a set of
	 * schedule UUIDs within a period window.
	 *
	 * @param array<int, string> $scheduleUuids The in-scope schedule UUIDs.
	 * @param array{start: DateTimeImmutable, end: DateTimeImmutable, key: string} $window The period window.
	 *
	 * @return int Total prompt+completion tokens recorded in the window.
	 */
	private function currentUsageTokens(array $scheduleUuids, array $window): int {
		if ($scheduleUuids === []) {
			return 0;
		}

		$inScope = array_flip($scheduleUuids);
		$logs = $this->auditTrailMapper->findAll(filters: ['action' => self::RUN_ACTION]);

		$total = 0;
		foreach ($logs as $log) {
			if (isset($inScope[(string)$log->getObjectUuid()]) === true && $this->isInWindow(log: $log, window: $window) === true) {
				$total += $this->runTokens(log: $log);
			}
		}

		return $total;
	}//end currentUsageTokens()

	/**
	 * Whether a run entry was created inside a period window (start inclusive, end exclusive).
	 *
	 * @param AuditTrail $log The run entry.
	 * @param array{start: DateTimeImmutable, end: DateTimeImmutable, key: string} $window The period window.
	 *
	 * @return bool True when inside the window.
	 */
	private function isInWindow(AuditTrail $log, array $window): bool {
		$created = $log->getCreated();
		return $created !== null && $created >= $window['start'] && $created < $window['end'];

	}//end isInWindow()

	/**
	 * The prompt plus completion tokens a run entry recorded; none without a usage record.
	 *
	 * @param AuditTrail $log The run entry.
	 *
	 * @return int The tokens.
	 */
	private function runTokens(AuditTrail $log): int {
		$usage = (($log->getChanged() ?? [])['usage'] ?? null);
		if (is_array($usage) === false) {
			return 0;
		}

		return (int)($usage['promptTokens'] ?? 0) + (int)($usage['completionTokens'] ?? 0);

	}//end runTokens()

	/**
	 * Compute the [start, end) UTC window and period key for a period kind, anchored
	 * to `$now`.
	 *
	 * @param string $period `daily`|`weekly`|`monthly`.
	 * @param DateTimeImmutable $now The current UTC moment.
	 *
	 * @return array{start: DateTimeImmutable, end: DateTimeImmutable, key: string}
	 */
	private function periodWindow(string $period, DateTimeImmutable $now): array {
		if ($period === 'daily') {
			$start = $now->setTime(0, 0, 0);
			return [
				'start' => $start,
				'end' => $start->modify('+1 day'),
				'key' => $start->format('Y-m-d'),
			];
		}

		if ($period === 'weekly') {
			$start = $now->setTime(0, 0, 0)->modify('monday this week');
			return [
				'start' => $start,
				'end' => $start->modify('+7 days'),
				'key' => $now->format('o-\WW'),
			];
		}

		// Default: monthly.
		$start = $now->setTime(0, 0, 0)->modify('first day of this month');
		return [
			'start' => $start,
			'end' => $start->modify('+1 month'),
			'key' => $now->format('Y-m'),
		];

	}//end periodWindow()

	/**
	 * The instance-wide EUR-per-1000-tokens conversion rate, or null when unset/invalid
	 * (EUR budgets/estimates stay unavailable — never a fabricated conversion).
	 *
	 * @return float|null
	 */
	private function eurRate(): ?float {
		$raw = $this->appConfig->getValueString(AppIdentity::APP_ID, self::EUR_RATE_KEY, '');
		if ($raw === '' || is_numeric($raw) === false) {
			return null;
		}

		return (float)$raw;
	}//end eurRate()

	/**
	 * Resolve an organisation's owner uid (the soft-threshold warning recipient).
	 * Fails open to an empty string (logged by the caller) rather than throwing.
	 *
	 * @param string $organisation The organisation identifier.
	 *
	 * @return string The owner uid, or '' when unresolvable.
	 */
	private function resolveOrgOwner(string $organisation): string {
		if ($organisation === '') {
			return '';
		}

		try {
			$org = $this->organisationMapper->findByUuid($organisation);
		} catch (Throwable $e) {
			return '';
		}

		return (string)($org->getOwner() ?? '');
	}//end resolveOrgOwner()

	/**
	 * Coerce a request-supplied value to an int, or null when empty/absent.
	 *
	 * @param mixed $value The candidate value.
	 *
	 * @return int|null
	 */
	private function intOrNull(mixed $value): ?int {
		if ($value === null || $value === '') {
			return null;
		}

		return (int)$value;
	}//end intOrNull()

	/**
	 * Coerce a request-supplied value to a float, or null when empty/absent.
	 *
	 * @param mixed $value The candidate value.
	 *
	 * @return float|null
	 */
	private function floatOrNull(mixed $value): ?float {
		if ($value === null || $value === '') {
			return null;
		}

		return (float)$value;
	}//end floatOrNull()
}//end class
