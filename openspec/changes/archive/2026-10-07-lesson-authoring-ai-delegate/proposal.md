---
kind: code
depends_on: []
---

# Proposal: lesson-authoring-ai-delegate

## Summary

Add a lesson authoring delegate to Hermiq. It is a governed AI feature, off by default, that offers teachers four assistive actions. It drafts a lesson outline from a learning goal, suggests questions for a lesson, rewrites a text at a lower reading level, and suggests which goals a lesson covers. The only content it accepts is lesson text and goal titles, never pupil data. Every output is marked as a draft, and every call is logged. It follows the same pattern as the message translation delegate: a repair step seeds a disabled `agentaifeature` row, a DPO must acknowledge it before it can be enabled, an engine checks the gate before it touches a model, and a thin controller maps HTTP to the engine. The model runs through `ProviderFactory::generateText()`. With the admin's `nextcloud` driver that is Nextcloud task processing (`OCP\TaskProcessing`, `core:text2text`). Learniq never calls a model vendor directly.

## Motivation

Learniq round 2 asks for AI help while writing lessons (recon `learniq-mi/learniq/_round2/recon/D-ai-lessons-onboarding-styles.md`, section 4, row `lesson-authoring-ai-delegate`). Plan assumption A5 fixes the shape: AI outputs are drafts a teacher accepts, and the delegate lives in Hermiq, off by default, gated by the AI Act register there. Learniq itself has no code path that calls a model today. Recon section 1 found zero hits for `TaskProcessing`, `OCA\Hermiq` or any vendor client in learniq, so the capability has to come from Hermiq.

The market already ships this, so learniq without it is behind:

- Corporate LMS vendors generate lesson drafts from a prompt or an uploaded file. Docebo Creator "generates lesson drafts based on your prompt and any uploaded documents"; Studytube converts SCORM, PDF or PPT uploads into courses; TalentLMS generates tests (`learniq-mi/learniq/corporate-lms/round1/documented-columns.md:294`, proposed row C-new-7, "AI course and assessment generation from prompts or uploaded documents", a vendor claim).
- Moodle ships AI placements in the editor and the course: generate, summarise, explain (`learniq-mi/learniq/moodle/round1/M1-moodle-column.md:291`, proposed row M-new-11, `yes public/ai/placement`, open source code).
- Canvas markets "outline lessons including learning objectives with AI assistance" under institution-controlled enablement (recon section 2, web sources, vendor claim).

No rung is assigned. C-new-7 and M-new-11 are proposed rows without a tier, and this is round 2 scope, not an M1 row. The adjacent governance row is M1 15.7 "AI features disabled by default with DPO acknowledgement (AI Act)" (`learniq-mi/learniq/_round1/compare/M1-rows-draft.md:260`), which this change keeps intact. The integration row M3 I24 ("AI features gated, delegated to hermiq", `_round1/compare/M3-integrations.md:36`) names Hermiq as the owner.

Posture: the four actions draft content and never assign a pupil to anything. That keeps the feature at `riskCategory: limited`. EU AI Act Annex III 3(b) makes a system high risk when it assesses "the appropriate level of education" a person will receive (recon section 6). A reading level rewrite changes a text, not a pupil's placement, and the spec says so.

## Affected Projects

- [ ] Project: `hermiq`: adds the `lesson-authoring` AI feature (governance seed, gated engine, four REST endpoints) and publishes its request and response contract for learniq.

No other project is touched. Learniq's call site (`lesson-ai-assist-actions`, round 2 wave 2) is a separate change that calls the contract this change publishes.

## Scope

### In Scope

1. A `lesson-authoring` `agentaifeature` row, seeded `lifecycle: disabled`, `riskCategory: limited`, fleet-wide (`tenantId: ''`), with the Algoritmekader purpose, data sources and human oversight filled in.
2. A `LessonAuthoringEngine` with four actions, each gated on the feature being `enabled` before any provider call: `outline`, `questions`, `simplify` and `goal-suggestions`.
3. Four REST endpoints under `/api/lesson-authoring/`, one per action, authenticated and rate limited per user. They validate input before any gate check or provider call.
4. A content allowlist. The only content fields are `lessonText` and `goalTitles`. The remaining fields are bounded control values: a language tag, a question count and a reading level enum. Any other parameter is ignored and never reaches a prompt.
5. A draft marker on every successful response (`draft: true` plus a `draftNotice`), and a structured `{available: false, reason}` degrade path for a disabled feature or a provider failure.
6. One log line per call through the PSR-3 logger, with action, user, outcome, provider and input sizes, never the text.
7. `contract.md` with the full request and response shape, so the learniq lane can build against it.
8. Unit tests for the seed, the engine and the controller.

