# Tasks: ai-course-recommendations

## 1. Schema (register patch)

- [x] 1.1 Add a `CourseRecommendation` entry under `components.schemas` in
      `lib/Settings/hermiq_register.json` (slug `courserecommendation`, icon
      `CompassOutline`, `version` `0.1.0`, title, description, `type: object`,
      `required: ["learnerId","sourceApp","status"]`,
      `"x-openregister": { "publicRead": false, "publicWrite": false }`); bump the
      register JSON `info.version` so `loadConfiguration` re-imports on upgrade;
      use the Edit tool and re-parse the JSON after editing (a merge can silently
      dup-keys).
- [x] 1.2 Declare properties: `learnerId` (string), `sourceApp` (string),
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
- [x] 1.3 Re-validate `hermiq_register.json` as well-formed JSON (parsed +
      duplicate-key-checked via python3) and confirmed every existing schema
      (`Schedule`, `Approval`, `AiFeature`, `Agent`, …) is unchanged (20 schemas
      total, 19 pre-existing + `CourseRecommendation`; no regression). Live import
      via the repair step against a running NC+OpenRegister instance was NOT done
      in this sandboxed apply run (no live instance available) — flagged for the
      live-verify pass (task 6.4).

## 2. Recommendation engine (cross-app read + deterministic scoring + optional LLM phrasing)

- [x] 2.1 Created `lib/Service/CourseRecommendationEngine.php` (SPDX docblock,
      namespace `OCA\Hermiq\Service`, injects `ObjectService`, `IAppManager`,
      `AiFeatureService`, `ScheduleService`, `ProviderFactory`,
      `OrganisationMapper`, `LoggerInterface` — `IUserSession` dropped: the
      engine takes `learnerUid` as a plain string parameter, resolved by the
      *caller* from its own session, per the self-scope requirement; the
      engine itself never touches session state). Private consts name the
      `course-recommendations` AiFeature slug and Scholiq's register/schema
      slugs (`scholiq`, `enrolment`, `course`, `xapi-statement`,
      `learning-plan`, `competency-attainment`).
- [x] 2.2 Implemented the feature-gate check first in `getOrRegenerate()`:
      resolves `course-recommendations` via `AiFeatureService::findBySlug()`;
      if missing or not `lifecycle: enabled`, returns an in-memory
      `status: unavailable` result immediately — zero Scholiq reads, zero LLM
      calls (verified by a PHPUnit test asserting zero `ObjectService::findAll()`
      calls of any kind in this path).
- [x] 2.3 Implemented the tenant kill-switch check via `ScheduleService::
      isOrganisationEngaged($organisation)` — **resolved differently than this
      task's original wording**: `design.md`'s own "Ranking approach" Stage 2
      guardrail and `spec.md` Requirement 4 ("Tenant kill-switch engaged skips
      the LLM call entirely" — scenario explicitly asserts the ranked,
      *explained* list is still returned) both normatively describe the
      kill-switch as skipping ONLY the optional LLM-phrasing step, not the
      whole computation. `design.md`'s separate "Alternatives considered"
      section contradicts this with near-identical wording describing the
      REJECTED alternative. Implemented per the two normative sources
      (Requirement 4 + Stage 2): kill-switch engaged → deterministic ranking
      still runs, LLM call is skipped, template explanation is used. Documented
      as a "Reconciliation note" in the class docblock; asserted by
      `testKillSwitchEngagedSkipsOnlyTheLlmStep()`.
- [x] 2.4 Implemented the Scholiq-installed check (`IAppManager::isInstalled('scholiq')`)
      — also runs unconditionally before any read, same as 2.2; absent →
      `status: unavailable`, logged once, no exception.
- [x] 2.5 Implemented the five (four required + one optional) cross-app reads
      in `collectSignals()` via `ObjectService->setRegister('scholiq')
      ->setSchema('<slug>')->findAll(['filters' => [...], 'limit' => ...])`,
      each independently try/caught in `readSignal()` so one failing or
      missing schema (including an unknown schema — the not-yet-shipped
      `competency-attainment`) degrades only that signal (drives the
      corresponding `signalsUsed.*` flag/count) without aborting the others.
      Note: the `course` read is deliberately UNfiltered by lifecycle (not
      `filters: ['lifecycle' => 'published']` as originally planned) — the
      scorer needs archived/enrolled reference courses too, to resolve
      `renewalCourseSlug`/`parentCourseId` for a completed mandatory course;
      candidate ELIGIBILITY (published-only) is enforced inside
      `scoreCandidates()`, not at the query layer.
