# Design: agent-evals

## Architecture Overview

```
EvalDatasets.vue ──CRUD──▶ createObjectStore('evaldataset') ──▶ OpenRegister ObjectService
                                                                  (register=hermiq, schema=evaldataset)

EvalRuns.vue ──"Run eval"──▶ POST /api/evals/{datasetId}/run ──▶ EvalRunController
                                                                        │
                                                                        ▼
                                                                 EvalRunService
                                        ┌───────────────┬──────────────┼───────────────────┐
                                        ▼               ▼              ▼                   ▼
                          ScheduleService::           BudgetService  EvalScoringService   AuditTrailMapper
                          isOrganisationEngaged()/    isBlocked()/   (deterministic +      createAuditTrailEntry()
                          runAgentAsOwner()           checkAndDeliverWarnings()  LLM-judge via
                          (per case; NEVER calls                     ProviderFactory::generateText())
                          DeliveryService)
                                        │
                                        ▼
                          EvalRun persisted via ObjectService
                          (register=hermiq, schema=evalrun) ──▶ regression-gate compare
                                                                 against previous EvalRun
                                                                 for same datasetId+agentId
```

Hermiq owns no LLM/tool engine of its own beyond the already-shipped in-app `Engine` (see
`agent-engine-port`) — this change adds NO new execution path. It reuses
`ScheduleService::runAgentAsOwner()` verbatim: the same impersonation, the same
Engine-vs-ChatService feature-flag dual path, the same usage/step capture. The only new
execution-adjacent code is the scoring layer (deterministic assertions + one LLM-judge call
per rubric case) and the run/dataset bookkeeping.

## API Design

### `POST /api/evals/{datasetId}/run`

Thin trigger endpoint (mirrors `RunNowController`) — OpenRegister exposes no
"run this against that agent" action, so this is the one net-new backend endpoint the UI
needs. Dataset and EvalRun CRUD itself goes through the generic
`/apps/openregister/api/objects/hermiq/{evaldataset|evalrun}` path via `createObjectStore`,
same as `Schedule`/`Agent`.

**Request:**
```json
{
  "agentId": "b2b0c6b0-...",
  "agentVersionId": null
}
```

**Response (200):**
```json
{
  "evalRunId": "a1a0c6b0-...",
  "status": "completed",
  "passRate": 0.85,
  "regressionGateResult": "passed",
  "previousPassRate": 0.90
}
```

**Errors:** `401` unauthenticated; `404` dataset/agent not found or not owned by the caller
(mirrors `RunNowController`'s IDOR guard — 404, never 403, so a non-owner cannot confirm
existence); `422` model-policy violation surfaced from a judge call
(`ModelPolicyViolationException`, same shape `ChatController` already returns); `503` no LLM
provider configured (`ProviderUnavailableException`) — the run is recorded with
`status: 'failed'` and the per-case errors captured rather than a bare 500, whenever the
failure occurs mid-dataset (partial results are not discarded).

## Nextcloud Integration

- **Controllers**: `EvalRunController` (new) — `run(string $datasetId)`, `#[NoAdminRequired]`,
  `#[NoCSRFRequired]`, owner-guarded exactly like `RunNowController::run()`.
- **Services**: `EvalRunService` (new, orchestration), `EvalScoringService` (new, deterministic
  + LLM-judge scoring). Reused unmodified: `ScheduleService::isOrganisationEngaged()`,
  `BudgetService::isBlocked()`/`checkAndDeliverWarnings()`, `RedactionService::redact()`,
  `ObjectService` (find/saveObject/findAll). Reused with a small additive widening:
  `ScheduleService::runAgentAsOwner()` (new public getters alongside it),
  `BudgetService::loadScheduleUuidsForScope()` (scope union widened),
  `ProviderFactory::generateText()` (new optional parameter).
- **Mappers/Entities**: none new — `AuditTrailMapper` (existing) writes the per-run entry;
  `EvalDataset`/`EvalRun` are plain OpenRegister `ObjectEntity` instances, not NC entities.
