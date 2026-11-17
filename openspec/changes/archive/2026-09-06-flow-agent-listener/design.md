# Design: flow-agent-listener

> Umbrella decisions apply (SPECTR-NEXTCLOUD-PLAN.md §5.2).

## Approach

Three new classes, thin-wrapper-to-service (ADR-002):

```
OpenRegister FlowActionService (type:"agent")
   -> dispatchTyped(AgentRunRequestedEvent)
        -> Hermiq AgentRunRequestedListener::handle()   [fast: mode check + enqueue]
             -> IJobList::add(AgentRunRequestedJob, payload)
                  -> AgentRunRequestedJob::run($payload)  [thin wrapper]
                       -> FlowAgentRunService::run($payload)  [all governed logic]
```

The listener is intentionally NOT where the agent runs — `mode: "async"`
(SPECTR-NEXTCLOUD-PLAN.md §5.2 point 5) means the triggering OpenRegister
save/request must never block on an LLM call. `IJobList::add()` enqueues a
one-shot `QueuedJob`; the actual run happens off-request, on the next
background-job tick — "classification lands seconds later," not synchronously.

## Event contract (copied verbatim from the OpenRegister `flow-agent-action` design.md — MUST match)

`OCA\OpenRegister\Event\AgentRunRequestedEvent`:

| Field | Type | Notes |
|---|---|---|
| `subjectUuid` | string | The triggering object's UUID. |
| `subjectRegister` | string | Register slug/id. |
| `subjectSchema` | string | Schema slug/id. |
| `agent` | string | Agent UUID (v1: UUID only). |
| `skill` | string\|null | Optional skill slug. |
| `prompt` | string | Fully-rendered (placeholders already resolved by OR). |
| `resultField` | string | The object field the run's output is written to. |
| `requiresApproval` | bool | Default `false`. |
| `mode` | string | `"async"` only in v1. |
| `flowName` | string | Diagnostics/audit only. |
| `correlationId` | string | Generated per dispatch; used for approval idempotency. |

`getPayload(): array` flattens all of the above to a plain, JSON-serialisable
array — this is the shape carried across the `IJobList` argument boundary (job
arguments are JSON-encoded; the event object itself cannot cross it) and the
shape `FlowAgentRunService::run(array $payload, …)` consumes throughout.

