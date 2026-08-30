# Proposal: cost-guardrails

## Summary
Adds per-organisation and per-agent budget guardrails to Hermiq: a soft threshold that warns via
notification and a hard cap that blocks *new* runs (never kills a run already in progress), plus a
pre-run rough cost estimate surfaced in "Run now" and schedule creation. Budgets are OpenRegister
objects following Hermiq's existing OR-object pattern (like `TenantControl`); enforcement lands in
`ScheduleService::dispatch()` alongside the existing kill-switch and human-approval gates, and the
estimate is derived from `AnalyticsService`'s existing per-agent token/cost aggregation — no new
telemetry pipeline.

## Motivation
Runaway-cost anxiety is the single strongest signal in Hermiq's competitive research: insight #1048
(`opportunity`, impact=high) documents a 100x token multiplier at 200 steps and a real $4,200 weekend
bill from unattended agent loops, and names per-agent soft-alert + hard-cutoff caps plus a pre-run
cost estimate as the exact gap to close. Insight #1064 (`competitive-gap`, impact=high) contrasts
Hermiq's flat self-hosted cost against every hosted rival's opaque, spike-prone consumption metering
(tokens/credits/messages/conversations) — a concrete TCO wedge in every sales conversation, but only
if Hermiq can actually show and cap spend. Insight #1054 (`competitive-gap`, impact=medium) notes that
self-host tools generally give *no* per-run cost/token visibility or spend cap, and that Hermiq
already has the raw ingredient (run analytics) to turn this into a differentiator by adding the
missing quota-pause guard.

Hermiq already records per-run token usage in `AnalyticsService::computeAnalytics()` and already has
a proven synchronous hard-gate pattern in `ScheduleService::dispatch()` (kill-switch, then human
approval). Cost guardrails is the same shape of control, scoped to budget instead of an on/off switch
or an approval requirement — it reuses both seams rather than inventing a new enforcement path.

## Affected Projects
- [x] Project: `hermiq` — new `Budget` OR object (org- and/or agent-scoped, tokens and/or EUR per
  period), `BudgetService` (status computation + threshold checks + estimate), a new dispatch gate in
  `ScheduleService`/`FlowAgentRunService`, budget status endpoints, and budget UI on TenantOps +
  AgentDetail + Run-now/schedule-creation.

## Scope

### In Scope
- A `Budget` OpenRegister object: scope (`organisation` or `agent`), period (`daily`/`weekly`/
  `monthly`), a token limit and/or an EUR limit, a soft-threshold percentage (default 80%), and derived
  fields for current-period usage and status — modelled on the existing `TenantControl` schema pattern
  (declarative entry in `lib/Settings/hermiq_register.json`, no NC DB migration).
- Soft threshold: when current-period spend crosses the threshold, a one-time-per-period notification
  is sent to the budget owner/org admin via the existing `DeliveryService` (same Talk/Notification
  dialect as the approval gate) — runs continue.
- Hard cap: when current-period spend reaches the limit, **new** runs for the budgeted scope are
  blocked at the same point in `ScheduleService::dispatch()` (and the equivalent gate in
  `FlowAgentRunService`) where the kill-switch and approval gates already sit — recorded as a new gate
  -skip status (`skipped_budget`), never as a silent no-op and never as an in-flight abort.
  Blocking is scoped: an org-level budget blocks all schedules in that organisation; an agent-level
  budget blocks only that agent's schedules.
- A pre-run rough cost estimate — the trailing average tokens/cost per run for that agent, computed
  from `AnalyticsService`'s existing aggregation — surfaced in the "Run now" action and at schedule
  creation, clearly labelled as an estimate (not a guarantee).
- Budget status surfaced on the TenantOps page (org-level budgets) and on AgentDetail (agent-level
  budgets), consistent with the existing quota-card pattern on TenantOps.
- Budget CRUD (create/edit/delete) scoped to org admins, following the same tenancy rules as
  `TenantControl`.

### Out of Scope
- Real-time token metering mid-run (this remains a post-run, per-run audit entry as today) — a hard
  cap can only ever block the *next* run, never abort one already executing.
