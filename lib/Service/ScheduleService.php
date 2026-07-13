<?php

/**
 * Hermiq ScheduleService.
 *
 * The dispatch engine behind ScheduleTask. On each tick it selects due, enabled
 * Schedule objects from OpenRegister (register `hermiq`, schema `schedule`),
 * advances their run-state BEFORE firing (at-most-once crash safety), impersonates
 * the schedule owner, invokes OpenRegister's agent runtime (ChatService) with the
 * bound agent and prompt, calls a delivery hook, and manages repeat accounting and
 * lifecycle. Hermiq owns no LLM/tool engine — all execution and persistence go
 * through OpenRegister's single write-path (ADR-001, ADR-002, ADR-004).
 *
 * This is the recognised ADR-031 imperative exception: scheduled bulk work that
 * fires agents, not a derived value or declarative lifecycle transition.
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
 * @spec openspec/changes/agent-schedule-dispatcher/tasks.md#3-scheduleservice-dispatch-logic
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use Cron\CronExpression;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use OCA\Hermiq\AppInfo\Application;
use OCA\Hermiq\Service\Engine\Engine;
use OCA\Hermiq\Service\Engine\RunTraceCollector;
use OCA\OpenRegister\Db\AgentMapper;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\Conversation;
use OCA\OpenRegister\Db\ConversationMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ChatService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Selects and dispatches due Hermiq schedules against OpenRegister agents.
 *
 * The register slug is `hermiq` and the schema slug is `schedule` (owned upstream
 * by the agent-schedule-schema change). Every state mutation flows through
 * OpenRegister's ObjectService so tenancy and the ADR-004 audit-trail are inherited.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Dispatcher coordinates OR services.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Sum of many small single-purpose
 *   defensive helpers (date/repeat sanitisers, per-kind next-run, impersonation);
 *   each method stays simple, but a poll-dispatch job legitimately has many of them.
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)     The agent-engine-port feature-flag
 *   pivot (runAgentAsOwner dual path) pushed the dispatcher just over the 1000-line
 *   threshold; the flag-off branch is removed wholesale by or-chat-proxy-deprecation,
 *   which brings the class back under it — splitting now would be churn.
 * @SuppressWarnings(PHPMD.TooManyMethods)           run-reliability's retry/dead-letter/
 *   circuit-breaker logic is a set of small, single-purpose private helpers
 *   (beginOccurrence/beginRetryAttempt/beginFreshOccurrence/applySuccessOutcome/
 *   applyFailureOutcome/scheduleRetry/markDeadLetter/…) kept deliberately tiny to stay
 *   under the per-method complexity threshold; design.md's Trade-offs rejected a
 *   separate RetryPolicyService because it would duplicate the kill-switch/approval
 *   gate call site instead of inheriting it for free from dispatch().
 *
 * @spec openspec/changes/agent-schedule-dispatcher/tasks.md#3-scheduleservice-dispatch-logic
 * @spec openspec/changes/run-reliability/design.md
 */
class ScheduleService
{

    /**
     * OpenRegister register slug that holds Hermiq schedule objects.
     *
     * @var string
     */
    private const REGISTER_SLUG = 'hermiq';

    /**
     * OpenRegister schema slug for schedule objects.
     *
     * @var string
     */
    private const SCHEMA_SLUG = 'schedule';

    /**
     * OpenRegister schema slug for tenant-control (kill-switch) objects.
     *
     * @var string
     */
    private const TENANT_CONTROL_SCHEMA = 'tenantcontrol';

    /**
     * OpenRegister schema slug for agent objects (agent-engine-port; only read
     * when the in-app engine feature flag is on).
     *
     * @var string
     */
    private const AGENT_SCHEMA = 'agent';

    /**
     * OpenRegister schema slug for conversation objects (agent-engine-port;
     * only written when the in-app engine feature flag is on).
     *
     * @var string
     */
    private const CONVERSATION_SCHEMA = 'conversation';

    /**
     * IAppConfig key (app `hermiq`) gating which engine runAgentAsOwner()
     * calls: 'true' routes through the in-app Engine facade against
     * hermiq-register objects; anything else (default 'false') keeps the
     * OpenRegister ChatService path byte-for-byte unchanged.
     *
     * @var string
     */
    private const ENGINE_FLAG_KEY = 'engine.enabled';

    /**
     * Schedule properties declared as `date-time` in the schema.
     *
     * OpenRegister's getObject() returns stored date-times as `Y-m-d H:i:s`
     * (space, no `T`), but saveObject re-validates the WHOLE object against the
     * schema's `date-time` format, which requires ISO-8601 with `T`. Every one of
     * these fields must be re-normalised to `format('c')` before a full-object
     * save, or the write is rejected.
     *
     * @var array<int, string>
     */
    private const DATE_TIME_FIELDS = ['nextRun', 'runAt'];

    /**
     * Per-run LLM token/latency usage captured from OpenRegister's ChatService result, so
     * writeRunAudit can record it for run-analytics (run-cost recording). Reset per run.
     *
     * @var array<string, int|float>
     */
    private array $lastRunUsage = [];

    /**
     * The identity that actually ran the last agent turn (schedule owner, unless
     * `Agent.actingUser` was set and resolved to a valid, active user), so
     * writeRunAudit can record it (agent-capability-profile). Reset per run.
     *
     * @var string
     */
    private string $lastRunAsUser = '';

    /**
     * The last run's ordered step timeline (run-trace-observability), captured
     * from either the in-app Engine's `RunTraceCollector` (context/history/tool/
     * llm — fine-grained tool steps included) or, on the default OpenRegister
     * `ChatService` path, coarse context/history/llm steps derived from its
     * `timings` return value (no tool-type step is ever fabricated for that
     * path). `runDue()` appends a final `delivery` step once `deliver()`
     * resolves. Reset per run, read by `writeRunAudit`.
     *
     * @var array<int, array<string, mixed>>
     */
    private array $lastRunSteps = [];

    /**
     * Constructor.
     *
     * @param ObjectService          $objectService          OpenRegister object read/write (single write-path).
     * @param AgentMapper            $agentMapper            Resolves an agent UUID to an Agent entity.
     * @param ConversationMapper     $conversationMapper     Creates the conversation row the agent runs against.
     * @param ChatService            $chatService            OpenRegister agent runtime (processMessage).
     * @param IUserSession           $userSession            Session used to impersonate the schedule owner.
     * @param IUserManager           $userManager            Resolves the owner UID to an IUser.
     * @param IConfig                $config                 Reads owner/instance timezone.
     * @param LoggerInterface        $logger                 PSR-3 logger (delivery seam + diagnostics).
     * @param DeliveryService        $deliveryService        Real Talk/notification delivery (talk-delivery).
     * @param AuditTrailMapper       $auditTrailMapper       OR audit write-path for the explicit per-run entry (run-audit-log).
     * @param RedactionService       $redactionService       Masks secrets/PII BEFORE the audit write (run-audit-log).
     * @param ApprovalService        $approvalService        Human-approval gate: ensures a pending Approval
     *                                                       for gated runs (human-approval-gate-enforcement).
     * @param IAppConfig             $appConfig              Reads the `hermiq`.`engine.enabled` feature flag (agent-engine-port).
     * @param Engine                 $engine                 In-app agent engine facade, used only when the flag is on (agent-engine-port).
     * @param BudgetService          $budgetService          Budget hard-cap gate + soft-threshold warning (cost-guardrails).
     * @param GuardrailPolicyService $guardrailPolicyService Resolves + applies the effective GuardrailPolicy's
     *                                                       input filter (legacy ChatService branch only —
     *                                                       the Engine branch already applies it internally)
     *                                                       and the defense-in-depth output filter at
     *                                                       runAgentAsOwner()'s single return point
     *                                                       (agent-guardrails).
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) Constructor DI: each parameter is
     *   a distinct injected collaborator, not a logic-bearing argument list.
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly AgentMapper $agentMapper,
        private readonly ConversationMapper $conversationMapper,
        private readonly ChatService $chatService,
        private readonly IUserSession $userSession,
        private readonly IUserManager $userManager,
        private readonly IConfig $config,
        private readonly LoggerInterface $logger,
        private readonly DeliveryService $deliveryService,
        private readonly AuditTrailMapper $auditTrailMapper,
        private readonly RedactionService $redactionService,
        private readonly ApprovalService $approvalService,
        private readonly IAppConfig $appConfig,
        private readonly Engine $engine,
        private readonly BudgetService $budgetService,
        private readonly GuardrailPolicyService $guardrailPolicyService,
    ) {
    }//end __construct()

    /**
     * Dispatch every due schedule for this tick.
     *
     * Selects enabled Schedule objects whose `nextRun` is at or before now, then
     * processes each in isolation: a failure on one schedule is recorded and does
     * not abort the rest of the tick.
     *
     * @return void
     *
     * @spec openspec/changes/agent-schedule-dispatcher/tasks.md#task-3-1
     * @spec openspec/changes/agent-schedule-dispatcher/tasks.md#task-3-7
     */
    public function run(): void
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        try {
            $due = $this->findDueSchedules(now: $now);
        } catch (Throwable $e) {
            $this->logger->error(
                'Hermiq dispatcher could not load due schedules: '.$e->getMessage(),
                ['exception' => $e]
            );
            return;
        }

        // Load engaged kill-switches ONCE per tick: any schedule whose organisation is
        // in this set is halted synchronously before its agent runs (ADR-004 Art. 14).
        $engagedOrganisations = $this->loadEngagedOrganisations();

