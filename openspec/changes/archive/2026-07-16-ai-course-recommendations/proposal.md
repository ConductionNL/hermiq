---
kind: code
---

# Proposal: ai-course-recommendations

## Why

Specter evidence names `ai-powered-recommendations` a canonical feature for the
learning-platform category, and every named competitor ships it: Cornerstone,
Docebo, and 360Learning each expose a "relevance score" next-best-content engine,
Udemy Business ranks its catalogue against a learner's role/skill profile, and
Coursera recommends the next course in a specialization from completion + assessment
signals. Scholiq has none of this today — its `course-management`, `enrolment`,
`data-exchange` (xAPI) and `learning-plan` capabilities (`openspec/specs/`) store
every signal a recommender needs (`lib/Settings/scholiq_register.json`), but nothing
reads them to suggest a next course. This is a fleet-scale gap, not a Scholiq-only
one, so per the fleet boundary in `openspec/architecture/adr-001-agent-boundary.md`
("agents + agent-core + governance = Hermiq") the capability belongs in Hermiq, not
duplicated per-app.

Hermiq is already positioned to own it:

- **The AI-feature governance register already exists and is live** —
  `lib/Settings/hermiq_register.json`'s `AiFeature` schema (`slug: agentaifeature`),
  `lib/Lifecycle/AiFeatureDpoAckGuard.php`, `lib/Controller/AiFeatureController.php`,
  and `lib/Service/AiFeatureService.php` (`ai-feature-governance-register`, shipped —
  `appinfo/routes.php:194-198`) give a design-time, risk-classified inventory of
  high-risk AI features gated on a DPO acknowledgement before `enable`. Scholiq
  already delegates its own high-risk gate here (`AssessmentPublishGuard.php`, ported
  by `openspec/changes/ai-feature-delegate-to-hermiq/`) — this capability is the
  second Hermiq-native `AiFeature`, not the first cross-app consumer.
- **The cross-app read pattern is proven, not invented.** Scholiq's
  `lib/Lifecycle/AssessmentPublishGuard.php:60-179` queries Hermiq's register
  directly through `ObjectService::findAll(['register' => 'hermiq', 'schema' =>
  'agentaifeature', 'filters' => [...], 'limit' => 1])`, gated on
  `IAppManager::isInstalled('hermiq')` to distinguish "app absent" from "no data" and
  degrading gracefully either way. This change runs the same pattern in the opposite
  direction: Hermiq reads Scholiq's `enrolment`/`course`/`xapi-statement`/
  `learning-plan` objects (`lib/Settings/scholiq_register.json`) the same way.
- **The LLM plumbing already exists and is governed.** `openspec/specs/agent-engine-port/spec.md`
  establishes that Hermiq holds no LLM API key and every provider call goes through
  the credential broker, failing closed (`ProviderUnavailableException`, 503) with no
  fallback. `lib/Service/Llm/ProviderFactory.php:484` (`generateText(string $prompt,
  ?string $userId = null, bool $allowNextcloud = true): string`) is a ready-made,
  already-governed single-call completion primitive — no new provider layer needed.
- **The tenant kill-switch is a ready-made governance primitive.**
  `lib/Service/ScheduleService.php:310-329` (`isOrganisationEngaged(string
  $organisation): bool`, reused by `FlowAgentRunService` per its own docblock,
  `lib/Service/ScheduleService.php:267-269`) is the one source of truth for "is this
  tenant's AI halted" — this change reuses it rather than adding a second kill-switch
  check.
- **The domain-MCP pattern is established.** `lib/Mcp/HermiqToolProvider.php` is
  Hermiq's registered `IMcpToolProvider` (DI-aliased in
  `lib/AppInfo/Application.php:119-126`); `openspec/changes/hermiq-domain-mcp-tools/proposal.md`
  documents (not yet built) the fleet expectation that a chat companion should be
  able to act on an app's own domain, not just generic NC-native tools. This change
  adds one domain tool directly to the existing provider rather than depending on
  that separate, still-open proposal.

## What Changes

- Add a new OpenRegister schema **`CourseRecommendation`**
  (`lib/Settings/hermiq_register.json`, slug `courserecommendation`, icon
  `CompassOutline`) — a per-learner, per-source-app snapshot of a ranked
  recommendation list, the signals consulted to produce it, and how it was produced
  (deterministic vs. LLM-assisted phrasing). Flat schema, no `x-openregister-lifecycle`
  (no approval-style transition is needed — freshness is a plain enum field the
  service manages, not a governed state machine).
