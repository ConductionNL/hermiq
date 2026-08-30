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
 * skill-evals adds the PAIRED BASELINE mode: the same cases run once WITH the
 * dataset's linked skills exposed (installed ∪ skillRefs) and once (or, in
 * per-skill mode, once per linked skill) WITHOUT them — via an in-memory
 * effective-skill-set override threaded down `runAgentAsOwner()`; the stored
 * `Agent.skillInstalls` / `Skill.installedOn` are NEVER written, so a crash at any
 * point leaves the agent intact. All halves are budget-counted through the same
 * gates and aggregated into the ONE per-run audit entry; a completed paired run is
 * the codebase's ONLY writer of `levelEvidence.l5` on each linked skill.
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
 * @spec openspec/changes/archive/2026-07-14-agent-evals/tasks.md#task-6-evalrunservice-orchestration
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use OCA\Hermiq\AppInfo\Application;
use OCA\Hermiq\BackgroundJob\SkillLearningsCaptureJob;
use OCA\Hermiq\Service\Engine\ContextAssembler;
use OCA\OpenRegister\Db\Agent;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Executes an EvalRun: gate checks, per-case execution + scoring, regression gate,
 * persistence, and the redacted audit write.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Coordinates several OR/Hermiq services.
 * @SuppressWarnings(PHPMD.ExcessiveParameterList)   Constructor DI: each parameter is a
 *   distinct injected collaborator, not a logic-bearing argument list.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) One class owns the whole eval-run
 *   lifecycle (gates, halves, scoring, regression gate, persistence, audit) so the
 *   paired-run invariants stay in a single place.
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)     Plain, paired and draft-comparison
 *   orchestration plus their shared persistence/audit helpers live together by design.
 *
 * @spec openspec/changes/archive/2026-07-14-agent-evals/tasks.md#task-6-evalrunservice-orchestration
 */
class EvalRunService {

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
	 * OpenRegister schema slug for Agent objects (paired mode reads the agent's
	 * `skillInstalls` + `evalBaselineMode` from the register object).
	 *
	 * @var string
	 */
	private const AGENT_SCHEMA = 'agent';

	/**
	 * OpenRegister schema slug for Skill objects (l5 evidence write-back target;
	 * namespaced to avoid a cross-app slug collision).
	 *
	 * @var string
	 */
	private const SKILL_SCHEMA = 'agentskill';

	/**
	 * Baseline attribution mode: ONE without-half detaching all linked skills
	 * together — joint delta, ~2× cost (the default, also when unset).
	 *
	 * @var string
	 */
	private const MODE_JOINT = 'joint';

