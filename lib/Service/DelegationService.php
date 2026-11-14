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
 * model-policy check (`OrganisationModelPolicyService`) are the SAME services every
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
 * @spec openspec/specs/sub-agent-delegation/spec.md#requirement-self-delegation-and-delegation-cycles-are-refused
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Hermiq\Kernel\AppIdentity;
use OCA\Hermiq\Kernel\Contracts\DelegationServiceInterface;
use OCA\Hermiq\Service\Delegation\DelegatedTurn;
use OCA\Hermiq\Service\Delegation\DelegationAuditEntry;
use OCA\Hermiq\Service\Delegation\DelegationPart;
use OCA\Hermiq\Service\Delegation\DelegationSlots;
use OCA\Hermiq\Service\Delegation\ParallelDelegation;
use OCA\Hermiq\Service\Delegation\PassedDelegation;
use OCA\Hermiq\Service\Engine\DelegationContext;
use OCA\Hermiq\Service\Engine\DelegationFrame;
use SplObjectStorage;
use OCA\OpenRegister\Db\AuditTrail\ObjectAuditEvent;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Capability\ToolGrantResolver;
use OCA\OpenRegister\Service\Capability\ToolReachResolver;
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
 *   constructor collaborator named after its class (OrganisationModelPolicyService) —
 *   shortening it would obscure which service is injected.
 *
 * @spec openspec/specs/sub-agent-delegation/spec.md#requirement-self-delegation-and-delegation-cycles-are-refused
 */
