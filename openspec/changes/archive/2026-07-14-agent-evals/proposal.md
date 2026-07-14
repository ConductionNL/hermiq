# Proposal: agent-evals

## Summary

Add an evaluation harness to Hermiq: named **EvalDataset**s of **EvalCase**s (a prompt plus
a deterministic assertion or an LLM-judge rubric), run on demand against a given **Agent**
as an **EvalRun**, scored automatically, and compared against the previous run for the same
dataset+agent as a **regression gate**. Each case executes through the agent's real engine
path (the same `ScheduleService`/`Engine` machinery a schedule or "Run now" uses) but in a
non-delivering mode — no Talk message, no notification is ever sent for an eval case. This
closes a genuine gap against Langfuse (evaluations + dataset management for testing),
AgentOps (evaluations & benchmarking), OpenAI AgentKit (evals + trace grading), and IBM
watsonx.governance / Monitaur (pre-deployment simulation) — all of which let an operator
verify an agent's behaviour before trusting it with real, delivered runs.

## Motivation

Hermiq operators can inspect a *single* past run's trace (run-trace-observability) and its
audit history (run-audit-log), but there is no way to ask "does this agent still behave
correctly across the 20 prompts I care about?" before or after a prompt/model/tool change.
Every rival evaluated ships some form of dataset-driven testing precisely because agent
behaviour drifts silently: a prompt edit, a model swap (tenant-model-policy already allows
switching provider/model per org), or a tool-catalogue change can regress a previously-working
agent with no signal until a real, delivered run misfires. An eval harness gives operators a
repeatable, governed way to catch that before it reaches Talk.

## Affected Projects

- [ ] Project: `hermiq` — new EvalDataset/EvalRun OpenRegister schemas, a thin run-trigger
  controller + service, LLM-judge scoring through the existing `ProviderFactory` chokepoint,
  a regression-gate comparison, and dataset/run management UI.

## Scope

### In Scope

- An **EvalDataset** OpenRegister object: name, description, and an embedded array of
  **EvalCase** entries (input prompt + expectation: contains / not-contains substring,
  a JSONPath-equals assertion against the parsed output, or a rubric for LLM-as-judge
  scoring). CRUD through the generic `createObjectStore` path (ADR-001/ADR-022), exactly
  like `Schedule`/`Agent` — no bespoke CRUD controller.
- An **EvalRun** OpenRegister object recording one execution of a dataset against an agent:
  status, per-case results (actual output, pass/fail, judge score/rationale where
  applicable), aggregate pass-rate, and the regression-gate outcome. An optional
  `agentVersionId` field is reserved for `agent-versioning` (not yet built) — see
  Cross-Project Dependencies.
- A thin `EvalRunController`/`EvalRunService` "run this dataset against this agent now"
  action (mirrors `RunNowController`/`ScheduleService::runNow()` — OpenRegister exposes no
  agent-trigger endpoint, so this is the one net-new backend action). Each case executes via
  `ScheduleService::runAgentAsOwner()` — the SAME impersonation + dual Engine/ChatService
  path a schedule tick uses — but the run never calls `DeliveryService` (non-delivering).
- Deterministic scoring (contains / not-contains / JSONPath-equals) plus LLM-as-judge scoring
  routed through `ProviderFactory` (the existing single LLM chokepoint), so tenant-model-policy,
  budgets, and the kill-switch all govern judge calls exactly as they govern any other LLM call.
- A regression gate: an EvalRun's aggregate pass-rate compared against the immediately
  preceding completed EvalRun for the same dataset+agent; a drop beyond a configured
  threshold is surfaced as a gate failure on the run record.
- UI: EvalDataset CRUD (with an inline case editor), a "Run eval" action, a per-run
  case-result table (highlighting failing cases with their actual output), and a pass-rate
  trend across a dataset+agent's run history.
- Kill-switch and budget hard-cap gates apply to an eval run exactly as they apply to a
  schedule tick (`ScheduleService::isOrganisationEngaged()`, `BudgetService::isBlocked()`) —
  eval spend rolls into the SAME per-org/per-agent budget usage total a scheduled run does
  (see design.md for the small, additive widening this requires in `BudgetService`).

### Out of Scope