### Out of Scope

- Learniq's buttons in `LessonComposer` and the accept or discard flow (`lesson-ai-assist-actions`, learniq, wave 2).
- An MCP tool wrapper around the same actions (recon open question 1, option C, deferred).
- Per-school opt-in on top of the tenant-wide DPO gate (recon open question 2; the default is the existing tenant gate).
- Enforcing the feature's own provider binding and `requiredResidency` on this call path. `generateText()` resolves the instance's chat provider, exactly as the translation delegate does; see design.md, Trade-offs.
- Any pupil-facing or level-assignment use. That would be Annex III high risk and needs its own change.
- Streaming or chat. Each call is one request and one response.

## Approach

Copy the message translation delegate (hermiq branch `feat/message-translation-delegate`, commit `75c66d16`, local only at the time of writing) end to end. The seed, gate, degrade shape and controller shell stay the same. Three things are new: four prompt builders, parsers that turn model output into typed fields (questions as a list, goal suggestions as indexes into the caller's list), and one info log line per call. Details are in design.md.

## New Dependencies

None. Reuses `ProviderFactory`, `AiFeatureService`, the `agentaifeature` schema and the `RunsUnderSystemIdentity` repair trait.

## Impact

- `lib/Repair/SeedLessonAuthoringFeature.php` (new): seeds the governance row.
- `lib/Service/LessonAuthoringEngine.php` (new): gate, prompts, parsers, provider call, call log.
- `lib/Controller/LessonAuthoringController.php` (new): four endpoints.
- `appinfo/routes.php`: four routes.
- `appinfo/info.xml`: the repair step in the `install` and `post-migration` blocks, next to `SeedCourseRecommendationFeature`, and a `<version>` bump. Without the bump `occ upgrade` answers "No upgrade required" and existing installs never get the feature row (gate 110).
- `CHANGELOG.md`: one Unreleased entry.

## Cross-Project Dependencies

Learniq consumes this contract in `lesson-ai-assist-actions` (wave 2, not in this change). The translation delegate is a sibling built on the same pattern. Neither depends on the other: both touch `appinfo/routes.php` and `appinfo/info.xml`, so the second one to land needs a trivial merge.

## Risks

### Risk 1: A teacher pastes pupil data into the lesson text
**Severity:** High. **Mitigation:** the contract accepts only lesson text and goal titles, and ignores every other field. Pupil identifiers never appear in the request shape. The prompt tells the model to leave out personal data. The call log records sizes, never content. Learniq's call site must show the "no pupil data" warning next to the button (a requirement on the consumer, stated in contract.md). Free text cannot be screened reliably, so the DPO acknowledgement and learniq's warning carry the rest.

### Risk 2: A draft is used as final content without review
**Severity:** Medium. **Mitigation:** every success carries `draft: true` and a `draftNotice`. The contract requires the caller to insert the output as an editable draft the teacher accepts. Learniq's `Lesson.lifecycle` stays `draft` until the teacher acts.

### Risk 3: The model returns output the parser cannot read
**Severity:** Medium. **Mitigation:** questions are parsed line by line, and goal suggestions accept either `NONE` or numbers. Output that yields nothing usable degrades to `provider-error` instead of an empty success. A garbage answer must never look like "no goals covered".

### Risk 4: Cost or abuse through repeated calls
**Severity:** Low. **Mitigation:** a per-user rate limit on each endpoint, a 20,000 character cap on lesson text and a 100 item cap on goal titles.

## Rollback Strategy

Revert the PR. The seeded row stays `disabled` unless a DPO enabled it. Disabling it, or reverting, removes the capability with no data migration, because nothing the model returns is persisted.