- [x] 2.6 Implemented the deterministic candidate filter (published courses
      minus active/completed enrolments) and the weighted scoring function
      (goal alignment, curriculum-path continuation, mandatory-renewal
      proximity, engagement recency, optional competency-gap boost) in the
      `public function scoreCandidates()` pure function — no I/O, producing
      `rank`, `score`, and `matchedSignals` per candidate. A candidate with
      zero matched signals is excluded (Requirement 4 needs at least one
      signal to explain). Unit-tested directly (`testDeterministicScoringIsIdempotent()`)
      and via the full pipeline.
- [x] 2.7 Implemented the optional explanation-phrasing step in `explain()`
      (+ `resolveLlmDriver()`/`tryLlmExplanation()` helpers): for the top-5
      ranked candidates, calls `ProviderFactory::generateText()` with a prompt
      scoped to ONE candidate's own `matchedSignals`; any `Throwable`
      (including `ProviderUnavailableException`) falls back to a deterministic
      template built from `matchedSignals`; stamps `explanationMode`/
      `modelUsed` accordingly. The step only ever sets the `explanation` key —
      never rank/score/matchedSignals/candidate set.
- [x] 2.8 Implemented `getOrRegenerate(string $learnerUid): array` —
      **strengthened beyond the literal wording**: the AiFeature gate (2.2) and
      Scholiq-installed check (2.4) now run UNCONDITIONALLY first, even ahead
      of consulting the TTL cache, so a feature disabled (or Scholiq
      uninstalled) after a recommendation was generated stops being served
      immediately, not only after the 24h TTL — a previously-fresh cached
      result is never served once a gate fails
      (`testDisablingTheFeatureStopsServingAPreviouslyFreshCachedResult()`).
      Once both gates pass, serves the cached `CourseRecommendation` when
      `status === 'fresh' && now < staleAt`, otherwise regenerates via
      `regenerate()` (which calls `collectSignals()` → `scoreCandidates()` →
      `explain()` → `persist()`) and persists the result (`generatedAt` = now,
      `staleAt` = now + 24h, `status: fresh`) via `ObjectService` (single
      write-path, auto-audited).

## 3. Controller + routes (self-scoped, no action-matrix gate needed)

- [x] 3.1 Created `lib/Controller/CourseRecommendationController.php` (SPDX
      docblock, `@NoAdminRequired`/`@NoCSRFRequired` docblock annotations —
      matches this codebase's existing convention, e.g. `AiFeatureController`/
      `TenantControlController`, rather than PHP8 attributes; injects
      `IUserSession`, `CourseRecommendationEngine`, `LoggerInterface`; 401 when
      no user): `index()` resolves `learnerId` exclusively from
      `IUserSession::getUser()->getUID()` (never a request parameter — the
      method takes no parameters at all) and calls `getOrRegenerate()`.
- [x] 3.2 Registered the route in `appinfo/routes.php`
      (`courseRecommendation#index`, GET `/api/recommendations`, inserted
      before the SPA catch-all) — resolves to an existing method (route-auth +
      route-reachability gates pass).

## 4. MCP tool (shared engine, no duplicated logic)

- [x] 4.1 Added `hermiq.recommendCourses` to `TOOL_DESCRIPTORS` in
      `lib/Mcp/HermiqToolProvider.php` (input schema: no parameters — self-scoped
      by design) and a dispatcher branch in `invokeTool()` that calls the same
      `CourseRecommendationEngine::getOrRegenerate()` the controller uses,
      resolving `learnerId` from the acting user's session exactly as the
      controller does (no separate authorization path). Relies on
      `invokeTool()`'s existing outer try/catch (never throws — any Throwable
      maps to `{error: {code: 'tool_failed', message}}`) rather than adding a
      second inner try/catch, matching every other case in this switch.