- **Automated prompt optimisation** (OpenAI AgentKit's eval-driven prompt rewriting) — this
  change only measures and gates; it does not rewrite prompts.
- **Human annotation queues** (Langfuse) — a follow-up; this change's judge is automatic
  (deterministic assertion or LLM rubric) only, no reviewer inbox for eval results.
- **Continuous production monitoring** (Monitaur's post-deployment half of "simulation +
  monitoring") — an EvalRun is always an explicit, on-demand action against a dataset, never
  a background watcher over live/delivered runs (that is run-analytics/run-audit-log's job).
- **Blocking version promotion on a regression-gate failure** — `agent-versioning` (a
  separate, not-yet-built change) is the only place a "promote this version" action could
  exist to block. This change only records the gate result; wiring an actual block is
  `agent-versioning`'s job once it lands (see Cross-Project Dependencies).
- The human-approval gate (Art. 14) does NOT apply to an eval run: that gate exists for
  autonomous/unattended dispatch of a real, delivered agent action. An eval run is a
  synchronous, non-delivering, on-demand test invocation by the authenticated owner —
  analogous to `RunNowController`, not to an autonomous schedule tick.

## Approach

`EvalRunService` orchestrates: (1) load the EvalDataset and Agent (owner/RBAC-guarded), (2)
check the kill-switch and budget hard-cap for the agent's organisation exactly as
`ScheduleService::dispatch()` does, (3) for each EvalCase, call
`ScheduleService::runAgentAsOwner()` to execute the real engine turn as the owner, capture its
usage/step data via two small new public getters, and never call `DeliveryService`, (4) score
the case (deterministic assertion, or an LLM-judge call via `ProviderFactory::generateText()`),
(5) persist the EvalRun with per-case results and the aggregate pass-rate, (6) compare against
the previous EvalRun for the same dataset+agent and record the regression-gate outcome, and
(7) write one redacted `AuditTrail` entry for the run (ADR-004), mirroring
`ScheduleService::writeRunAudit()`.

## New Dependencies

None.

## Impact

- **New schemas** (`lib/Settings/hermiq_register.json`): `EvalDataset`, `EvalRun`.
- **New backend**: `lib/Service/EvalRunService.php`, `lib/Service/EvalScoringService.php`,
  `lib/Controller/EvalRunController.php`.
- **Small, additive widening of existing shared files**: `ScheduleService::runAgentAsOwner()`
  gains two public getters (`getLastRunUsage()`, `getLastRunSteps()`) so a caller outside
  `ScheduleService` can read what it captured; `BudgetService`'s usage aggregation is widened
  to also scan EvalRun objects (today it only scans Schedule objects) so eval spend counts
  toward the same budget; `ProviderFactory::generateText()` gains an optional
  `?string $organisation = null` parameter (default preserves every existing caller's
  behaviour unchanged) so the judge call is model-policy-enforced when an eval run supplies
  its organisation.
- **New frontend**: `src/views/EvalDatasets.vue`, `src/views/EvalRuns.vue`,
  `src/modals/EvalDatasetFormModal.vue`, `src/api/evalRuns.js`, two new `createObjectStore`
  exports in `src/store/store.js`, two new `manifest.json` pages/menu entries.
- **`appinfo/info.xml`**: patch version bump (register re-import is version-gated).
- **`l10n/en.json` + `l10n/nl.json`**: new user-facing strings.

## Cross-Project Dependencies

Depends conceptually on `agent-versioning` (not yet built, no artifacts exist beyond its
`.openspec.yaml` at HEAD) for two things this change deliberately does NOT build: resolving
`EvalRun.agentVersionId` to an actual versioned agent snapshot (today it is an inert, optional,
unvalidated string field — no lookup, no `$ref`), and blocking a version "promotion" action on
a regression-gate failure (no such action exists yet). Both are described as a seam in
design.md, not implemented here. No other apps-extra project is affected — Hermiq is a thin
app and eval data/runs are plain OpenRegister objects in the `hermiq` register, same as every
other Hermiq schema.

## Risks

### Risk 1: Eval spend under-counted if the budget widening is skipped
**Severity:** Medium — **Mitigation:** `BudgetService::loadScheduleUuidsForScope()` today
only unions in `Schedule` object UUIDs when matching a scope's `action='run'` AuditTrail
entries; without extending it to also union in `EvalRun` UUIDs, eval-run token usage would
never roll into `currentUsageTokens()`, silently breaking the "eval runs consume budget like
any other run" requirement while `isBlocked()`/`checkAndDeliverWarnings()` still appear to
"work" (they'd just always see zero eval spend). tasks.md makes this widening an explicit,
tested task, not an afterthought.

### Risk 2: LLM-judge call cost is not separately metered
**Severity:** Low — **Mitigation:** `ProviderFactory::generateText()` returns only generated
text, no token-usage payload (unlike the agent-under-test turn, whose usage is captured via
`ScheduleService::getLastRunUsage()`). The judge call's own token cost is therefore not added
to the EvalRun's recorded `usage` and is an accepted approximation — judge prompts are small
relative to the agent turn they grade. Documented in design.md as a known limitation, not a
silent gap.

### Risk 3: Non-deterministic LLM-judge scores make the regression gate flaky
**Severity:** Low — **Mitigation:** the regression gate compares aggregate PASS-RATE
(a 0–1 fraction across all cases, most of which can be deterministic assertions) against a
configurable threshold, not individual judge scores — a single borderline rubric case
flipping does not, by itself, usually move the aggregate past the threshold. Operators can
also compose datasets from purely deterministic cases where judge flakiness matters most.

## Rollback Strategy

Remove the `EvalDataset`/`EvalRun` schema entries from `hermiq_register.json` and bump the
patch version again so the register re-import drops them; delete the new controller/service
files; revert the three additive widenings (`ScheduleService` getters,
`BudgetService` scope union, `ProviderFactory::generateText()` optional parameter) — each is
a strict superset of prior behaviour, so reverting them is a clean, isolated diff with no
knock-on effect on schedules, budgets, or existing judge-less callers. Remove the new
frontend views/modals/store exports and manifest entries.

## Open Questions

- Should the regression-gate threshold be a single instance-wide `IAppConfig` default, or
  configurable per EvalRun trigger (like `Budget.softThresholdPercent` is per-budget)? design.md
  proposes an instance-wide default with a per-trigger override, mirroring no exact existing
  precedent 1:1 but closest to `Budget`'s per-scope configurability — flagged for review.