- Per-tool or per-step cost breakdown (that is `run-trace-observability`'s concern).
- Billing/invoicing integration — budgets are an operational guardrail, not a metering/billing system.
- Cross-app OR-side enforcement (e.g. a hard reject at OR object-create time) — any such seam
  discovered during implementation is filed as an OR issue, not hand-implemented as cross-app RPC
  (hydra gate-27).
- Model-provider cost differences / per-provider pricing tables — the estimate uses Hermiq's own
  recorded token usage, not external pricing APIs (a fixed, admin-configurable EUR-per-1k-tokens rate
  is the simplest bridge from tokens to EUR budgets, tracked as an open question below).

## Approach
`Budget` objects are read the same way `TenantControl` is read today: a register/schema-wide,
RBAC-off query once per dispatch tick (`BudgetService::loadEngagedBudgets()`, mirroring
`loadEngagedOrganisations()`), producing a lookup of blocked organisation/agent scopes. `dispatch()`
gains a new gate between the kill-switch and the approval gate (budget exhaustion is a harder, more
absolute block than "needs a human to look at it," but softer than the kill-switch's instance-wide
intent — see design.md for the ordering rationale). Current-period usage is computed on read from the
same `action='run'` AuditTrail entries `AnalyticsService` already aggregates, windowed to the budget's
period — no new counter table, no new write path beyond the existing run-audit write. The pre-run
estimate calls a new `BudgetService::estimateNextRun(agentId)` that reuses
`AnalyticsService::computeAnalytics()`'s per-agent token/cost totals to produce a trailing average.

## New Dependencies
None.

## Impact
- **Backend**: new `lib/Service/BudgetService.php`, new `lib/Controller/BudgetController.php`, a new
  `Budget` schema in `lib/Settings/hermiq_register.json`, a new gate in
  `lib/Service/ScheduleService.php::dispatch()` and the equivalent point in
  `lib/Service/FlowAgentRunService.php`, new routes in `appinfo/routes.php`.
- **Frontend**: budget status cards on `src/views/TenantOps.vue` and `src/views/AgentDetail.vue`,
  budget CRUD UI (new modal), a cost-estimate line in the Run-now action and schedule-creation modal
  (`src/modals/ScheduleFormModal.vue`), a new `src/api/budgets.js` following the existing
  `tenantOps.js` pattern.
- **Specs**: modifies `run-analytics` (adds the estimate derivation) and `multi-tenant-ops` (adds
  budget enforcement as a tenant-ops control alongside quotas and the kill-switch).

## Cross-Project Dependencies
None. Budgets are Hermiq-local OR objects (same register, same app); no other apps-extra project
reads or writes them. If a hard reject at OpenRegister object-create time is later wanted (mirroring
the existing quota create-time-reject seam noted in `multi-tenant-ops`), that is a separate OR-side
issue, not part of this change.

## Risks

### Risk 1: EUR budgets require a token→EUR conversion Hermiq does not currently have
**Severity:** Medium — **Mitigation:** ship token-based budgets as the primary, always-available
mechanism; EUR budgets use a single admin-configurable EUR-per-1k-tokens rate (an `IAppConfig` value,
default unset/disabled) rather than per-provider pricing tables. When the rate is unset, EUR budgets
are simply unavailable and the UI communicates that plainly — never a fabricated conversion.

### Risk 2: A hard cap that is too eager could block a legitimate run on a cost estimate rather than actual overrun
**Severity:** Medium — **Mitigation:** the hard cap is evaluated strictly against *actual* recorded
usage for the current period (never the rough estimate) — the estimate is advisory-only and appears
solely in Run-now/schedule-creation UI, never in the enforcement path.

### Risk 3: Budget read adds a register/schema-wide query to every dispatch tick
**Severity:** Low — **Mitigation:** same shape and cost as the existing `loadEngagedOrganisations()`
kill-switch read already on this path every tick; a read failure fails open (log + treat as
"no budget blocked") exactly as the kill-switch read does, so a transient read error never halts
every tenant's runs.

## Rollback Strategy
The new gate is additive: removing the `Budget` schema entry and the dispatch-gate check restores
byte-for-byte the current kill-switch → approval → run flow. No existing schema, endpoint, or UI is
modified in a way that isn't purely additive (TenantOps/AgentDetail gain new cards; nothing existing
is removed). Budget objects left behind after a rollback are inert (nothing reads them) and can be
cleaned up independently.

## Open Questions
- Should the admin-configurable EUR-per-1k-tokens rate be a single instance-wide value, or
  per-organisation? Provisional choice (see design.md): instance-wide `IAppConfig`, matching the
  existing `scheduleQuota`/`agentQuota` pattern in `TenantOpsService` — simplest, and per-org rate
  overrides can be added later without a breaking change.
- Should a soft-threshold notification re-fire once per period only, or every time a run crosses back
  above/below the threshold within a period? Provisional choice: once per period (avoids notification
  spam on a bursty schedule), tracked via a derived `warnedAt` field on the `Budget` object.