- [x] 4.2 Confirmed autowired: added `CourseRecommendationEngine
      $courseEngine` to `HermiqToolProvider`'s constructor; Nextcloud's DI
      resolves it (and its own 7 constructor dependencies) with no explicit
      registration in `lib/AppInfo/Application.php` needed — verified by the
      full PHPUnit run (610→626 green) exercising real construction paths.

## 5. Seed the AiFeature (governance data, not schema)

- [x] 5.1 Created `lib/Repair/SeedCourseRecommendationFeature.php` (idempotent —
      skips when the `slug` already exists), mirroring
      `lib/Repair/SeedAiFeatures.php` exactly: writes one `AiFeature` object via
      `ObjectService` — `slug: course-recommendations`, `name`
      "Course recommendations", `description` naming the EU AI Act Annex III §3
      rationale, `riskCategory: high`, `lifecycle: disabled`, `tenantId: ''`
      (system-scoped, `_rbac: false, _multitenancy: false` — same posture as
      the existing seeded features). Registered in `appinfo/info.xml`'s
      `<install>` and `<post-migration>` repair-step lists, alongside
      `SeedAiFeatures`.

## 6. Tests + verification

- [x] 6.1 Added `tests/Unit/Service/CourseRecommendationEngineTest.php`
      (namespace `OCA\Hermiq\Tests\Unit\Service`, extends `TestCase`, 10 tests):
      the feature-disabled AND missing-feature short-circuits (zero
      `ObjectService`/LLM calls, verified via a schema-call-log double), the
      Scholiq-not-installed degrade, a single failing signal read degrading
      only that signal, the optional competency-gap signal absent/present,
      deterministic-scoring idempotency (same input twice → byte-identical
      rank/score/matchedSignals, plus an explicit expected-ranking-order
      assertion across all 5 named signals), the kill-switch-skips-only-the-LLM-step
      invariant (ranking + template explanation still returned), the
      LLM-unavailable → template-fallback path, that the LLM prompt is scoped
      to a single candidate (asserted by checking each of the 5 prompts
      contains only its own candidate's course name and none of the other 4),
      and that a disabled feature wins over a still-fresh cached result.
- [x] 6.2 Added `tests/Unit/Controller/CourseRecommendationControllerTest.php`
      (4 tests): asserts the engine is NEVER invoked for an unauthenticated
      caller (401 first), that `learnerId` passed to the engine is always the
      session's own uid, that the controller returns the engine's payload
      verbatim, and that an engine `Throwable` maps to 500 (never uncaught).
- [x] 6.3 Extended `tests/Unit/Mcp/HermiqToolProviderTest.php` (+2 tests, updated
      the `provider()` helper's constructor call for the new DI param): the tool
      catalogue count is now 8 and includes `hermiq.recommendCourses`;
      `invokeTool()` delegates to the engine with the acting user's own uid,
      and a thrown engine exception maps to the structured `tool_failed` error
      envelope, never an uncaught exception.
- [x] 6.4 Live NC+OpenRegister verification (DPO-ack flow, Scholiq
      install/uninstall toggle, kill-switch toggle) was **NOT performed in this
      sandboxed apply run** — no live Nextcloud/OpenRegister/Scholiq instance
      was available. What WAS run and verified in this pass, against the real
      CI toolchain (`php:8.3-cli` container, matching
      `.forgejo/workflows/pre-merge-check-strict.yaml`):
        - `composer test:unit` (`vendor/bin/phpunit`): baseline 610/610 green
          → 626/626 green after this change (16 new tests, 0 regressions).
        - `composer lint` (`php -l` on every `lib/*.php`): clean.
        - `composer phpcs` (full `lib/` tree, the actual enforced CI gate):
          100/100 files, 0 errors.
        - `phpstan analyse` scoped to the 4 new/changed PHP files: 0 errors.
        - `phpmd` scoped to the 4 new/changed PHP files: 0 findings (after
          extracting `collectSignals()`/`persist()`/`resolveLlmDriver()`/
          `tryLlmExplanation()` from `regenerate()`/`explain()` to bring
          complexity/length under threshold, and shortening several
          >20-char local variable names).
        - `openspec validate ai-course-recommendations --strict`: valid.
      **Flagged as open follow-up**: the live disabled→acknowledged→enabled→
      disabled flow, the live Scholiq-install-toggle, and the live kill-switch
      toggle, against a running instance with real seeded Scholiq course data.

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