Hermiq references OR's real event class directly
(`use OCA\OpenRegister\Event\AgentRunRequestedEvent;` in
`AgentRunRequestedListener.php` and `Application.php`) — no `class_exists()` guard,
since OpenRegister is already a hard dependency of Hermiq (mirrors the existing
`DeepLinkRegistrationEvent` registration). For hermiq's OWN standalone PHPUnit
suite (no live OpenRegister — `tests/bootstrap.php` skips NC bootstrap when no
server tree is present), a lightweight local copy lives at
`tests/Stubs/Event/AgentRunRequestedEvent.php`, matching the real class's public
constructor + `getPayload()` shape (the `OCA\OpenRegister\` autoload-dev PSR-4
mapping already points at `tests/Stubs/`).

## Governance wiring — reusing ScheduleService's rails, not re-implementing them

The plan explicitly asks for "the same ScheduleService/Engine path scheduled runs
use." Two options were considered:

1. **Duplicate ScheduleService's gate/dispatch logic inside a new, independent
   service.** Rejected — two copies of kill-switch/approval/agent-invocation logic
   drift over time (e.g. the `engine.enabled` feature-flag dual path
   `ScheduleService::runAgentAsOwner()`/`runAgentViaEngine()` implements would need
   a second, parallel implementation that could silently diverge).
2. **Reuse `ScheduleService`'s methods directly (chosen).** Two methods are
   widened from `private` to `public`:
   - `ScheduleService::isOrganisationEngaged(string $organisation): bool` — a new
     public wrapper around the existing private `loadEngagedOrganisations()`
     query (unchanged internally; the tick's own per-tick batch load is untouched).
   - `ScheduleService::runAgentAsOwner(string $owner, string $agentId, string $prompt): string` —
     already had exactly the signature `FlowAgentRunService` needs (impersonate →
     dispatch through the `engine.enabled`-flagged branch → return output). No
     internal change; only the visibility keyword moved.

   `FlowAgentRunService` calls these two methods and nothing else on
   `ScheduleService` — it does NOT touch Schedule-object persistence, repeat
   accounting, or delivery (a flow-triggered run has no "schedule" and its output
   goes to `resultField`, not Talk/notification). This keeps the reuse narrow and
   auditable: a flow-triggered run gets identical kill-switch behaviour and
   identical agent-engine routing to a scheduled run, with zero duplicated
   branching logic.

GATE 2 (human approval) is reused similarly: `ApprovalService` already owns the
`Approval` OR schema, the reviewer-resolution idiom, and the redacted
decision-audit write-path. Rather than a second approval object model,
`ApprovalService` is generalised with a `sourceType` discriminator
(`"schedule"` | `"flow"`, defaulting to `"schedule"` for back-compatibility with
existing data) and a `flowContext` field carrying the flow-run's resume payload.
`approve()` branches on `sourceType` to call either `ScheduleService::runNow()`
(unchanged) or the new `FlowAgentRunService::run(payload: $flowContext,
bypassApprovalGate: true)`.

## Agent reference resolution (v1 scope decision)

The plan's example config writes `"agent": "<agent-uuid-or-slug>"`. OpenRegister's
`Agent` entity has **no `slug` field** at HEAD — only `uuid`/`name`/`owner`/etc.
`FlowAgentRunService::resolveAgent()` therefore resolves the reference as a UUID
only (`AgentMapper::findByUuid()`), returning `null` (skip + log) on any failure.
A future OR change adding a `slug` field to `Agent` would extend this resolver;
inventing a name-based fallback now was rejected as speculative — Agent has no
"slug" concept to fall back to, and matching by `name` would silently break on
duplicate agent names (no uniqueness constraint).

## Acting-user resolution (v1 scope decision)

SPECTR-NEXTCLOUD-PLAN.md §6.3 (per-agent capability profile) defines an
`actingUser` field — "the NC service account the agent acts as... Default remains
'schedule owner' (current impersonation behavior)." That field does not exist yet
(§6.3 is still unimplemented). For a flow-triggered run there is no "schedule
owner" to default to, so the closest existing analogue is used: the resolved
`Agent`'s own `owner` field (already on the `Agent` entity today). When §6.3 lands,
`FlowAgentRunService` should read `actingUser` first, falling back to `owner` —
tracked as follow-up, not blocking this change.

## Skill reference (v1 scope decision)

The event carries an optional `skill` slug. SPECTR-NEXTCLOUD-PLAN.md §6.2/§6.6
describes skills becoming `hermiq.skill.{slug}` TOOLS once installed on an agent
(skills-catalog) — there is no runtime skill-injection parameter on
`ScheduleService::runAgentAsOwner()`/`ChatService::processMessage()` today. Rather
than block this change on that (separate, larger) capability-profile work, a
present `skill` is surfaced to the model as a prompt directive
(`"[skill: {slug}] {prompt}"`). This is an honest, bounded v1 behaviour — full
skill routing is explicitly deferred, not silently dropped.

## Approval schema generalisation (back-compatible)

`hermiq_register.json`'s `Approval` schema (v0.1.0 → v0.2.0):
- `required` relaxed from `["status", "scheduleId", "agentId", "requestedAt"]` to
  `["status", "agentId", "requestedAt"]` — dropping a required field is always
  backward-compatible (every existing valid Approval already had `scheduleId` set).
- New optional `sourceType` (enum `schedule`|`flow`, default `schedule`),
  `correlationId` (string), `flowContext` (object) — additive, so existing
  Approval objects (all implicitly `sourceType: "schedule"` via the default) keep
  validating and keep working through the unmodified schedule branch.

## Failure isolation

`FlowAgentRunService::run()` wraps its entire dispatch in try/catch and returns
`bool`, never throwing — symmetric with `ScheduleService::run()`'s per-schedule
isolation. `AgentRunRequestedListener::handle()` similarly never throws (a
`IJobList::add()` failure is logged, not propagated) so a broken Hermiq
installation can never make OpenRegister's `dispatchTyped()` call fail the
triggering save. When Hermiq is absent entirely, OpenRegister's dispatch is a
silent no-op (no listener registered) — existing objects keep flowing
(SPECTR-NEXTCLOUD-PLAN.md §5.2 point 4).

## Files Affected

### Backend (new)
- `lib/Listener/AgentRunRequestedListener.php`
- `lib/BackgroundJob/AgentRunRequestedJob.php`
- `lib/Service/FlowAgentRunService.php`
- `tests/Stubs/Event/AgentRunRequestedEvent.php`
- `tests/Unit/Listener/AgentRunRequestedListenerTest.php`
- `tests/Unit/Service/FlowAgentRunServiceTest.php`

### Backend (modified)
- `lib/AppInfo/Application.php` — registers the new listener.
- `lib/Service/ScheduleService.php` — `isOrganisationEngaged()` new public method;
  `runAgentAsOwner()` visibility widened `private` → `public` (no behavioural change).
- `lib/Service/ApprovalService.php` — `sourceType` tagging, new
  `ensurePendingApprovalForFlowRun()`/`findPendingApprovalForCorrelation()`/
  `runApprovedFlowRun()`, `approve()` branches on `sourceType`.
- `lib/Service/DeliveryService.php` — new `deliverApprovalRequestForFlowRun()`.
- `lib/Notification/Notifier.php` — approval-requested wording made source-agnostic
  ("an agent run" instead of "a scheduled agent run" — the notification path is now
  shared by both sources).
- `lib/Settings/hermiq_register.json` — Approval schema v0.2.0 (see above); title/
  description added to all remaining under-specced schemas (gate-28 scope is the
  whole touched file).
- `tests/Stubs/Db/Agent.php` — `uuid`/`owner` getters/setters added.
- `tests/Unit/Service/ScheduleServiceTest.php`, `ApprovalServiceTest.php`,
  `DeliveryServiceTest.php` — new test coverage for the above.

## Risks

| Risk | Mitigation |
|---|---|
| Payload shape drifts between the two repos (OR emits, Hermiq listens) | Both `design.md`s carry the identical field table; the stub event class mirrors the real one's constructor/`getPayload()` exactly. A future shared-contract-test is a reasonable follow-up but out of scope for this change (no cross-repo test harness exists today). |
| Widening `ScheduleService` methods to `public` invites unintended external callers | Both newly-public methods have narrow, well-documented contracts already exercised by existing ScheduleService tests; no new caller exists outside `FlowAgentRunService`. |
| A flow author sets `requiresApproval: true` with an agent that has no `owner` | The approval-gate path resolves the agent BEFORE creating the pending Approval so the reviewer can still default to the `admin` group even when no owner exists — the gate still fires; only the reviewer default changes. |
| Touching `hermiq_register.json` for one schema exposes gate-28 debt across nine unrelated schemas | Fixed in the same batch (see tasks.md) rather than leaving `schema-property-titles` red for an unrelated reason. |