	/**
	 * Baseline attribution mode: one without-half PER linked skill — true
	 * per-skill marginals at (N+1)× token cost per paired run.
	 *
	 * @var string
	 */
	private const MODE_PER_SKILL = 'per-skill';

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
	 * @param ObjectService $objectService Loads the dataset's cases source-of-truth
	 *                                     and persists the EvalRun (single
	 *                                     write-path).
	 * @param ScheduleService $scheduleService Reused kill-switch check
	 *                                         (`isOrganisationEngaged()`)
	 *                                         AND the reused
	 *                                         agent-turn dispatch
	 *                                         (`runAgentAsOwner()`)
	 *                                         — the SAME
	 *                                         ScheduleService/Engine
	 *                                         path a scheduled run
	 *                                         uses.
	 * @param BudgetService $budgetService Reused budget hard-cap gate + soft-threshold
	 *                                     warning (cost-guardrails).
	 * @param EvalScoringService $scoringService Deterministic + LLM-as-judge case scoring.
	 * @param AuditTrailMapper $auditTrailMapper OR audit write-path for the redacted
	 *                                           per-run entry.
	 * @param RedactionService $redactionService Masks secrets/PII before the audit write.
	 * @param IAppConfig $appConfig Reads the instance-wide regression-gate
	 *                              threshold default.
	 * @param LoggerInterface $logger Logs gate skips + non-fatal failures.
	 * @param ContextAssembler $contextAssembler The engine's skill-exposure seam —
	 *                                           the draft comparison sets its
	 *                                           transient CONTENT override around
	 *                                           the draft half (skill-self-improvement).
	 * @param SkillVersionService $skillVersionService Never-fatal skill version pins for the
	 *                                                 run audit entry (skill-self-improvement).
	 * @param IJobList $jobList Enqueues the post-run learnings capture
	 *                          job for failing cases of a COMPLETED
	 *                          run (skill-learnings design.md
	 *                          Decision 8 marker producer).
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Constructor DI: each parameter is
	 *   a distinct injected collaborator, not a logic-bearing argument list.
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
		private readonly ContextAssembler $contextAssembler,
		private readonly SkillVersionService $skillVersionService,
		private readonly IJobList $jobList,
	) {
	}//end __construct()

	/**
	 * Run the given EvalDataset against the given Agent, applying the same
	 * governance gates a scheduled tick applies before ever invoking the agent.
	 *
	 * @param ObjectEntity $dataset The EvalDataset (already owner-guarded
	 *                              by the controller).
	 * @param Agent $agent The target Agent (already owner-guarded
	 *                     by the controller).
	 * @param string|null $agentVersionId Reserved for agent-versioning
	 *                                    (not yet built) — accepted
	 *                                    and stored verbatim, never
	 *                                    resolved or validated.
	 * @param int|null $regressionThresholdOverride A per-trigger override (percentage
	 *                                              points) for the regression-gate
	 *                                              threshold; null uses the
	 *                                              instance-wide IAppConfig default.
	 * @param bool $baseline Whether to run in PAIRED baseline
	 *                       mode (skill-evals): the with-half
	 *                       exposes installed ∪ linked skills,
	 *                       the without-half(s) detach them
	 *                       per the agent's evalBaselineMode
	 *                       — in-memory only. False (every
	 *                       pre-existing caller) is
	 *                       byte-identical to before.
	 *
	 * @return array{evalRunId:string,status:string,passRate:float,regressionGateResult:string,previousPassRate:?float}
	 *
	 * @throws InvalidArgumentException When `$baseline` is true but the dataset has no
	 *                                  linked skills (`skillRefs` empty) — the
	 *                                  controller maps this to 400.
	 *
	 * @SuppressWarnings(PHPMD.LongVariable)        $regressionThresholdOverride mirrors the
	 *   documented regressionThresholdPercent contract field — the clarity IS the length.
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) $baseline is the documented paired-mode
	 *   API switch (skill-evals); false keeps every pre-existing caller byte-identical.
	 *
	 * @spec openspec/specs/agent-evals/spec.md#requirement-kill-switch-and-budget-hard-cap-gate-an-eval-run-exactly-as-they-gate-a-schedule-tick
	 * @spec openspec/specs/agent-evals/spec.md#requirement-every-half-of-a-paired-run-counts-toward-the-same-budgets-and-gates
	 */
	public function run(
		ObjectEntity $dataset,
		Agent $agent,
		?string $agentVersionId = null,
		?int $regressionThresholdOverride = null,
		bool $baseline = false,
	): array {
		$organisation = (string)($agent->getOrganisation() ?? '');
		$agentId = (string)$agent->getUuid();
		$datasetId = (string)$dataset->getUuid();
		$owner = (string)($agent->getOwner() ?? '');
		$startedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));

		// Paired mode requires linked skills — rejected BEFORE any gate-skip run is
		// ever persisted (the controller mirrors this as a 400).
		$linkedSkills = $this->linkedSkillIds(dataset: $dataset);
		if ($baseline === true && $linkedSkills === []) {
			throw new InvalidArgumentException('Baseline mode requires a dataset with linked skills (skillRefs).');
		}

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
				'Hermiq eval-run budget soft-threshold check failed: ' . $e->getMessage(),
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

		if ($baseline === true) {
			return $this->executePairedRun(
				dataset: $dataset,
				linkedSkills: $linkedSkills,
				organisation: $organisation,
				agentId: $agentId,
				datasetId: $datasetId,
				owner: $owner,
				agentVersionId: $agentVersionId,
				regressionThresholdOverride: $regressionThresholdOverride,
				startedAt: $startedAt
			);
		}

		return $this->executeCases(
			dataset: $dataset,
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
	 * Run the DRAFT-vs-ACTIVE paired comparison for one skill (skill-self-improvement
	 * pre-qualification): two sequential halves over the same frozen agent/dataset/
	 * cases — the ACTIVE half with the stored skill content, the DRAFT half with the
	 * draft's content swapped in via the ContextAssembler's transient IN-MEMORY
	 * override (the thin adapter over the per-run skill-set seam; no stored object is
	 * ever written). Kill-switch and budget gates apply exactly as `run()` applies
	 * them. Persisted as ONE EvalRun with the dedicated `draft-comparison` status so
	 * it can NEVER become a regression-gate baseline (`findPreviousCompletedRun()`
	 * only matches `completed`), and NEVER writes `levelEvidence.l5` (spec: accepting
	 * an unmeasured draft never grants L5; measured evidence stays `skill-evals`'
	 * paired mode's).
	 *
	 * @param ObjectEntity $dataset The linked EvalDataset (frozen cases).
	 * @param Agent $agent The agent to execute the halves as.
	 * @param string $skillId The skill under comparison.
	 * @param array<string, mixed> $draftContent The draft's `{name, description, body}`
	 *                                           override for the draft half.
	 *
	 * @return array{evalRunId:string,status:string,draftPassRate:float,activePassRate:float}
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength) One linear gates → active-half →
	 *   draft-half → persist → audit sequence mirroring run(); splitting it would
	 *   scatter the draft-comparison invariants across helpers.
	 *
	 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-a-paired-draft-vs-active-eval-gates-the-draft-and-a-worse-draft-is-auto-discarded
	 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-consolidation-and-its-evals-respect-the-kill-switch-and-budget-hard-caps
	 */
	public function runDraftComparison(
		ObjectEntity $dataset,
		Agent $agent,
		string $skillId,
		array $draftContent,
	): array {
		$organisation = (string)($agent->getOrganisation() ?? '');
		$agentId = (string)$agent->getUuid();
		$datasetId = (string)$dataset->getUuid();
		$owner = (string)($agent->getOwner() ?? '');
		$startedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));

		// GATE 1 — KILL-SWITCH (exactly like run()).
		if ($organisation !== '' && $this->scheduleService->isOrganisationEngaged(organisation: $organisation) === true) {
			$skip = $this->persistGateSkip(
				dataset: $dataset,
				agentId: $agentId,
				organisation: $organisation,
				agentVersionId: null,
				status: 'blocked_killswitch',
				startedAt: $startedAt
			);
			return [
				'evalRunId' => (string)$skip['evalRunId'],
				'status' => 'blocked_killswitch',
				'draftPassRate' => 0.0,
				'activePassRate' => 0.0,
			];
		}

		// GATE 2 — BUDGET HARD CAP (exactly like run()).
		if ($this->budgetService->isBlocked(organisation: $organisation, agentId: $agentId) === true) {
			$skip = $this->persistGateSkip(
				dataset: $dataset,
				agentId: $agentId,
				organisation: $organisation,
				agentVersionId: null,
				status: 'blocked_budget',
				startedAt: $startedAt
			);
			return [
				'evalRunId' => (string)$skip['evalRunId'],
				'status' => 'blocked_budget',
				'draftPassRate' => 0.0,
				'activePassRate' => 0.0,
			];
		}

		$data = $dataset->getObject();
		$cases = ($data['cases'] ?? []);
		if (is_array($cases) === false) {
			$cases = [];
		}

		[$installedSkills] = $this->agentSkillProfile(agentId: $agentId);
		$withSet = array_values(array_unique(array_merge($installedSkills, [$skillId])));

		$aggregateUsage = [
			'promptTokens' => 0,
			'completionTokens' => 0,
		];
		$skillsUsed = [];

		// ACTIVE half: the stored skill content, same effective set.
		$activeHalf = $this->runHalf(
			cases: $cases,
			owner: $owner,
			agentId: $agentId,
			organisation: $organisation,
			skillSetOverride: $withSet,
			aggregateUsage: $aggregateUsage,
			skillsUsed: $skillsUsed
		);

		// DRAFT half: identical set, the draft's content swapped in IN MEMORY only —
		// cleared in `finally` so no later run can ever see it.
		$this->contextAssembler->setTransientSkillContentOverride(override: [$skillId => $draftContent]);
		try {
			$draftHalf = $this->runHalf(
				cases: $cases,
				owner: $owner,
				agentId: $agentId,
				organisation: $organisation,
				skillSetOverride: $withSet,
				aggregateUsage: $aggregateUsage,
				skillsUsed: $skillsUsed
			);
		} finally {
			$this->contextAssembler->setTransientSkillContentOverride(override: null);
		}

		$activePassRate = $activeHalf['passRate'];
		$draftPassRate = $draftHalf['passRate'];
		$hadInfraError = ($activeHalf['hadInfraError'] === true || $draftHalf['hadInfraError'] === true);

		$status = 'draft-comparison';
		if ($hadInfraError === true) {
			// An infrastructure error means the comparison is NOT evidence — the
			// caller treats a failed comparison as "eval unavailable" (fail closed).
			$status = 'failed';
		}

		$endedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));

		$saved = $this->persistEvalRun(
			datasetId: $datasetId,
			agentId: $agentId,
			organisation: $organisation,
			agentVersionId: null,
			status: $status,
			startedAt: $startedAt,
			endedAt: $endedAt,
			results: $draftHalf['results'],
			passRate: $draftPassRate,
			regressionGateResult: 'not_applicable',
			previousPassRate: null,
			regressionThresholdPercent: 0,
			extra: [
				'baselineResults' => $activeHalf['results'],
				'baselinePassRate' => $activePassRate,
				'skillResults' => [
					[
						'skillId' => $skillId,
						'passRateWith' => $draftPassRate,
						'passRateWithout' => $activePassRate,
						'baselineDelta' => ($draftPassRate - $activePassRate),
					],
				],
			]
		);

		// Both halves' usage in ONE audit entry — the EvalRun UUID is already in
		// BudgetService's scope union, so the spend rolls into the same budgets.
		$this->writeRunAudit(
			evalRun: $saved,
			status: $status,
			usage: $aggregateUsage,
			passRate: $draftPassRate,
			startedAt: $startedAt,
			skillsUsed: $skillsUsed
		);

		return [
			'evalRunId' => (string)$saved->getUuid(),
			'status' => $status,
			'draftPassRate' => $draftPassRate,
			'activePassRate' => $activePassRate,
		];

	}//end runDraftComparison()

	/**
	 * The dataset's linked skill uuids (`skillRefs`), filtered to non-empty strings.
	 *
	 * @param ObjectEntity $dataset The EvalDataset.
	 *
	 * @return array<int, string> The linked skill uuids (deduplicated, reindexed).
	 *
	 * @spec openspec/specs/agent-evals/spec.md#requirement-an-evaldataset-links-skills-via-skillrefs-per-the-relation-dialect
	 */
	private function linkedSkillIds(ObjectEntity $dataset): array {
		$refs = ($dataset->getObject()['skillRefs'] ?? []);
		if (is_array($refs) === false) {
			return [];
		}

		$ids = array_filter($refs, static fn ($ref): bool => is_string($ref) === true && $ref !== '');

		return array_values(array_unique($ids));
	}//end linkedSkillIds()

	/**
	 * Execute every case in the dataset sequentially (never in parallel —
	 * `IUserSession::setUser()` impersonation is not concurrency-safe, spec.md
	 * Non-Functional Requirements), score each, compute the regression gate, persist
	 * the EvalRun, and write the redacted audit entry.
	 *
	 * @param ObjectEntity $dataset The EvalDataset.
	 * @param string $organisation The agent's organisation.
	 * @param string $agentId The agent UUID.
	 * @param string $datasetId The dataset UUID.
	 * @param string $owner The agent's owner uid (impersonation
	 *                      target).
	 * @param string|null $agentVersionId Inert, forward-compatible field.
	 * @param int|null $regressionThresholdOverride Per-trigger threshold override.
	 * @param DateTimeImmutable $startedAt When this run began.
	 *
	 * @return array{evalRunId:string,status:string,passRate:float,regressionGateResult:string,previousPassRate:?float}
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Threaded call-context, not a
	 *   logic-bearing argument list — every value is already resolved by run().
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)  One linear run-half → threshold →
	 *   regression gate → persist → audit → capture-enqueue sequence; splitting it
	 *   would scatter the single-run invariants across helpers.
	 * @SuppressWarnings(PHPMD.LongVariable)           $regressionThresholdOverride mirrors
	 *   the documented regressionThresholdPercent contract field — the clarity IS the
	 *   length.
	 *
	 * @spec openspec/specs/agent-evals/spec.md#requirement-an-evalrun-executes-each-case-through-the-agent-s-real-engine-path
	 * @spec openspec/specs/agent-evals/spec.md#requirement-eval-runs-are-non-delivering
	 */
	private function executeCases(
		ObjectEntity $dataset,
		string $organisation,
		string $agentId,
		string $datasetId,
		string $owner,
		?string $agentVersionId,
		?int $regressionThresholdOverride,
		DateTimeImmutable $startedAt,
	): array {
		$data = $dataset->getObject();
		$cases = $data['cases'] ?? [];
		if (is_array($cases) === false) {
			$cases = [];
		}

		$aggregateUsage = [
			'promptTokens' => 0,
			'completionTokens' => 0,
		];
		$skillsUsed = [];

		$half = $this->runHalf(
			cases: $cases,
			owner: $owner,
			agentId: $agentId,
			organisation: $organisation,
			skillSetOverride: null,
			aggregateUsage: $aggregateUsage,
			skillsUsed: $skillsUsed
		);

		$results = $half['results'];
		$hadInfraError = $half['hadInfraError'];
		$passRate = $half['passRate'];

		$thresholdPercent = $regressionThresholdOverride;
		if ($thresholdPercent === null) {
			$thresholdPercent = (int)$this->appConfig->getValueString(
				Application::APP_ID,
				self::REGRESSION_THRESHOLD_KEY,
				(string)self::DEFAULT_REGRESSION_THRESHOLD
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
			startedAt: $startedAt,
			skillsUsed: $skillsUsed
		);

		// Skill-learnings Decision 8 producer: AFTER the audit write (the capture
		// job reads the trace from that entry), failure-isolated like every other
		// capture enqueue.
		$this->enqueueEvalFailCaptures(
			evalRun: $saved,
			status: $status,
			results: $results,
			skillsUsed: $skillsUsed,
			agentId: $agentId,
			organisation: $organisation
		);

		return [
			'evalRunId' => (string)$saved->getUuid(),
			'status' => $status,
			'passRate' => $passRate,
			'regressionGateResult' => $regressionGateResult,
			'previousPassRate' => $previousPassRate,
		];

	}//end executeCases()

	/**
	 * Execute a PAIRED baseline run (skill-evals): the WITH half (installed ∪
	 * linked skills) plus the WITHOUT half(s) per the agent's `evalBaselineMode` —
	 * `joint` (default/absent): ONE half at installed ∖ linked, a JOINT delta
	 * shared across `skillResults` entries; `per-skill`: one half per linked skill
	 * at with-set ∖ {skill}, TRUE per-entry marginals with that half's case
	 * results on the entry's own `baselineResults`. All halves run sequentially,
	 * non-delivering, through `runAgentAsOwner()` with the per-run IN-MEMORY
	 * override — no code path here writes `Agent.skillInstalls` or
	 * `Skill.installedOn`, so a crash between halves leaves the stored objects
	 * byte-identical. Every half's token usage aggregates into the single per-run
	 * audit entry (same budgets, no separate meter); the regression gate is
	 * evaluated on the with-half pass rate via the existing machinery; on
	 * `status=completed` the l5 evidence is written per linked skill — the
	 * codebase's only l5 writer.
	 *
	 * @param ObjectEntity $dataset The EvalDataset.
	 * @param array<int,string> $linkedSkills The dataset's linked skill uuids (non-empty).
	 * @param string $organisation The agent's organisation.
	 * @param string $agentId The agent UUID.
	 * @param string $datasetId The dataset UUID.
	 * @param string $owner The agent's owner uid (impersonation target).
	 * @param string|null $agentVersionId Inert, forward-compatible field.
	 * @param int|null $regressionThresholdOverride Per-trigger threshold override.
	 * @param DateTimeImmutable $startedAt When this run began.
	 *
	 * @return array{evalRunId:string,status:string,passRate:float,regressionGateResult:string,previousPassRate:?float}
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Threaded call-context, not a
	 *   logic-bearing argument list — every value is already resolved by run().
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)  One linear with-half →
	 *   without-half(s) → aggregate → persist → evidence sequence; splitting it
	 *   would scatter the paired-run invariants across helpers.
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)   Paired-run orchestration mirrors
	 *   the spec's gate order across both attribution modes in one place.
	 * @SuppressWarnings(PHPMD.NPathComplexity)        The joint/per-skill branches and
	 *   infra-error checks are the spec's own decision points, kept linear here.
	 * @SuppressWarnings(PHPMD.LongVariable)           $regressionThresholdOverride mirrors
	 *   the documented regressionThresholdPercent contract field — the clarity IS the
	 *   length.
	 *
	 * @spec openspec/specs/agent-evals/spec.md#requirement-a-paired-baseline-run-executes-with-and-without-halves-per-evalbaselinemode
	 * @spec openspec/specs/agent-evals/spec.md#requirement-baseline-detachment-is-per-run-and-in-memory-only
	 * @spec openspec/specs/agent-evals/spec.md#requirement-the-regression-gate-applies-to-a-paired-run-s-with-half-pass-rate
	 */
	private function executePairedRun(
		ObjectEntity $dataset,
		array $linkedSkills,
		string $organisation,
		string $agentId,
		string $datasetId,
		string $owner,
		?string $agentVersionId,
		?int $regressionThresholdOverride,
		DateTimeImmutable $startedAt,
	): array {
		$data = $dataset->getObject();
		$cases = $data['cases'] ?? [];
		if (is_array($cases) === false) {
			$cases = [];
		}

		[$installedSkills, $attributionMode] = $this->agentSkillProfile(agentId: $agentId);

		// WITH half: installed ∪ linked — a linked-but-not-installed skill is exposed
		// exactly like an installed one, so install state cannot skew the comparison
		// (a skill can be qualified BEFORE it is ever installed on the agent).
		$withSet = array_values(array_unique(array_merge($installedSkills, $linkedSkills)));

		$aggregateUsage = [
			'promptTokens' => 0,
			'completionTokens' => 0,
		];
		$skillsUsed = [];

		$withHalf = $this->runHalf(
			cases: $cases,
			owner: $owner,
			agentId: $agentId,
			organisation: $organisation,
			skillSetOverride: $withSet,
			aggregateUsage: $aggregateUsage,
			skillsUsed: $skillsUsed
		);

		$passRate = $withHalf['passRate'];
		$hadInfraError = $withHalf['hadInfraError'];

		// WITHOUT half(s) — per-run, in-memory overrides ONLY; never a stored write.
		$baselineResults = null;
		$baselinePassRate = null;
		$skillResults = [];

		if ($attributionMode === self::MODE_PER_SKILL) {
			foreach ($linkedSkills as $skillId) {
				$withoutHalf = $this->runHalf(
					cases: $cases,
					owner: $owner,
					agentId: $agentId,
					organisation: $organisation,
					skillSetOverride: array_values(array_diff($withSet, [$skillId])),
					aggregateUsage: $aggregateUsage,
					skillsUsed: $skillsUsed
				);
				if ($withoutHalf['hadInfraError'] === true) {
					$hadInfraError = true;
				}

				$skillResults[] = [
					'skillId' => $skillId,
					'passRateWith' => $passRate,
					'passRateWithout' => $withoutHalf['passRate'],
					'baselineDelta' => ($passRate - $withoutHalf['passRate']),
					'baselineResults' => $withoutHalf['results'],
				];
			}//end foreach
		}//end if

		if ($attributionMode !== self::MODE_PER_SKILL) {
			$withoutHalf = $this->runHalf(
				cases: $cases,
				owner: $owner,
				agentId: $agentId,
				organisation: $organisation,
				skillSetOverride: array_values(array_diff($installedSkills, $linkedSkills)),
				aggregateUsage: $aggregateUsage,
				skillsUsed: $skillsUsed
			);
			if ($withoutHalf['hadInfraError'] === true) {
				$hadInfraError = true;
			}

			$baselineResults = $withoutHalf['results'];
			$baselinePassRate = $withoutHalf['passRate'];

			// Joint mode: every entry carries the SAME numbers — the delta is the
			// joint contribution of the linked set, honestly shared, never split.
			foreach ($linkedSkills as $skillId) {
				$skillResults[] = [
					'skillId' => $skillId,
					'passRateWith' => $passRate,
					'passRateWithout' => $baselinePassRate,
					'baselineDelta' => ($passRate - $baselinePassRate),
				];
			}
		}//end if

		$thresholdPercent = $regressionThresholdOverride;
		if ($thresholdPercent === null) {
			$thresholdPercent = (int)$this->appConfig->getValueString(
				Application::APP_ID,
				self::REGRESSION_THRESHOLD_KEY,
				(string)self::DEFAULT_REGRESSION_THRESHOLD
			);
		}

		// Regression gate: the with-half pass rate through the EXISTING machinery,
		// compared like-for-like against the previous completed run (paired or not).
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

		$paired = [
			'baselineMode' => true,
			'attributionMode' => $attributionMode,
			'skillResults' => $skillResults,
		];
		if ($attributionMode === self::MODE_JOINT) {
			$paired['baselineResults'] = $baselineResults;
			$paired['baselinePassRate'] = $baselinePassRate;
		}

		$saved = $this->persistEvalRun(
			datasetId: $datasetId,
			agentId: $agentId,
			organisation: $organisation,
			agentVersionId: $agentVersionId,
			status: $status,
			startedAt: $startedAt,
			endedAt: $endedAt,
			results: $withHalf['results'],
			passRate: $passRate,
			regressionGateResult: $regressionGateResult,
			previousPassRate: $previousPassRate,
			regressionThresholdPercent: $thresholdPercent,
			extra: $paired
		);

		// Every half's usage in the ONE per-run audit entry — BudgetService sums it
		// into the SAME per-org/per-agent budget a scheduled run uses.
		$this->writeRunAudit(
			evalRun: $saved,
			status: $status,
			usage: $aggregateUsage,
			passRate: $passRate,
			startedAt: $startedAt,
			skillsUsed: $skillsUsed
		);

		// Skill-learnings Decision 8 producer over the WITH-half results (the real
		// configuration) — after the audit write, failure-isolated.
		$this->enqueueEvalFailCaptures(
			evalRun: $saved,
			status: $status,
			results: $withHalf['results'],
			skillsUsed: $skillsUsed,
			agentId: $agentId,
			organisation: $organisation
		);

		// L5 evidence: ONLY on completed (every case of ALL halves executed), in
		// BOTH attribution modes — failed runs write nothing.
		if ($status === 'completed') {
			$this->writeL5Evidence(
				skillResults: $skillResults,
				datasetId: $datasetId,
				attributionMode: $attributionMode,
				endedAt: $endedAt
			);
		}

		return [
			'evalRunId' => (string)$saved->getUuid(),
			'status' => $status,
			'passRate' => $passRate,
			'regressionGateResult' => $regressionGateResult,
			'previousPassRate' => $previousPassRate,
		];

	}//end executePairedRun()

	/**
	 * Read the agent register object's skill profile: its stored `skillInstalls`
	 * and its `evalBaselineMode` (defaulting to `joint` when absent/invalid —
	 * an agent created before this change runs joint baselines). Read-only:
	 * the paired run never writes the agent object.
	 *
	 * @param string $agentId The agent UUID (hermiq register `agent` object).
	 *
	 * @return array{0: array<int,string>, 1: string} `[installedSkillIds, attributionMode]`.
	 *
	 * @spec openspec/specs/agent-evals/spec.md#requirement-the-agent-schema-declares-evalbaselinemode-with-a-consequence-explaining-description
	 */
	private function agentSkillProfile(string $agentId): array {
		$installed = [];
		$mode = self::MODE_JOINT;

		try {
			$agentObject = $this->objectService->find(
				id: $agentId,
				register: self::REGISTER_SLUG,
				schema: self::AGENT_SCHEMA,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				sprintf('Hermiq paired eval could not read agent %s — assuming no installs, joint mode: %s', $agentId, $e->getMessage()),
				['exception' => $e]
			);
			return [$installed, $mode];
		}

		if ($agentObject === null) {
			return [$installed, $mode];
		}

		$agentData = $agentObject->getObject();
		$stored = ($agentData['skillInstalls'] ?? []);
		if (is_array($stored) === true) {
			$installed = array_values(
				array_filter($stored, static fn ($uuid): bool => is_string($uuid) === true && $uuid !== '')
			);
		}

		$storedMode = (string)($agentData['evalBaselineMode'] ?? self::MODE_JOINT);
		if ($storedMode === self::MODE_PER_SKILL) {
			$mode = self::MODE_PER_SKILL;
		}

		return [$installed, $mode];
	}//end agentSkillProfile()

	/**
	 * Execute every case of ONE half sequentially (impersonation is not
	 * concurrency-safe) with the given effective-skill-set override, scoring each
	 * case and accumulating usage + exposed skills in place.
	 *
	 * @param array<int,mixed> $cases The dataset's cases.
	 * @param string $owner The agent's owner uid.
	 * @param string $agentId The agent UUID.
	 * @param string $organisation The agent's organisation.
	 * @param array<int,string>|null $skillSetOverride The half's effective skill set
	 *                                                 (null = the plain, non-paired
	 *                                                 path: stored installs).
	 * @param array<string,int> $aggregateUsage Running token totals, accumulated
	 *                                          in place across ALL halves.
	 * @param array<int,string> $skillsUsed Skill uuids actually exposed across
	 *                                      halves, accumulated in place
	 *                                      (deduplicated) for the audit entry.
	 *
	 * @return array{results: array<int,array<string,mixed>>, passRate: float, hadInfraError: bool}
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Threaded call-context shared by
	 *   the plain path and every paired half.
	 *
	 * @spec openspec/specs/agent-evals/spec.md#requirement-a-paired-baseline-run-executes-with-and-without-halves-per-evalbaselinemode
	 */
	private function runHalf(
		array $cases,
		string $owner,
		string $agentId,
		string $organisation,
		?array $skillSetOverride,
		array &$aggregateUsage,
		array &$skillsUsed,
	): array {
		$results = [];
		$passedCount = 0;
		$hadInfraError = false;

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
				aggregateUsage: $aggregateUsage,
				skillSetOverride: $skillSetOverride,
				skillsUsed: $skillsUsed
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
		$passRate = 0.0;
		if ($totalCases > 0) {
			// Explicit float: PHP's / yields an int for exact divisions (4/4 = 1),
			// and the pass rate is contractually a 0–1 fraction.
			$passRate = ((float)$passedCount / $totalCases);
		}

		return [
			'results' => $results,
			'passRate' => $passRate,
			'hadInfraError' => $hadInfraError,
		];

	}//end runHalf()

	/**
	 * Execute and score one case. Never throws — an agent-turn failure (an
	 * infrastructure-level error, e.g. no LLM provider configured) is recorded as a
	 * failed case with `infraError: true` so the caller can distinguish it from a
	 * normal failed assertion, but the run continues to the next case regardless
	 * (spec.md "one bad case does not abort the run").
	 *
	 * @param array<string,mixed> $case The EvalCase.
	 * @param int $caseIndex The case's 0-based index.
	 * @param string $owner The agent's owner uid (impersonation target).
	 * @param string $agentId The agent UUID.
	 * @param string $organisation The agent's organisation.
	 * @param array<string,int> $aggregateUsage Running prompt/completion token totals,
	 *                                          accumulated in place across cases.
	 * @param array<int,string>|null $skillSetOverride Per-run effective-skill-set override
	 *                                                 for this half (skill-evals); null
	 *                                                 (the plain path) exposes the agent's
	 *                                                 stored installs.
	 * @param array<int,string> $skillsUsed Exposed skill uuids, accumulated in
	 *                                      place (deduplicated) for the audit
	 *                                      entry.
	 *
	 * @return array{caseIndex:int,prompt:string,expectationType:string,actualOutput:string,passed:bool,errorMessage:?string,score:?float,judgeRationale:?string,infraError:bool}
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Threaded call-context, not a
	 *   logic-bearing argument list.
	 */
	private function executeCase(
		array $case,
		int $caseIndex,
		string $owner,
		string $agentId,
		string $organisation,
		array &$aggregateUsage,
		?array $skillSetOverride = null,
		array &$skillsUsed = [],
	): array {
		$prompt = (string)($case['prompt'] ?? '');
		$expectationType = (string)($case['expectationType'] ?? '');

		try {
			// Reused verbatim — the SAME impersonation + feature-flagged engine
			// branch a scheduled run dispatches through. Never calls
			// DeliveryService: that call lives in ScheduleService::dispatch()'s
			// own delivery step, not inside runAgentAsOwner() itself, so an eval
			// case is non-delivering by construction, not by a special flag.
			// The skill-set override is IN-MEMORY ONLY (skill-evals): stored
			// skillInstalls/installedOn are never touched by any code path here.
			$actualOutput = $this->scheduleService->runAgentAsOwner(
				owner: $owner,
				agentId: $agentId,
				prompt: $prompt,
				organisation: $organisation,
				skillSetOverride: $skillSetOverride
			);

			$usage = $this->scheduleService->getLastRunUsage();
			$aggregateUsage['promptTokens'] += (int)($usage['promptTokens'] ?? 0);
			$aggregateUsage['completionTokens'] += (int)($usage['completionTokens'] ?? 0);

			$exposed = $this->scheduleService->getLastRunSkillsUsed();
			$skillsUsed = array_values(array_unique(array_merge($skillsUsed, $exposed)));
		} catch (Throwable $e) {
			$this->logger->warning(
				sprintf('Hermiq eval case %d failed to execute: %s', $caseIndex, $e->getMessage()),
				['exception' => $e]
			);

			return [
				'caseIndex' => $caseIndex,
				'prompt' => $prompt,
				'expectationType' => $expectationType,
				'actualOutput' => '',
				'passed' => false,
				'errorMessage' => 'Agent run failed: ' . $e->getMessage(),
				'score' => null,
				'judgeRationale' => null,
				'infraError' => true,
			];
		}//end try

		$scored = $this->scoringService->score(case: $case, actualOutput: $actualOutput, organisation: $organisation);

		return [
			'caseIndex' => $caseIndex,
			'prompt' => $prompt,
			'expectationType' => $expectationType,
			'actualOutput' => $actualOutput,
			'passed' => $scored['passed'],
			'errorMessage' => $scored['errorMessage'],
			'score' => $scored['score'],
			'judgeRationale' => $scored['judgeRationale'],
			'infraError' => false,
		];

	}//end executeCase()

	/**
	 * Compare `$passRate` against the immediately preceding completed EvalRun for
	 * the same dataset+agent.
	 *
	 * @param string $datasetId The dataset UUID.
	 * @param string $agentId The agent UUID.
	 * @param float $passRate This run's aggregate pass-rate.
	 * @param int $thresholdPercent The effective regression threshold (percentage
	 *                              points of pass-rate drop).
	 *
	 * @return array{0:string,1:?float} `[regressionGateResult, previousPassRate]`.
	 *
	 * @spec openspec/specs/agent-evals/spec.md#requirement-regression-gate-compares-aggregate-pass-rate-against-the-previous-run
	 */
	private function evaluateRegressionGate(string $datasetId, string $agentId, float $passRate, int $thresholdPercent): array {
		$previous = $this->findPreviousCompletedRun(datasetId: $datasetId, agentId: $agentId);
		if ($previous === null) {
			return ['not_applicable', null];
		}

		$previousData = $previous->getObject();
		$previousPassRate = (float)($previousData['passRate'] ?? 0.0);
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
	 * @param string $agentId The agent UUID.
	 *
	 * @return ObjectEntity|null The most recent completed run, or null when none exists.
	 */
	private function findPreviousCompletedRun(string $datasetId, string $agentId): ?ObjectEntity {
		try {
			$objects = $this->objectService
				->setRegister(self::REGISTER_SLUG)
				->setSchema(self::EVALRUN_SCHEMA)
				->findAll(config: ['limit' => 1000], _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Hermiq could not load prior eval runs for the regression gate: ' . $e->getMessage(),
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
			if ((string)($data['datasetId'] ?? '') !== $datasetId) {
				continue;
			}

			if ((string)($data['agentId'] ?? '') !== $agentId) {
				continue;
			}

			if ((string)($data['status'] ?? '') !== 'completed') {
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
				$leftCreated = ($left->getCreated() ?? new DateTime('@0'));
				$rightCreated = ($right->getCreated() ?? new DateTime('@0'));
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
	 * @param string $datasetId The dataset UUID.
	 * @param string $agentId The agent UUID.
	 * @param string $organisation The agent's organisation.
	 * @param string|null $agentVersionId Inert field.
	 * @param string $status completed|failed.
	 * @param DateTimeImmutable $startedAt When the run began.
	 * @param DateTimeImmutable $endedAt When the run finished.
	 * @param array<int,array<string,mixed>> $results Per-case results.
	 * @param float $passRate Aggregate pass-rate.
	 * @param string $regressionGateResult passed|failed|not_applicable.
	 * @param float|null $previousPassRate The compared-against prior run's pass-rate.
	 * @param int $regressionThresholdPercent The effective threshold applied.
	 * @param array<string,mixed> $extra Paired-mode fields (skill-evals:
	 *                                   baselineMode, attributionMode,
	 *                                   skillResults, joint-mode
	 *                                   baselineResults/baselinePassRate);
	 *                                   empty for plain runs.
	 *
	 * @return ObjectEntity The persisted EvalRun.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Every field is a distinct EvalRun
	 *   column being persisted, not a logic-bearing argument list.
	 * @SuppressWarnings(PHPMD.LongVariable)           $regressionThresholdPercent mirrors
	 *   the persisted EvalRun schema field of the same name — the clarity IS the length.
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
		int $regressionThresholdPercent,
		array $extra = [],
	): ObjectEntity {
		$evalRunData = [
			'datasetId' => $datasetId,
			'agentId' => $agentId,
			'agentVersionId' => $agentVersionId,
			'status' => $status,
			'startedAt' => $startedAt->format('c'),
			'endedAt' => $endedAt->format('c'),
			'results' => $results,
			'passRate' => $passRate,
			'regressionGateResult' => $regressionGateResult,
			'previousPassRate' => $previousPassRate,
			'regressionThresholdPercent' => $regressionThresholdPercent,
			'@self' => ['organisation' => $organisation],
		];

		// Paired-mode fields ride the same single write; a plain run's payload is
		// byte-identical to before this change ($extra empty — no key is added).
		$evalRunData = array_merge($evalRunData, $extra);

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
	 * @param ObjectEntity $dataset The EvalDataset.
	 * @param string $agentId The agent UUID.
	 * @param string $organisation The agent's organisation.
	 * @param string|null $agentVersionId Inert field.
	 * @param string $status blocked_killswitch|blocked_budget.
	 * @param DateTimeImmutable $startedAt When the (skipped) run was triggered.
	 *
	 * @return array{evalRunId:string,status:string,passRate:float,regressionGateResult:string,previousPassRate:?float}
	 */
	private function persistGateSkip(
		ObjectEntity $dataset,
		string $agentId,
		string $organisation,
		?string $agentVersionId,
		string $status,
		DateTimeImmutable $startedAt,
	): array {
		$endedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));

		$saved = $this->persistEvalRun(
			datasetId: (string)$dataset->getUuid(),
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
			'evalRunId' => (string)$saved->getUuid(),
			'status' => $status,
			'passRate' => 0.0,
			'regressionGateResult' => 'not_applicable',
			'previousPassRate' => null,
		];

	}//end persistGateSkip()

	/**
	 * Write one redacted `action='run'` AuditTrail entry on the persisted EvalRun —
	 * mirrors `ScheduleService::writeRunAudit()`'s redaction-before-persist contract
	 * and `action` value, so `BudgetService::loadScheduleUuidsForScope()`'s widened
	 * scan counts this run's usage toward the same budget total a Schedule's does.
	 * Non-fatal: an audit-write failure never fails the eval run itself.
	 *
	 * @param ObjectEntity $evalRun The persisted EvalRun.
	 * @param string $status completed|failed|blocked_killswitch|blocked_budget.
	 * @param array<string,int> $usage Aggregated prompt/completion token usage across
	 *                                 cases — for a paired run, ALL halves' usage in
	 *                                 this ONE entry (same budgets, no separate meter).
	 * @param float $passRate The run's aggregate pass-rate (for the audit summary).
	 * @param DateTimeImmutable $startedAt When the run began.
	 * @param array<int,string> $skillsUsed The skill uuids the run-loop seam actually exposed
	 *                                      across this run's halves (skill-evals; recorded
	 *                                      for ALL runs — skill-learnings consumes it later).
	 *
	 * @return void
	 */
	private function writeRunAudit(
		ObjectEntity $evalRun,
		string $status,
		array $usage,
		float $passRate,
		DateTimeImmutable $startedAt,
		array $skillsUsed = [],
	): void {
		try {
			$endedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
			$summary = sprintf('Eval run %s — pass rate %.2f', $status, $passRate);

			$context = [
				'status' => $status,
				// Skill-learnings: the run's own identity INSIDE the context — the
				// capture pass's trace loader matches entries on `context.runId`
				// (an EvalRun's audit anchor object IS the run, so uuid = runId).
				'runId' => (string)$evalRun->getUuid(),
				'startedAt' => $startedAt->format('c'),
				'endedAt' => $endedAt->format('c'),
				'durationMs' => (((int)$endedAt->format('U') - (int)$startedAt->format('U')) * 1000),
				// Run-analytics/cost-guardrails: the SAME shape ScheduleService::writeRunAudit()
				// records, so BudgetService's existing `currentUsageTokens()` reader needs no
				// eval-specific branch.
				'usage' => $usage,
				// Skill-evals: which skill uuids the run(s) actually exposed — the same
				// key ScheduleService::writeRunAudit() records for scheduled runs.
				'skillsUsed' => $skillsUsed,
				// Skill-self-improvement: pin each exposed skill's version as of run
				// start (AuditTrail entry UUID) alongside the existing agent-version
				// pin. Never fatal — an unresolvable skill is simply absent.
				'skillVersions' => $this->skillVersionService->pinsFor(skillUuids: $skillsUsed),
				// REDACTION-BEFORE-PERSIST: mask secrets/PII before the append-only write
				// (ADR-004). The raw, unredacted per-case output only ever lives on the
				// EvalRun object itself (tenant/RBAC-scoped like any other Hermiq object).
				'summary' => $this->redactionService->redact($summary),
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
					(string)$evalRun->getUuid(),
					$e->getMessage()
				),
				['exception' => $e]
			);
		}//end try

	}//end writeRunAudit()

	/**
	 * Skill-learnings design.md Decision 8 PRODUCER: for every FAILING case of a
	 * COMPLETED eval run that actually exercised skills, enqueue the post-run
	 * learnings capture job carrying the failed-eval marker
	 * `<evalRunUuid>#<caseIndex>` — the promotion pass promotes a candidate with
	 * this marker regardless of confirmation count ("explains a failed eval case").
	 *
	 * Deliberately NEVER called from `runDraftComparison()` (its `draft-comparison`
	 * status would be filtered here anyway): a draft's transient content must never
	 * write learnings. Runs AFTER the audit write (the capture job reads the trace
	 * from that entry via `context.runId`), and mirrors
	 * `ScheduleService::enqueueLearningsCapture()`'s hard non-fatal contract — an
	 * enqueue failure is logged and swallowed, never failing the eval run.
	 *
	 * The capture pass is idempotent per (skill, runId): with several failing
	 * cases the first executed job captures and stamps the run id, later jobs
	 * no-op — one marker per run's candidates, exactly what promotion needs.
	 *
	 * @param ObjectEntity $evalRun The persisted EvalRun.
	 * @param string $status The run's final status.
	 * @param array<int,array<string,mixed>> $results The run's (with-half) case results.
	 * @param array<int,string> $skillsUsed Skill uuids the run actually exposed.
	 * @param string $agentId The run's agent uuid.
	 * @param string $organisation The run's organisation (budget scope).
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Threaded call-context from the
	 *   two run paths, not a logic-bearing argument list.
	 *
	 * @spec openspec/specs/skill-learnings/spec.md#requirement-promotion-is-a-mechanical-two-stage-background-pass
	 * @spec openspec/specs/skill-learnings/spec.md#requirement-capture-is-failure-isolated-from-the-run
	 */
	private function enqueueEvalFailCaptures(
		ObjectEntity $evalRun,
		string $status,
		array $results,
		array $skillsUsed,
		string $agentId,
		string $organisation,
	): void {
		if ($status !== 'completed' || $skillsUsed === []) {
			return;
		}

		$evalRunUuid = (string)$evalRun->getUuid();

		foreach ($results as $result) {
			// Entries are built in-process by executeCase(), so the shape is
			// guaranteed — only the pass/fail verdict needs checking.
			if (($result['passed'] ?? null) !== false) {
				continue;
			}

			try {
				$this->jobList->add(
					SkillLearningsCaptureJob::class,
					[
						'runId' => $evalRunUuid,
						// The trace anchor: the audit entry lives ON the EvalRun
						// object (same lookup shape a Schedule's runs use).
						'scheduleUuid' => $evalRunUuid,
						'agentId' => $agentId,
						'skillIds' => $skillsUsed,
						'organisation' => $organisation,
						'evalFail' => $evalRunUuid . '#' . ((int)($result['caseIndex'] ?? 0)),
					]
				);
			} catch (Throwable $e) {
				$this->logger->warning(
					sprintf(
						'Hermiq could not enqueue eval-fail learnings capture for run %s: %s',
						$evalRunUuid,
						$e->getMessage()
					),
					['exception' => $e]
				);
			}//end try
		}//end foreach

	}//end enqueueEvalFailCaptures()

	/**
	 * Write `levelEvidence.l5` on each linked skill after a COMPLETED paired run —
	 * the codebase's ONLY l5 writer (client writes stay silently preserved-over per
	 * the skill-maturity-model contract). Per skill: read the CURRENT stored
	 * object, patch ONLY `levelEvidence.l5` (OR saveObject is PUT-semantic — every
	 * other field is carried forward verbatim), never touching `body`,
	 * `frontmatter`, `files`, `state`, `installedOn`, or `maturityLevel`; the
	 * level itself updates on the next qualify, never here. The `mode` marker
	 * keeps joint evidence honest: a `joint` delta is the joint contribution of
	 * the linked set, `per-skill` is that skill's true marginal. Per-skill
	 * failures are logged, never fatal — one unresolvable skill must not void the
	 * others' evidence.
	 *
	 * @param array<int,array<string,mixed>> $skillResults The run's per-skill entries.
	 * @param string $datasetId The dataset UUID.
	 * @param string $attributionMode joint|per-skill (the run's snapshot).
	 * @param DateTimeImmutable $endedAt The run's endedAt (becomes lastValidated).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agent-evals/spec.md#requirement-a-completed-paired-run-is-the-only-writer-of-l5-evidence
	 */
	private function writeL5Evidence(array $skillResults, string $datasetId, string $attributionMode, DateTimeImmutable $endedAt): void {
		foreach ($skillResults as $entry) {
			$skillId = (string)($entry['skillId'] ?? '');
			if ($skillId === '') {
				continue;
			}

			try {
				$skill = $this->objectService->find(
					id: $skillId,
					register: self::REGISTER_SLUG,
					schema: self::SKILL_SCHEMA,
					_rbac: false,
					_multitenancy: false
				);
				if (($skill instanceof ObjectEntity) === false) {
					$this->logger->warning(
						sprintf('Hermiq paired eval could not resolve skill %s for l5 evidence — skipped.', $skillId)
					);
					continue;
				}

				$data = $skill->getObject();
				$evidence = ($data['levelEvidence'] ?? []);
				if (is_array($evidence) === false) {
					$evidence = [];
				}

				$evidence['l5'] = [
					'evalDatasetId' => $datasetId,
					'passRate' => (float)($entry['passRateWith'] ?? 0.0),
					'baselineDelta' => (float)($entry['baselineDelta'] ?? 0.0),
					'lastValidated' => $endedAt->format('c'),
					'mode' => $attributionMode,
				];

				$data['levelEvidence'] = $evidence;

				$this->objectService->saveObject(
					object: $data,
					register: self::REGISTER_SLUG,
					schema: self::SKILL_SCHEMA,
					uuid: $skillId,
					_rbac: false,
					_multitenancy: false
				);
			} catch (Throwable $e) {
				$this->logger->warning(
					sprintf('Hermiq could not write l5 evidence on skill %s: %s', $skillId, $e->getMessage()),
					['exception' => $e]
				);
			}//end try
		}//end foreach

	}//end writeL5Evidence()
}//end class