- Add **`lib/Service/CourseRecommendationEngine.php`**: for a given learner (the
  caller's own NC user id, self-service only in this change) and source app
  (hard-coded to `scholiq` at this revision — the schema's `sourceApp` field is
  generic so a second app's adapter can be added later without a schema change):
  1. Checks the `course-recommendations` `AiFeature` is `enabled` in Hermiq's own
     register (own-app governance, not cross-app) and that the tenant's kill-switch
     (`ScheduleService::isOrganisationEngaged()`) is not engaged — fails closed
     (no recommendation) on either.
  2. Checks `IAppManager::isInstalled('scholiq')`; if absent, returns an
     `unavailable`-status recommendation set (no crash).
  3. Reads the learner's `Enrolment`, `Course`, `XapiStatement`, and `LearningPlan`
     objects cross-app via `ObjectService::findAll(['register' => 'scholiq', 'schema'
     => '<slug>', 'filters' => ['learnerId' => $uid], ...])`, wrapped so a read
     failure (including an unknown schema — the wave-2 `competency-framework`
     Competency/CompetencyAttainment schemas do not exist in Scholiq at this
     revision) degrades that one signal to "unavailable" rather than failing the
     whole computation.
  4. Deterministically filters eligible candidate `Course` objects (published,
     not already completed/active-enrolled) and scores them by weighted signals
     (goal alignment from `LearningPlan.goals[]`, curriculum-path continuation from
     `Course.parentCourseId`/`programmeIds`, mandatory-renewal proximity from
     `Course.renewalCourseSlug`, xAPI engagement recency, and — when present —
     competency-gap closure). The **ranking and every contributing signal are
     deterministic and reproducible**, not LLM output.
  5. Optionally calls `ProviderFactory::generateText()` to phrase the
     human-readable `explanation` string for the top-ranked courses, grounded
     strictly in that course's own computed signal breakdown; on
     `ProviderUnavailableException` or the kill-switch being engaged, falls back to
     a deterministic template built from the same signal breakdown — the
     explanation is never absent, only its phrasing degrades.
  6. Persists the result as a `CourseRecommendation` object via `ObjectService`
     (single write-path, auto-audited).
- Add **`lib/Controller/CourseRecommendationController.php`**: `index()` —
  `#[NoAdminRequired]`, self-scoped (`learnerId` is always the caller's own uid in
  this change; no action-matrix gate is needed because the endpoint never resolves
  another user's data) — returns the caller's current `CourseRecommendation`
  (regenerating through the engine when missing or past `staleAt`). Route in
  `appinfo/routes.php`.
- Add tool **`hermiq.recommendCourses`** to `lib/Mcp/HermiqToolProvider.php`'s
  `TOOL_DESCRIPTORS`, delegating to the same `CourseRecommendationEngine` and the
  same self-scope rule as the controller (no separate authorization path — mirrors
  the existing tools' per-object-guard-before-business-logic rule stated in the
  provider's own docblock).
- Seed one `AiFeature` object (`lib/Repair/` idempotent step, mirroring
  `lib/Repair/SeedAiFeatures.php`): `slug: course-recommendations`, `riskCategory:
  high` (EU AI Act Annex III §3 — an education recommender that can materially
  influence a learner's course/career path), `lifecycle: disabled` — an admin/DPO
  must explicitly acknowledge and enable it (existing `AiFeatureController`
  acknowledge/enable flow — no new governance UI needed).
- Add PHPUnit tests for the engine's signal scoring, its three degrade paths
  (Scholiq absent, competency schema absent, LLM provider unavailable/kill-switch
  engaged), the `AiFeature`-disabled fail-closed path, and the controller/MCP-tool
  self-scope enforcement.

**Not in this change (scholiq-side leaf):** the "Recommended for you" surface and
its `AiFeature`-delegation registration in Scholiq (mirroring how
`ScholiqSettings.vue` already links out to Hermiq's `/ai-features` register per
`ai-feature-delegate-to-hermiq`) is explicit follow-up work in the scholiq repo. No
scholiq files are touched by this change.

## Capabilities

### New Capabilities

- `course-recommendations`: Hermiq-owned, AI-Act-governed next-best-course
  recommendation engine — cross-app signal reads from Scholiq, deterministic
  weighted ranking with optional LLM-assisted, signal-grounded explanation
  phrasing, self-scoped API + MCP tool exposure, advisory-only posture (no
  automated enrolment, no profiling).

### Modified Capabilities

<!-- None. ai-feature-governance and nc-native-tools are consumed, not modified:
     this change adds one new AiFeature *object* (data) and one new MCP *tool*
     entry within the existing provider — no requirement in either capability's
     spec changes. -->
- <!-- none -->

## Impact

- **Config:** `lib/Settings/hermiq_register.json` gains a `CourseRecommendation`
  schema entry (union import, existing schemas untouched — no regression); register
  `info.version` bumped so the importer re-imports on upgrade.
- **Code:** new `lib/Service/CourseRecommendationEngine.php`,
  `lib/Controller/CourseRecommendationController.php`, a seed repair step
  (`lib/Repair/SeedCourseRecommendationFeature.php`); `lib/Mcp/HermiqToolProvider.php`
  and `appinfo/routes.php` are extended, not replaced.
- **Data:** OpenRegister creates a magic table for `CourseRecommendation` in the
  `hermiq` register on import; the seed step writes one `AiFeature` object
  idempotently (`disabled` until DPO-acknowledged).
- **Dependencies:** OpenRegister (existing) for object storage/RBAC/audit and the
  MCP tool registry. **Scholiq is an optional runtime peer, not a hard dependency**
  — `appinfo/info.xml` gains no `<app>scholiq</app>` entry; absence degrades to an
  `unavailable` recommendation set (mirrors `ai-feature-delegate-to-hermiq`'s
  `IAppManager` posture, in the opposite direction).
- **Cross-app coupling:** localised to explicit `private const`s naming Scholiq's
  register/schema slugs in `CourseRecommendationEngine`, documented and tested —
  same posture `AssessmentPublishGuard.php` already established for the reverse
  direction.
