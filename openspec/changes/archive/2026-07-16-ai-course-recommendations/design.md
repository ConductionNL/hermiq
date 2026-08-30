# Design: ai-course-recommendations

## Context

Scholiq stores every signal a next-best-course recommender needs — enrolment
history (`Enrolment`), the course catalogue (`Course`), learning-activity telemetry
(`XapiStatement`), and declared learner goals (`LearningPlan`) — but nothing reads
them together to suggest what a learner should study next. Per
`openspec/architecture/adr-001-agent-boundary.md` ("agents + agent-core +
governance = Hermiq"), and per the precedent already shipped in
`ai-feature-delegate-to-hermiq` (Scholiq's own high-risk AI gate now lives in
Hermiq's register, read cross-app), this capability is built once in Hermiq and
read cross-app rather than duplicated in every leaf app that has learner data.

This design covers only the Hermiq-side engine, its data model, and its two
consumption surfaces (a self-scoped REST endpoint and an MCP tool). The
scholiq-side "Recommended for you" widget and its local `AiFeature`-delegation
registration (mirroring `ScholiqSettings.vue`'s existing "Manage AI features" link)
are explicit follow-up work in the scholiq repo, noted but not designed here.

## EU AI Act posture (read this section first)

This is the load-bearing decision of the whole change. An education recommender
that can steer a learner's course or career path is listed in **Annex III §3**
(education and vocational training) of the EU AI Act as high-risk when it
materially influences access to, or the course of, education. The design commits
to the following posture, enforced structurally — not just documented:

1. **The `course-recommendations` `AiFeature` is seeded `riskCategory: high` and
   `lifecycle: disabled`.** It uses the *already-shipped* governance machinery
   (`lib/Lifecycle/AiFeatureDpoAckGuard.php`, `lib/Controller/AiFeatureController.php`)
   unchanged — this change adds one governed feature, not a second gate. Until a
   DPO acknowledges it (`AiFeatureController::acknowledge()`) and an admin/DPO
   enables it (`AiFeatureController::enable()`), `CourseRecommendationEngine`
   refuses to run at all (see "Fail-closed on the feature gate" below) — the
   feature genuinely does not compute or serve anything pre-acknowledgement, not
   merely "hidden in the UI".
2. **Recommendations are advisory only.** The `CourseRecommendation` schema has
   no field that represents an accepted, actioned, or auto-enrolled state. There
   is no `acceptRecommendation()` mutation and no code path that writes an
   `Enrolment` object. A learner (or, in a future scholiq surface, a mentor) reads
   the ranked list and enrols through Scholiq's own existing enrolment flow,
   completely untouched by this change.
3. **A human decides.** The engine's own output is a ranked *suggestion* list with
   explanations, consumed either by the learner directly (self-scoped API) or via
   the chat companion (self-scoped MCP tool). Nothing in this change autonomously
   acts on a recommendation.
4. **No profiling of protected characteristics.** The signal set is deliberately
   limited to learning-activity and curriculum data the learner already generated
   by studying (enrolments, completions, xAPI activity, self-declared goals,
   competency gaps against a defined taxonomy). It excludes demographic,
   protected-characteristic, or any signal not already visible to the learner
   themselves in Scholiq's own UI — nothing is inferred about the learner that
   they could not already see about themselves.
5. **The reasoning is surfaced, not hidden.** Every recommendation carries
   `matchedSignals` (which specific signals contributed) and a plain-language
   `explanation` grounded in those signals (see "Explainability substrate"
   below). There is no ranked entry without an explanation — the write path
   rejects one if it can't produce the other.
6. **The ranking itself is deterministic, not an opaque LLM judgment call.** See
   "Ranking approach" below — this is the single biggest lever for reducing this
   feature's actual risk surface without weakening its usefulness, and it is the
   main design decision this document defends.

## Goals / Non-Goals

**Goals:**
- A cross-app-aware ranking engine that reads Scholiq's existing learner-signal
  schemas and produces an explainable, reproducible ranked course list.
- Graceful degradation at every integration seam: Scholiq absent, competency data
  absent, LLM provider absent/kill-switched — none of these should crash the
  feature or silently produce an unexplained result.
- Reuse of every governance primitive Hermiq already ships (`AiFeature`
  DPO-ack gate, tenant kill-switch, credential-broker LLM calls) rather than
  inventing new ones.
- Two thin, self-scoped consumption surfaces (REST + MCP tool) sharing one
  engine, so there is exactly one place the ranking/explanation logic lives.

**Non-Goals (explicitly NOT built in this change):**
- **No automated enrolment.** Covered above — structural, not a policy note.
- **No adaptive/computerised-adaptive-testing (CAT).** This is a course
  *recommender*, not an assessment engine — it does not select or sequence exam
  items, does not adapt question difficulty, and does not touch `Assessment` or
  `GradeEntry` objects at all. Conflating the two would pull this into
  Annex III's assessment-integrity high-risk category on top of the education
  category already accepted, for no product value in this change.
- **No collaborative-filtering / trained ML model.** Ranking is rule-based over
  explicit signals (see below) — no model training pipeline, no embeddings-based
  similarity search over the learner population, no cross-learner "learners like
  you" comparison. This is a deliberate simplicity/explainability trade — see
  "Alternatives considered".
- **No cross-learner (mentor/staff) views.** This change is self-service only —
  a caller can only ever request their own recommendations. A mentor/coordinator
  dashboard showing another learner's recommendations needs a per-object
  authorization decision this change does not make (Hermiq cannot evaluate
  Scholiq's own mentor/coordinator relationships without a facade Scholiq does
  not yet expose) — deferred to a follow-up once that facade exists.
- **No scheduled/background regeneration.** Recommendations are computed lazily
  on read when missing or stale (see "Freshness" below) — no new `TimedJob`,
  no new `Schedule` object type. Simpler, and avoids computing recommendations
  for learners who never look at them.
- **No Algoritmeregister publication in this change.** The `AiFeature` schema
  already carries the optional Algoritmekader fields (`doel`,
  `wettelijkeGrondslag`, `impacttoetsen`, etc., `lib/Settings/hermiq_register.json`
  `AiFeature.properties`) — filling those in and publishing is a governance
  task for the DPO/admin after acknowledgement, not something this change
  automates.

## Cross-app signal read

**Pattern.** Identical in shape to `AssessmentPublishGuard.php:60-179` (Scholiq
reading Hermiq's register), run in the opposite direction:

```php
if ($this->appManager->isInstalled('scholiq') === false) {
    // degrade: return status=unavailable, no crash, log once
}

$enrolments = $this->objectService->findAll([
    'register' => 'scholiq',
    'schema'   => 'enrolment',
    'filters'  => ['learnerId' => $learnerUid],
    'limit'    => 200,
]);
```

Four reads, one per signal source, each independently wrapped: `enrolment`,
`course` (to resolve names/metadata for candidate scoring), `xapi-statement`
(filtered by `verified_actor_id` — the xAPI ingest controller's server-trusted
learner identifier, `lib/Settings/scholiq_register.json` `XapiStatement.
verified_actor_id`), and `learning-plan` (filtered by `learnerId`). A fifth,
**optional**, read attempts `competency-attainment` (the wave-2
`competency-framework` schema slug) purely speculatively — Scholiq does not ship
this schema at this change's HEAD (verified: no `competency-framework` directory
exists under scholiq's `openspec/changes/` or `openspec/changes/archive/` at the
time this was written), so this read is expected to return "schema not found" in
the near term and MUST be treated identically to "no data", not as an error.

**RBAC.** The read runs in the caller's own Nextcloud session (Hermiq and
Scholiq share one NC user base) with OpenRegister's default per-object RBAC
**left on** (no `_rbac: false` override, unlike `ScheduleService`'s
system-wide kill-switch scan) — the explicit `filters: ['learnerId' =>
$learnerUid]` restricts to the caller's own records at the query layer, and OR's
native per-object authorization is the second, independent gate on top of it,
exactly the two-layer posture `ai-companion-tools`'s spec documents
("OpenRegister's RBAC inside `ObjectService` is the second, per-object gate").

**Coupling.** Localised to `private const`s on `CourseRecommendationEngine`
naming Scholiq's register (`scholiq`) and schema slugs (`enrolment`, `course`,
`xapi-statement`, `learning-plan`, `competency-attainment`), documented in the
class docblock — same posture `AssessmentPublishGuard.php`'s
`HERMIQ_REGISTER`/`HERMIQ_AI_FEATURE_SCHEMA` constants already established for the
reverse direction. A future Scholiq-exposed capability API could replace the
direct query without changing this contract.

## Ranking approach

**Two-stage, deterministic-then-optional-LLM-phrasing:**

**Stage 1 — deterministic candidate filter + weighted score (always runs, is the
actual ranking).**
1. Candidate set = published `Course` objects (`lifecycle: published`) minus
   courses the learner has an `active` or `completed` `Enrolment` for.
2. Each candidate is scored by summing independently-computed, weighted signal
   contributions:
   - **Goal alignment** — the candidate's `tags`/`code` overlaps a `LearningPlan.
     goals[].domain`/`description` for an `open` goal.
   - **Curriculum-path continuation** — the candidate's `parentCourseId` or
     `programmeIds` continues a programme the learner has an active/completed
     enrolment in.
   - **Mandatory-renewal proximity** — the candidate is the `renewalCourseSlug`
     target of a completed mandatory course approaching its regulatory renewal
     window.
   - **Engagement recency** — the learner has recent `XapiStatement` activity
     (`timestamp`) against the candidate's prerequisite/sibling courses (module
     progression signal).
   - **Competency-gap closure (optional, boosted when present)** — when the
     wave-2 `competency-attainment` read succeeds and returns gap data, a
     candidate that closes a documented gap gets an additional weighted boost.
     When absent, this signal simply contributes zero — the total score is still
     computed from the other four, per the brief's "degrade gracefully" requirement.
3. Output: an ordered candidate list, each entry carrying its numeric `score` and
   the **list of which named signals fired** (`matchedSignals`) — this list *is*
   the explainability substrate, independent of anything LLM-generated.

This stage is a plain weighted-sum heuristic over explicit, named signals — every
number in it is reproducible from the same input data, auditable by re-running it,
and does not require any model, training data, or non-deterministic call. It is
intentionally **not** a trained/learned model (see Alternatives considered).

**Stage 2 — explanation phrasing (optional, cosmetic only, never changes the
ranking).**
For the top-N candidates, `CourseRecommendationEngine` may call
`ProviderFactory::generateText()` (`lib/Service/Llm/ProviderFactory.php:484`) with
a prompt containing **only** that candidate's own `matchedSignals` breakdown
(never the full candidate list, never other learners' data, never anything the
deterministic stage didn't already compute) to turn the structured signal
breakdown into one or two natural-language sentences. Guardrails:
- Before calling, check `ScheduleService::isOrganisationEngaged($organisation)`
  (`lib/Service/ScheduleService.php:310-329`) — if the tenant kill-switch is
  engaged, skip straight to the deterministic template (below); this is the same
  kill-switch every other Hermiq-triggered run already respects, reused rather
  than re-implemented.
- On `ProviderUnavailableException` (no credential configured — the documented,
  intentional fail-closed behaviour of the broker per
  `openspec/specs/agent-engine-port/spec.md`) or the kill-switch check above, fall
  back to a **deterministic template** built directly from `matchedSignals`
  (e.g. "Continues your {programme} path and matches your goal of
  {goalDescription}.") — the explanation is never blank; only its fluency
  degrades.
- The response is not re-ranked or filtered by anything the LLM call returns —
  it produces phrasing for an already-fixed candidate and already-fixed rank.
- `explanationMode` (`template`|`llm-assisted`) and, when applicable, `modelUsed`
  are stamped on the persisted object — the recommendation is always
  self-describing about how its explanation was produced, for audit and for a
  future DPIA.

**Why this split, not "ask the LLM to rank + explain in one call".** Handing the
LLM the candidate list and asking it to both rank and explain collapses the
explainability guarantee into "trust the model's stated reasoning" — exactly the
opaque-recommender risk the AI Act posture above is trying to avoid, and it opens
a hallucination surface (an LLM can recommend a course that isn't actually
eligible, or misstate why). Keeping the ranking deterministic and letting the LLM
only phrase an already-fixed, already-explained result removes that whole failure
class while still giving a natural-language product surface. This is the central
trade-off of this design: less "the AI decided" and more "the platform computed a
reproducible score and can also say it more nicely."

## Explainability substrate

`matchedSignals` (structured, always present) is the actual explanation; the
`explanation` string (LLM-phrased or templated) is a rendering of it, never an
independent source of truth. A UI (the scholiq-side follow-up) can render either
or both — showing the structured signals directly is itself sufficient to satisfy
"why this course" without depending on the LLM path having run at all.

## Fail-closed on the feature gate

`CourseRecommendationEngine::generate()` starts by resolving the `course-
recommendations` `AiFeature` via `AiFeatureService::findBySlug()`
(`lib/Service/AiFeatureService.php:176`) for the caller's tenant and checking
`lifecycle === 'enabled'`. If the feature does not exist yet (seed step hasn't
run) or is `disabled` (default, pre-DPO-acknowledgement), the engine returns a
`status: unavailable` result and does **not** read any Scholiq data, run any
scoring, or call any LLM — the gate is checked first, before any cross-app read,
so a disabled feature has zero data-access footprint, not just a hidden UI. This
mirrors `AssessmentPublishGuard`'s "fail closed for the high-risk path" posture,
applied to Hermiq's own feature instead of a delegated one.

## Freshness

`CourseRecommendation.status` (`fresh`|`stale`|`unavailable`) and `staleAt`
(`generatedAt` + a fixed TTL, 24h at this revision — a plain constant, not
configurable in this change) are the mechanism: `CourseRecommendationController::
index()` and the MCP tool both call the same `CourseRecommendationEngine::
getOrRegenerate()`, which serves the cached object when `now < staleAt` and
regenerates synchronously otherwise. No background job — the compute cost is one
learner's data at read time, not a fleet-wide sweep.

## Consumption surfaces

Both surfaces are thin and share one engine call — neither re-implements scoring,
signal reads, or the feature/kill-switch gates:
- **`CourseRecommendationController::index()`** — `#[NoAdminRequired]`, resolves
  `learnerId` from `IUserSession` (never from a request parameter in this
  change — see "No cross-learner views" above), returns the current
  `CourseRecommendation` (regenerating via the engine if needed).
- **`hermiq.recommendCourses`** MCP tool on the existing `HermiqToolProvider` —
  same self-scope rule, same engine call, structured `{success|isError, ...}`
  response per the provider's existing no-throw convention. This lets a learner
  ask the chat companion "what should I study next" and get the same,
  identically-governed answer the REST surface would give.

## Data model

New schema `CourseRecommendation` (`lib/Settings/hermiq_register.json`, slug
`courserecommendation`, icon `CompassOutline`, flat — no `x-openregister-lifecycle`):

| Field | Type | Notes |
|---|---|---|
| `learnerId` | string | NC user id (self-scoped identity key, mirrors Scholiq's own `Enrolment.learnerId`/`LearningPlan.learnerId` convention — plain string, not a `$ref`) |
| `sourceApp` | string | The leaf app the signals were read from; hard-coded `scholiq` at this revision, kept generic for a future second adapter |
| `tenantId` | string | Mirrors the source app's `tenant_id` — multi-tenant isolation, not translated |
| `status` | string enum `fresh`\|`stale`\|`unavailable` | Plain field, not a governed lifecycle — no approval-style transition needed |
| `generatedAt` / `staleAt` | date-time | TTL bookkeeping for lazy regeneration |
| `signalsUsed` | object | Snapshot of signal availability/counts (`enrolmentCount`, `completedCourseCount`, `xapiStatementCount`, `goalCount`, `competencyDataAvailable: bool`) — audit/debug substrate, not shown to the learner |
| `candidateCount` | integer | How many eligible courses were scored |
| `recommendations` | array of object | The ranked list: `courseId` (string uuid — foreign key into Scholiq's `Course` register, no `$ref` across registers), `courseCode`, `courseName`, `rank`, `score`, `matchedSignals` (array of the named signal enum), `explanation` (string) |
| `explanationMode` | string enum `template`\|`llm-assisted` | Which path produced the explanation text |
| `modelUsed` | string, nullable | Provider/model id when `llm-assisted` |
| `viewedAt` | date-time, nullable | Derived — stamped by the read endpoint when the learner actually opens the list |

No field represents acceptance, enrolment, or any write-back into a course or
enrolment record — deliberately absent, per the AI Act posture above.

## Alternatives considered

- **Ask the LLM to rank and explain in one call.** Rejected — collapses the
  explainability guarantee into trusting the model's stated reasoning, opens a
  hallucination surface (recommending an ineligible or non-existent course), and
  makes the ranking non-reproducible. See "Ranking approach" above.
- **Trained collaborative-filtering / embeddings-similarity model.** Rejected for
  this change's scope — no ML training infrastructure exists in the fleet, no
  labelled outcome data (did the recommendation lead to a good result?) exists
  yet to train against, and a deterministic weighted-signal approach is already
  competitive with what the named competitors describe as a "relevance score".
  A learned model is a plausible future replacement for Stage 1's weights, not a
  different architecture — the schema and consumption surfaces would not need to
  change.
- **Fail-open when the LLM provider is unavailable (skip the explanation
  entirely).** Rejected — the AI Act posture requires every recommendation to be
  explained; skipping the explanation would violate the platform's own stated
  posture whenever a provider hiccups. The deterministic template guarantees an
  explanation always exists.
- **Fail-open when the tenant kill-switch is engaged (still rank, just skip the
  LLM).** Considered, but rejected in favour of failing the *entire*
  recommendation closed when the org kill-switch is engaged. The kill-switch is a
  blanket "halt AI-adjacent activity for this tenant" control
  (`lib/Service/ScheduleService.php`'s own docblock: "When engaged, ALL runs for
  that organisation are halted"); treating this feature's deterministic stage as
  exempt would quietly carve out an exception to an operator's explicit kill
  decision. One invariant — engaged kill-switch means no recommendation at all —
  is simpler to reason about and to test than a partial-degrade rule.
- **Give Scholiq a facade/API instead of a direct cross-app `ObjectService`
  read.** Rejected for this change — `AssessmentPublishGuard.php` already
  established the direct-read pattern in the opposite direction and it is live,
  tested, and working; building a bespoke facade for symmetry alone is scope this
  M-sized change does not need. Noted as a viable future refactor if the
  direct-query coupling becomes a maintenance problem across more than these two
  apps.
- **Let a mentor/coordinator request another learner's recommendations in this
  change.** Rejected — Hermiq has no way to evaluate Scholiq's own
  mentor/coordinator relationship without a capability Scholiq does not yet
  expose; building a bespoke cross-app role check for this one feature would be
  exactly the kind of app-specific coupling ADR-022 warns against. Self-service
  only until that facade exists.

## Follow-up (scholiq repo, not this change)

- A "Recommended for you" widget/page in Scholiq's `src/manifest.json` calling
  `CourseRecommendationController::index()` (or the MCP tool via the existing
  chat companion surface).
- Scholiq registering its own delegation note for this feature, mirroring how
  `ScholiqSettings.vue` already links to Hermiq's `/ai-features` register for
  `assessment-ai-proctor-review` (`ai-feature-delegate-to-hermiq`) — no new
  Scholiq schema needed, the existing "Manage AI features" affordance already
  covers any Hermiq-governed feature, including this one.
- Once scholiq's `competency-framework` change ships, no Hermiq-side change is
  needed for the competency-gap signal to activate — the read is already wired
  and defensively degrades today; it will simply start returning data.