class DelegationService implements DelegationServiceInterface {

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
	 * @param ObjectService $objectService Resolves caller/target Agent objects.
	 * @param IAppConfig $appConfig Reads `delegation.maxDepth`/`delegation.maxFanOut`.
	 * @param DelegationContext $delegationContext Trusted, request-scoped call-stack — the
	 *                                             ONLY source of depth/ancestry/fan-out/anchor.
	 * @param EffectiveModelPolicy $tenantModelPolicyService GATE 4: the target agent's provider/model
	 *                                                       against the organisation's effective
	 *                                                       ModelPolicy.
	 * @param ScheduleService $scheduleService GATE 5 (kill-switch, reused) and the actual
	 *                                         governed dispatch (`runAgentAsOwner()`,
	 *                                         `getLastRunId()`).
	 * @param BudgetService $budgetService GATE 6: the organisation's/target agent's
	 *                                     budget hard cap + soft-threshold warning.
	 * @param AuditTrailMapper $auditTrailMapper OR audit write-path for the delegated sub-run's
	 *                                           own `AuditTrail` entry.
	 * @param RedactionService $redactionService Masks secrets/PII before the audit write.
	 * @param IUserSession $userSession The CURRENTLY impersonated user —
	 *                                  since `delegate()` only ever runs
	 *                                  mid-turn, inside the parent's own
	 *                                  impersonation, this IS the parent's
	 *                                  already-resolved acting uid.
	 * @param LoggerInterface $logger PSR-3 logger (fail-open diagnostics, non-fatal
	 *                                warnings).
	 * @param ToolGrantResolver $grantResolver Parses the TARGET agent's grant list so the
	 *                                         delegation's effective reach can be composed
	 *                                         from it (agent-capability-reach). Pure and
	 *                                         stateless, hence the default instance —
	 *                                         every existing caller is unchanged.
	 * @param ParallelDelegation|null $parallel Runs the parts of one delegateAgents call on background
	 *                                          workers at the same time (delegation-runs-in-parallel);
	 *                                          without it the parts run one after another.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly IAppConfig $appConfig,
		private readonly DelegationContext $delegationContext,
		private readonly EffectiveModelPolicy $tenantModelPolicyService,
		private readonly ScheduleService $scheduleService,
		private readonly BudgetService $budgetService,
		private readonly AuditTrailMapper $auditTrailMapper,
		private readonly RedactionService $redactionService,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
		private readonly ToolGrantResolver $grantResolver = new ToolGrantResolver(),
		private readonly ?ParallelDelegation $parallel = null,
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
	 * @param string $task The bounded task/prompt to hand to the target (LLM-supplied).
	 *
	 * @return array<string, mixed> `{targetAgentId, result}` on success, or
	 *                              `{error: {code, message}}` on refusal/failure.
	 *
	 * @spec openspec/specs/sub-agent-delegation/spec.md#requirement-self-delegation-and-delegation-cycles-are-refused
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) A fixed, ordered gate sequence
	 *   (self/cycle → allowlist → depth/fan-out → same-organisation → model-policy →
	 *   kill-switch → budget → approval) — each gate's OWN logic already lives in its
	 *   own small, independently-tested private method; this method is the orchestrator
	 *   deciding whether to proceed to the next one, mirroring
	 *   `ScheduleService::dispatch()`'s identical shape for the same reason.
	 * @SuppressWarnings(PHPMD.NPathComplexity)      Same reasoning as above.
	 */
	public function delegate(string $callerAgentId, string $targetAgentId, string $task): array {
		$passed = $this->gate(callerAgentId: $callerAgentId, targetAgentId: $targetAgentId, task: $task, pending: 0);
		if (is_array($passed) === true) {
			return $passed;
		}

		return $this->runDelegatedTurn(targetAgent: $passed->targetAgent, turn: $passed->turn);

	}//end delegate()

	/**
	 * Delegate several parts of one task, each to its own target agent
	 * (REQ-DLGPAR-001).
	 *
	 * Every part passes the same gates as `delegate()`, in call order, before
	 * any part runs. A part that passes counts toward the per-turn fan-out
	 * from that moment, so a later part of the same call is measured against
	 * the parts before it; a refused part counts for nothing and the others
	 * still run. The result holds one slot per part, in call order: the
	 * part's `{targetAgentId, result, effectiveReach}`, or its error envelope
	 * with the `targetAgentId` it named.
	 *
	 * Two or more passing parts are handed to background workers and run at
	 * the same time (`ParallelDelegation`); a part no worker picks up in time
	 * runs here. A single passing part, a `delegation.maxParallel` of 1, or no
	 * acting user runs the parts here, one after another.
	 *
	 * @param string                           $callerAgentId The delegating agent's own UUID (server-injected).
	 * @param array<int, array<string, mixed>> $parts         One `{targetAgentId, task}` per part (LLM-supplied).
	 *
	 * @return array<string, mixed> `{results: [...]}`, one slot per part in call order.
	 *
	 * @spec openspec/changes/delegation-runs-in-parallel/specs/sub-agent-delegation/spec.md#requirement-several-parts-of-one-task-run-on-sub-agents-at-the-same-time-req-dlgpar-001
	 */
	public function delegateMany(string $callerAgentId, array $parts): array {
		$slots = new DelegationSlots();
		foreach (array_values($parts) as $part) {
			$slots->add(slot: $this->gatePart(callerAgentId: $callerAgentId, part: new DelegationPart(part: $part), pending: $slots->passed()));
		}

		$owner = (string)($this->userSession->getUser()?->getUID() ?? '');
		if ($this->parallel === null || $slots->passed() < 2 || $this->parallel->maxParallel() < 2 || $owner === '') {
			return [
				'results' => $slots->results(
					run: fn (PassedDelegation $passed): array => $this->runDelegatedTurn(targetAgent: $passed->targetAgent, turn: $passed->turn)
				),
			];
		}

		return ['results' => $this->runInParallel(slots: $slots, owner: $owner)];

	}//end delegateMany()

	/**
	 * Hand the passing parts to background workers and collect their outcomes in call order.
	 *
	 * @param DelegationSlots $slots The gated parts.
	 * @param string          $owner The parent's acting user.
	 *
	 * @return array<int, array<string, mixed>> One result per part, in call order.
	 *
	 * @spec openspec/changes/delegation-runs-in-parallel/specs/sub-agent-delegation/spec.md#requirement-several-parts-of-one-task-run-on-sub-agents-at-the-same-time-req-dlgpar-001
	 */
	private function runInParallel(DelegationSlots $slots, string $owner): array {
		$frame   = $this->delegationContext->current();
		$order   = new SplObjectStorage();
		$records = [];
		$slots->results(
			run: function (PassedDelegation $passed) use ($order, &$records, $owner, $frame): array {
				$order[$passed] = count($records);
				$records[]      = $this->recordFor(passed: $passed, owner: $owner, frame: $frame);
				$this->delegationContext->incrementFanOut();
				return [];
			}
		);

		$outcomes = $this->parallel->run(
			records: $records,
			runInline: fn (array $claimed): array => $this->runStored(record: $claimed, anchor: $frame?->anchor)
		);

		return $slots->results(
			run: function (PassedDelegation $passed) use ($order, $outcomes, $frame): array {
				$outcome = $outcomes[$order[$passed]];
				if (($outcome['error']['code'] ?? null) === ParallelDelegation::TIMEOUT_CODE) {
					$this->writeDelegationAudit(
						auditTarget: ($frame->anchor ?? $passed->targetAgent),
						entry: new DelegationAuditEntry(
							targetAgentId: (string)$passed->targetAgent->getUuid(),
							status: 'timeout',
							summary: 'The delegated part did not finish in time.',
							runId: '',
							parentRunId: $frame?->runId,
							startedAt: new DateTimeImmutable('now', new DateTimeZone('UTC'))
						)
					);
				}

				return $outcome;
			}
		);
	}//end runInParallel()

	/**
	 * The DelegatedRun record of one passing part.
	 *
	 * @param PassedDelegation     $passed The part.
	 * @param string               $owner  The parent's acting user.
	 * @param DelegationFrame|null $frame  The parent run's frame.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/delegation-runs-in-parallel/specs/sub-agent-delegation/spec.md#requirement-several-parts-of-one-task-run-on-sub-agents-at-the-same-time-req-dlgpar-001
	 */
	private function recordFor(PassedDelegation $passed, string $owner, ?DelegationFrame $frame): array {
		return [
			'parentRunId' => (string)($frame->runId ?? ''),
			'parentAgentId' => (string)($frame->agentId ?? ''),
			'ancestorAgentIds' => ($frame->ancestorAgentIds ?? []),
			'depth' => ($frame->depth ?? 1),
			'targetAgentId' => (string)$passed->targetAgent->getUuid(),
			'task' => $passed->turn->task,
			'owner' => $owner,
			'organisation' => $passed->turn->organisation,
			'anchorId' => $frame?->anchor?->getUuid(),
			'effectiveReach' => $passed->turn->effectiveReach,
		];
	}//end recordFor()

	/**
	 * Claim and run one stored part of a parallel delegation, as a background
	 * worker does (REQ-DLGPAR-002: a part runs once, whoever claims it).
	 *
	 * The worker takes up the parent run's frame first, so the part runs at the
	 * parent's own depth and ancestry and its own delegations are bounded as
	 * the parent's would be.
	 *
	 * @param string $delegatedRunId The part's DelegatedRun id.
	 * @param string $claimant       worker or parent.
	 *
	 * @return bool True when this claim won and the part ran.
	 *
	 * @spec openspec/changes/delegation-runs-in-parallel/specs/sub-agent-delegation/spec.md#requirement-a-part-no-worker-picks-up-runs-inline-req-dlgpar-002
	 */
	public function runStoredPart(string $delegatedRunId, string $claimant): bool {
		$claimed = $this->parallel?->store()->claim(id: $delegatedRunId, claimant: $claimant);
		if ($claimed === null) {
			return false;
		}

		$anchor = null;
		if ((string)($claimed['anchorId'] ?? '') !== '') {
			$anchor = $this->findObject(id: (string)$claimed['anchorId']);
		}

		$resumed = (string)($claimed['parentRunId'] ?? '') !== '';
		if ($resumed === true) {
			$this->delegationContext->resume(
				frame: new DelegationFrame(
					runId: (string)$claimed['parentRunId'],
					agentId: (string)($claimed['parentAgentId'] ?? ''),
					organisation: (string)($claimed['organisation'] ?? ''),
					anchor: $anchor,
					parentRunId: null,
					ancestorAgentIds: array_values(array_map('strval', (array)($claimed['ancestorAgentIds'] ?? []))),
					depth: max(1, (int)($claimed['depth'] ?? 1))
				)
			);
		}

		try {
			$this->runStored(record: $claimed, anchor: $anchor);
		} finally {
			if ($resumed === true) {
				$this->delegationContext->pop();
			}
		}

		return true;
	}//end runStoredPart()

	/**
	 * Run a claimed part as the parent's acting user, audit it under the parent
	 * run and record its outcome on the part.
	 *
	 * @param array<string, mixed> $record The claimed DelegatedRun.
	 * @param ObjectEntity|null    $anchor The parent run's anchor.
	 *
	 * @return array<string, mixed> `{result, effectiveReach}`, or a `delegation_failed` envelope.
	 *
	 * @spec openspec/changes/delegation-runs-in-parallel/specs/sub-agent-delegation/spec.md#requirement-several-parts-of-one-task-run-on-sub-agents-at-the-same-time-req-dlgpar-001
	 * @spec openspec/specs/sub-agent-delegation/spec.md#requirement-delegation-is-traceable-as-one-auditable-tree
	 */
	private function runStored(array $record, ?ObjectEntity $anchor): array {
		$targetAgentId = (string)($record['targetAgentId'] ?? '');
		$startedAt     = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		$status        = 'ok';
		try {
			$result = $this->scheduleService->runAgentAsOwner(
				owner: (string)($record['owner'] ?? ''),
				agentId: $targetAgentId,
				prompt: (string)($record['task'] ?? ''),
				organisation: (string)($record['organisation'] ?? ''),
				forceOwner: true,
				anchor: $anchor
			);
		} catch (Throwable $e) {
			$status = 'error';
			$result = 'error: ' . $e->getMessage();
			$this->logger->warning('Hermiq delegated agent run failed: ' . $e->getMessage(), ['exception' => $e]);
		}

		$runId       = $this->scheduleService->getLastRunId();
		$auditTarget = ($anchor ?? $this->findAgent(agentId: $targetAgentId));
		if ($auditTarget !== null) {
			$this->writeDelegationAudit(
				auditTarget: $auditTarget,
				entry: new DelegationAuditEntry(
					targetAgentId: $targetAgentId,
					status: $status,
					summary: $result,
					runId: $runId,
					parentRunId: (string)($record['parentRunId'] ?? ''),
					startedAt: $startedAt
				)
			);
		}

		if ($status === 'error') {
			$this->parallel?->store()->finish(id: (string)$record['id'], fields: ['state' => 'failed', 'runId' => $runId]);
			return $this->refuse(code: 'delegation_failed', message: 'The delegated agent run failed.');
		}

		$this->parallel?->store()->finish(id: (string)$record['id'], fields: ['state' => 'done', 'result' => $result, 'runId' => $runId]);

		return ['result' => $result, 'effectiveReach' => (string)($record['effectiveReach'] ?? '')];
	}//end runStored()

	/**
	 * Gate one part of a `delegateMany()` call: its shape, then every gate.
	 *
	 * @param string         $callerAgentId The delegating agent's own UUID.
	 * @param DelegationPart $part          The part as the model sent it.
	 * @param int            $pending       Parts of this call that already passed.
	 *
	 * @return PassedDelegation|array<string, mixed> The passed part, or its error slot.
	 */
	private function gatePart(string $callerAgentId, DelegationPart $part, int $pending): PassedDelegation|array {
		if ($part->isComplete() === false) {
			return $part->slot(outcome: $this->refuse(code: 'invalid_argument', message: 'Each part needs a targetAgentId and a non-empty task.'));
		}

		$passed = $this->gate(callerAgentId: $callerAgentId, targetAgentId: $part->targetAgentId, task: $part->task, pending: $pending);
		if (is_array($passed) === true) {
			return $part->slot(outcome: $passed);
		}

		return $passed;
	}//end gatePart()

	/**
	 * Every gate a delegation passes, in order, without running it.
	 *
	 * @param string $callerAgentId The delegating agent's own UUID.
	 * @param string $targetAgentId The agent UUID to delegate to.
	 * @param string $task          The bounded task to hand to the target.
	 * @param int    $pending       Delegations of the same call that passed but have not run yet.
	 *
	 * @return PassedDelegation|array<string, mixed> The passed delegation, or the refusal envelope.
	 *
	 * @spec openspec/specs/sub-agent-delegation/spec.md#requirement-self-delegation-and-delegation-cycles-are-refused
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) A fixed, ordered gate sequence
	 *   (self/cycle → allowlist → depth/fan-out → same-organisation → model-policy →
	 *   kill-switch → budget → approval); each gate's own logic lives in its own
	 *   small private method, and this method only decides whether to go on.
	 * @SuppressWarnings(PHPMD.NPathComplexity)      Same reasoning as above.
	 */
	private function gate(string $callerAgentId, string $targetAgentId, string $task, int $pending): PassedDelegation|array {
		$callerFrame = $this->delegationContext->current();
		$callerOrganisation = ($callerFrame->organisation ?? '');

		$refusal = $this->checkSelfAndCycle(callerAgentId: $callerAgentId, targetAgentId: $targetAgentId);
		if ($refusal !== null) {
			return $refusal;
		}

		$callerAgent = $this->findAgent(agentId: $callerAgentId);
		$refusal = $this->checkAllowlist(callerAgent: $callerAgent, targetAgentId: $targetAgentId);
		if ($refusal !== null) {
			return $refusal;
		}

		$refusal = $this->checkBounds(frame: $callerFrame, pending: $pending);
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

		$targetData = $targetAgent->getObject();
		$targetOrganisation = (string)($targetAgent->getOrganisation() ?? '');
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

		if ((bool)($targetData['requiresApproval'] ?? false) === true) {
			return $this->refuse(
				code: 'delegation_requires_approval',
				message: 'Delegation refused: the target agent requires human approval and cannot be reached '
					. 'via delegation. It remains runnable via its own schedule or flow trigger.'
			);
		}

		// Agent-capability-reach: compute the delegation's EFFECTIVE reach last,
		// after every refusal above. Placing it here is deliberate — a
		// computation that ran earlier and returned `self` could not weaken a
		// refusal, but a future edit that turned it into a decision could, and
		// the ordering is the only thing that makes that impossible by
		// construction.
		return new PassedDelegation(
			targetAgent: $targetAgent,
			turn: new DelegatedTurn(
				task: $task,
				organisation: $callerOrganisation,
				callerFrame: $callerFrame,
				effectiveReach: $this->effectiveReachFor(targetData: $targetData)
			)
		);

	}//end gate()

	/**
	 * The effective reach of a delegation: the MAXIMUM of the delegation tool's
	 * own reach and the highest reach among the target agent's grants.
	 *
	 * 🔴 A delegation cannot launder reach. An agent whose own grants are all
	 * `user` does not become harmless by handing the work to an agent that can
	 * send mail — the mail still leaves the building, and the caller still
	 * caused it. Reporting this delegation as an `instance` action because
	 * `hermiq.delegateAgent` is itself `instance` would describe the tool rather
	 * than the act.
	 *
	 * Grants are resolved from their ids alone, with no catalogue descriptor, so
	 * `ToolReachResolver` falls to its inference and fail-closed rules: a
	 * 3-segment derived id infers from its verb, and a 2-segment curated id —
	 * `hermiq.sendMail` — resolves `external`. Failing closed is the right
	 * direction here, because the alternative is under-reporting the reach of a
	 * target whose grants we could not read precisely.
	 *
	 * An EMPTY grant list means "every discovered tool, default-denied", which
	 * after the union rule contains nothing above `user` — so it yields the
	 * delegation tool's own `instance`, not `external`.
	 *
	 * @param array<string, mixed> $targetData The target agent's stored object.
	 *
	 * @return string One of the `ToolReachResolver::ORDER` constants.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) `ToolReachResolver` is a pure classification
	 *   function, called statically wherever reach is decided so the answers cannot drift.
	 *
	 * @spec openspec/specs/agent-capability-reach/spec.md#requirement-a-delegation-cannot-launder-reach
	 */
	private function effectiveReachFor(array $targetData): string {
		// The delegation tool's own reach is the floor.
		$reach = ToolReachResolver::REACH_INSTANCE;

		$grants = ($targetData['tools'] ?? []);
		if (is_array($grants) === false) {
			return $reach;
		}

		foreach ($this->grantResolver->baseToolIds(grants: $grants) as $toolId) {
			$reach = ToolReachResolver::max(
				$reach,
				ToolReachResolver::resolve(toolId: $toolId)
			);
		}

		return $reach;
	}//end effectiveReachFor()

	/**
	 * GATE 0 — self-delegation and delegation cycles, checked BEFORE the
	 * allowlist (spec: refused regardless of what any allowlist permits).
	 *
	 * @param string $callerAgentId The delegating agent's UUID.
	 * @param string $targetAgentId The requested target's UUID.
	 *
	 * @return array<string, mixed>|null The refusal envelope, or null when neither applies.
	 *
	 * @spec openspec/specs/sub-agent-delegation/spec.md#requirement-self-delegation-and-delegation-cycles-are-refused
	 */
	private function checkSelfAndCycle(string $callerAgentId, string $targetAgentId): ?array {
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
	 * @param ObjectEntity|null $callerAgent The resolved caller Agent, or null.
	 * @param string $targetAgentId The requested target's UUID.
	 *
	 * @return array<string, mixed>|null The refusal envelope, or null when allowed.
	 *
	 * @spec openspec/specs/sub-agent-delegation/spec.md#requirement-delegation-is-refused-by-default-until-explicitly-allowlisted
	 */
	private function checkAllowlist(?ObjectEntity $callerAgent, string $targetAgentId): ?array {
		$allowlist = [];
		if ($callerAgent !== null) {
			$allowlist = (array)($callerAgent->getObject()['delegationAllowlist'] ?? []);
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
	 * @param DelegationFrame|null $frame   The calling agent's own current frame.
	 * @param int                  $pending Delegations of the same call that passed but have not run yet;
	 *                                      each already counts toward the fan-out.
	 *
	 * @return array<string, mixed>|null The refusal envelope, or null when within bounds.
	 *
	 * @spec openspec/specs/sub-agent-delegation/spec.md#requirement-delegation-depth-and-fan-out-are-bounded
	 */
	private function checkBounds(?DelegationFrame $frame, int $pending=0): ?array {
		$depth = ($frame->depth ?? 0);
		if (($depth + 1) > $this->maxDepth()) {
			return $this->refuse(
				code: 'delegation_depth_exceeded',
				message: sprintf('Delegation refused: maximum delegation depth (%d) reached.', $this->maxDepth())
			);
		}

		$fanOut = (($frame->fanOutCount ?? 0) + $pending);
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
	 * @param string $organisation The caller's (and hence the target's,
	 *                             post cross-organisation check) organisation.
	 * @param array<string, mixed> $targetData The target Agent's object data.
	 *
	 * @return array<string, mixed>|null The refusal envelope, or null when allowed/unchecked.
	 *
	 * @spec openspec/specs/sub-agent-delegation/spec.md#requirement-delegation-is-refused-when-gated-by-kill-switch-budget-or-a-target-requiring-approval
	 */
	private function checkModelPolicy(string $organisation, array $targetData): ?array {
		$provider = trim((string)($targetData['provider'] ?? ''));
		$model = trim((string)($targetData['model'] ?? ''));

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
	 * @param string $organisation The caller's organisation.
	 * @param string $targetAgentId The target agent's UUID.
	 *
	 * @return array<string, mixed>|null The refusal envelope, or null when not blocked.
	 *
	 * @spec openspec/specs/sub-agent-delegation/spec.md#requirement-delegation-is-refused-when-gated-by-kill-switch-budget-or-a-target-requiring-approval
	 */
	private function checkBudget(string $organisation, string $targetAgentId): ?array {
		try {
			$this->budgetService->checkAndDeliverWarnings(organisation: $organisation, agentId: $targetAgentId);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Hermiq delegation budget soft-threshold check failed: ' . $e->getMessage(),
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
	 * @param ObjectEntity $targetAgent The resolved target Agent object.
	 * @param DelegatedTurn $turn The task one agent delegates to another, and the frame it runs in.
	 *
	 * @return array<string, mixed> `{targetAgentId, result, effectiveReach}` on success, or a
	 *                              `delegation_failed` error envelope.
	 *
	 * @spec openspec/specs/sub-agent-delegation/spec.md#requirement-delegated-runs-inherit-the-parents-acting-user-attribution
	 * @spec openspec/specs/sub-agent-delegation/spec.md#requirement-delegation-is-traceable-as-one-auditable-tree
	 */
	private function runDelegatedTurn(ObjectEntity $targetAgent, DelegatedTurn $turn): array {
		$task = $turn->task;
		$organisation = $turn->organisation;
		$callerFrame = $turn->callerFrame;
		$effectiveReach = $turn->effectiveReach;
		$targetAgentId = (string)$targetAgent->getUuid();

		$owner = (string)($this->userSession->getUser()?->getUID() ?? '');
		if ($owner === '') {
			return $this->refuse(
				code: 'delegation_failed',
				message: 'Delegation refused: no acting user identity is available for this run.'
			);
		}

		// Only a gate-PASSED, actually-attempted call counts toward fan-out —
		// a refused call above never reaches this line (spec).
		$this->delegationContext->incrementFanOut();

		$anchor = $callerFrame?->anchor;
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
			$result = 'error: ' . $e->getMessage();
			$this->logger->warning('Hermiq delegated agent run failed: ' . $e->getMessage(), ['exception' => $e]);
		}

		$this->writeDelegationAudit(
			auditTarget: ($anchor ?? $targetAgent),
			entry: new DelegationAuditEntry(
				targetAgentId: $targetAgentId,
				status: $status,
				summary: $result,
				runId: $this->scheduleService->getLastRunId(),
				parentRunId: $callerFrame?->runId,
				startedAt: $startedAt
			)
		);

		if ($status === 'error') {
			return $this->refuse(code: 'delegation_failed', message: 'The delegated agent run failed.');
		}

		// The effective reach rides on the RESULT, not only in the audit: the
		// calling turn is what decides whether to keep going, and it cannot
		// account for a reach it is never told about.
		return [
			'targetAgentId' => $targetAgentId,
			'result' => $result,
			'effectiveReach' => $effectiveReach,
		];

	}//end runDelegatedTurn()

	/**
	 * Write the delegated sub-run's own `AuditTrail` entry — never fatal by
	 * contract.
	 *
	 * @param ObjectEntity $auditTarget The object this entry is written against
	 *                                  (the shared anchor, or the target agent
	 *                                  itself when no anchor is available).
	 * @param DelegationAuditEntry $entry What one delegation audit entry records.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/sub-agent-delegation/spec.md#requirement-delegation-is-traceable-as-one-auditable-tree
	 */
	private function writeDelegationAudit(ObjectEntity $auditTarget, DelegationAuditEntry $entry): void {
		$targetAgentId = $entry->targetAgentId;
		$status = $entry->status;
		$summary = $entry->summary;
		$runId = $entry->runId;
		$parentRunId = $entry->parentRunId;
		$startedAt = $entry->startedAt;
		try {
			$endedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));

			$context = [
				'status' => $status,
				'agentId' => $targetAgentId,
				'delegated' => true,
				'runId' => $runId,
				'parentRunId' => $parentRunId,
				'startedAt' => $startedAt->format('c'),
				'endedAt' => $endedAt->format('c'),
				// REDACTION-BEFORE-PERSIST: mask secrets/PII before the append-only write.
				'summary' => $this->redactionService->redact($summary),
			];

			$this->auditTrailMapper->recordObjectEvent(
				event: new ObjectAuditEvent(
					object: $auditTarget,
					action: self::RUN_ACTION,
					context: $context
				)
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
	private function findAgent(string $agentId): ?ObjectEntity {
		return $this->findObject(id: $agentId, schema: self::AGENT_SCHEMA);
	}//end findAgent()

	/**
	 * Resolve an object system-wide, or null: an agent of this register, or a
	 * run's anchor wherever it lives.
	 *
	 * @param string      $id     The object's UUID.
	 * @param string|null $schema The schema in this register, or null for any object.
	 *
	 * @return ObjectEntity|null
	 */
	private function findObject(string $id, ?string $schema = null): ?ObjectEntity {
		$agentId = $id;
		if ($agentId === '') {
			return null;
		}

		$register = null;
		if ($schema !== null) {
			$register = self::REGISTER_SLUG;
		}

		try {
			return $this->objectService->find(
				id: $agentId,
				register: $register,
				schema: $schema,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				sprintf('Hermiq delegation could not resolve object %s: %s', $agentId, $e->getMessage()),
				['exception' => $e]
			);
			return null;
		}

	}//end findObject()

	/**
	 * The instance-wide maximum delegation chain depth (`delegation.maxDepth`,
	 * default 2).
	 *
	 * @return int
	 */
	private function maxDepth(): int {
		return max(1, (int)$this->appConfig->getValueString(AppIdentity::APP_ID, self::MAX_DEPTH_KEY, self::DEFAULT_MAX_DEPTH));
	}//end maxDepth()

	/**
	 * The instance-wide maximum per-turn fan-out (`delegation.maxFanOut`,
	 * default 3).
	 *
	 * @return int
	 */
	private function maxFanOut(): int {
		return max(1, (int)$this->appConfig->getValueString(AppIdentity::APP_ID, self::MAX_FAN_OUT_KEY, self::DEFAULT_MAX_FAN_OUT));
	}//end maxFanOut()

	/**
	 * Build a structured refusal envelope (never throws — mirrors
	 * `HermiqToolProvider`'s own contract).
	 *
	 * @param string $code The machine error code.
	 * @param string $message The human-readable message.
	 *
	 * @return array<string, mixed> The error envelope.
	 */
	private function refuse(string $code, string $message): array {
		return ['error' => ['code' => $code, 'message' => $message]];
	}//end refuse()
}//end class