- **Events/Hooks**: none new. An eval run is a synchronous, on-demand HTTP action, not an
  event listener (unlike `flow-agent-listener`'s `AgentRunRequestedEvent` consumer).

## Security Considerations

- `EvalRunController::run()` loads the EvalDataset WITH RBAC (tenant-scoped `ObjectService::find`)
  and additionally checks the requesting user owns it — 404 (not 403) on mismatch, identical
  to `RunNowController::loadOwnedSchedule()`'s IDOR guard (OWASP A01). The target Agent is
  loaded the same way.
- The judge's rubric-scoring prompt embeds the case's actual agent output verbatim; that
  output is user/agent-controlled content flowing into a second LLM call. Standard prompt-
  injection caution applies exactly as it already does for every other Hermiq LLM call —
  no new trust boundary is crossed (the judge call still goes through
  `ProviderFactory`/model-policy/budget like every other call; it is not given tool access,
  so a prompt-injected judge call cannot invoke tools).
- `EvalRun.results[].actualOutput` and `judgeRationale` are redacted with the SAME
  `RedactionService::redact()` used for `writeRunAudit()`'s summary BEFORE the AuditTrail
  entry is written (ADR-004 redaction-before-persist) — the raw, unredacted output only ever
  lives on the EvalRun object itself (tenant/RBAC-scoped, same protection as any other
  Hermiq object), never in the immutable audit trail.
- No new public/anonymous route — `EvalDataset`/`EvalRun` inherit OpenRegister's standard
  `publicRead: false, publicWrite: false` (see hermiq_register.json entries below).

## NL Design System

- `EvalDatasets.vue`/`EvalRuns.vue` render through `CnDataTable` (the shared list widget
  every other Hermiq index-style page uses — `AgentCatalog.vue`, budget's list view) —
  no bespoke table markup.
- `EvalDatasetFormModal.vue` is an `NcModal`-based form (mirrors `AgentFormModal.vue`,
  `BudgetFormModal.vue`) living under `src/modals/`, with an inline, repeatable case-row
  editor (add/remove rows) — not a separate dialog per case (a case is a sub-object of one
  dataset, not an independent OR object; mirrors `Schedule.repeat`'s embedded-object pattern).
- Pass-rate trend uses a simple sparkline/small bar treatment consistent with existing
  Hermiq analytics widgets (`AnalyticsController`/`run-analytics` dashboards) — no new
  charting library is introduced.
- All new user-facing strings via `l10n/en.json`/`l10n/nl.json` (English keys, ADR-007).

## File Structure

```
lib/
  Controller/
    EvalRunController.php          (new)
  Service/
    EvalRunService.php              (new)
    EvalScoringService.php          (new)
    ScheduleService.php             (modified: + getLastRunUsage(), + getLastRunSteps())
    BudgetService.php               (modified: loadScheduleUuidsForScope() unions in EvalRun UUIDs)
    Llm/
      ProviderFactory.php           (modified: generateText() gains ?string $organisation=null)
  Settings/
    hermiq_register.json            (modified: + EvalDataset, + EvalRun schemas)
appinfo/
  info.xml                          (modified: patch version bump)
  routes.php                        (modified: + evalRun#run route)
src/
  views/
    EvalDatasets.vue                 (new)
    EvalRuns.vue                     (new)
  modals/
    EvalDatasetFormModal.vue         (new)
  api/
    evalRuns.js                      (new — thin "run" action helper, mirrors budgets.js)
  store/
    store.js                         (modified: + useEvalDatasetStore, + useEvalRunStore)
  manifest.json                      (modified: + 2 pages, + menu entries)
l10n/
  en.json, nl.json                   (modified: new keys)
```

## Seed Data

### Schema: `evaldataset`
| Field | Object 1 | Object 2 | Object 3 |
|-------|----------|----------|----------|
| slug | `daily-briefing-smoke` | `inbox-triage-regression` | `permit-drafting-accuracy` |
| name | Daily briefing smoke test | Inbox triage regression | Permit drafting accuracy |
| description | Sanity checks for the daily-briefing agent's format and tone | Guards the inbox-triage agent's categorisation | Grades a permit-drafting agent's factual grounding |
| cases | 3 cases: 1 contains, 1 jsonPathEquals, 1 rubric | 4 cases: 2 contains, 1 notContains, 1 rubric | 3 rubric cases (factual grounding, tone, completeness) |

**Related items per object:** Files: none. Notes: none. Tasks: none. Contacts: none — an
EvalDataset is a self-contained testing artifact, no cross-entity linkage.

### Schema: `evalrun`
| Field | Object 1 | Object 2 | Object 3 |
|-------|----------|----------|----------|
| slug | (system-generated UUID, no human slug) | | |
| datasetId | → `daily-briefing-smoke` | → `inbox-triage-regression` | → `permit-drafting-accuracy` |
| status | completed | completed | completed |
| passRate | 1.0 | 0.75 | 0.67 |
| regressionGateResult | not_applicable (first run) | passed | failed (seeded to demonstrate the UI's failure state) |

**Related items per object:** none — an EvalRun is a point-in-time execution record, not
linked to Files/Notes/Tasks/Contacts.

## Trade-offs

- **Embedded cases vs. a separate EvalCase schema**: cases are stored as an embedded array
  property on `EvalDataset`, not a standalone OR schema/register relation. Rejected the
  separate-schema alternative because a case has no independent lifecycle, ownership, or
  cross-dataset reuse need — it only ever exists as part of one dataset, exactly like
  `Schedule.repeat` is an embedded object rather than a `Repeat` schema. A separate schema
  would add a relation to manage for zero behavioural gain.
- **Reusing `ScheduleService::runAgentAsOwner()` vs. a parallel eval-only execution path**:
  the brief requires "the agent's real engine path" — reusing the exact method a schedule
  tick calls (rather than re-implementing impersonation + the Engine/ChatService dual-path
  branch) is the only way to guarantee an eval reflects real run behaviour, and it is
  already `public`. The two new getters are the minimum surface needed to read what it
  already captures internally.
- **`generateText()` widening vs. a bespoke judge-only LLM call path**: `EvalScoringService`
  could have bypassed `ProviderFactory` and built its own driver-selection logic for judge
  calls. Rejected: the brief explicitly requires judge calls to go through the SAME
  chokepoint so tenant-model-policy/budgets/guardrails apply — duplicating driver selection
  would silently exempt judge calls from governance the first time `ProviderFactory` gained
  a new provider or a policy rule.
- **`BudgetService` scope widening vs. a separate eval-spend counter**: the brief explicitly
  forbids inventing a separate meter. The alternative — teaching `currentUsageTokens()` to
  accept a second object-uuid source (EvalRun) alongside Schedule — is additive and keeps
  exactly one usage-aggregation code path; the alternative (a parallel `EvalBudgetService`)
  would have created the exact "separate meter" the brief rules out.
- **Regression-gate threshold: instance-wide default vs. required per-run input**: an
  instance-wide `IAppConfig` key `eval.regressionThresholdPercent` (default `10`, i.e. a
  drop of more than 10 percentage points fails the gate) is read when no override is
  supplied on the trigger request, with an optional per-request override for a team that
  wants a stricter/looser bar for one dataset. This mirrors `Budget.softThresholdPercent`
  being per-scope while `engine.enabled` is instance-wide — evals sit closer to the
  per-scope end, but a sane default avoids forcing every trigger call to specify it. Flagged
  in proposal.md's Open Questions for review.
- **No approval gate for eval runs**: see proposal.md's Out of Scope — an eval run is a
  synchronous, attended, non-delivering test action, not an autonomous dispatch; Art. 14's
  human-oversight gate exists for the latter.
