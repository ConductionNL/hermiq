# Tasks: ai-course-recommendations

## 1. Schema (register patch)

- [ ] 1.1 Add a `CourseRecommendation` entry under `components.schemas` in
      `lib/Settings/hermiq_register.json` (slug `courserecommendation`, icon
      `CompassOutline`, `version` `0.1.0`, title, description, `type: object`,
      `required: ["learnerId","sourceApp","status"]`,
      `"x-openregister": { "publicRead": false, "publicWrite": false }`); bump the
      register JSON `info.version` so `loadConfiguration` re-imports on upgrade;
      use the Edit tool and re-parse the JSON after editing (a merge can silently
      dup-keys).
- [ ] 1.2 Declare properties: `learnerId` (string), `sourceApp` (string),
      `tenantId` (string), `status` (enum `fresh`|`stale`|`unavailable`, default
      `unavailable`), `generatedAt` (date-time), `staleAt` (date-time),
      `signalsUsed` (object: `enrolmentCount`, `completedCourseCount`,
      `xapiStatementCount`, `goalCount`, `competencyDataAvailable` boolean),
      `candidateCount` (integer), `recommendations` (array of object: `courseId`
      string uuid — plain string, no cross-register `$ref` — `courseCode`,
      `courseName`, `rank` integer, `score` number, `matchedSignals` array of
      string enum `goal-alignment`|`curriculum-path`|`mandatory-renewal`|
      `engagement-recency`|`competency-gap`, `explanation` string),
      `explanationMode` (enum `template`|`llm-assisted`), `modelUsed` (string,
      nullable), `viewedAt` (date-time, nullable). Keep the schema flat — no
      `if`/`then`/`allOf`, no `x-openregister-lifecycle` (freshness is a plain
      field the service manages, not a governed transition).
- [ ] 1.3 Re-validate `hermiq_register.json` as well-formed JSON and confirm every
      existing schema (`Schedule`, `Approval`, `AiFeature`, `Agent`, …) is
      unchanged (union import, no regression); import the register live via the
      repair step and confirm the `CourseRecommendation` schema is created
      cleanly.

## 2. Recommendation engine (cross-app read + deterministic scoring + optional LLM phrasing)

- [ ] 2.1 Create `lib/Service/CourseRecommendationEngine.php` (SPDX docblock,
      namespace `OCA\Hermiq\Service`, inject `ObjectService`, `IAppManager`,
      `AiFeatureService`, `ScheduleService`, `ProviderFactory`, `IUserSession`,
      `LoggerInterface`): private consts naming the `course-recommendations`
      AiFeature slug and Scholiq's register/schema slugs (`scholiq`,
      `enrolment`, `course`, `xapi-statement`, `learning-plan`,
      `competency-attainment`).
- [ ] 2.2 Implement the feature-gate check first: resolve the
      `course-recommendations` `AiFeature` via `AiFeatureService::findBySlug()`
      for the caller's tenant; if missing or not `lifecycle: enabled`, return
      `status: unavailable` immediately — no Scholiq read, no LLM call.
- [ ] 2.3 Implement the tenant kill-switch check: `ScheduleService::
      isOrganisationEngaged($organisation)`; if engaged, the whole
      recommendation computation is skipped (`status: unavailable`) per the
      design's "one invariant" decision (not a partial LLM-only skip).
- [ ] 2.4 Implement the Scholiq-installed check (`IAppManager::isInstalled('scholiq')`);
      absent → `status: unavailable`, logged once, no exception.
- [ ] 2.5 Implement the four (plus one optional) cross-app reads via
      `ObjectService::findAll(['register' => 'scholiq', 'schema' => '<slug>',
      'filters' => ['learnerId' => $uid, ...], 'limit' => ...])`, each
      independently try/caught so one failing or missing schema degrades only
      that signal (set the corresponding `signalsUsed.*` flag/count) without
      aborting the others.
- [ ] 2.6 Implement the deterministic candidate filter (published courses minus
      active/completed enrolments) and the weighted scoring function (goal
      alignment, curriculum-path continuation, mandatory-renewal proximity,
      engagement recency, optional competency-gap boost), producing `rank`,
      `score`, and `matchedSignals` per candidate — pure function, unit-testable
      without any I/O.
- [ ] 2.7 Implement the optional explanation-phrasing step: for the top-N
      candidates, call `ProviderFactory::generateText()` with a prompt scoped to
      one candidate's own `matchedSignals`; catch `ProviderUnavailableException`
      and fall back to a deterministic template built from `matchedSignals`; stamp
      `explanationMode`/`modelUsed` accordingly. Never let this step alter rank,
      score, `matchedSignals`, or introduce a course outside the candidate set.
- [ ] 2.8 Implement `getOrRegenerate(string $learnerUid): array` — serves the
      cached `CourseRecommendation` (via `ObjectService`) when `now < staleAt`,
      otherwise regenerates through steps 2.2–2.7 and persists the result
      (`generatedAt` = now, `staleAt` = now + 24h, `status: fresh`) via
      `ObjectService` (single write-path, auto-audited).

## 3. Controller + routes (self-scoped, no action-matrix gate needed)

- [ ] 3.1 Create `lib/Controller/CourseRecommendationController.php` (SPDX
      docblock, `#[NoAdminRequired]`, inject `IUserSession`,
      `CourseRecommendationEngine`, `LoggerInterface`; 401 when no user):
      `index()` resolves `learnerId` exclusively from `IUserSession::getUser()->
      getUID()` (never a request parameter) and calls `getOrRegenerate()`.