        foreach ($due as $schedule) {
            // Per-schedule isolation: one bad schedule must not block the tick.
            try {
                $this->dispatch(schedule: $schedule, now: $now, engagedOrganisations: $engagedOrganisations);
            } catch (Throwable $e) {
                $this->recordFailure(schedule: $schedule, error: $e);
            }
        }

    }//end run()

    /**
     * Load the set of organisations whose kill-switch (TenantControl) is engaged.
     *
     * Read once per tick (and per runNow) via a single register/schema-wide query.
     * When engaged, ALL runs for that organisation are halted in dispatch(). A read
     * failure is logged and treated as "no organisation engaged" so a transient
     * TenantControl read error never silently halts every tenant — the dispatcher
     * fails open on the read but the halt itself is a hard, synchronous block.
     *
     * @return array<int, string> The engaged organisation identifiers.
     *
     * @spec openspec/changes/human-approval-gate-enforcement/tasks.md#task-3-1
     */
    private function loadEngagedOrganisations(): array
    {
        try {
            $objects = $this->objectService
                ->setRegister(self::REGISTER_SLUG)
                ->setSchema(self::TENANT_CONTROL_SCHEMA)
                ->findAll(
                    config: ['filters' => ['engaged' => true]],
                    _rbac: false,
                    _multitenancy: false
                );
        } catch (Throwable $e) {
            $this->logger->error(
                'Hermiq could not load engaged kill-switches: '.$e->getMessage(),
                ['exception' => $e]
            );
            return [];
        }

        $organisations = [];
        foreach ($objects as $object) {
            if (($object instanceof ObjectEntity) === false) {
                continue;
            }

            $data = $object->getObject();
            if (($data['engaged'] ?? false) !== true) {
                continue;
            }

            $organisation = (string) ($object->getOrganisation() ?? '');
            if ($organisation !== '') {
                $organisations[] = $organisation;
            }
        }

        return array_values(array_unique($organisations));

    }//end loadEngagedOrganisations()

    /**
     * Whether the given organisation's kill-switch (TenantControl) is currently
     * engaged — GATE 1 for any governed run, not just a Schedule tick.
     *
     * Reused by `FlowAgentRunService` so a flow-triggered agent run (from OR's
     * `AgentRunRequestedEvent`, ADR-041) is halted by the SAME kill-switch data
     * source a scheduled run already respects — one query, one source of truth.
     *
     * @param string $organisation The organisation identifier to check.
     *
     * @return bool True when the organisation's kill-switch is engaged.
     *
     * @spec openspec/changes/flow-agent-listener/tasks.md#task-2-1
     */
    public function isOrganisationEngaged(string $organisation): bool
    {
        if ($organisation === '') {
            return false;
        }

        return in_array($organisation, $this->loadEngagedOrganisations(), true);

    }//end isOrganisationEngaged()

    /**
     * Offboarding: pause every Schedule owned by, or whose Agent's `actingUser`
     * resolves to, the given (deleted/disabled) Nextcloud user, and flag each
     * affected Agent for reassignment (agent-lifecycle-governance).
     *
     * Runs system-wide (`_rbac:false`, `_multitenancy:false`) — mirrors
     * findDueSchedules()/loadEngagedOrganisations() — because the caller
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
     * @spec openspec/changes/agent-lifecycle-governance/specs/agent-lifecycle-governance/spec.md#requirement-automatic-offboarding-pause-on-nextcloud-user-deletion-or-disable
     */
    public function pauseForUser(string $uid): int
    {
        $uid = trim($uid);
        if ($uid === '') {
            return 0;
        }

        try {
            $schedules = $this->objectService
                ->setRegister(self::REGISTER_SLUG)
                ->setSchema(self::SCHEMA_SLUG)
                ->findAll(config: ['limit' => 1000], _rbac: false, _multitenancy: false);
        } catch (Throwable $e) {
            $this->logger->error(
                sprintf('Hermiq offboarding pause could not load schedules for user %s: %s', $uid, $e->getMessage()),
                ['exception' => $e]
            );
            return 0;
        }

        $actingUserCache = [];
        $flaggedAgentIds = [];
        $pausedCount     = 0;

        foreach ($schedules as $schedule) {
            if (($schedule instanceof ObjectEntity) === false) {
                continue;
            }

            $data    = $schedule->getObject();
            $owner   = (string) ($schedule->getOwner() ?? '');
            $agentId = (string) ($data['agentId'] ?? '');

            $matches = ($owner === $uid);
            if ($matches === false && $agentId !== '') {
                $matches = ($this->rawAgentActingUser(agentId: $agentId, cache: $actingUserCache) === $uid);
            }

            if ($matches === false) {
                continue;
            }

            if ($agentId !== '') {
                $flaggedAgentIds[$agentId] = true;
            }

            if (($data['enabled'] ?? false) !== true) {
                // Already disabled — the Agent above is still flagged, but there is
                // nothing further to pause/persist/audit for this schedule.
                continue;
            }

            $data['enabled']    = false;
            $data['lastStatus'] = 'paused_offboarding';

            $this->persist(schedule: $schedule, data: $data);
            $this->writeOffboardingAudit(schedule: $schedule, uid: $uid);
            $pausedCount++;
        }//end foreach

        foreach (array_keys($flaggedAgentIds) as $agentId) {
            $this->flagAgentForReassignment(agentId: $agentId);
        }

        return $pausedCount;

    }//end pauseForUser()

    /**
     * Read an Agent's raw, unresolved `actingUser` field (no fallback, no
     * live-user validity check) — deliberately distinct from resolveActingUser(),
     * which falls back to the schedule owner for an invalid/disabled actingUser
     * and would therefore never match a JUST-disabled/deleted user (the exact
     * case pauseForUser() needs to detect). Cached per agentId within one
     * pauseForUser() call so a fleet of schedules sharing one agent reads it once.
     *
     * @param string                    $agentId The bound agent UUID.
     * @param array<string,string|null> $cache   Per-call cache (by reference), keyed by agentId.
     *
     * @return string|null The raw actingUser value, or null when unset/unresolvable.
     *
     * @spec openspec/changes/agent-lifecycle-governance/specs/agent-lifecycle-governance/spec.md#requirement-automatic-offboarding-pause-on-nextcloud-user-deletion-or-disable
     */
    private function rawAgentActingUser(string $agentId, array &$cache): ?string
    {
        if ($agentId === '') {
            return null;
        }

        if (array_key_exists($agentId, $cache) === true) {
            return $cache[$agentId];
        }

        try {
            $agent = $this->objectService->find(
                id: $agentId,
                register: self::REGISTER_SLUG,
                schema: self::AGENT_SCHEMA,
                _rbac: false,
                _multitenancy: false
            );
        } catch (Throwable $e) {
            $cache[$agentId] = null;
            return null;
        }

        if ($agent === null) {
            $cache[$agentId] = null;
            return null;
        }

        $actingUser = trim((string) ($agent->getObject()['actingUser'] ?? ''));

        $resolved = $actingUser;
        if ($actingUser === '') {
            $resolved = null;
        }

        $cache[$agentId] = $resolved;
        return $resolved;

    }//end rawAgentActingUser()

    /**
     * Flag an Agent for reassignment (agent-lifecycle-governance offboarding).
     *
     * Non-fatal by contract: a flag-write failure is logged, never thrown — the
     * schedules for this user are still paused even if the Agent flag write fails.
     *
     * @param string $agentId The Agent UUID to flag.
     *
     * @return void
     *
     * @spec openspec/changes/agent-lifecycle-governance/specs/agent-lifecycle-governance/spec.md#requirement-automatic-offboarding-pause-on-nextcloud-user-deletion-or-disable
     */
    private function flagAgentForReassignment(string $agentId): void
    {
        try {
            $agent = $this->objectService->find(
                id: $agentId,
                register: self::REGISTER_SLUG,
                schema: self::AGENT_SCHEMA,
                _rbac: false,
                _multitenancy: false
            );
            if ($agent === null) {
                return;
            }

            $data = $agent->getObject();
            $data['reassignmentFlag'] = true;

            $this->objectService->saveObject(
                object: $data,
                register: self::REGISTER_SLUG,
                schema: self::AGENT_SCHEMA,
                uuid: (string) $agent->getUuid(),
                _rbac: false,
                _multitenancy: false
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                sprintf('Hermiq could not flag agent %s for reassignment: %s', $agentId, $e->getMessage()),
                ['exception' => $e]
            );
        }//end try

    }//end flagAgentForReassignment()

    /**
     * Write a minimal, explicit AuditTrail entry for an offboarding pause.
     *
     * Deliberately does NOT call the private writeRunAudit() helper — see
     * pauseForUser()'s docblock for why reusing it here would risk leaking stale
     * per-dispatch instance state into this entry. Non-fatal by contract.
     *
     * @param ObjectEntity $schedule The schedule that was paused.
     * @param string       $uid      The offboarded user id.
     *
     * @return void
     *
     * @spec openspec/changes/agent-lifecycle-governance/specs/agent-lifecycle-governance/spec.md#requirement-automatic-offboarding-pause-on-nextcloud-user-deletion-or-disable
     */
    private function writeOffboardingAudit(ObjectEntity $schedule, string $uid): void
    {
        try {
            $this->auditTrailMapper->createAuditTrailEntry(
                object: $schedule,
                action: 'run',
                context: [
                    'status'         => 'paused_offboarding',
                    'offboardedUser' => $uid,
                ]
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                sprintf(
                    'Hermiq could not write offboarding-pause audit for schedule %s: %s',
                    (string) $schedule->getUuid(),
                    $e->getMessage()
                ),
                ['exception' => $e]
            );
        }

    }//end writeOffboardingAudit()

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
     * @param ObjectEntity $schedule           The schedule to run right now.
     * @param bool         $bypassApprovalGate When true, skip the requiresApproval gate
     *                                         for this authorised occurrence (approval-run).
     *
     * @return void
     *
     * @throws Throwable When the run fails catastrophically (re-thrown after recording).
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The bypass is a genuine two-mode
     *   authorisation input (normal run vs. an already-approved occurrence), not a
     *   responsibility split — both modes share the identical dispatch path.
     *
     * @spec openspec/changes/agent-management-ui/tasks.md#task-1-1
     * @spec openspec/changes/human-approval-gate-enforcement/tasks.md#task-4-2
     */
    public function runNow(ObjectEntity $schedule, bool $bypassApprovalGate=false): void
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        try {
            $this->dispatch(
                schedule: $schedule,
                now: $now,
                engagedOrganisations: $this->loadEngagedOrganisations(),
                bypassApprovalGate: $bypassApprovalGate
            );
        } catch (Throwable $e) {
            // Same isolation as the tick loop, then re-throw so the caller can surface it.
            $this->recordFailure(schedule: $schedule, error: $e);
            throw $e;
        }

    }//end runNow()

    /**
     * Find enabled schedules that are due at or before the given moment.
     *
     * Enabled schedules are fetched register/schema-wide (RBAC/multi-tenancy off —
     * the tick runs system-wide, then impersonates each owner before firing) and a
     * schedule is due when EITHER its own `nextRun <= now` OR (run-reliability) it
     * carries an open retry sequence whose `retryState.nextAttemptAt <= now` — the
     * latter fires a schedule whose regular `nextRun` has not arrived yet, so a
     * pending retry is never silently skipped. Both cuts are applied in PHP for
     * operator-independent correctness.
     *
     * @param DateTimeImmutable $now The current UTC moment.
     *
     * @return array<int, ObjectEntity> The due, enabled schedule objects.
     *
     * @spec openspec/changes/agent-schedule-dispatcher/tasks.md#task-3-1
     * @spec openspec/changes/run-reliability/specs/agent-schedule/spec.md#requirement-per-schedule-opt-in-bounded-retry-with-exponential-backoff-mvp
     */
    private function findDueSchedules(DateTimeImmutable $now): array
    {
        $objects = $this->objectService
            ->setRegister(self::REGISTER_SLUG)
            ->setSchema(self::SCHEMA_SLUG)
            ->findAll(
                config: ['filters' => ['enabled' => true]],
                _rbac: false,
                _multitenancy: false
            );

        $due = [];
        foreach ($objects as $object) {
            if (($object instanceof ObjectEntity) === false) {
                continue;
            }

            $data = $object->getObject();
            if (($data['enabled'] ?? false) !== true) {
                continue;
            }

            $nextRun      = $this->parseDate(value: (string) ($data['nextRun'] ?? ''));
            $dueByNextRun = ($nextRun === null || $nextRun <= $now);

            $dueByRetry = false;
            $retryState = $this->normaliseRetryState(raw: ($data['retryState'] ?? null));
            if ($retryState !== null) {
                $nextAttemptAt = $this->parseDate(value: $retryState['nextAttemptAt']);
                $dueByRetry    = ($nextAttemptAt === null || $nextAttemptAt <= $now);
            }

            if ($dueByNextRun === true || $dueByRetry === true) {
                $due[] = $object;
            }
        }//end foreach

        return $due;

    }//end findDueSchedules()

    /**
     * Process one due schedule, applying the synchronous oversight gates first.
     *
     * Three hard blocks run BEFORE the agent is ever invoked (EU AI Act Art. 14):
     *   1. KILL-SWITCH — if the schedule's organisation has an engaged TenantControl,
     *      the run is skipped (never runs, even for an authorised approval-run).
     *   2. BUDGET HARD CAP (cost-guardrails) — if the schedule's organisation/agent
     *      budget has reached its cap for the current period, the run is skipped
     *      (never runs, even for an authorised approval-run — a budget-exhausted
     *      occurrence must not even accumulate a fresh pending Approval). A
     *      soft-threshold crossing, independent of the block, may fire a one-time
     *      warning notification — runs continue in that case.
     *   3. APPROVAL GATE — if the schedule requires approval and this occurrence is not
     *      authorised (bypass), a single pending Approval is ensured (idempotent) and
     *      the reviewer notified; the agent does NOT run.
     * Only when none of the gates apply does the normal commit-before-run dispatch fire.
     *
     * @param ObjectEntity      $schedule             The schedule object to fire.
     * @param DateTimeImmutable $now                  The current UTC moment.
     * @param array<int,string> $engagedOrganisations Organisations whose kill-switch is engaged.
     * @param bool              $bypassApprovalGate   When true, skip the requiresApproval gate
     *                                                (an approved occurrence running via approve()).
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The bypass is a genuine two-mode
     *   authorisation input (normal run vs. an already-approved occurrence), not a
     *   responsibility split — both modes share the identical dispatch path.
     *
     * @spec openspec/changes/human-approval-gate-enforcement/tasks.md#task-3-2
     * @spec openspec/changes/human-approval-gate-enforcement/tasks.md#task-2-1
     * @spec openspec/changes/cost-guardrails/tasks.md#task-3-1
     */
    private function dispatch(
        ObjectEntity $schedule,
        DateTimeImmutable $now,
        array $engagedOrganisations=[],
        bool $bypassApprovalGate=false
    ): void {
        $data         = $schedule->getObject();
        $owner        = (string) ($schedule->getOwner() ?? '');
        $organisation = (string) ($schedule->getOrganisation() ?? '');
        $agentId      = (string) ($data['agentId'] ?? '');

        // Reset per-schedule run-identity/usage/steps BEFORE either gate can short-circuit
        // to writeRunAudit(): without this, a gate-skipped schedule's audit entry could leak
        // a PREVIOUS schedule's lastRunAsUser/lastRunUsage/lastRunSteps from earlier in the
        // same tick. A skip never runs an agent, so all three reflect "nothing ran" (owner,
        // empty usage, empty step timeline).
        $this->lastRunAsUser = $owner;
        $this->lastRunUsage  = [];
        $this->lastRunSteps  = [];

        // GATE 1 — KILL-SWITCH (highest priority; halts even an authorised approval-run).
        if ($organisation !== '' && in_array($organisation, $engagedOrganisations, true) === true) {
            $this->recordGateSkip(schedule: $schedule, data: $data, owner: $owner, now: $now, status: 'skipped_killswitch');
            return;
        }

        // GATE 2 — BUDGET HARD CAP (cost-guardrails). A soft-threshold warning is
        // independent of the block (runs continue when only the soft threshold is
        // crossed), so it is checked unconditionally here, every tick the schedule is
        // due — never fatal to the dispatch. The hard-cap block itself halts even an
        // authorised approval-run bypass, exactly like the kill-switch.
        try {
            $this->budgetService->checkAndDeliverWarnings(organisation: $organisation, agentId: $agentId);
        } catch (Throwable $e) {
            $this->logger->warning(
                sprintf('Hermiq budget soft-threshold check failed for %s: %s', (string) $schedule->getUuid(), $e->getMessage()),
                ['exception' => $e]
            );
        }

        if ($this->budgetService->isBlocked(organisation: $organisation, agentId: $agentId) === true) {
            $this->recordGateSkip(schedule: $schedule, data: $data, owner: $owner, now: $now, status: 'skipped_budget');
            return;
        }

        // GATE 3 — HUMAN APPROVAL (Art. 14). A gated, unauthorised occurrence does not
        // run: ensure a single pending Approval (idempotent) and mark awaiting_approval.
        if ($bypassApprovalGate === false && ($data['requiresApproval'] ?? false) === true) {
            try {
                $this->approvalService->ensurePendingApproval(schedule: $schedule);
            } catch (Throwable $e) {
                // Gate-setup failure is non-fatal: log and still block the run.
                $this->logger->warning(
                    sprintf('Hermiq approval gate setup failed for %s: %s', (string) $schedule->getUuid(), $e->getMessage()),
                    ['exception' => $e]
                );
            }

            $this->recordGateSkip(schedule: $schedule, data: $data, owner: $owner, now: $now, status: 'awaiting_approval');
            return;
        }

        $this->runDue(schedule: $schedule, now: $now);

    }//end dispatch()

    /**
     * Record a gate skip: advance nextRun, set the gate status, persist, and audit.
     *
     * A gated/halted occurrence does NOT consume the repeat counter and does NOT
     * disable the schedule — it simply does not run. nextRun IS advanced (per the gate
     * contract) so a recurring schedule is not perpetually due while gated/halted. One
     * redacted audit entry records the skip so no gated occurrence escapes the trail
     * (ADR-004). Non-fatal by contract.
     *
     * @param ObjectEntity        $schedule The gated schedule.
     * @param array<string,mixed> $data     The schedule payload (advanced + finalised here).
     * @param string              $owner    The owner UID (for timezone-anchored next-run).
     * @param DateTimeImmutable   $now      The current UTC moment.
     * @param string              $status   The gate status (skipped_killswitch|skipped_budget|awaiting_approval).
     *
     * @return void
     *
     * @spec openspec/changes/human-approval-gate-enforcement/tasks.md#task-2-1
     * @spec openspec/changes/human-approval-gate-enforcement/tasks.md#task-3-2
     * @spec openspec/changes/cost-guardrails/tasks.md#task-3-1
     */
    private function recordGateSkip(ObjectEntity $schedule, array $data, string $owner, DateTimeImmutable $now, string $status): void
    {
        $startedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        // Run-reliability: a pending retry attempt's own number is preserved — a
        // kill-switch/budget/approval skip does NOT touch retryState, so the retry
        // stays due and fires with the SAME attempt number once the gate clears.
        $attempt = $this->currentAttemptNumber(data: $data);

        $nextRun            = $this->computeNextRun(kind: (string) ($data['kind'] ?? ''), data: $data, owner: $owner, now: $now);
        $data['nextRun']    = $nextRun?->format('c');
        $data['lastStatus'] = $status;
        $data['lastError']  = null;

        $this->persist(schedule: $schedule, data: $data);
        $this->writeRunAudit(schedule: $schedule, data: $data, summary: '', startedAt: $startedAt, attempt: $attempt);

    }//end recordGateSkip()

    /**
     * The attempt number about to run (or being gate-skipped) for a schedule's
     * current occurrence — 1 for a fresh occurrence, or `retryState.attempt + 1`
     * when an open retry sequence is pending (run-reliability).
     *
     * @param array<string,mixed> $data The schedule payload.
     *
     * @return int The 1-based attempt number.
     *
     * @spec openspec/changes/run-reliability/specs/run-audit-log/spec.md#requirement-run-history-surfaces-retry-attempts-and-dead-lettercircuit-breaker-outcomes-mvp
     */
    private function currentAttemptNumber(array $data): int
    {
        $retryState = $this->normaliseRetryState(raw: ($data['retryState'] ?? null));
        if ($retryState === null) {
            return 1;
        }

        return ($retryState['attempt'] + 1);

    }//end currentAttemptNumber()

    /**
     * Run one due schedule end-to-end (the normal, ungated path).
     *
     * Order matters for at-most-once safety: run-state (`nextRun`, `lastStatus`,
     * `repeat.completed`) is committed BEFORE the agent turn, so a crash during the
     * (long) agent run cannot re-fire the same occurrence.
     *
     * run-reliability: this same method also drives a RETRY attempt of an already
     * committed occurrence (findDueSchedules() selects a schedule whose
     * `retryState.nextAttemptAt` is due). A retry attempt is distinguished from a
     * fresh occurrence by the presence of `retryState` on a `retryEnabled` schedule
     * — see isRetryAttempt() — and does NOT repeat the nextRun/repeat commit-before-run
     * advance (that already happened on the occurrence's first attempt); it only
     * re-invokes the agent and finalises the retry/dead-letter/circuit-breaker state.
     * The once/finite-repeat auto-disable is deferred on a retry-enabled schedule
     * until the retry sequence resolves (success or dead-letter) — see $deferDisable.
     *
     * @param ObjectEntity      $schedule The schedule object to fire.
     * @param DateTimeImmutable $now      The current UTC moment.
     *
     * @return void
     *
     * @spec openspec/changes/agent-schedule-dispatcher/tasks.md#task-3-3
     * @spec openspec/changes/agent-schedule-dispatcher/tasks.md#task-3-4
     * @spec openspec/changes/agent-schedule-dispatcher/tasks.md#task-3-6
     * @spec openspec/changes/run-reliability/specs/agent-schedule/spec.md#requirement-per-schedule-opt-in-bounded-retry-with-exponential-backoff-mvp
     * @spec openspec/changes/run-reliability/specs/agent-schedule/spec.md#requirement-dead-letter-state-after-retries-are-exhausted-with-manual-re-run-mvp
     * @spec openspec/changes/run-reliability/specs/agent-schedule/spec.md#requirement-consecutive-dead-letter-circuit-breaker-auto-pauses-a-schedule-mvp
     */
    private function runDue(ObjectEntity $schedule, DateTimeImmutable $now): void
    {
        $data = $schedule->getObject();

        $occurrence    = $this->beginOccurrence(schedule: $schedule, data: $data, now: $now);
        $data          = $occurrence['data'];
        $attemptNumber = $occurrence['attemptNumber'];
        $deferDisable  = $occurrence['deferDisable'];
        $limitReached  = $occurrence['limitReached'];
        $retryEnabled  = $occurrence['retryEnabled'];

        $this->persist(schedule: $schedule, data: $data);

        // Wall-clock start of the agent turn, for the run-audit timing (run-audit-log).
        $startedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $summary   = '';

        // Run the agent + deliver + finalise inside a try/catch that operates on the
        // SAME $data that already carries the committed advance (nextRun / disabled
        // one-shot / bumped repeat). CRASH-SAFETY INVARIANT (task 4.2): whether the
        // turn succeeds or fails, the failure branch must NOT re-read the stale
        // pre-commit entity nor recompute nextRun — reverting the advance would make
        // a failing schedule stay perpetually due and re-fire every tick, defeating
        // commit-before-run. On failure the advance stays; only lastStatus/lastError
        // (and the run-reliability retry/dead-letter/circuit-breaker fields) change.
        try {
            $output = $this->runAgentAsOwner(
                owner: (string) ($schedule->getOwner() ?? ''),
                agentId: (string) ($data['agentId'] ?? ''),
                prompt: (string) ($data['prompt'] ?? ''),
                organisation: (string) ($schedule->getOrganisation() ?? '')
            );

            // Run-trace-observability: a `delivery` step timed around the existing
            // DeliveryService call — appended AFTER any context/history/tool/llm
            // steps runAgentAsOwner() already captured, never fatal to the run
            // (DeliveryService already promises never to throw; a failed delivery
            // is recorded as outcome=error, not an aborted run).
            $deliveryStartedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $delivery          = $this->deliver(
                channel: (string) ($data['deliver'] ?? 'none'),
                output: $output,
                schedule: $schedule
            );
            $this->appendDeliveryStep(startedAt: $deliveryStartedAt, delivery: $delivery);

            $data    = $this->applySuccessOutcome(data: $data, delivery: $delivery, deferDisable: $deferDisable);
            $summary = $output;
        } catch (Throwable $e) {
            $this->logger->warning(
                sprintf('Hermiq schedule %s failed: %s', (string) $schedule->getUuid(), $e->getMessage()),
                ['exception' => $e]
            );

            $data    = $this->applyFailureOutcome(
                schedule: $schedule,
                data: $data,
                retryEnabled: $retryEnabled,
                attemptNumber: $attemptNumber,
                deferDisable: $deferDisable,
                now: $now,
                error: $e
            );
            $summary = 'error: '.$e->getMessage();
        }//end try

        // Write the explicit, redacted per-run AuditTrail entry (run-audit-log). Done
        // for BOTH success and error, and BEFORE any delete, so no run — including the
        // final occurrence of a finite repeat — escapes the immutable trail. Never
        // fatal to the tick (ADR-004): a redaction/audit failure is logged, not raised.
        $this->writeRunAudit(schedule: $schedule, data: $data, summary: $summary, startedAt: $startedAt, attempt: $attemptNumber);

        // Run-reliability: the deferred finite-repeat delete only fires once the
        // occurrence has actually RESOLVED (ok / error / dead_letter /
        // paused_circuit_breaker) — never while a retry is still pending, or the
        // schedule would be destroyed mid-sequence and the pending retry lost.
        if ($limitReached === true && $data['lastStatus'] !== 'retry_pending') {
            $this->deleteSchedule(schedule: $schedule);
            return;
        }

        $this->persist(schedule: $schedule, data: $data);

    }//end runDue()

    /**
     * Compute the commit-before-run advance for one occurrence — either a FRESH
     * occurrence (the unchanged nextRun/repeat advance) or a RETRY ATTEMPT of an
     * already-committed occurrence (run-reliability; no re-advance, only marks
     * running and preserves the deferred-disable decision).
     *
     * A retry attempt is distinguished from a fresh occurrence by the presence of
     * `retryState` on a `retryEnabled` schedule. The once/finite-repeat
     * auto-disable is DEFERRED on a retry-enabled schedule until the retry
     * sequence resolves (success or dead-letter) — see `deferDisable` — so a due
     * retry is never dropped by findDueSchedules()'s `enabled=true` filter.
     *
     * @param ObjectEntity        $schedule The schedule object to fire.
     * @param array<string,mixed> $data     The schedule payload (getObject() snapshot).
     * @param DateTimeImmutable   $now      The current UTC moment.
     *
     * @return array{data:array<string,mixed>, attemptNumber:int, deferDisable:bool, limitReached:bool, retryEnabled:bool}
     *
     * @spec openspec/changes/run-reliability/specs/agent-schedule/spec.md#requirement-per-schedule-opt-in-bounded-retry-with-exponential-backoff-mvp
     * @spec openspec/changes/run-reliability/specs/agent-schedule/spec.md#requirement-dead-letter-state-after-retries-are-exhausted-with-manual-re-run-mvp
     */
    private function beginOccurrence(ObjectEntity $schedule, array $data, DateTimeImmutable $now): array
    {
        $retryEnabled       = (($data['retryEnabled'] ?? false) === true);
        $existingRetryState = $this->normaliseRetryState(raw: ($data['retryState'] ?? null));

        if ($retryEnabled === true && $existingRetryState !== null) {
            return $this->beginRetryAttempt(data: $data, retryEnabled: $retryEnabled, retryState: $existingRetryState);
        }

        return $this->beginFreshOccurrence(schedule: $schedule, data: $data, now: $now, retryEnabled: $retryEnabled);

    }//end beginOccurrence()

    /**
     * The RETRY ATTEMPT branch of beginOccurrence(): do not re-advance nextRun/
     * repeat — only mark running and remember whether the once/finite-repeat
     * disable that was deferred on the occurrence's first attempt still applies
     * once this attempt resolves.
     *
     * @param array<string,mixed>                     $data         The schedule payload.
     * @param bool                                    $retryEnabled Always true (caller-checked).
     * @param array{attempt:int,nextAttemptAt:string} $retryState   The open retry state.
     *
     * @return array{data:array<string,mixed>, attemptNumber:int, deferDisable:bool, limitReached:bool, retryEnabled:bool}
     *
     * @spec openspec/changes/run-reliability/specs/agent-schedule/spec.md#requirement-per-schedule-opt-in-bounded-retry-with-exponential-backoff-mvp
     */
    private function beginRetryAttempt(array $data, bool $retryEnabled, array $retryState): array
    {
        $isOnce       = ((string) ($data['kind'] ?? '') === 'once');
        $repeat       = $this->normaliseRepeat(repeat: ($data['repeat'] ?? []));
        $limitReached = ($repeat['times'] > 0 && $repeat['completed'] >= $repeat['times']);

        $data['lastStatus'] = 'running';
        $data['lastError']  = null;

        return [
            'data'          => $data,
            'attemptNumber' => ($retryState['attempt'] + 1),
            'deferDisable'  => ($isOnce === true || $limitReached === true),
            'limitReached'  => $limitReached,
            'retryEnabled'  => $retryEnabled,
        ];

    }//end beginRetryAttempt()

    /**
     * The FRESH OCCURRENCE branch of beginOccurrence() — the unchanged commit-
     * before-run advance (nextRun/repeat), deferring the once/finite-repeat
     * auto-disable when this schedule opted into retry (agent-schedule "Pause a
     * schedule" invariant is unaffected — only THIS occurrence's disable waits; a
     * user-disabled schedule never re-enters findDueSchedules()).
     *
     * @param ObjectEntity        $schedule     The schedule object to fire.
     * @param array<string,mixed> $data         The schedule payload.
     * @param DateTimeImmutable   $now          The current UTC moment.
     * @param bool                $retryEnabled Whether this schedule opted into retry.
     *
     * @return array{data:array<string,mixed>, attemptNumber:int, deferDisable:bool, limitReached:bool, retryEnabled:bool}
     *
     * @spec openspec/changes/run-reliability/specs/agent-schedule/spec.md#requirement-dead-letter-state-after-retries-are-exhausted-with-manual-re-run-mvp
     */
    private function beginFreshOccurrence(ObjectEntity $schedule, array $data, DateTimeImmutable $now, bool $retryEnabled): array
    {
        $kind   = (string) ($data['kind'] ?? '');
        $isOnce = ($kind === 'once');
        $owner  = (string) ($schedule->getOwner() ?? '');

        $repeat = $this->normaliseRepeat(repeat: ($data['repeat'] ?? []));
        $repeat['completed'] += 1;
        $limitReached         = ($repeat['times'] > 0 && $repeat['completed'] >= $repeat['times']);
        $deferDisable         = ($retryEnabled === true && ($isOnce === true || $limitReached === true));

        $nextRun            = $this->computeNextRun(kind: $kind, data: $data, owner: $owner, now: $now);
        $data['nextRun']    = $nextRun?->format('c');
        $data['lastStatus'] = 'running';
        $data['lastError']  = null;
        $data['repeat']     = $repeat;
        if (($isOnce === true || $limitReached === true) && $deferDisable === false) {
            $data['enabled'] = false;
        }

        return [
            'data'          => $data,
            'attemptNumber' => 1,
            'deferDisable'  => $deferDisable,
            'limitReached'  => $limitReached,
            'retryEnabled'  => $retryEnabled,
        ];

    }//end beginFreshOccurrence()

    /**
     * Finalise a successful agent turn onto the advanced $data.
     *
     * A delivery problem is NEVER fatal: the run stays 'ok' and any delivery
     * warning is persisted to lastDeliveryError (cleared to null on a clean
     * delivery). Run-reliability: a success — whether the first attempt or a
     * later retry — clears any open retry sequence, resets the consecutive-
     * dead-letter streak, and applies the once/finite-repeat disable if it was
     * deferred while the retry sequence was open.
     *
     * @param array<string,mixed> $data         The advanced schedule payload.
     * @param DeliveryResult      $delivery     The delivery outcome.
     * @param bool                $deferDisable Whether the once/finite-repeat disable is pending.
     *
     * @return array<string,mixed> The finalised schedule payload.
     *
     * @spec openspec/changes/run-reliability/specs/agent-schedule/spec.md#requirement-consecutive-dead-letter-circuit-breaker-auto-pauses-a-schedule-mvp
     */
    private function applySuccessOutcome(array $data, DeliveryResult $delivery, bool $deferDisable): array
    {
        $data['lastStatus']        = 'ok';
        $data['lastError']         = null;
        $data['lastDeliveryError'] = $delivery->getWarning();
        $data['retryState']        = null;
        $data['consecutiveDeadLetters'] = 0;
        if ($deferDisable === true) {
            $data['enabled'] = false;
        }

        return $data;

    }//end applySuccessOutcome()

    /**
     * Finalise a failed agent turn onto the advanced $data: unchanged `error` when
     * retry is disabled, or the run-reliability retry/dead-letter/circuit-breaker
     * branches when it is enabled.
     *
     * @param ObjectEntity        $schedule      The schedule that failed.
     * @param array<string,mixed> $data          The advanced schedule payload.
     * @param bool                $retryEnabled  Whether this schedule opted into retry.
     * @param int                 $attemptNumber The attempt number that just failed.
     * @param bool                $deferDisable  Whether the once/finite-repeat disable is pending.
     * @param DateTimeImmutable   $now           The current UTC moment.
     * @param Throwable           $error         The captured agent-turn failure.
     *
     * @return array<string,mixed> The finalised schedule payload.
     *
     * @spec openspec/changes/run-reliability/specs/agent-schedule/spec.md#requirement-per-schedule-opt-in-bounded-retry-with-exponential-backoff-mvp
     * @spec openspec/changes/run-reliability/specs/agent-schedule/spec.md#requirement-dead-letter-state-after-retries-are-exhausted-with-manual-re-run-mvp
     * @spec openspec/changes/run-reliability/specs/agent-schedule/spec.md#requirement-consecutive-dead-letter-circuit-breaker-auto-pauses-a-schedule-mvp
     */
    private function applyFailureOutcome(
        ObjectEntity $schedule,
        array $data,
        bool $retryEnabled,
        int $attemptNumber,
        bool $deferDisable,
        DateTimeImmutable $now,
        Throwable $error
    ): array {
        if ($retryEnabled === false) {
            // Unchanged pre-run-reliability behavior.
            $data['lastStatus'] = 'error';
            $data['lastError']  = $error->getMessage();
            return $data;
        }

        $maxAttempts = $this->clampInt(value: ($data['retryMaxAttempts'] ?? 3), min: 1, max: 10);
        if ($attemptNumber < $maxAttempts) {
            return $this->scheduleRetry(data: $data, attemptNumber: $attemptNumber, now: $now, error: $error);
        }

        return $this->markDeadLetter(schedule: $schedule, data: $data, deferDisable: $deferDisable, error: $error);

    }//end applyFailureOutcome()

    /**
     * Schedule the next retry attempt with exponential backoff
     * (`backoffBase * 2^(attempt-1)`), keeping the occurrence `retry_pending`.
     *
     * @param array<string,mixed> $data          The advanced schedule payload.
     * @param int                 $attemptNumber The attempt number that just failed.
     * @param DateTimeImmutable   $now           The current UTC moment.
     * @param Throwable           $error         The captured agent-turn failure.
     *
     * @return array<string,mixed> The schedule payload with a pending retryState.
     *
     * @spec openspec/changes/run-reliability/specs/agent-schedule/spec.md#requirement-per-schedule-opt-in-bounded-retry-with-exponential-backoff-mvp
     */
    private function scheduleRetry(array $data, int $attemptNumber, DateTimeImmutable $now, Throwable $error): array
    {
        $backoffBase  = max(1, (int) ($data['retryBackoffBaseSeconds'] ?? 60));
        $delaySeconds = $backoffBase * (2 ** ($attemptNumber - 1));

        $data['lastStatus'] = 'retry_pending';
        $data['lastError']  = $error->getMessage();
        $data['retryState'] = [
            'attempt'       => $attemptNumber,
            'nextAttemptAt' => $now->add(new DateInterval('PT'.$delaySeconds.'S'))->format('c'),
        ];

        return $data;

    }//end scheduleRetry()

    /**
     * Mark the occurrence dead-letter once its retry budget is exhausted: clears
     * retryState, applies the deferred once/finite-repeat disable, increments
     * consecutiveDeadLetters, alerts the owner, and — once the circuit-breaker
     * threshold is reached — auto-pauses the schedule with a distinct alert.
     *
     * @param ObjectEntity        $schedule     The schedule being dead-lettered.
     * @param array<string,mixed> $data         The advanced schedule payload.
     * @param bool                $deferDisable Whether the once/finite-repeat disable is pending.
     * @param Throwable           $error        The captured agent-turn failure.
     *
     * @return array<string,mixed> The schedule payload marked dead_letter (or paused_circuit_breaker).
     *
     * @spec openspec/changes/run-reliability/specs/agent-schedule/spec.md#requirement-dead-letter-state-after-retries-are-exhausted-with-manual-re-run-mvp
     * @spec openspec/changes/run-reliability/specs/agent-schedule/spec.md#requirement-consecutive-dead-letter-circuit-breaker-auto-pauses-a-schedule-mvp
     */
    private function markDeadLetter(ObjectEntity $schedule, array $data, bool $deferDisable, Throwable $error): array
    {
        $data['lastStatus'] = 'dead_letter';
        $data['lastError']  = $error->getMessage();
        $data['retryState'] = null;
        if ($deferDisable === true) {
            $data['enabled'] = false;
        }

        $deadLetterStreak = ((int) ($data['consecutiveDeadLetters'] ?? 0)) + 1;
        $data['consecutiveDeadLetters'] = $deadLetterStreak;

        $this->safeDeliverFailureAlert(schedule: $schedule, reason: $error->getMessage());

        $threshold = $this->clampInt(value: ($data['circuitBreakerThreshold'] ?? 3), min: 1, max: PHP_INT_MAX);
        if ($deadLetterStreak >= $threshold) {
            $data['enabled']    = false;
            $data['lastStatus'] = 'paused_circuit_breaker';
            $this->safeDeliverCircuitBreakerAlert(schedule: $schedule);
        }

        return $data;

    }//end markDeadLetter()

    /**
     * Write the explicit per-run AuditTrail entry via OpenRegister (run-audit-log).
     *
     * REDACTION-BEFORE-PERSIST (ADR-004): the output/error summary is masked by
     * RedactionService BEFORE it is placed in the immutable, hash-chained audit
     * context — the trail is append-only, so a secret written once cannot be
     * removed. The entry inherits the impersonated owner as `user` and the
     * Schedule's `organisation`, and joins OpenRegister's verify() hash chain.
     *
     * Non-fatal by contract: any failure here is logged and swallowed so auditing
     * never fails the run (the dispatcher's own ObjectService saves already leave
     * auto-audit traces regardless).
     *
     * @param ObjectEntity        $schedule  The schedule that ran.
     * @param array<string,mixed> $data      The finalised schedule payload (status/agentId).
     * @param string              $summary   The raw run output / error (redacted here).
     * @param DateTimeImmutable   $startedAt When the agent turn began (UTC).
     * @param int                 $attempt   The attempt number for this occurrence (1 for a
     *                                       fresh/non-retry run; 2+ for a retry — run-reliability).
     *
     * @return void
     *
     * @spec openspec/changes/run-audit-log/tasks.md#task-2-2
     * @spec openspec/changes/run-audit-log/tasks.md#task-2-3
     * @spec openspec/changes/run-reliability/specs/run-audit-log/spec.md#requirement-run-history-surfaces-retry-attempts-and-dead-lettercircuit-breaker-outcomes-mvp
     */
    private function writeRunAudit(ObjectEntity $schedule, array $data, string $summary, DateTimeImmutable $startedAt, int $attempt=1): void
    {
        try {
            $endedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));

            $context = [
                'status'             => (string) ($data['lastStatus'] ?? 'unknown'),
                'agentId'            => (string) ($data['agentId'] ?? ''),
                'startedAt'          => $startedAt->format('c'),
                'endedAt'            => $endedAt->format('c'),
                'durationMs'         => (((int) $endedAt->format('U') - (int) $startedAt->format('U')) * 1000),
                // Per-run LLM token/latency usage from OpenRegister's ChatService (run-analytics).
                'usage'              => $this->lastRunUsage,
                // The identity that actually ran the turn — the schedule owner, unless
                // Agent.actingUser overrode it (agent-capability-profile).
                'runAsUser'          => $this->lastRunAsUser,
                // REDACTION-BEFORE-PERSIST: mask secrets/PII before the append-only write.
                'summary'            => $this->redactionService->redact($summary),
                // Run-reliability: the attempt number within this occurrence's retry
                // sequence (1 = first attempt), so run history can show each retry.
                'attempt'            => $attempt,
                // Run-trace-observability: the run's ordered step timeline (empty for a
                // gate-skip — no agent turn ran) and whether it includes any tool-type
                // step (only ever true on the in-app Engine path; never fabricated).
                'steps'              => $this->lastRunSteps,
                'toolStepsAvailable' => $this->stepsIncludeToolCall(steps: $this->lastRunSteps),
            ];

            $this->auditTrailMapper->createAuditTrailEntry(
                object: $schedule,
                action: 'run',
                context: $context
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                sprintf(
                    'Hermiq could not write run audit for schedule %s: %s',
                    (string) $schedule->getUuid(),
                    $e->getMessage()
                ),
                ['exception' => $e]
            );
        }//end try

    }//end writeRunAudit()

    /**
     * Whether a step timeline includes any `tool`-type step.
     *
     * Run-trace-observability: `toolStepsAvailable` lets a reader distinguish "no
     * tools were called this run" (in-app Engine path, empty steps of type tool)
     * from "tool-level detail unavailable on this run's execution path" (default
     * OpenRegister `ChatService` path, which never produces a tool-type step at
     * all) — see proposal.md Risk 2.
     *
     * @param array<int, array<string, mixed>> $steps The step timeline.
     *
     * @return bool True when at least one step has `type === 'tool'`.
     *
     * @spec openspec/changes/run-trace-observability/tasks.md#task-3-scheduleservice-captures-steps-and-includes-them-in-the-run-audit-write
     */
    private function stepsIncludeToolCall(array $steps): bool
    {
        foreach ($steps as $step) {
            if (($step['type'] ?? null) === 'tool') {
                return true;
            }
        }

        return false;

    }//end stepsIncludeToolCall()

    /**
     * Build coarse context/history/llm steps from the agent-turn call's already-
     * returned `timings` bucket (a formatted-seconds string per leg, e.g. `"0.18s"`)
     * — the ONLY step source available on the default OpenRegister `ChatService`
     * path (Hermiq does not instrument OR's internal tool loop, so no `tool` step
     * is ever produced here).
     *
     * Each leg's real DURATION is known but not its absolute wall-clock start/end,
     * so the three legs are chained backward from `$anchorEnd` (the moment the
     * agent-turn call returned) in call order (context, history, llm) — a
     * contiguous, honest synthetic timeline. A leg that is absent or does not
     * parse as `<number>s` is skipped entirely rather than fabricated (proposal
     * Risk 1 / tasks.md task 3 acceptance).
     *
     * @param array<string, mixed> $timings   The `timings` bucket (`context`/`history`/`llm`/`total`
     *                                        formatted-seconds strings), or malformed/absent.
     * @param DateTimeImmutable    $anchorEnd The moment the agent-turn call returned.
     *
     * @return array<int, array<string, mixed>> The coarse steps, oldest leg first, `seq` 0..n-1.
     *
     * @spec openspec/changes/run-trace-observability/tasks.md#task-3-scheduleservice-captures-steps-and-includes-them-in-the-run-audit-write
     */
    private function buildCoarseStepsFromTimings(array $timings, DateTimeImmutable $anchorEnd): array
    {
        // Chained backward from $anchorEnd; legs are appended in call order below
        // (context, history, llm) so the final array is already in call order.
        $legs = [
            ['type' => 'llm', 'name' => 'LLM generation', 'key' => 'llm'],
            ['type' => 'history', 'name' => 'History build', 'key' => 'history'],
            ['type' => 'context', 'name' => 'Context retrieval', 'key' => 'context'],
        ];

        $cursor        = $anchorEnd;
        $backwardSteps = [];
        foreach ($legs as $leg) {
            $durationMs = $this->parseTimingSeconds(value: ($timings[$leg['key']] ?? null));
            if ($durationMs === null) {
                continue;
            }

            $endedAt   = $cursor;
            $startedAt = $endedAt->modify('-'.$durationMs.' milliseconds');

            $backwardSteps[] = [
                'type'       => $leg['type'],
                'name'       => $leg['name'],
                'startedAt'  => $startedAt->format('c'),
                'endedAt'    => $endedAt->format('c'),
                'durationMs' => $durationMs,
                'outcome'    => 'ok',
            ];

            $cursor = $startedAt;
        }//end foreach

        $steps = array_reverse($backwardSteps);

        $seq = 0;
        return array_map(
            static function (array $step) use (&$seq): array {
                $step['seq'] = $seq++;
                return $step;
            },
            $steps
        );

    }//end buildCoarseStepsFromTimings()

    /**
     * Parse a `timings` leg value (`"0.18s"`) into a millisecond duration.
     *
     * @param mixed $value The raw timing value.
     *
     * @return int|null The duration in milliseconds, or null when absent/malformed
     *                  (never fabricated — proposal Risk 1).
     *
     * @spec openspec/changes/run-trace-observability/tasks.md#task-3-scheduleservice-captures-steps-and-includes-them-in-the-run-audit-write
     */
    private function parseTimingSeconds(mixed $value): ?int
    {
        if (is_string($value) === false || preg_match('~^(\d+(?:\.\d+)?)s$~', $value, $matches) !== 1) {
            return null;
        }

        return (int) round(((float) $matches[1]) * 1000);

    }//end parseTimingSeconds()

    /**
     * Compute the next run time for a schedule, anchored to the owner's timezone.
     *
     * Cron uses dragonmantank/cron-expression; interval adds `intervalMinutes`;
     * once returns null (one-shots do not recur). The owner's configured timezone is
     * used, falling back to the instance default timezone when the owner has none.
     *
     * @param string              $kind  Schedule kind: once|interval|cron.
     * @param array<string,mixed> $data  The schedule payload.
     * @param string              $owner The owner UID (for timezone resolution).
     * @param DateTimeImmutable   $now   The current UTC moment.
     *
     * @return DateTimeImmutable|null The next run (UTC), or null for one-shots.
     *
     * @spec openspec/changes/agent-schedule-dispatcher/tasks.md#task-3-2
     */
    private function computeNextRun(string $kind, array $data, string $owner, DateTimeImmutable $now): ?DateTimeImmutable
    {
        $timezone = $this->resolveTimezone(owner: $owner);

        if ($kind === 'cron') {
            $cronExpr = (string) ($data['cronExpr'] ?? '');
            if ($cronExpr === '') {
                return null;
            }

            $cron = new CronExpression($cronExpr);
            $next = $cron->getNextRunDate(
                currentTime: $now->setTimezone($timezone),
                nth: 0,
                allowCurrentDate: false,
                timeZone: $timezone->getName()
            );

            return DateTimeImmutable::createFromMutable($next)->setTimezone(new DateTimeZone('UTC'));
        }

        if ($kind === 'interval') {
            $minutes = (int) ($data['intervalMinutes'] ?? 0);
            if ($minutes <= 0) {
                return null;
            }

            return $now->add(new DateInterval('PT'.$minutes.'M'));
        }

        // Once: one-shot, does not recur.
        return null;

    }//end computeNextRun()

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
     * @param string $owner        The uid to impersonate for this run.
     * @param string $agentId      The bound agent UUID.
     * @param string $prompt       The prompt to run.
     * @param string $organisation The run's organisation (schedule/agent/flow-subject
     *                             organisation — '' resolves the org-less instance
     *                             default), used ONLY to resolve the effective
     *                             GuardrailPolicy (agent-guardrails): the input filter
     *                             on the legacy ChatService branch (the one path
     *                             Engine::processMessage() never sees), and the
     *                             defense-in-depth output filter applied to BOTH
     *                             branches' result before it reaches this method's
     *                             single return point.
     *
     * @return string The agent's response text (already output-filtered).
     *
     * @throws RuntimeException           When the owner or agent cannot be resolved.
     * @throws GuardrailBlockedException  When the effective GuardrailPolicy's input
     *                                    filter refuses the prompt (legacy branch only —
     *                                    the Engine branch throws this internally,
     *                                    inside `runAgentViaEngine()`/`Engine::processMessage()`).
     *
     * @spec openspec/changes/agent-schedule-dispatcher/tasks.md#task-3-4
     * @spec openspec/changes/agent-engine-port/tasks.md#task-6-1
     * @spec openspec/changes/agent-engine-port/tasks.md#task-6-2
     * @spec openspec/changes/flow-agent-listener/tasks.md#task-2-2
     * @spec openspec/changes/agent-guardrails/tasks.md#task-4-wire-inputoutput-filters-into-scheduleservicerunagentasowner
     */
    public function runAgentAsOwner(string $owner, string $agentId, string $prompt, string $organisation=''): string
    {
        // Reset per-run usage/steps so a failed run never records the previous run's
        // tokens or step timeline.
        $this->lastRunUsage  = [];
        $this->lastRunSteps  = [];
        $this->lastRunAsUser = $owner;

        // Agent-guardrails: resolve the effective GuardrailPolicy ONCE for this run.
        $guardrailPolicy = $this->guardrailPolicyService->effectivePolicyFor(organisation: $organisation);

        // Agent-capability-profile: on the engine-enabled path only, an Agent may name
        // an actingUser to impersonate instead of the schedule owner. Resolved BEFORE
        // impersonation so the whole turn (conversation + messages + tool writes) runs
        // as that identity; falls back to $owner (silently, logged) when unset/invalid.
        $impersonateAs = $owner;
        if ($this->isEngineEnabled() === true) {
            $impersonateAs = $this->resolveActingUser(agentId: $agentId, fallbackOwner: $owner);
        }

        $this->lastRunAsUser = $impersonateAs;

        $user = $this->userManager->get($impersonateAs);
        if ($user === null) {
            throw new RuntimeException("Run-as user '{$impersonateAs}' does not exist");
        }

        $priorUser = $this->userSession->getUser();
        $this->userSession->setUser($user);

        try {
            // Agent-engine-port pivot (task 6.2): with the feature flag ON, the run
            // goes through the in-app Engine against hermiq-register objects — still
            // inside the same impersonation try/finally, and only reached AFTER the
            // kill-switch/approval gating upstream of this method. With the flag OFF
            // (default) the OpenRegister ChatService path below is byte-for-byte the
            // pre-flag behavior. Engine::processMessage() already applies its OWN
            // input/output filter internally; the output filter is re-applied here
            // too (defense-in-depth at the delivery/persistence trust boundary,
            // design.md Decision 7) — idempotent on already-filtered text.
            if ($this->isEngineEnabled() === true) {
                $output = $this->runAgentViaEngine(owner: $impersonateAs, agentId: $agentId, prompt: $prompt);
                return $this->applyOutputGuardrail(policy: $guardrailPolicy, output: $output);
            }

            // Agent-guardrails: the input filter's ONLY seam on this legacy
            // ChatService branch — Engine::processMessage() is never invoked here,
            // so nothing else would ever filter this prompt. A `block` match throws
            // BEFORE the legacy ChatService call, so no conversation/message is ever
            // created for this attempt; a `redact` match replaces $prompt so the
            // masked text is what the agent actually receives.
            $inputFilter = $this->guardrailPolicyService->filterInput(policy: $guardrailPolicy, text: $prompt);
            if ($this->guardrailActed(filter: $inputFilter, originalText: $prompt) === true) {
                $this->appendGuardrailStep(name: 'Input filter', filter: $inputFilter);
            }

            if ($inputFilter['blocked'] === true) {
                throw new GuardrailBlockedException(reason: (string) $inputFilter['reason']);
            }

            $prompt = (string) $inputFilter['text'];

            $agent        = $this->agentMapper->findByUuid($agentId);
            $conversation = new Conversation();
            $conversation->setUserId($owner);
            $conversation->setOwner($owner);
            $conversation->setAgentId($agent->getId());
            $conversation->setTitle('Hermiq scheduled run');
            $conversation = $this->conversationMapper->insert($conversation);

            $result = $this->chatService->processMessage(
                conversationId: (int) $conversation->getId(),
                userId: $owner,
                userMessage: $prompt
            );

            // Capture the LLM token/latency usage OpenRegister now reports, so writeRunAudit
            // records it for run-analytics (run-cost recording). Empty when unavailable.
            $usage = ($result['usage'] ?? []);
            if (is_array($usage) === true) {
                $this->lastRunUsage = $usage;
            }

            // Run-trace-observability: Hermiq does not instrument OR's internal tool
            // loop on this path, so only coarse context/history/llm steps are ever
            // captured here — derived from the `timings` bucket the call already
            // returns (never a fabricated duration when a leg is absent/malformed).
            $timings = ($result['timings'] ?? []);
            if (is_array($timings) === true) {
                $this->lastRunSteps = $this->buildCoarseStepsFromTimings(
                    timings: $timings,
                    anchorEnd: new DateTimeImmutable('now', new DateTimeZone('UTC'))
                );
            }

            $output = (string) ($result['message'] ?? '');
            return $this->applyOutputGuardrail(policy: $guardrailPolicy, output: $output);
        } finally {
            // Restore the pre-impersonation identity (OpenConnector #1006 pattern).
            $this->userSession->setUser($priorUser);
        }//end try

    }//end runAgentAsOwner()

    /**
     * Apply the effective GuardrailPolicy's output filter at this method's
     * single return point — the ONE seam every caller (`runDue()` before
     * `DeliveryService::deliver()`; `FlowAgentRunService`/`WebhookAgentRunService`
     * before their own persistence writes) reads before delivery/persistence
     * (design.md Decision 7). Never throws: a `block` match replaces `$output`
     * with the withheld-response placeholder so the turn always completes.
     *
     * @param array<string,mixed> $policy The effective GuardrailPolicy.
     * @param string              $output The raw agent output.
     *
     * @return string The filtered (possibly placeholder) output.
     *
     * @spec openspec/changes/agent-guardrails/specs/agent-guardrails/spec.md#requirement-output-is-filtered-before-persistence-and-before-delivery
     */
    private function applyOutputGuardrail(array $policy, string $output): string
    {
        $filtered = $this->guardrailPolicyService->filterOutput(policy: $policy, text: $output);
        if ($this->guardrailActed(filter: $filtered, originalText: $output) === true) {
            $this->appendGuardrailStep(name: 'Output filter', filter: $filtered);
        }

        return (string) $filtered['text'];

    }//end applyOutputGuardrail()

    /**
     * Whether a `filterInput()`/`filterOutput()` result represents an actual
     * guardrail ACTION (a block, or a redaction that changed the text) versus a
     * no-op pass-through — mirrors `Engine::guardrailActed()`. Only an action is
     * worth a `run-history`-visible trace step (spec: "record every input
     * block, output block/redaction... as a trace step"); a fully-open policy
     * (the default, no `GuardrailPolicy` configured) must leave the step
     * timeline byte-for-byte identical to before this change.
     *
     * @param array{text:string,blocked:bool,reason:?string} $filter       The filter result.
     * @param string                                         $originalText The pre-filter text.
     *
     * @return bool
     *
     * @spec openspec/changes/agent-guardrails/specs/agent-guardrails/spec.md#requirement-every-guardrail-action-is-visible-in-run-history
     */
    private function guardrailActed(array $filter, string $originalText): bool
    {
        if ($filter['blocked'] === true) {
            return true;
        }

        return ((string) $filter['text']) !== $originalText;

    }//end guardrailActed()

    /**
     * Append a `guardrail` step onto `$this->lastRunSteps` (run-trace-observability),
     * so a filter block/redaction on the ScheduleService-owned legacy/defense-in-depth
     * seams is visible in run history exactly like the in-app Engine's own
     * RunTraceCollector steps — no new logging or audit mechanism. Only called
     * when `guardrailActed()` is true — see that method's docblock.
     *
     * @param string                                         $name   A human-readable step name
     *                                                               ("Input filter"|"Output
     *                                                               filter").
     * @param array{text:string,blocked:bool,reason:?string} $filter The filter result.
     *
     * @return void
     *
     * @spec openspec/changes/agent-guardrails/specs/agent-guardrails/spec.md#requirement-every-guardrail-action-is-visible-in-run-history
     */
    private function appendGuardrailStep(string $name, array $filter): void
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $outcome = 'redacted';
        if ($filter['blocked'] === true) {
            $outcome = 'blocked';
        }

        $this->lastRunSteps[] = [
            'seq'        => count($this->lastRunSteps),
            'type'       => 'guardrail',
            'name'       => $name,
            'startedAt'  => $now->format('c'),
            'endedAt'    => $now->format('c'),
            'durationMs' => 0,
            'outcome'    => $outcome,
        ];

    }//end appendGuardrailStep()

    /**
     * Whether the in-app agent engine feature flag (`hermiq`.`engine.enabled`)
     * is on. Defaults to 'false' — existing installs see zero behavior change.
     *
     * @return bool True when the in-app Engine must be used.
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-6-1
     */
    private function isEngineEnabled(): bool
    {
        return $this->appConfig->getValueString(Application::APP_ID, self::ENGINE_FLAG_KEY, 'false') === 'true';

    }//end isEngineEnabled()

    /**
     * Resolve the identity to impersonate for an agent turn: the Agent's
     * `actingUser` when set and valid, otherwise the schedule owner.
     *
     * Reads the hermiq-register `agent` object system-wide (`_rbac`/`_multitenancy`
     * off — mirrors `findDueSchedules()`/`loadEngagedOrganisations()`: no user is
     * impersonated yet at this point in the call chain). A missing agent, a read
     * failure, an unset `actingUser`, or an `actingUser` that does not resolve to an
     * existing, ENABLED NC user all fail open to `$fallbackOwner` (logged at
     * `warning` for the invalid-override cases) — a misconfigured profile field must
     * never brick a schedule (agent-capability-profile).
     *
     * @param string $agentId       The bound agent UUID (hermiq register `agent` object).
     * @param string $fallbackOwner The schedule owner UID to fall back to.
     *
     * @return string The resolved run-as user id.
     *
     * @spec openspec/changes/agent-capability-profile/tasks.md#task-3-1
     */
    private function resolveActingUser(string $agentId, string $fallbackOwner): string
    {
        try {
            $agent = $this->objectService->find(
                id: $agentId,
                register: self::REGISTER_SLUG,
                schema: self::AGENT_SCHEMA,
                _rbac: false,
                _multitenancy: false
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                sprintf('Hermiq could not resolve actingUser for agent %s: %s', $agentId, $e->getMessage()),
                ['exception' => $e]
            );
            return $fallbackOwner;
        }

        if ($agent === null) {
            return $fallbackOwner;
        }

        $actingUser = (string) ($agent->getObject()['actingUser'] ?? '');
        if (trim($actingUser) === '') {
            return $fallbackOwner;
        }

        $candidate = $this->userManager->get($actingUser);
        if ($candidate === null || $candidate->isEnabled() === false) {
            $this->logger->warning(
                sprintf(
                    "Hermiq agent %s declares actingUser '%s', which is not an existing, active user — falling back to the schedule owner.",
                    $agentId,
                    $actingUser
                )
            );
            return $fallbackOwner;
        }

        return $actingUser;

    }//end resolveActingUser()

    /**
     * Run the agent turn through the in-app Engine against hermiq-register
     * objects (feature-flag ON path of runAgentAsOwner()).
     *
     * Resolves the agent as a hermiq-register `agent` object via ObjectService
     * (NOT AgentMapper — with the flag on no OR chat table is touched), creates
     * a `conversation` object bound to it, and calls Engine::processMessage().
     * The per-run `usage` capture into $this->lastRunUsage is identical to the
     * flag-off path so run-analytics never loses cost data (spec scenario:
     * usage shape must survive). Callers hold the impersonation (schedule owner,
     * or the Agent's `actingUser` when set/valid — agent-capability-profile).
     *
     * @param string $owner   The identity to run as (schedule owner, or the
     *                        resolved `actingUser` — see resolveActingUser()).
     * @param string $agentId The bound agent UUID (hermiq register `agent` object).
     * @param string $prompt  The prompt to run.
     *
     * @return string The agent's response text.
     *
     * @throws RuntimeException When the agent cannot be resolved in the hermiq register.
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-6-2
     * @spec openspec/changes/agent-capability-profile/tasks.md#task-3-3
     */
    private function runAgentViaEngine(string $owner, string $agentId, string $prompt): string
    {
        $agent = $this->objectService->find(
            id: $agentId,
            register: self::REGISTER_SLUG,
            schema: self::AGENT_SCHEMA
        );
        if ($agent === null) {
            throw new RuntimeException("Agent '{$agentId}' does not exist in the hermiq register");
        }

        $conversation = $this->objectService->saveObject(
            object: [
                'title'   => 'Hermiq scheduled run',
                'userId'  => $owner,
                'agentId' => (string) $agent->getUuid(),
            ],
            register: self::REGISTER_SLUG,
            schema: self::CONVERSATION_SCHEMA
        );

        // Run-trace-observability: the in-app Engine path is the ONLY path Hermiq
        // instruments fine-grained tool-call steps on (agent-engine-port ownership
        // boundary) — thread a fresh collector through the SAME call chain
        // StreamYieldChannel already uses.
        $trace  = new RunTraceCollector();
        $result = $this->engine->processMessage(
            conversationId: (string) $conversation->getUuid(),
            userId: $owner,
            userMessage: $prompt,
            trace: $trace
        );

        // Capture the LLM token/latency usage identically to the flag-off path, so
        // writeRunAudit records it for run-analytics (run-cost recording). The
        // defensive shape checks mirror the flag-off path so a future engine
        // return-shape change can never silently break run-cost recording (hence
        // the phpstan ignores on the shape-narrowed reads).
        // @phpstan-ignore-next-line -- deliberate defensive fallback, see above.
        $usage = ($result['usage'] ?? []);
        if (is_array($usage) === true) {
            $this->lastRunUsage = $usage;
        }

        // Prefer the collector's own record (this call owns it end-to-end); fall
        // back to the envelope's `steps` key (identical content, per Engine's
        // contract) only if a future engine swap ever stops accepting `$trace`.
        $this->lastRunSteps = $trace->toArray();
        if ($this->lastRunSteps === []) {
            // @phpstan-ignore-next-line -- deliberate defensive fallback, see above.
            $envelopeSteps = ($result['steps'] ?? []);
            if (is_array($envelopeSteps) === true) {
                $this->lastRunSteps = $envelopeSteps;
            }
        }

        // @phpstan-ignore-next-line -- deliberate defensive fallback, see above.
        return (string) ($result['message'] ?? '');

    }//end runAgentViaEngine()

    /**
     * Delivery seam — delegates to the real DeliveryService (talk-delivery).
     *
     * Replaces the former logging-only no-op. DeliveryService performs the actual
     * Talk/notification delivery and NEVER throws for a delivery problem: it returns a
     * DeliveryResult carrying any warning to persist as lastDeliveryError, so a failed
     * delivery can never fail the run.
     *
     * @param string       $channel  Delivery channel: talk|notification|none.
     * @param string       $output   The agent output to deliver.
     * @param ObjectEntity $schedule The schedule the output belongs to.
     *
     * @return DeliveryResult The delivery outcome (warning ⇒ lastDeliveryError).
     *
     * @spec openspec/changes/talk-delivery/tasks.md#task-3-1
     */
    private function deliver(string $channel, string $output, ObjectEntity $schedule): DeliveryResult
    {
        return $this->deliveryService->deliver(
            channel: $channel,
            output: $output,
            schedule: $schedule
        );

    }//end deliver()

    /**
     * Append a `delivery` step onto `$this->lastRunSteps` (run-trace-observability),
     * timed around the `deliver()` call that just resolved.
     *
     * Outcome is `error` exactly when `DeliveryResult::getWarning()` is non-null —
     * a deliberate no-op delivery (channel `none`/empty output) carries no warning
     * and is recorded as `ok`, matching `applySuccessOutcome()`'s existing
     * "no warning ⇒ no `lastDeliveryError`" contract.
     *
     * @param DateTimeImmutable $startedAt The moment the `deliver()` call began.
     * @param DeliveryResult    $delivery  The delivery outcome.
     *
     * @return void
     *
     * @spec openspec/changes/run-trace-observability/tasks.md#task-3-scheduleservice-captures-steps-and-includes-them-in-the-run-audit-write
     */
    private function appendDeliveryStep(DateTimeImmutable $startedAt, DeliveryResult $delivery): void
    {
        $endedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $outcome = 'ok';
        if ($delivery->getWarning() !== null) {
            $outcome = 'error';
        }

        $this->lastRunSteps[] = [
            'seq'        => count($this->lastRunSteps),
            'type'       => 'delivery',
            'name'       => 'Talk delivery',
            'startedAt'  => $startedAt->format('c'),
            'endedAt'    => $endedAt->format('c'),
            // Whole-second precision, mirroring writeRunAudit()'s own durationMs.
            'durationMs' => (((int) $endedAt->format('U') - (int) $startedAt->format('U')) * 1000),
            'outcome'    => $outcome,
        ];

    }//end appendDeliveryStep()

    /**
     * Owner failure-alert seam (run-reliability) — delegates to DeliveryService,
     * defensively wrapped so a delivery-layer surprise can NEVER escape into the
     * dispatch tick (DeliveryService already promises never to throw; this is
     * defense-in-depth, mirroring the try/catch already around the budget
     * soft-threshold check in dispatch()).
     *
     * @param ObjectEntity $schedule The dead-lettered schedule.
     * @param string       $reason   The failure reason (the last agent-turn error).
     *
     * @return void
     *
     * @spec openspec/changes/run-reliability/specs/talk-delivery/spec.md#requirement-deliver-a-failure-alert-to-the-schedule-owner-mvp
     */
    private function safeDeliverFailureAlert(ObjectEntity $schedule, string $reason): void
    {
        try {
            $this->deliveryService->deliverFailureAlert(schedule: $schedule, reason: $reason);
        } catch (Throwable $e) {
            $this->logger->warning(
                sprintf('Hermiq dead-letter alert failed for %s: %s', (string) $schedule->getUuid(), $e->getMessage()),
                ['exception' => $e]
            );
        }

    }//end safeDeliverFailureAlert()

    /**
     * Circuit-breaker auto-pause alert seam (run-reliability) — see
     * safeDeliverFailureAlert() for the defensive-wrapping rationale.
     *
     * @param ObjectEntity $schedule The auto-paused schedule.
     *
     * @return void
     *
     * @spec openspec/changes/run-reliability/specs/talk-delivery/spec.md#requirement-deliver-a-failure-alert-to-the-schedule-owner-mvp
     */
    private function safeDeliverCircuitBreakerAlert(ObjectEntity $schedule): void
    {
        try {
            $this->deliveryService->deliverCircuitBreakerAlert(schedule: $schedule);
        } catch (Throwable $e) {
            $this->logger->warning(
                sprintf('Hermiq circuit-breaker alert failed for %s: %s', (string) $schedule->getUuid(), $e->getMessage()),
                ['exception' => $e]
            );
        }

    }//end safeDeliverCircuitBreakerAlert()

    /**
     * Last-resort failure recorder for exceptions that escape dispatch()'s own
     * try/catch (e.g. a failure during the commit-before-run write itself).
     *
     * The agent-turn failure is handled inside dispatch() on the post-commit $data,
     * so this path should essentially never fire. When it does, it MUST NOT revert
     * the committed run-state: it re-fetches the FRESH object (which already carries
     * any advanced nextRun / disabled one-shot / bumped repeat that a prior persist
     * committed) and overwrites only `lastStatus`/`lastError` — it never operates on
     * the stale pre-commit in-memory entity (BUG 4). If the object cannot be
     * re-fetched, it logs and skips rather than clobbering with stale data.
     * Best-effort: a persistence failure here is logged, not re-thrown.
     *
     * @param ObjectEntity $schedule The schedule that failed.
     * @param Throwable    $error    The captured failure.
     *
     * @return void
     *
     * @spec openspec/changes/agent-schedule-dispatcher/tasks.md#task-3-7
     */
    private function recordFailure(ObjectEntity $schedule, Throwable $error): void
    {
        $this->logger->warning(
            sprintf('Hermiq schedule %s failed: %s', (string) $schedule->getUuid(), $error->getMessage()),
            ['exception' => $error]
        );

        try {
            $uuid  = (string) $schedule->getUuid();
            $fresh = $this->objectService->find(
                id: $uuid,
                register: self::REGISTER_SLUG,
                schema: self::SCHEMA_SLUG,
                _rbac: false,
                _multitenancy: false
            );

            if ($fresh === null) {
                // Do not clobber committed run-state with a stale pre-commit copy.
                $this->logger->error(
                    sprintf('Hermiq could not re-fetch schedule %s to record failure; skipping.', $uuid)
                );
                return;
            }

            $data = $fresh->getObject();
            $data['lastStatus'] = 'error';
            $data['lastError']  = $error->getMessage();
            $this->persist(schedule: $fresh, data: $data);
        } catch (Throwable $e) {
            $this->logger->error(
                'Hermiq could not record schedule failure: '.$e->getMessage(),
                ['exception' => $e]
            );
        }//end try

    }//end recordFailure()

    /**
     * Persist a schedule payload through OpenRegister's single write-path.
     *
     * Every date-time field is re-normalised to ISO-8601 (`format('c')`) first, so
     * a full-object save always passes the schema's `date-time` format — OR's
     * getObject() hands date-times back as `Y-m-d H:i:s` (space, no `T`), which the
     * schema rejects. Applied here so ALL callers (success + failure) are covered.
     *
     * @param ObjectEntity        $schedule The schedule being updated.
     * @param array<string,mixed> $data     The new object payload.
     *
     * @return void
     *
     * @spec openspec/changes/agent-schedule-dispatcher/tasks.md#task-3-3
     */
    private function persist(ObjectEntity $schedule, array $data): void
    {
        $this->objectService->saveObject(
            object: $this->sanitizeForSave(data: $data),
            register: self::REGISTER_SLUG,
            schema: self::SCHEMA_SLUG,
            uuid: (string) $schedule->getUuid(),
            _rbac: false,
            _multitenancy: false
        );

    }//end persist()

    /**
     * Neutralise OpenRegister read-modify-write round-trip artifacts before a save.
     *
     * The dispatcher reads a whole object via getObject(), edits a few fields, and
     * saves the whole array back — so OR re-validates the ENTIRE payload against the
     * schema, including fields OR itself materialised on read in a shape the schema
     * rejects. This single seam repairs the artifacts on the fields this change owns
     * (`nextRun`/`runAt` date-time format, and the nullable `repeat` object) so a
     * full-object save passes OR's own validation. It repairs OR's own read-side
     * shape only — it does not mask genuinely user-supplied invalid data.
     *
     * @param array<string,mixed> $data The payload about to be saved.
     *
     * @return array<string,mixed> The sanitised payload.
     *
     * @spec openspec/changes/agent-schedule-dispatcher/tasks.md#task-3-3
     * @spec openspec/changes/agent-schedule-dispatcher/tasks.md#task-3-6
     */
    private function sanitizeForSave(array $data): array
    {
        $data = $this->normaliseDates(data: $data);
        $data = $this->sanitizeRepeat(data: $data);
        $data = $this->sanitizeRetryState(data: $data);
        return $data;

    }//end sanitizeForSave()

    /**
     * Normalise all date-time fields in a schedule payload to ISO-8601 with `T`.
     *
     * Reparses each present `date-time` field (from any parseable form, notably OR's
     * `Y-m-d H:i:s`) and rewrites it with `format('c')`. Null/empty values are left
     * as-is; genuinely unparseable values are left untouched so validation surfaces
     * them rather than this method silently masking a bad payload.
     *
     * @param array<string,mixed> $data The payload about to be saved.
     *
     * @return array<string,mixed> The payload with normalised date-time fields.
     *
     * @spec openspec/changes/agent-schedule-dispatcher/tasks.md#task-3-3
     */
    private function normaliseDates(array $data): array
    {
        foreach (self::DATE_TIME_FIELDS as $field) {
            if (array_key_exists($field, $data) === false) {
                continue;
            }

            $value = $data[$field];
            if ($value === null || $value === '') {
                continue;
            }

            $parsed = $this->parseDate(value: (string) $value);
            if ($parsed !== null) {
                $data[$field] = $parsed->format('c');
            }
        }

        return $data;

    }//end normaliseDates()

    /**
     * Sanitise the nullable `repeat` object against its schema constraints.
     *
     * `repeat` is optional: a schedule with no valid finite-repeat is INFINITE and
     * must serialise as `repeat = null`. OR's getObject() materialises the nullable
     * object as `{"times": 0, "completed": 0}` on read, which then fails the schema's
     * `repeat.times` `minimum: 1` when the whole object is saved back. This collapses
     * any non-finite repeat (missing, non-array, or `times < 1`) to `null`, and keeps
     * a genuine finite repeat only when `times >= 1` (with `completed` coerced to an
     * int >= 0). It repairs OR's round-trip artifact, not user intent: a user who
     * wants a finite repeat supplies `times >= 1`, which is preserved verbatim.
     *
     * @param array<string,mixed> $data The payload about to be saved.
     *
     * @return array<string,mixed> The payload with a schema-valid `repeat`.
     *
     * @spec openspec/changes/agent-schedule-dispatcher/tasks.md#task-3-6
     */
    private function sanitizeRepeat(array $data): array
    {
        if (array_key_exists('repeat', $data) === false) {
            return $data;
        }

        $repeat = $data['repeat'];
        if (is_array($repeat) === false || isset($repeat['times']) === false || is_numeric($repeat['times']) === false) {
            $data['repeat'] = null;
            return $data;
        }

        $times = (int) $repeat['times'];
        if ($times < 1) {
            // A times value below 1 means an infinite schedule; drop the artifact.
            $data['repeat'] = null;
            return $data;
        }

        $completed = 0;
        if (isset($repeat['completed']) === true && is_numeric($repeat['completed']) === true) {
            $completed = max(0, (int) $repeat['completed']);
        }

        $data['repeat'] = [
            'times'     => $times,
            'completed' => $completed,
        ];

        return $data;

    }//end sanitizeRepeat()

    /**
     * Sanitise the nullable `retryState` object against its schema constraints
     * (run-reliability) — mirrors sanitizeRepeat()'s OR round-trip repair.
     *
     * `retryState` is optional: no open retry sequence serialises as `null`. OR's
     * getObject() may materialise the nullable object as `{}` (or an incomplete
     * shape) on read, which this collapses back to `null`, and re-normalises a
     * genuine retry state's `nextAttemptAt` to ISO-8601 (the same round-trip
     * artifact `nextRun`/`runAt` have — see normaliseDates()).
     *
     * @param array<string,mixed> $data The payload about to be saved.
     *
     * @return array<string,mixed> The payload with a schema-valid `retryState`.
     *
     * @spec openspec/changes/run-reliability/specs/agent-schedule/spec.md#requirement-per-schedule-opt-in-bounded-retry-with-exponential-backoff-mvp
     */
    private function sanitizeRetryState(array $data): array
    {
        if (array_key_exists('retryState', $data) === false) {
            return $data;
        }

        $state = $this->normaliseRetryState(raw: $data['retryState']);
        if ($state === null) {
            $data['retryState'] = null;
            return $data;
        }

        $parsed        = $this->parseDate(value: $state['nextAttemptAt']);
        $nextAttemptAt = $state['nextAttemptAt'];
        if ($parsed !== null) {
            $nextAttemptAt = $parsed->format('c');
        }

        $data['retryState'] = [
            'attempt'       => $state['attempt'],
            'nextAttemptAt' => $nextAttemptAt,
        ];

        return $data;

    }//end sanitizeRetryState()

    /**
     * Normalise a raw `retryState` value into a `{attempt:int, nextAttemptAt:string}`
     * shape, or `null` when it is absent/invalid (run-reliability).
     *
     * @param mixed $raw The raw retryState value from the schedule payload.
     *
     * @return array{attempt:int, nextAttemptAt:string}|null The normalised state, or null.
     *
     * @spec openspec/changes/run-reliability/specs/agent-schedule/spec.md#requirement-per-schedule-opt-in-bounded-retry-with-exponential-backoff-mvp
     */
    private function normaliseRetryState(mixed $raw): ?array
    {
        if (is_array($raw) === false) {
            return null;
        }

        $attempt       = ($raw['attempt'] ?? null);
        $nextAttemptAt = ($raw['nextAttemptAt'] ?? null);
        if (is_numeric($attempt) === false || empty($nextAttemptAt) === true) {
            return null;
        }

        return [
            'attempt'       => (int) $attempt,
            'nextAttemptAt' => (string) $nextAttemptAt,
        ];

    }//end normaliseRetryState()

    /**
     * Clamp an integer-ish value into an inclusive [min, max] range (run-reliability).
     *
     * @param mixed $value The raw value (schema already bounds it; this is defense-in-depth).
     * @param int   $min   The inclusive lower bound.
     * @param int   $max   The inclusive upper bound.
     *
     * @return int The clamped integer.
     *
     * @spec exclude Pure arithmetic helper; no independent behavioural spec.
     */
    private function clampInt(mixed $value, int $min, int $max): int
    {
        $int = (int) $value;
        if ($int < $min) {
            return $min;
        }

        if ($int > $max) {
            return $max;
        }

        return $int;

    }//end clampInt()

    /**
     * Delete a schedule that has reached its repeat limit.
     *
     * @param ObjectEntity $schedule The schedule to delete.
     *
     * @return void
     *
     * @spec openspec/changes/agent-schedule-dispatcher/tasks.md#task-3-6
     */
    private function deleteSchedule(ObjectEntity $schedule): void
    {
        $this->objectService->deleteObject(
            uuid: (string) $schedule->getUuid(),
            register: self::REGISTER_SLUG,
            schema: self::SCHEMA_SLUG,
            _rbac: false,
            _multitenancy: false
        );

    }//end deleteSchedule()

    /**
     * Resolve the timezone to anchor next-run computation to.
     *
     * Uses the owner's configured Nextcloud timezone; falls back to the instance
     * default timezone, and finally UTC, when the owner has none (decision locked).
     *
     * @param string $owner The owner UID.
     *
     * @return DateTimeZone The resolved timezone.
     *
     * @spec openspec/changes/agent-schedule-dispatcher/tasks.md#task-3-2
     */
    private function resolveTimezone(string $owner): DateTimeZone
    {
        $tz = '';
        if ($owner !== '') {
            $tz = (string) $this->config->getUserValue($owner, 'core', 'timezone', '');
        }

        if ($tz === '') {
            $tz = (string) $this->config->getSystemValueString('default_timezone', 'UTC');
        }

        try {
            return new DateTimeZone($tz);
        } catch (Throwable $e) {
            return new DateTimeZone('UTC');
        }

    }//end resolveTimezone()

    /**
     * Normalise the `repeat` payload to a `{times:int, completed:int}` shape.
     *
     * @param mixed $repeat The raw repeat value from the schedule.
     *
     * @return array{times:int, completed:int}
     *
     * @spec openspec/changes/agent-schedule-dispatcher/tasks.md#task-3-6
     */
    private function normaliseRepeat(mixed $repeat): array
    {
        if (is_array($repeat) === false) {
            $repeat = [];
        }

        return [
            'times'     => (int) ($repeat['times'] ?? 0),
            'completed' => (int) ($repeat['completed'] ?? 0),
        ];

    }//end normaliseRepeat()

    /**
     * Parse a stored ISO-8601 timestamp into a UTC DateTimeImmutable.
     *
     * @param string $value The stored timestamp (may be empty).
     *
     * @return DateTimeImmutable|null The parsed UTC moment, or null when unparseable.
     */
    private function parseDate(string $value): ?DateTimeImmutable
    {
        if ($value === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'));
        } catch (Throwable $e) {
            return null;
        }

    }//end parseDate()
}//end class
