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
use OCA\Hermiq\BackgroundJob\SkillLearningsCaptureJob;
use OCA\Hermiq\Kernel\AppIdentity;
use OCA\Hermiq\Kernel\Contracts\EvalRunServiceInterface;
use OCA\Hermiq\Service\Engine\ContextAssembler;
use OCA\Hermiq\Service\Eval\EvalRunRecord;
use OCA\Hermiq\Service\Eval\EvalRunScores;
use OCA\Hermiq\Service\Eval\EvalRunSubject;
use OCA\Hermiq\Service\Eval\L5EvidenceContext;
use OCA\OpenRegister\Db\AuditTrail\ObjectAuditEvent;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Mcp\Db\Agent;
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
class EvalRunService implements EvalRunServiceInterface {

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
		$trigger = $this->triggerFor(dataset: $dataset, agent: $agent);
		$trigger->agentVersionId = $agentVersionId;
		$trigger->thresholdOverride = $regressionThresholdOverride;
		$trigger->baseline = $baseline;

		return $this->runTriggered(trigger: $trigger);

	}//end run()

	/**
	 * Start an eval run of the dataset as the given agent, now.
	 *
	 * @param ObjectEntity $dataset The EvalDataset.
	 * @param Agent        $agent   The target Agent.
	 *
	 * @return EvalRunTrigger
	 */
	private function triggerFor(ObjectEntity $dataset, Agent $agent): EvalRunTrigger {
		$organisation = (string)($agent->getOrganisation() ?? '');
		$agentId = (string)$agent->getUuid();
		$owner = (string)($agent->getOwner() ?? '');

		$trigger = new EvalRunTrigger(dataset: $dataset, startedAt: new DateTimeImmutable('now', new DateTimeZone('UTC')));
		$trigger->organisation = $organisation;
		$trigger->agentId = $agentId;
		$trigger->owner = $owner;

		return $trigger;

	}//end triggerFor()

	/**
	 * Apply the governance gates a scheduled tick applies, then execute the run
	 * (paired when the trigger asks for a baseline) or persist the gate skip.
	 *
	 * @param EvalRunTrigger $trigger The triggered run.
	 *
	 * @return array{evalRunId:string,status:string,passRate:float,regressionGateResult:string,previousPassRate:?float}
	 *
	 * @throws InvalidArgumentException When a baseline run's dataset has no linked skills.
	 *
	 * @spec openspec/specs/agent-evals/spec.md#requirement-kill-switch-and-budget-hard-cap-gate-an-eval-run-exactly-as-they-gate-a-schedule-tick
	 */
	private function runTriggered(EvalRunTrigger $trigger): array {
		// Paired mode requires linked skills — rejected BEFORE any gate-skip run is
		// ever persisted (the controller mirrors this as a 400).
		$linkedSkills = $this->linkedSkillIds(dataset: $trigger->dataset);
		if ($trigger->baseline === true && $linkedSkills === []) {
			throw new InvalidArgumentException('Baseline mode requires a dataset with linked skills (skillRefs).');
		}

		$blockedStatus = $this->gateBlockedStatus(trigger: $trigger);
		if ($blockedStatus !== null) {
			return $this->persistGateSkip(trigger: $trigger, status: $blockedStatus);
		}

		if ($trigger->baseline === true) {
			return $this->executePairedRun(trigger: $trigger, linkedSkills: $linkedSkills);
		}

		return $this->executeCases(trigger: $trigger);

	}//end runTriggered()

	/**
	 * The status a gate blocks the run with, or null when the run may execute.
	 *
	 * GATE 1 — KILL-SWITCH (highest priority, exactly like ScheduleService::dispatch()).
	 * GATE 2 — BUDGET HARD CAP (cost-guardrails), after the unconditional, never-fatal
	 * soft-threshold check, mirroring ScheduleService::dispatch().
	 *
	 * @param EvalRunTrigger $trigger The triggered run.
	 *
	 * @return string|null blocked_killswitch|blocked_budget, or null.
	 */
	private function gateBlockedStatus(EvalRunTrigger $trigger): ?string {
		$organisation = $trigger->organisation;
		if ($organisation !== '' && $this->scheduleService->isOrganisationEngaged(organisation: $organisation) === true) {
			return 'blocked_killswitch';
		}

		$this->checkBudgetWarnings(trigger: $trigger);
		if ($this->budgetService->isBlocked(organisation: $organisation, agentId: $trigger->agentId) === true) {
			return 'blocked_budget';
		}

		return null;

	}//end gateBlockedStatus()

	/**
	 * Deliver the budget soft-threshold warnings; a failure is logged, never fatal.
	 *
	 * @param EvalRunTrigger $trigger The triggered run.
	 *
	 * @return void
	 */
	private function checkBudgetWarnings(EvalRunTrigger $trigger): void {
		try {
			$this->budgetService->checkAndDeliverWarnings(organisation: $trigger->organisation, agentId: $trigger->agentId);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Hermiq eval-run budget soft-threshold check failed: ' . $e->getMessage(),
				['exception' => $e]
			);
		}

	}//end checkBudgetWarnings()

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
	 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-a-paired-draft-vs-active-eval-gates-the-draft-and-a-worse-draft-is-auto-discarded
	 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-consolidation-and-its-evals-respect-the-kill-switch-and-budget-hard-caps
	 */
	public function runDraftComparison(
		ObjectEntity $dataset,
		Agent $agent,
		string $skillId,
		array $draftContent,
	): array {
		$trigger = $this->triggerFor(dataset: $dataset, agent: $agent);

		return $this->compareDraft(trigger: $trigger, draft: new SkillDraftOverride(skillId: $skillId, content: $draftContent));

	}//end runDraftComparison()

	/**
	 * Gate the comparison, run the ACTIVE half with the stored skill content and the
	 * DRAFT half with the draft swapped in, and record both.
	 *
	 * @param EvalRunTrigger     $trigger The triggered comparison.
	 * @param SkillDraftOverride $draft   The draft under comparison.
	 *
	 * @return array{evalRunId:string,status:string,draftPassRate:float,activePassRate:float}
	 *
	 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-a-paired-draft-vs-active-eval-gates-the-draft-and-a-worse-draft-is-auto-discarded
	 */
	private function compareDraft(EvalRunTrigger $trigger, SkillDraftOverride $draft): array {
		$skip = $this->draftComparisonGateSkip(trigger: $trigger);
		if ($skip !== null) {
			return $skip;
		}

		$run = $this->draftComparisonRun(trigger: $trigger, draft: $draft);

		// ACTIVE half: the stored skill content, same effective set.
		$activeHalf = $this->runContextHalf(run: $run, skillSet: $run['withSet']);
		$draftHalf = $this->draftHalf(run: $run, draft: $draft);

		return $this->recordDraftComparison(run: $run, halves: ['active' => $activeHalf, 'draft' => $draftHalf]);
	}//end compareDraft()

	/**
	 * The draft comparison's run context: the agent's installed skills plus the
	 * skill under comparison as the effective set of both halves.
	 *
	 * @param EvalRunTrigger     $trigger The triggered comparison.
	 * @param SkillDraftOverride $draft   The draft under comparison.
	 *
	 * @return array<string, mixed>
	 */
	private function draftComparisonRun(EvalRunTrigger $trigger, SkillDraftOverride $draft): array {
		[$installedSkills] = $this->agentSkillProfile(agentId: $trigger->agentId);

		return $this->runContext(trigger: $trigger) + [
			'skillId' => $draft->skillId,
			'withSet' => array_values(array_unique(array_merge($installedSkills, [$draft->skillId]))),
			'usage' => ['promptTokens' => 0, 'completionTokens' => 0],
			'skillsUsed' => [],
		];
	}//end draftComparisonRun()

	/**
	 * DRAFT half: the identical set, the draft's content swapped in IN MEMORY only —
	 * cleared in `finally` so no later run can ever see it.
	 *
	 * @param array<string, mixed> $run   The draft-comparison context; its usage accumulates in place.
	 * @param SkillDraftOverride   $draft The draft under comparison.
	 *
	 * @return array{results: array<int,array<string,mixed>>, passRate: float, hadInfraError: bool}
	 */
	private function draftHalf(array &$run, SkillDraftOverride $draft): array {
		$this->contextAssembler->setTransientSkillContentOverride(override: [$draft->skillId => $draft->content]);
		try {
			return $this->runContextHalf(run: $run, skillSet: $run['withSet']);
		} finally {
			$this->contextAssembler->setTransientSkillContentOverride(override: null);
		}
	}//end draftHalf()

	/**
	 * Applies the kill-switch and then the budget hard cap exactly as `run()` does,
	 * and persists the skipped run when one of them blocks.
	 *
	 * @param EvalRunTrigger $trigger The triggered comparison.
	 *
	 * @return array{evalRunId:string,status:string,draftPassRate:float,activePassRate:float}|null Null when neither gate blocks.
	 *
	 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-consolidation-and-its-evals-respect-the-kill-switch-and-budget-hard-caps
	 */
	private function draftComparisonGateSkip(EvalRunTrigger $trigger): ?array {
		$status = $this->draftComparisonGateStatus(trigger: $trigger);
		if ($status === null) {
			return null;
		}

		$skip = $this->persistGateSkip(trigger: $trigger, status: $status);

		return ['evalRunId' => (string)$skip['evalRunId'], 'status' => $status, 'draftPassRate' => 0.0, 'activePassRate' => 0.0];
	}//end draftComparisonGateSkip()

	/**
	 * The status the kill-switch or the budget hard cap blocks a draft comparison
	 * with (no soft-threshold check), or null when neither blocks.
	 *
	 * @param EvalRunTrigger $trigger The triggered comparison.
	 *
	 * @return string|null blocked_killswitch|blocked_budget, or null.
	 */
	private function draftComparisonGateStatus(EvalRunTrigger $trigger): ?string {
		$organisation = $trigger->organisation;
		if ($organisation !== '' && $this->scheduleService->isOrganisationEngaged(organisation: $organisation) === true) {
			return 'blocked_killswitch';
		}

		if ($this->budgetService->isBlocked(organisation: $organisation, agentId: $trigger->agentId) === true) {
			return 'blocked_budget';
		}

		return null;
	}//end draftComparisonGateStatus()

	/**
	 * Persists a finished draft comparison as ONE EvalRun (the draft half as its
	 * results, the active half as its baseline) under the `draft-comparison`
	 * status, or `failed` when either half hit an infrastructure error, and
	 * writes its audit entry.
	 *
	 * @param array<string, mixed> $run    The draft-comparison context.
	 * @param array<string, mixed> $halves The `active` and `draft` halves.
	 *
	 * @return array{evalRunId:string,status:string,draftPassRate:float,activePassRate:float}
	 *
	 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-a-paired-draft-vs-active-eval-gates-the-draft-and-a-worse-draft-is-auto-discarded
	 */
	private function recordDraftComparison(array $run, array $halves): array {
		$activePassRate = $halves['active']['passRate'];
		$draftPassRate = $halves['draft']['passRate'];
		$status = 'draft-comparison';
		if ($halves['active']['hadInfraError'] === true || $halves['draft']['hadInfraError'] === true) {
			// An infrastructure error means the comparison is NOT evidence — the
			// caller treats a failed comparison as "eval unavailable" (fail closed).
			$status = 'failed';
		}

		$saved = $this->persistEvalRun(
			record: new EvalRunRecord(
				subject: new EvalRunSubject(
					datasetId: $run['datasetId'],
					agentId: $run['agentId'],
					organisation: $run['organisation'],
					agentVersionId: null
				),
				status: $status,
				startedAt: $run['startedAt'],
				endedAt: new DateTimeImmutable('now', new DateTimeZone('UTC')),
				scores: new EvalRunScores(
					results: $halves['draft']['results'],
					passRate: $draftPassRate,
					regressionGateResult: 'not_applicable',
					previousPassRate: null,
					regressionThresholdPercent: 0
				),
				extra: [
				'baselineResults' => $halves['active']['results'],
				'baselinePassRate' => $activePassRate,
				'skillResults' => [
					[
						'skillId' => $run['skillId'],
						'passRateWith' => $draftPassRate,
						'passRateWithout' => $activePassRate,
						'baselineDelta' => ($draftPassRate - $activePassRate),
					],
				],
			]
			)
		);

		// Both halves' usage in ONE audit entry — the EvalRun UUID is already in
		// BudgetService's scope union, so the spend rolls into the same budgets.
		$recorded = $this->recordedRun(evalRun: $saved, run: $run);
		$recorded->status = $status;
		$recorded->passRate = $draftPassRate;
		$this->writeRunAudit(recorded: $recorded);

		return ['evalRunId' => (string)$saved->getUuid(), 'status' => $status, 'draftPassRate' => $draftPassRate, 'activePassRate' => $activePassRate];
	}//end recordDraftComparison()

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
	 * @param EvalRunTrigger $trigger The triggered run, past its gates.
	 *
	 * @return array{evalRunId:string,status:string,passRate:float,regressionGateResult:string,previousPassRate:?float}
	 *
	 * @spec openspec/specs/agent-evals/spec.md#requirement-an-evalrun-executes-each-case-through-the-agent-s-real-engine-path
	 * @spec openspec/specs/agent-evals/spec.md#requirement-eval-runs-are-non-delivering
	 */
	private function executeCases(EvalRunTrigger $trigger): array {
		$run = $this->runContext(trigger: $trigger);
		$half = $this->runContextHalf(run: $run, skillSet: null);
		$outcome = $this->regressionOutcome(run: $run, half: $half);
		$saved = $this->recordRun(run: $run, outcome: $outcome);

		return $this->runSummary(evalRun: $saved, outcome: $outcome);

	}//end executeCases()

	/**
	 * The run context the case execution and the recording steps read: the
	 * dataset's cases, the agent, the trigger's choices and the start time.
	 *
	 * @param EvalRunTrigger $trigger The triggered run.
	 *
	 * @return array<string, mixed>
	 */
	private function runContext(EvalRunTrigger $trigger): array {
		return [
			'cases' => $this->datasetCases(dataset: $trigger->dataset),
			'owner' => $trigger->owner,
			'agentId' => $trigger->agentId,
			'organisation' => $trigger->organisation,
			'datasetId' => $trigger->datasetId(),
			'agentVersionId' => $trigger->agentVersionId,
			'thresholdOverride' => $trigger->thresholdOverride,
			'startedAt' => $trigger->startedAt,
		];

	}//end runContext()

	/**
	 * The dataset's cases, or none when `cases` is not a list.
	 *
	 * @param ObjectEntity $dataset The EvalDataset.
	 *
	 * @return array<int|string, mixed>
	 */
	private function datasetCases(ObjectEntity $dataset): array {
		$cases = ($dataset->getObject()['cases'] ?? []);
		if (is_array($cases) === false) {
			return [];
		}

		return $cases;

	}//end datasetCases()

	/**
	 * The run's regression-gate verdict on the half's pass rate, its status and its
	 * end time, alongside the half's results.
	 *
	 * The gate compares like-for-like against the previous completed run (paired
	 * or not), through the existing machinery.
	 *
	 * @param array<string, mixed> $run  The run context.
	 * @param array<string, mixed> $half The half the verdict is on (results, passRate, hadInfraError).
	 *
	 * @return array<string, mixed>
	 */
	private function regressionOutcome(array $run, array $half): array {
		$verdict = $this->regressionVerdict(run: $run, passRate: $half['passRate']);

		return [
			'status' => $this->runStatus(hadInfraError: $half['hadInfraError']),
			'endedAt' => new DateTimeImmutable('now', new DateTimeZone('UTC')),
			'results' => $half['results'],
			'passRate' => $half['passRate'],
		] + $verdict;

	}//end regressionOutcome()

	/**
	 * The regression gate's verdict on a pass rate, with the threshold it applied.
	 *
	 * @param array<string, mixed> $run      The run context.
	 * @param float                $passRate The pass rate the gate judges.
	 *
	 * @return array{regressionGateResult:string,previousPassRate:?float,thresholdPercent:int}
	 */
	private function regressionVerdict(array $run, float $passRate): array {
		$thresholdPercent = $this->regressionThreshold(override: $run['thresholdOverride']);
		$previousPassRate = $this->previousPassRate(run: $run);
		$dropPercentPoints = $this->dropPercentPoints(previousPassRate: $previousPassRate, passRate: $passRate);

		return [
			'regressionGateResult' => $this->regressionGateResult(dropPercentPoints: $dropPercentPoints, thresholdPercent: $thresholdPercent),
			'previousPassRate' => $previousPassRate,
			'thresholdPercent' => $thresholdPercent,
		];

	}//end regressionVerdict()

	/**
	 * A run that hit an infrastructure error failed; otherwise it completed.
	 *
	 * @param bool $hadInfraError Whether any case hit an infrastructure error.
	 *
	 * @return string completed|failed
	 */
	private function runStatus(bool $hadInfraError): string {
		if ($hadInfraError === true) {
			return 'failed';
		}

		return 'completed';

	}//end runStatus()

	/**
	 * What `run()` returns for a recorded run.
	 *
	 * @param ObjectEntity         $evalRun The persisted EvalRun.
	 * @param array<string, mixed> $outcome The run's outcome.
	 *
	 * @return array{evalRunId:string,status:string,passRate:float,regressionGateResult:string,previousPassRate:?float}
	 */
	private function runSummary(ObjectEntity $evalRun, array $outcome): array {
		return [
			'evalRunId' => (string)$evalRun->getUuid(),
			'status' => $outcome['status'],
			'passRate' => $outcome['passRate'],
			'regressionGateResult' => $outcome['regressionGateResult'],
			'previousPassRate' => $outcome['previousPassRate'],
		];

	}//end runSummary()

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
	 * @param EvalRunTrigger    $trigger      The triggered run, past its gates.
	 * @param array<int,string> $linkedSkills The dataset's linked skill uuids (non-empty).
	 *
	 * @return array{evalRunId:string,status:string,passRate:float,regressionGateResult:string,previousPassRate:?float}
	 *
	 * @spec openspec/specs/agent-evals/spec.md#requirement-a-paired-baseline-run-executes-with-and-without-halves-per-evalbaselinemode
	 * @spec openspec/specs/agent-evals/spec.md#requirement-baseline-detachment-is-per-run-and-in-memory-only
	 * @spec openspec/specs/agent-evals/spec.md#requirement-the-regression-gate-applies-to-a-paired-run-s-with-half-pass-rate
	 */
	private function executePairedRun(EvalRunTrigger $trigger, array $linkedSkills): array {
		$context = $this->runContext(trigger: $trigger);
		[$installedSkills, $attributionMode] = $this->agentSkillProfile(agentId: $trigger->agentId);

		$run = $context + [
			'linked' => $linkedSkills,
			'installed' => $installedSkills,
			'mode' => $attributionMode,
			// WITH half: installed ∪ linked — a linked-but-not-installed skill is exposed
			// exactly like an installed one, so install state cannot skew the comparison
			// (a skill can be qualified BEFORE it is ever installed on the agent).
			'withSet' => array_values(array_unique(array_merge($installedSkills, $linkedSkills))),
		];

		$halves = $this->runPairedHalves(run: $run);

		return $this->finishPairedRun(run: $run, halves: $halves);

	}//end executePairedRun()

	/**
	 * Runs the WITH half and the WITHOUT half(s) of a paired run, sequentially.
	 *
	 * WITHOUT halves use per-run, in-memory overrides ONLY; never a stored write.
	 * Every half's usage and exposed skills accumulate on `$run`.
	 *
	 * @param array<string, mixed> $run The paired-run context (see executePairedRun()).
	 *
	 * @return array{with: array, hadInfraError: bool, baselineResults: ?array, baselinePassRate: ?float, skillResults: array<int, array<string, mixed>>}
	 *
	 * @spec openspec/specs/agent-evals/spec.md#requirement-a-paired-baseline-run-executes-with-and-without-halves-per-evalbaselinemode
	 */
	private function runPairedHalves(array &$run): array {
		$withHalf = $this->runContextHalf(run: $run, skillSet: $run['withSet']);
		$halves = [
			'with' => $withHalf,
			'hadInfraError' => $withHalf['hadInfraError'],
			'baselineResults' => null,
			'baselinePassRate' => null,
			'skillResults' => [],
		];

		if ($run['mode'] === self::MODE_PER_SKILL) {
			return $this->perSkillHalves(run: $run, halves: $halves);
		}

		return $this->jointHalf(run: $run, halves: $halves);
	}//end runPairedHalves()

	/**
	 * Per-skill mode: one WITHOUT half per linked skill at with-set ∖ {skill}, each
	 * entry carrying its own marginal and that half's case results.
	 *
	 * @param array<string, mixed> $run The paired-run context.
	 * @param array<string, mixed> $halves The halves so far (see runPairedHalves()).
	 *
	 * @return array<string, mixed>
	 */
	private function perSkillHalves(array &$run, array $halves): array {
		$passRate = $halves['with']['passRate'];
		foreach ($run['linked'] as $skillId) {
			$withoutHalf = $this->runContextHalf(run: $run, skillSet: array_values(array_diff($run['withSet'], [$skillId])));
			if ($withoutHalf['hadInfraError'] === true) {
				$halves['hadInfraError'] = true;
			}

			$halves['skillResults'][] = [
				'skillId' => $skillId,
				'passRateWith' => $passRate,
				'passRateWithout' => $withoutHalf['passRate'],
				'baselineDelta' => ($passRate - $withoutHalf['passRate']),
				'baselineResults' => $withoutHalf['results'],
			];
		}

		return $halves;
	}//end perSkillHalves()

	/**
	 * Joint mode: ONE WITHOUT half at installed ∖ linked. Every entry carries the
	 * SAME numbers: the delta is the joint contribution of the linked set, shared,
	 * never split.
	 *
	 * @param array<string, mixed> $run The paired-run context.
	 * @param array<string, mixed> $halves The halves so far (see runPairedHalves()).
	 *
	 * @return array<string, mixed>
	 */
	private function jointHalf(array &$run, array $halves): array {
		$passRate = $halves['with']['passRate'];
		$withoutHalf = $this->runContextHalf(run: $run, skillSet: array_values(array_diff($run['installed'], $run['linked'])));
		if ($withoutHalf['hadInfraError'] === true) {
			$halves['hadInfraError'] = true;
		}

		$halves['baselineResults'] = $withoutHalf['results'];
		$halves['baselinePassRate'] = $withoutHalf['passRate'];
		foreach ($run['linked'] as $skillId) {
			$halves['skillResults'][] = [
				'skillId' => $skillId,
				'passRateWith' => $passRate,
				'passRateWithout' => $withoutHalf['passRate'],
				'baselineDelta' => ($passRate - $withoutHalf['passRate']),
			];
		}

		return $halves;
	}//end jointHalf()

	/**
	 * Runs one half of a run (a paired half, a draft-comparison half or the
	 * whole plain run) at the given skill set.
	 *
	 * @param array<string, mixed>    $run      The run context; its `usage` and
	 *                                          `skillsUsed` accumulate in place.
	 * @param array<int, string>|null $skillSet The per-run skill-set override (null =
	 *                                          the plain path: stored installs).
	 *
	 * @return array{results: array<int,array<string,mixed>>, passRate: float, hadInfraError: bool}
	 */
	private function runContextHalf(array &$run, ?array $skillSet): array {
		$caseRun = $this->caseRunFor(run: $run);
		$half = $this->runHalf(run: $caseRun, skillSetOverride: $skillSet);
		$run['usage'] = $caseRun->usage;
		$run['skillsUsed'] = $caseRun->skillsUsed;

		return $half;
	}//end runContextHalf()

	/**
	 * Evaluates the regression gate on the with-half pass rate and records the run.
	 *
	 * @param array<string, mixed> $run The paired-run context.
	 * @param array<string, mixed> $halves The executed halves (see runPairedHalves()).
	 *
	 * @return array{evalRunId:string,status:string,passRate:float,regressionGateResult:string,previousPassRate:?float}
	 *
	 * @spec openspec/specs/agent-evals/spec.md#requirement-the-regression-gate-applies-to-a-paired-run-s-with-half-pass-rate
	 */
	private function finishPairedRun(array $run, array $halves): array {
		$outcome = $this->regressionOutcome(run: $run, half: ['hadInfraError' => $halves['hadInfraError']] + $halves['with']);
		$outcome['paired'] = $this->pairedFields(run: $run, halves: $halves);
		$saved = $this->recordRun(run: $run, outcome: $outcome);

		return $this->runSummary(evalRun: $saved, outcome: $outcome);
	}//end finishPairedRun()

	/**
	 * The paired-mode fields the EvalRun carries: the attribution mode, the
	 * per-skill results and, in joint mode, the without-half's results.
	 *
	 * @param array<string, mixed> $run The paired-run context.
	 * @param array<string, mixed> $halves The executed halves (see runPairedHalves()).
	 *
	 * @return array<string, mixed>
	 */
	private function pairedFields(array $run, array $halves): array {
		$paired = [
			'baselineMode' => true,
			'attributionMode' => $run['mode'],
			'skillResults' => $halves['skillResults'],
		];
		if ($run['mode'] === self::MODE_JOINT) {
			$paired['baselineResults'] = $halves['baselineResults'];
			$paired['baselinePassRate'] = $halves['baselinePassRate'];
		}

		return $paired;
	}//end pairedFields()

	/**
	 * Persists a finished run, writes its audit entry, enqueues the fail
	 * captures and, for a completed paired run, writes the l5 evidence.
	 *
	 * @param array<string, mixed> $run The run context (plain or paired).
	 * @param array<string, mixed> $outcome The run's status, end time, results and gate
	 *                                      verdict, plus `paired` for a paired run.
	 *
	 * @return ObjectEntity The persisted EvalRun.
	 */
	private function recordRun(array $run, array $outcome): ObjectEntity {
		$saved = $this->persistEvalRun(
			record: new EvalRunRecord(
				subject: new EvalRunSubject(
					datasetId: $run['datasetId'],
					agentId: $run['agentId'],
					organisation: $run['organisation'],
					agentVersionId: $run['agentVersionId']
				),
				status: $outcome['status'],
				startedAt: $run['startedAt'],
				endedAt: $outcome['endedAt'],
				scores: new EvalRunScores(
					results: $outcome['results'],
					passRate: $outcome['passRate'],
					regressionGateResult: $outcome['regressionGateResult'],
					previousPassRate: $outcome['previousPassRate'],
					regressionThresholdPercent: $outcome['thresholdPercent']
				),
				extra: ($outcome['paired'] ?? [])
			)
		);

		$recorded = $this->recordedRun(evalRun: $saved, run: $run);
		$recorded->status = $outcome['status'];
		$recorded->passRate = $outcome['passRate'];
		$recorded->results = $outcome['results'];

		// Every half's usage in the ONE per-run audit entry: BudgetService sums it
		// into the SAME per-org/per-agent budget a scheduled run uses.
		$this->writeRunAudit(recorded: $recorded);

		// Skill-learnings Decision 8 producer over the (WITH-half) results — the real
		// configuration — after the audit write (the capture job reads the trace from
		// that entry), failure-isolated.
		$this->enqueueEvalFailCaptures(recorded: $recorded);

		$this->recordPairedEvidence(run: $run, outcome: $outcome);

		return $saved;
	}//end recordRun()

	/**
	 * L5 evidence: ONLY for a paired run that completed (every case of ALL halves
	 * executed), in BOTH attribution modes. Failed and plain runs write nothing.
	 *
	 * @param array<string, mixed> $run The run context.
	 * @param array<string, mixed> $outcome The run's outcome.
	 *
	 * @return void
	 */
	private function recordPairedEvidence(array $run, array $outcome): void {
		if (isset($outcome['paired']) === false || $outcome['status'] !== 'completed') {
			return;
		}

		$this->writeL5Evidence(
			skillResults: $outcome['paired']['skillResults'],
			l5Context: new L5EvidenceContext(
				datasetId: $run['datasetId'],
				attributionMode: $run['mode'],
				endedAt: $outcome['endedAt']
			)
		);
	}//end recordPairedEvidence()

	/**
	 * The regression threshold: the per-trigger override, else the app setting.
	 *
	 * @param int|null $override Per-trigger threshold override.
	 *
	 * @return int
	 */
	private function regressionThreshold(?int $override): int {
		if ($override !== null) {
			return $override;
		}

		return (int)$this->appConfig->getValueString(
			AppIdentity::APP_ID,
			self::REGRESSION_THRESHOLD_KEY,
			(string)self::DEFAULT_REGRESSION_THRESHOLD
		);
	}//end regressionThreshold()

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
	 * case and accumulating usage + exposed skills on the run.
	 *
	 * @param EvalCaseRun            $run              The case execution; its usage and exposed
	 *                                                 skills accumulate in place across ALL halves.
	 * @param array<int,string>|null $skillSetOverride The half's effective skill set
	 *                                                 (null = the plain, non-paired
	 *                                                 path: stored installs).
	 *
	 * @return array{results: array<int,array<string,mixed>>, passRate: float, hadInfraError: bool}
	 *
	 * @spec openspec/specs/agent-evals/spec.md#requirement-a-paired-baseline-run-executes-with-and-without-halves-per-evalbaselinemode
	 */
	private function runHalf(EvalCaseRun $run, ?array $skillSetOverride): array {
		$run->skillSetOverride = $skillSetOverride;
		$caseResults = [];
		foreach ($run->cases as $index => $case) {
			if (is_array($case) === true) {
				$caseResults[] = $this->executeCase(run: $run, caseIndex: $index);
			}
		}

		return $this->summariseHalf(caseResults: $caseResults);

	}//end runHalf()

	/**
	 * The half's stored case results (without the in-process infraError flag),
	 * its pass rate and whether any case hit an infrastructure error.
	 *
	 * @param array<int,array<string,mixed>> $caseResults The executed cases, in order.
	 *
	 * @return array{results: array<int,array<string,mixed>>, passRate: float, hadInfraError: bool}
	 */
	private function summariseHalf(array $caseResults): array {
		$passed = array_filter($caseResults, static fn (array $result): bool => $result['passed'] === true);
		$infraErrors = array_filter($caseResults, static fn (array $result): bool => $result['infraError'] === true);
		$results = array_map(static fn (array $result): array => array_diff_key($result, ['infraError' => true]), $caseResults);

		return [
			'results' => $results,
			'passRate' => $this->passRate(passedCount: count($passed), totalCases: count($results)),
			'hadInfraError' => ($infraErrors !== []),
		];

	}//end summariseHalf()

	/**
	 * The share of passed cases as a 0–1 fraction (0.0 for no cases).
	 *
	 * @param int $passedCount The number of passed cases.
	 * @param int $totalCases  The number of executed cases.
	 *
	 * @return float
	 */
	private function passRate(int $passedCount, int $totalCases): float {
		if ($totalCases === 0) {
			return 0.0;
		}

		// Explicit float: PHP's / yields an int for exact divisions (4/4 = 1),
		// and the pass rate is contractually a 0–1 fraction.
		return ((float)$passedCount / $totalCases);

	}//end passRate()

	/**
	 * Execute and score one case. Never throws — an agent-turn failure (an
	 * infrastructure-level error, e.g. no LLM provider configured) is recorded as a
	 * failed case with `infraError: true` so the caller can distinguish it from a
	 * normal failed assertion, but the run continues to the next case regardless
	 * (spec.md "one bad case does not abort the run").
	 *
	 * @param EvalCaseRun $run       The case execution (agent, half's skill set, usage).
	 * @param int         $caseIndex The case's 0-based index in `$run->cases`.
	 *
	 * @return array{caseIndex:int,prompt:string,expectationType:string,actualOutput:string,passed:bool,errorMessage:?string,score:?float,judgeRationale:?string,infraError:bool}
	 */
	private function executeCase(EvalCaseRun $run, int $caseIndex): array {
		$case = $run->cases[$caseIndex];
		$record = $this->caseRecord(case: $case, caseIndex: $caseIndex);
		if ($record['expectationType'] === 'counterfactualPair') {
			return $this->executePairCase(run: $run, case: $case, record: $record);
		}

		try {
			$actualOutput = $this->runCaseTurn(run: $run, prompt: $record['prompt']);
		} catch (Throwable $e) {
			return $this->failedCase(record: $record, error: $e);
		}

		$scored = $this->scoringService->score(case: $case, actualOutput: $actualOutput, organisation: $run->organisation);

		return $this->scoredCase(record: $record + ['actualOutput' => $actualOutput], scored: $scored);

	}//end executeCase()

	/**
	 * Execute and score a `counterfactualPair` case: request A and request B
	 * each run as their own agent turn (a separate session, so neither answer
	 * sees the other), then the judge compares the two answers. Both answers
	 * and the judge's rationale are kept on the case result. A failed turn is
	 * an infra-error case, as for any other case.
	 *
	 * @param EvalCaseRun                                                $run    The case execution.
	 * @param array<string,mixed>                                        $case   The EvalCase.
	 * @param array{caseIndex:int,prompt:string,expectationType:string} $record The case's identifying fields.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-test-case-can-compare-two-requests-that-differ-in-one-attribute-req-biaseval-001
	 */
	private function executePairCase(EvalCaseRun $run, array $case, array $record): array {
		$inputA = (string)($case['inputA'] ?? $record['prompt']);
		$inputB = (string)($case['inputB'] ?? '');
		$record['prompt'] = $inputA;
		$record['inputB'] = $inputB;

		try {
			$outputA = $this->runCaseTurn(run: $run, prompt: $inputA);
			$outputB = $this->runCaseTurn(run: $run, prompt: $inputB);
		} catch (Throwable $e) {
			return $this->failedCase(record: $record, error: $e) + ['actualOutputB' => ''];
		}

		$scored = $this->scoringService->scorePair(case: $case, outputA: $outputA, outputB: $outputB, organisation: $run->organisation);

		return $this->scoredCase(record: $record + ['actualOutput' => $outputA, 'actualOutputB' => $outputB], scored: $scored);
	}//end executePairCase()

	/**
	 * The identifying fields every case result starts with.
	 *
	 * @param array<string,mixed> $case      The EvalCase.
	 * @param int                 $caseIndex The case's 0-based index.
	 *
	 * @return array{caseIndex:int,prompt:string,expectationType:string}
	 */
	private function caseRecord(array $case, int $caseIndex): array {
		return [
			'caseIndex' => $caseIndex,
			'prompt' => (string)($case['prompt'] ?? ''),
			'expectationType' => (string)($case['expectationType'] ?? ''),
		];

	}//end caseRecord()

	/**
	 * Run one case's agent turn and add its usage and exposed skills to the run.
	 *
	 * Reused verbatim — the SAME impersonation + feature-flagged engine
	 * branch a scheduled run dispatches through. Never calls
	 * DeliveryService: that call lives in ScheduleService::dispatch()'s
	 * own delivery step, not inside runAgentAsOwner() itself, so an eval
	 * case is non-delivering by construction, not by a special flag.
	 * The skill-set override is IN-MEMORY ONLY (skill-evals): stored
	 * skillInstalls/installedOn are never touched by any code path here.
	 *
	 * @param EvalCaseRun $run    The case execution.
	 * @param string      $prompt The case's prompt.
	 *
	 * @return string The agent's output.
	 */
	private function runCaseTurn(EvalCaseRun $run, string $prompt): string {
		$actualOutput = $this->scheduleService->runAgentAsOwner(
			owner: $run->owner,
			agentId: $run->agentId,
			prompt: $prompt,
			organisation: $run->organisation,
			skillSetOverride: $run->skillSetOverride
		);

		$usage = $this->scheduleService->getLastRunUsage();
		$run->usage['promptTokens'] += (int)($usage['promptTokens'] ?? 0);
		$run->usage['completionTokens'] += (int)($usage['completionTokens'] ?? 0);

		$exposed = $this->scheduleService->getLastRunSkillsUsed();
		$run->skillsUsed = array_values(array_unique(array_merge($run->skillsUsed, $exposed)));

		return $actualOutput;

	}//end runCaseTurn()

	/**
	 * Log a case whose agent turn threw and record it as a failed infra-error case.
	 *
	 * @param array{caseIndex:int,prompt:string,expectationType:string} $record The case's identifying fields.
	 * @param Throwable                                                 $error  What the turn threw.
	 *
	 * @return array<string,mixed>
	 */
	private function failedCase(array $record, Throwable $error): array {
		$this->logger->warning(
			sprintf('Hermiq eval case %d failed to execute: %s', $record['caseIndex'], $error->getMessage()),
			['exception' => $error]
		);

		return $record + [
			'actualOutput' => '',
			'passed' => false,
			'errorMessage' => 'Agent run failed: ' . $error->getMessage(),
			'score' => null,
			'judgeRationale' => null,
			'infraError' => true,
		];

	}//end failedCase()

	/**
	 * A case result carrying the scoring verdict.
	 *
	 * @param array<string,mixed> $record The case's identifying fields and actual output.
	 * @param array<string,mixed> $scored The scoring verdict.
	 *
	 * @return array<string,mixed>
	 */
	private function scoredCase(array $record, array $scored): array {
		return $record + [
			'passed' => $scored['passed'],
			'errorMessage' => $scored['errorMessage'],
			'score' => $scored['score'],
			'judgeRationale' => $scored['judgeRationale'],
			'infraError' => false,
		];

	}//end scoredCase()

	/**
	 * The case execution of a run context (paired run or draft comparison), carrying
	 * the usage and exposed skills accumulated so far.
	 *
	 * @param array<string,mixed> $run The run context: cases, owner, agentId,
	 *                                 organisation and, optionally, usage and skillsUsed.
	 *
	 * @return EvalCaseRun
	 */
	private function caseRunFor(array $run): EvalCaseRun {
		$caseRun = new EvalCaseRun();
		$caseRun->cases = array_values($run['cases']);
		$caseRun->owner = $run['owner'];
		$caseRun->agentId = $run['agentId'];
		$caseRun->organisation = $run['organisation'];
		$caseRun->usage = ($run['usage'] ?? $caseRun->usage);
		$caseRun->skillsUsed = ($run['skillsUsed'] ?? []);

		return $caseRun;

	}//end caseRunFor()

	/**
	 * The pass rate of the immediately preceding completed EvalRun for the same
	 * dataset+agent, or null when there is none.
	 *
	 * @param array<string, mixed> $run The run context (datasetId, agentId).
	 *
	 * @return float|null
	 *
	 * @spec openspec/specs/agent-evals/spec.md#requirement-regression-gate-compares-aggregate-pass-rate-against-the-previous-run
	 */
	private function previousPassRate(array $run): ?float {
		$previous = $this->findPreviousCompletedRun(datasetId: $run['datasetId'], agentId: $run['agentId']);
		if ($previous === null) {
			return null;
		}

		return (float)($previous->getObject()['passRate'] ?? 0.0);

	}//end previousPassRate()

	/**
	 * The pass-rate drop against the previous run, in percentage points (null
	 * without a previous run).
	 *
	 * @param float|null $previousPassRate The previous run's pass rate.
	 * @param float      $passRate         This run's aggregate pass rate.
	 *
	 * @return float|null
	 */
	private function dropPercentPoints(?float $previousPassRate, float $passRate): ?float {
		if ($previousPassRate === null) {
			return null;
		}

		return (($previousPassRate - $passRate) * 100);

	}//end dropPercentPoints()

	/**
	 * The regression gate's result: not applicable without a previous run, failed
	 * when the drop exceeds the threshold, passed otherwise.
	 *
	 * @param float|null $dropPercentPoints The pass-rate drop in percentage points.
	 * @param int        $thresholdPercent  The effective regression threshold.
	 *
	 * @return string not_applicable|failed|passed
	 *
	 * @spec openspec/specs/agent-evals/spec.md#requirement-regression-gate-compares-aggregate-pass-rate-against-the-previous-run
	 */
	private function regressionGateResult(?float $dropPercentPoints, int $thresholdPercent): string {
		if ($dropPercentPoints === null) {
			return 'not_applicable';
		}

		if ($dropPercentPoints > $thresholdPercent) {
			return 'failed';
		}

		return 'passed';

	}//end regressionGateResult()

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
	 * @param EvalRunRecord $record What one stored eval run records.
	 *
	 * @return ObjectEntity The persisted EvalRun.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Every field is a distinct EvalRun
	 *   column being persisted, not a logic-bearing argument list.
	 * @SuppressWarnings(PHPMD.LongVariable)           $regressionThresholdPercent mirrors
	 *   the persisted EvalRun schema field of the same name — the clarity IS the length.
	 */
	private function persistEvalRun(EvalRunRecord $record): ObjectEntity {
		$datasetId = $record->datasetId;
		$agentId = $record->agentId;
		$organisation = $record->organisation;
		$agentVersionId = $record->agentVersionId;
		$status = $record->status;
		$startedAt = $record->startedAt;
		$endedAt = $record->endedAt;
		$results = $record->results;
		$passRate = $record->passRate;
		$regressionGateResult = $record->regressionGateResult;
		$previousPassRate = $record->previousPassRate;
		$regressionThresholdPercent = $record->regressionThresholdPercent;
		$extra = $record->extra;
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
	 * @param EvalRunTrigger $trigger The blocked run.
	 * @param string         $status  blocked_killswitch|blocked_budget.
	 *
	 * @return array{evalRunId:string,status:string,passRate:float,regressionGateResult:string,previousPassRate:?float}
	 */
	private function persistGateSkip(EvalRunTrigger $trigger, string $status): array {
		$endedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));

		$saved = $this->persistEvalRun(
			record: new EvalRunRecord(
				subject: new EvalRunSubject(
					datasetId: $trigger->datasetId(),
					agentId: $trigger->agentId,
					organisation: $trigger->organisation,
					agentVersionId: $trigger->agentVersionId
				),
				status: $status,
				startedAt: $trigger->startedAt,
				endedAt: $endedAt,
				scores: new EvalRunScores(
					results: [],
					passRate: 0.0,
					regressionGateResult: 'not_applicable',
					previousPassRate: null,
					regressionThresholdPercent: 0
				)
			)
		);

		$recorded = new RecordedEvalRun(evalRun: $saved, startedAt: $trigger->startedAt);
		$recorded->status = $status;
		$this->writeRunAudit(recorded: $recorded);

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
	 * @param RecordedEvalRun $recorded The persisted run: its status
	 *                                  (completed|failed|blocked_killswitch|blocked_budget),
	 *                                  pass rate, start time, usage (for a paired run, ALL
	 *                                  halves' usage in this ONE entry — same budgets, no
	 *                                  separate meter) and exposed skills.
	 *
	 * @return void
	 */
	private function writeRunAudit(RecordedEvalRun $recorded): void {
		try {
			$this->auditTrailMapper->recordObjectEvent(event: $this->runAuditEvent(recorded: $recorded));
		} catch (Throwable $e) {
			$this->logger->warning(
				sprintf('Hermiq could not write eval-run audit for %s: %s', (string)$recorded->evalRun->getUuid(), $e->getMessage()),
				['exception' => $e]
			);
		}

	}//end writeRunAudit()

	/**
	 * The redacted `action='run'` audit event on the persisted EvalRun.
	 *
	 * @param RecordedEvalRun $recorded The persisted run.
	 *
	 * @return ObjectAuditEvent
	 */
	private function runAuditEvent(RecordedEvalRun $recorded): ObjectAuditEvent {
		return new ObjectAuditEvent(
			object: $recorded->evalRun,
			action: self::RUN_ACTION,
			context: $this->runAuditContext(recorded: $recorded)
		);

	}//end runAuditEvent()

	/**
	 * The run audit entry's context.
	 *
	 * @param RecordedEvalRun $recorded The persisted run.
	 *
	 * @return array<string, mixed>
	 */
	private function runAuditContext(RecordedEvalRun $recorded): array {
		$summary = sprintf('Eval run %s — pass rate %.2f', $recorded->status, $recorded->passRate);

		return [
			'status' => $recorded->status,
			// Skill-learnings: the run's own identity INSIDE the context — the
			// capture pass's trace loader matches entries on `context.runId`
			// (an EvalRun's audit anchor object IS the run, so uuid = runId).
			'runId' => (string)$recorded->evalRun->getUuid(),
		] + $this->runTiming(startedAt: $recorded->startedAt) + [
			// Run-analytics/cost-guardrails: the SAME shape ScheduleService::writeRunAudit()
			// records, so BudgetService's existing `currentUsageTokens()` reader needs no
			// eval-specific branch.
			'usage' => $recorded->usage,
			// Skill-evals: which skill uuids the run(s) actually exposed — the same
			// key ScheduleService::writeRunAudit() records for scheduled runs.
			'skillsUsed' => $recorded->skillsUsed,
			// Skill-self-improvement: pin each exposed skill's version as of run
			// start (AuditTrail entry UUID) alongside the existing agent-version
			// pin. Never fatal — an unresolvable skill is simply absent.
			'skillVersions' => $this->skillVersionService->pinsFor(skillUuids: $recorded->skillsUsed),
			// REDACTION-BEFORE-PERSIST: mask secrets/PII before the append-only write
			// (ADR-004). The raw, unredacted per-case output only ever lives on the
			// EvalRun object itself (tenant/RBAC-scoped like any other Hermiq object).
			'summary' => $this->redactionService->redact($summary),
		];

	}//end runAuditContext()

	/**
	 * The run's start, end (now) and duration, as the audit context records them.
	 *
	 * @param DateTimeImmutable $startedAt When the run began.
	 *
	 * @return array{startedAt:string,endedAt:string,durationMs:int}
	 */
	private function runTiming(DateTimeImmutable $startedAt): array {
		$endedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));

		return [
			'startedAt' => $startedAt->format('c'),
			'endedAt' => $endedAt->format('c'),
			'durationMs' => (((int)$endedAt->format('U') - (int)$startedAt->format('U')) * 1000),
		];

	}//end runTiming()

	/**
	 * The persisted run with the run context's start time, usage, exposed skills
	 * and agent; the caller sets the outcome's status, pass rate and results.
	 *
	 * @param ObjectEntity         $evalRun The persisted EvalRun.
	 * @param array<string, mixed> $run     The run context.
	 *
	 * @return RecordedEvalRun
	 */
	private function recordedRun(ObjectEntity $evalRun, array $run): RecordedEvalRun {
		$recorded = new RecordedEvalRun(evalRun: $evalRun, startedAt: $run['startedAt']);
		$recorded->usage = $run['usage'];
		$recorded->skillsUsed = $run['skillsUsed'];
		$recorded->agentId = $run['agentId'];
		$recorded->organisation = $run['organisation'];

		return $recorded;

	}//end recordedRun()

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
	 * @param RecordedEvalRun $recorded The persisted run: its final status, (with-half)
	 *                                  case results, exposed skills, agent and
	 *                                  organisation (budget scope).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/skill-learnings/spec.md#requirement-promotion-is-a-mechanical-two-stage-background-pass
	 * @spec openspec/specs/skill-learnings/spec.md#requirement-capture-is-failure-isolated-from-the-run
	 */
	private function enqueueEvalFailCaptures(RecordedEvalRun $recorded): void {
		if ($recorded->status !== 'completed' || $recorded->skillsUsed === []) {
			return;
		}

		foreach ($recorded->results as $result) {
			// Entries are built in-process by executeCase(), so the shape is
			// guaranteed — only the pass/fail verdict needs checking.
			if (($result['passed'] ?? null) === false) {
				$this->enqueueEvalFailCapture(recorded: $recorded, caseIndex: (int)($result['caseIndex'] ?? 0));
			}
		}

	}//end enqueueEvalFailCaptures()

	/**
	 * Enqueue the learnings capture job for one failing case; a failure is logged
	 * and swallowed.
	 *
	 * @param RecordedEvalRun $recorded  The persisted run.
	 * @param int             $caseIndex The failing case's index.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/skill-learnings/spec.md#requirement-capture-is-failure-isolated-from-the-run
	 */
	private function enqueueEvalFailCapture(RecordedEvalRun $recorded, int $caseIndex): void {
		try {
			$this->jobList->add(SkillLearningsCaptureJob::class, $this->evalFailCaptureArgument(recorded: $recorded, caseIndex: $caseIndex));
		} catch (Throwable $e) {
			$this->logger->warning(
				sprintf(
					'Hermiq could not enqueue eval-fail learnings capture for run %s: %s',
					(string)$recorded->evalRun->getUuid(),
					$e->getMessage()
				),
				['exception' => $e]
			);
		}

	}//end enqueueEvalFailCapture()

	/**
	 * The capture job's argument for one failing case.
	 *
	 * @param RecordedEvalRun $recorded  The persisted run.
	 * @param int             $caseIndex The failing case's index.
	 *
	 * @return array<string, mixed>
	 */
	private function evalFailCaptureArgument(RecordedEvalRun $recorded, int $caseIndex): array {
		$evalRunUuid = (string)$recorded->evalRun->getUuid();

		return [
			'runId' => $evalRunUuid,
			// The trace anchor: the audit entry lives ON the EvalRun
			// object (same lookup shape a Schedule's runs use).
			'scheduleUuid' => $evalRunUuid,
			'agentId' => $recorded->agentId,
			'skillIds' => $recorded->skillsUsed,
			'organisation' => $recorded->organisation,
			'evalFail' => $evalRunUuid . '#' . $caseIndex,
		];

	}//end evalFailCaptureArgument()

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
	 * @param L5EvidenceContext $l5Context The dataset, attribution and time the level-5 evidence belongs to.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agent-evals/spec.md#requirement-a-completed-paired-run-is-the-only-writer-of-l5-evidence
	 */
	private function writeL5Evidence(array $skillResults, L5EvidenceContext $l5Context): void {
		$datasetId = $l5Context->datasetId;
		$attributionMode = $l5Context->attributionMode;
		$endedAt = $l5Context->endedAt;
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