- [ ] 3.2 Register the route in `appinfo/routes.php`
      (`courseRecommendation#index`, GET `/api/recommendations`) — resolves to
      an existing method (route-auth + route-reachability gates pass).

## 4. MCP tool (shared engine, no duplicated logic)

- [ ] 4.1 Add `hermiq.recommendCourses` to `TOOL_DESCRIPTORS` in
      `lib/Mcp/HermiqToolProvider.php` (input schema: no parameters — self-scoped
      by design) and a dispatcher branch in `invokeTool()` that calls the same
      `CourseRecommendationEngine::getOrRegenerate()` the controller uses,
      resolving `learnerId` from the acting user's session exactly as the
      controller does (no separate authorization path). Never throws — wraps the
      call and returns `{isError: true, error, message}` on failure.
- [ ] 4.2 Update `lib/AppInfo/Application.php`'s constructor DI for
      `HermiqToolProvider` if the new dependencies (`CourseRecommendationEngine`)
      require it (likely autowired — confirm, do not force-register if DI already
      resolves it).

## 5. Seed the AiFeature (governance data, not schema)

- [ ] 5.1 Create `lib/Repair/SeedCourseRecommendationFeature.php` (idempotent —
      skip when the `slug` already exists), mirroring
      `lib/Repair/SeedAiFeatures.php`: write one `AiFeature` object via
      `ObjectService` — `slug: course-recommendations`, `name`
      "Course recommendations", `description` naming the EU AI Act Annex III §3
      rationale, `riskCategory: high`, `lifecycle: disabled`. Register the repair
      step alongside the existing ones.

## 6. Tests + verification

- [ ] 6.1 Add `tests/Unit/Service/CourseRecommendationEngineTest.php`
      (namespace `OCA\Hermiq\Tests\Unit\Service`, extends `TestCase`): the
      feature-disabled short-circuit (no `ObjectService`/LLM call made), the
      kill-switch short-circuit, Scholiq-not-installed degrade, a missing/erroring
      signal schema degrading only that signal, deterministic-scoring idempotency
      (same input twice → same rank/score/matchedSignals), the LLM-unavailable →
      template-fallback path, and that the LLM step's prompt is scoped to a
      single candidate.
- [ ] 6.2 Add `tests/Unit/Controller/CourseRecommendationControllerTest.php`:
      asserts `learnerId` is always taken from `IUserSession`, never from request
      input, and that an unauthenticated caller gets 401.
- [ ] 6.3 Add/extend `tests/Unit/Mcp/HermiqToolProviderTest.php`: the tool
      catalogue includes `hermiq.recommendCourses`; `invokeTool()` returns a
      structured (non-throwing) result for each exercised failure path
      (feature disabled, Scholiq absent).
- [ ] 6.4 Verify live on NC + OpenRegister (with Scholiq installed, seeded course
      data): before DPO-ack the endpoint returns `unavailable`; after
      acknowledge+enable it returns a ranked, explained list; disabling
      Scholiq's app returns `unavailable` again; toggling the tenant's
      `TenantControl` kill-switch suppresses recommendations entirely; run
      PHPUnit the CI way (php:8.3-cli + OCP stubs).

## Acceptance criteria

- A `CourseRecommendation` OpenRegister schema exists and imports cleanly
  alongside every existing hermiq schema (no regression).
- The engine refuses to run (zero Scholiq reads, zero LLM calls) until the
  `course-recommendations` `AiFeature` is DPO-acknowledged and enabled.
- The engine degrades gracefully — never throwing — when Scholiq is absent, when
  the optional competency-gap schema is absent, when a signal read fails, when
  the LLM provider is unavailable, and when the tenant kill-switch is engaged.
- The deterministic ranking is reproducible from the same input and independent
  of any LLM call; the optional LLM step only phrases an already-fixed
  candidate's explanation and never changes rank/score/matchedSignals/candidate
  set.
- Every returned recommendation carries a non-empty `explanation` and at least
  one `matchedSignals` entry.
- No field, endpoint, or code path represents acceptance/enrolment — advisory
  only, verified structurally.
- Both the REST endpoint and the `hermiq.recommendCourses` MCP tool are
  self-scoped to the caller's own learner identity and share one engine call.
- The engine, controller, and MCP tool are unit-tested per 6.1–6.3, and the full
  disabled→acknowledged→enabled flow plus the two degrade paths are verified
  live.

## Quality reminders

- SPDX `@license`/`@copyright` tags inside every new PHP file's docblock; pass
  `composer check:strict`; add `@spec` docblock tags referencing this change's
  spec/tasks.
- Use the Edit tool (not sed/awk/scripts) to modify `hermiq_register.json`,
  `appinfo/routes.php`, and `lib/Mcp/HermiqToolProvider.php`; re-parse JSON after
  each edit.
- Keep the schema flat — the OpenRegister importer rejects `if`/`then`/`allOf`.
- Single write-path (ADR-004): all `CourseRecommendation` persistence goes
  through `ObjectService`.
- No stub bodies, no `var_dump`/`error_log`/`die`; no mock-based test fixes
  against shared OpenRegister stubs. Keep i18n keys (any user-facing strings the
  seed/description text touches) in English source.
- No PR/merge/deploy/process tasks — this list is implementation only.
