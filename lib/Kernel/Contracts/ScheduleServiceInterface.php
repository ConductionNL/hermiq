<?php

/**
 * Contract of ScheduleService
 *
 * The public surface of ScheduleService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Hermiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Kernel\Contracts;

use OCA\Hermiq\Service\EngineRequiredException;
use OCA\Hermiq\Service\GuardrailBlockedException;
use OCA\OpenRegister\Db\ObjectEntity;
use RuntimeException;
use Throwable;

/**
 * Contract of ScheduleService.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ScheduleServiceInterface {

	/**
	 * Return this schedule's clock to the app-local dispatcher.
	 *
	 * Clears `engineFlowId` through the sanitised persist, after which
	 * findDueSchedules() selects the schedule as due by nextRun again. The
	 * caller (bridge retire, rollback command) deletes the mirror flow AFTER
	 * this write: a crash between the two leaves a mirror whose delegation
	 * check refuses to fire, never a double clock.
	 *
	 * @param ObjectEntity $schedule The schedule being taken back.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-in-flight-schedules-migrate-and-roll-back
	 */
	public function clearEngineDelegation(ObjectEntity $schedule): void;

	/**
	 * Preview a schedule's agent run as a dry-run (run-replay-and-dry-run):
	 * the SAME governance gates as a real run apply (`evaluateGates()`), but on
	 * pass the schedule's `nextRun`/`repeat`/`enabled` state is NEVER mutated
	 * and no `Approval` is created — a preview must be repeatable without
	 * affecting the schedule's real cadence.
	 *
	 * Returns `{status: 'blocked', gate: ...}` when a governance gate refused the
	 * preview, otherwise `{status: 'ok'|'error', error, steps, summary}`.
	 *
	 * @param ObjectEntity $schedule The schedule to preview.
	 *
	 * @return array{status:string,gate?:string,error?:?string,steps?:array<int,array<string,mixed>>,summary?:string}
	 *
	 * @throws EngineRequiredException When `hermiq.engine.enabled` is off.
	 *
	 * @spec openspec/specs/run-replay-and-dry-run/spec.md#requirement-dry-run-neutralises-side-effecting-tool-calls
	 * @spec openspec/specs/run-replay-and-dry-run/spec.md#requirement-dry-run-and-replay-require-the-in-app-agent-engine
	 */
	public function dryRunNow(ObjectEntity $schedule): array;

	/**
	 * The conversation the LAST `runAgentAsOwner()` produced, if any.
	 *
	 * Read straight after a turn by callers that need to point AT the session
	 * rather than copy it — the flow run log links a step to the conversation
	 * its agent created, so an operator can read the reasoning instead of
	 * having it duplicated into the log.
	 *
	 * Exposed as a getter rather than by widening `runAgentAsOwner()`'s return
	 * or adding an out-parameter: that method has 43 call sites across nine
	 * files and runs inside user impersonation, and none of the other 42 want
	 * this. The field already existed for Talk-room binding; this only reads it.
	 *
	 * Empty for a dry run and for the flag-off legacy path, both of which
	 * deliberately produce no bindable conversation. Empty means "no session to
	 * link", never "the link is missing" — a caller must treat it as absence,
	 * not as an error.
	 *
	 * @return string The conversation uuid, or '' when there is none.
	 *
	 * @spec openspec/changes/archive/2026-09-02-consume-or-flow-engine/specs/or-flow-consumer/spec.md
	 */
	public function lastRunConversationUuid(): string;

	/**
	 * Offboarding: pause every Schedule owned by, or whose Agent's `actingUser`
	 * resolves to, the given (deleted/disabled) Nextcloud user, and flag each
	 * affected Agent for reassignment (agent-lifecycle-governance).
	 *
	 * Runs system-wide (`_rbac:false`, `_multitenancy:false`) — mirrors
	 * findDueSchedules()/TenantKillSwitch::engagedOrganisations() — because the caller
	 * (UserLifecycleListener) fires outside any user session and must see every
	 * tenant's schedules to find the ones owned by the affected user. Reuses the
	 * SAME `enabled=false` + persist() mechanic recordGateSkip() already uses, but
	 * writes its own minimal audit entry rather than calling the private
	 * writeRunAudit() helper: that helper reads $this->lastRunUsage/lastRunSteps/
	 * lastRunAsUser, which are per-dispatch instance state that would otherwise
	 * leak a stale, unrelated schedule's last-run data into this pause's audit
	 * entry (this method never goes through dispatch(), so those fields are not
	 * reset here).
	 *
	 * A schedule already disabled is left untouched (no redundant persist/audit)
	 * but its Agent is still flagged when it matches — an already-paused schedule
	 * still means its owning human is gone. A currently in-progress run is
	 * unaffected: only `enabled`/`lastStatus` change here, exactly like every
	 * other gate skip — the running turn already in flight completes normally.
	 *
	 * @param string $uid The Nextcloud user id that was deleted or disabled.
	 *
	 * @return int The number of schedules actually paused (flipped from enabled to disabled).
	 *
	 * @spec openspec/specs/agent-lifecycle-governance/spec.md#requirement-automatic-offboarding-pause-on-nextcloud-user-deletion-or-disable
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) One linear sweep whose per-schedule
	 *   guards (entity check, owner/actingUser match, already-disabled skip) each add
	 *   a branch; no nested decision logic.
	 * @SuppressWarnings(PHPMD.NPathComplexity)      Same reasoning: independent
	 *   early-continue guards multiply paths without nesting.
	 */
	public function pauseForUser(string $uid): int;

	/**
	 * Replay a past run's exact recorded prompt as a fresh dry-run and diff
	 * the outcome against the original run's recorded trace
	 * (run-replay-and-dry-run). Always executes as a dry-run — a replay never
	 * actually invokes a side-effecting tool, identical to `dryRunNow()`.
	 *
	 * @param ObjectEntity $schedule The schedule owning the run to replay.
	 * @param string $runId The original run's AuditTrail UUID.
	 * @param array<string,mixed> $originalTrace The original run's already-fetched trace
	 *                                           (`RunHistoryService::getRunTrace()`'s
	 *                                           return shape) — the caller (the
	 *                                           controller) already reads this to apply
	 *                                           its own 404/anti-probing checks, so it is
	 *                                           passed in rather than re-read here.
	 *
	 * @return array{status:string,gate?:string,scheduleId?:string,replayOf?:string,original?:array<string,mixed>,replay?:array<string,mixed>,diff?:array<string,mixed>}
	 *                                                                                                                                                                   `{status: 'blocked', gate: ...}` when a governance gate refused the
	 *                                                                                                                                                                   replay, otherwise the full original/replay/diff payload.
	 *
	 * @throws EngineRequiredException When `hermiq.engine.enabled` is off.
	 * @throws RuntimeException When the original run's prompt was never persisted
	 *                          (a run recorded before this change shipped).
	 *
	 * @spec openspec/specs/run-replay-and-dry-run/spec.md#requirement-replay-re-executes-a-run-s-exact-recorded-prompt-as-a-dry-run-and-diffs-the-outcome
	 */
	public function replayRun(ObjectEntity $schedule, string $runId, array $originalTrace): array;

	/**
	 * Dispatch every due schedule for this tick.
	 *
	 * Selects enabled Schedule objects whose `nextRun` is at or before now, then
	 * processes each in isolation: a failure on one schedule is recorded and does
	 * not abort the rest of the tick.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-09-06-agent-schedule-dispatcher/tasks.md#3-scheduleservice-dispatch-logic
	 * @spec openspec/changes/archive/2026-09-06-agent-schedule-dispatcher/tasks.md#3-scheduleservice-dispatch-logic
	 */
	public function run(): void;

	/**
	 * Invoke the OpenRegister agent as the given owner and return its output.
	 *
	 * Impersonates the owner (IUserSession/IUserManager, mirroring OpenConnector's
	 * JobService), then dispatches through the SAME feature-flagged engine branch a
	 * scheduled tick uses (OpenRegister ChatService by default, or the in-app Engine
	 * facade when `hermiq`.`engine.enabled` is on — see isEngineEnabled()/
	 * runAgentViaEngine()). The prior session user is always restored so identity
	 * never bleeds across runs.
	 *
	 * PUBLIC (not just ScheduleService-internal): FlowAgentRunService calls this
	 * directly so a flow-triggered agent run (OpenRegister's AgentRunRequestedEvent,
	 * ADR-041) goes through the identical dispatch path a scheduled run does —
	 * SPECTR-NEXTCLOUD-PLAN.md §5.2 point 2 ("the same ScheduleService/Engine path
	 * scheduled runs use"). `$owner` is the schedule owner for a scheduled run, or
	 * the agent's own `owner` (acting user) for a flow-triggered run.
	 *
	 * @param string $owner The uid to impersonate for this run.
	 * @param string $agentId The bound agent UUID.
	 * @param string $prompt The prompt to run.
	 * @param string $organisation The run's organisation (schedule/agent/flow-subject
	 *                             organisation — '' resolves the org-less instance
	 *                             default), used ONLY to resolve the effective
	 *                             GuardrailPolicy (agent-guardrails): the input
	 *                             filter on the legacy ChatService branch (the one
	 *                             path Engine::processMessage() never sees), and the
	 *                             defense-in-depth output filter applied to BOTH
	 *                             branches' result before it reaches this method's
	 *                             single return point.
	 * @param bool $dryRun Whether this run is a dry-run preview
	 *                     (run-replay-and-dry-run):
	 *                     side-effecting tool calls are
	 *                     neutralised instead of actually
	 *                     invoked. Requires the in-app Engine
	 *                     path (`hermiq.engine.enabled=true`)
	 *                     — throws `EngineRequiredException`
	 *                     otherwise, since dry-run's entire
	 *                     mechanism depends on
	 *                     `FacadeToolInvoker`, which only
	 *                     exists on that path. False (every
	 *                     pre-existing caller) is byte-for-byte
	 *                     unchanged behavior.
	 * @param bool $forceOwner Sub-agent-delegation: when true, skip
	 *                         `resolveActingUser()` entirely and
	 *                         impersonate `$owner` verbatim, even
	 *                         when the target Agent has its own
	 *                         `actingUser` set — the ONLY way a
	 *                         delegated sub-run's attribution
	 *                         cannot be laundered from the parent's
	 *                         already-resolved identity to a
	 *                         different one. `DelegationService` is
	 *                         the ONLY caller that ever passes
	 *                         `true`; every pre-existing caller
	 *                         (scheduled tick, Run-now,
	 *                         `FlowAgentRunService`,
	 *                         `WebhookAgentRunService`) omits it
	 *                         and is byte-for-byte unaffected.
	 * @param ObjectEntity|null $anchor Sub-agent-delegation: the object this run's
	 *                                  (and any of ITS OWN delegated sub-runs')
	 *                                  `AuditTrail` entry rolls up to for
	 *                                  `BudgetService`'s existing usage
	 *                                  aggregation — the schedule for a
	 *                                  scheduled run, the triggering object for a
	 *                                  flow-triggered run, the Agent object for a
	 *                                  webhook-triggered run, or null (every
	 *                                  pre-existing caller before this change)
	 *                                  when delegation is not in play. Threaded
	 *                                  onto the `DelegationContext` frame
	 *                                  `runAgentViaEngine()` pushes, so a NESTED
	 *                                  delegate call inherits the SAME anchor
	 *                                  however deep the tree goes.
	 * @param array|null $skillSetOverride Per-run effective-skill-set override
	 *                                     (skill uuids) for the run-loop
	 *                                     skill-exposure seam (skill-evals): a
	 *                                     paired eval half varies exactly this
	 *                                     set, IN MEMORY ONLY — the agent's
	 *                                     stored `skillInstalls` and the skills'
	 *                                     `installedOn` are never written. Null
	 *                                     (every non-eval caller) exposes the
	 *                                     agent's stored installs.
	 * @param array<string, mixed> $startValues The schedule's start field values
	 *                                          (agents-instruction-variables), filtered
	 *                                          like the prompt and stored on the run's
	 *                                          session; a field without one uses its default.
	 * @param string|null $continueSessionUuid A standing goal's session: the run continues
	 *                                         it instead of opening a new one (agents-standing-goal).
	 *
	 * @return string The agent's response text (already output-filtered).
	 *
	 * @throws RuntimeException When the owner or agent cannot be resolved.
	 * @throws EngineRequiredException When `$dryRun` is true and the in-app engine
	 *                                 feature flag is off (run-replay-and-dry-run).
	 * @throws GuardrailBlockedException When the effective GuardrailPolicy's input
	 *                                   filter refuses the prompt (legacy branch only —
	 *                                   the Engine branch throws this internally,
	 *                                   inside `runAgentViaEngine()`/`Engine::processMessage()`).
	 *
	 * @spec openspec/changes/archive/2026-09-06-agent-schedule-dispatcher/tasks.md#3-scheduleservice-dispatch-logic
	 * @spec openspec/changes/archive/2026-09-06-agent-engine-port/tasks.md#6-feature-flag-the-engine-and-pivot-scheduleservice
	 * @spec openspec/changes/archive/2026-09-06-agent-engine-port/tasks.md#6-feature-flag-the-engine-and-pivot-scheduleservice
	 * @spec openspec/changes/archive/2026-09-06-flow-agent-listener/tasks.md#2-flowagentrunservice-governed-dispatch
	 * @spec openspec/changes/archive/2026-07-13-agent-guardrails/tasks.md#task-4-wire-input-output-filters-into-scheduleservice-runagentasowner
	 * @spec openspec/specs/run-replay-and-dry-run/spec.md#requirement-dry-run-and-replay-require-the-in-app-agent-engine
	 * @spec openspec/specs/sub-agent-delegation/spec.md#requirement-delegated-runs-inherit-the-parent-s-acting-user-attribution
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)  Sub-agent-delegation's `forceOwner`
	 *   branch pushed this over the threshold; the method is still one linear
	 *   impersonate → dispatch (flag-on/flag-off) → restore sequence, not incidental
	 *   branching — splitting further would duplicate the impersonation try/finally.
	 * @SuppressWarnings(PHPMD.NPathComplexity)       Same reasoning as above.
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Same reasoning as above; the
	 *   flag-off legacy branch is removed wholesale by or-chat-proxy-deprecation,
	 *   which brings this back under the threshold — splitting now would be churn.
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)   `$dryRun`/`$forceOwner` are
	 *   genuine two-mode authorisation inputs (preview vs. real; parent-forced vs.
	 *   self-resolved identity), not a responsibility split — mirrors this class's
	 *   existing `$bypassApprovalGate`/`$dryRun` precedent elsewhere.
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Every parameter after $prompt is an
	 *   optional named mode with a default; agents-standing-goal's $continueSessionUuid
	 *   made it ten. Callers name only what they use.
	 */
	public function runAgentAsOwner(
		string $owner,
		string $agentId,
		string $prompt,
		string $organisation = '',
		bool $dryRun = false,
		bool $forceOwner = false,
		?ObjectEntity $anchor = null,
		?array $skillSetOverride = null,
		array $startValues = [],
		?string $continueSessionUuid = null,
	): string;

	/**
	 * Run one schedule immediately, on demand (the "Run now" action).
	 *
	 * Reuses the SAME run-one path as a scheduler tick: it delegates to the private
	 * dispatch() — compute + commit run-state (at-most-once), impersonate the owner,
	 * invoke the OpenRegister agent, deliver, and write the explicit `action='run'`
	 * AuditTrail entry — so a manual run is indistinguishable from a scheduled one and
	 * there is zero duplicated dispatch logic. Because dispatch() shares the tick's
	 * commit-before-run semantics, running a `once` schedule consumes it (disables it)
	 * and a finite `repeat` bumps `completed`, exactly as a tick would.
	 *
	 * An OpenRegister agent-turn error is caught INSIDE dispatch(), recorded on the
	 * schedule as `lastStatus='error'` and audited, so this method returns normally for
	 * that (expected, given the OR agent-execution WIP) case; the caller reads the
	 * refreshed schedule status to surface the error in the UI. Only a catastrophic
	 * failure (e.g. the commit write itself) escapes dispatch(): it is recorded via the
	 * same recordFailure() isolation as the tick and re-thrown so the controller can
	 * return a graceful error response.
	 *
	 * The kill-switch and the human-approval gate apply here exactly as on a tick: a
	 * "Run now" on a gated schedule creates a pending Approval instead of running, and
	 * a run for a halted organisation is skipped. The one exception is the authorised
	 * approval-run: ApprovalService approves the Approval and calls this method with
	 * `bypassApprovalGate=true`, which runs THIS occurrence without re-gating (the
	 * kill-switch still applies).
	 *
	 * @param ObjectEntity $schedule The schedule to run right now.
	 * @param bool $bypassApprovalGate When true, skip the requiresApproval gate
	 *                                 for this authorised occurrence (approval-run).
	 *
	 * @return void
	 *
	 * @throws Throwable When the run fails catastrophically (re-thrown after recording).
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The bypass is a genuine two-mode
	 *   authorisation input (normal run vs. an already-approved occurrence), not a
	 *   responsibility split — both modes share the identical dispatch path.
	 *
	 * @spec openspec/changes/archive/2026-09-06-agent-management-ui/tasks.md#1-backend-thin-run-now-endpoint
	 * @spec openspec/changes/archive/2026-09-06-human-approval-gate-enforcement/tasks.md#4-approve-deny-endpoints-reviewer-admin-guarded
	 */
	public function runNow(ObjectEntity $schedule, bool $bypassApprovalGate = false): void;
}//end interface
