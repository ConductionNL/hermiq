# Tasks: lesson-authoring-ai-delegate

## Implementation Tasks

### Task 1: Seed the lesson-authoring AiFeature, disabled, pending DPO acknowledgement
- **spec_ref**: `openspec/changes/lesson-authoring-ai-delegate/specs/lesson-authoring/spec.md#requirement-req-001-lesson-authoring-is-gated-by-a-disabled-by-default-aifeature`
- **files**: `lib/Repair/SeedLessonAuthoringFeature.php`, `appinfo/info.xml`, `tests/Unit/Repair/SeedLessonAuthoringFeatureTest.php`
- **acceptance_criteria**:
  - GIVEN a fresh install WHEN the repair step runs THEN one `agentaifeature` row is saved with slug `lesson-authoring`, `riskCategory: limited`, `lifecycle: disabled`, `tenantId: ''`, under a system identity
  - GIVEN the slug already exists WHEN the step runs again THEN nothing is saved
  - GIVEN OpenRegister is missing or a write fails WHEN the step runs THEN it warns and returns, never throws
  - GIVEN `appinfo/info.xml` WHEN inspected THEN the step is in both `post-migration` and `install`, after `SeedCourseRecommendationFeature`
  - GIVEN an existing install WHEN it upgrades THEN the new step runs, because `<version>` moved past the merge base (gate 110)
- [x] Implement
- [x] Test

### Task 2: Build the gated engine with four actions, draft marker, parsers and call log
- **spec_ref**: `openspec/changes/lesson-authoring-ai-delegate/specs/lesson-authoring/spec.md#requirement-req-001-lesson-authoring-is-gated-by-a-disabled-by-default-aifeature`
- **files**: `lib/Service/LessonAuthoringEngine.php`, `tests/Unit/Service/LessonAuthoringEngineTest.php`
- **acceptance_criteria**:
  - GIVEN the feature is missing or disabled WHEN any action runs THEN it returns `feature-not-enabled` and calls no provider method (REQ-001)
  - GIVEN any action WHEN its prompt is built THEN it holds only the allowlisted inputs and an instruction to leave out personal data (REQ-002)
  - GIVEN a success WHEN returned THEN it carries `draft: true`, a `draftNotice`, `action` and `provider` (REQ-003)
  - GIVEN outline, questions, simplify and goal-suggestions WHEN the provider answers THEN each returns its typed field per REQ-004 to REQ-007
  - GIVEN a provider exception or unusable output WHEN any action runs THEN it returns `provider-error` and never throws (REQ-008)
  - GIVEN any call WHEN it ends THEN exactly one info line is logged with no lesson text, goal titles or output (REQ-009)
  - GIVEN any call WHEN the provider is invoked THEN `generateText()` receives the requesting user id (REQ-011)
- [x] Implement
- [x] Test

### Task 3: Add the four REST endpoints with auth, allowlist, limits and rate limit
- **spec_ref**: `openspec/changes/lesson-authoring-ai-delegate/specs/lesson-authoring/spec.md#requirement-req-010-the-endpoints-authenticate-validate-and-rate-limit-before-the-gate`
- **files**: `lib/Controller/LessonAuthoringController.php`, `appinfo/routes.php`, `tests/Unit/Controller/LessonAuthoringControllerTest.php`
- **acceptance_criteria**:
  - GIVEN no session WHEN any endpoint is called THEN 401 and the engine is never invoked
  - GIVEN a missing or out-of-limit field WHEN an endpoint is called THEN 400 and the engine is never invoked
  - GIVEN an extra `learnerName` parameter WHEN a valid request arrives THEN the engine receives only allowlisted values (REQ-002)
  - GIVEN a valid request WHEN the engine answers THEN its result is returned with status 200; an engine exception maps to 500
- [x] Implement
- [x] Test

### Task 4: Changelog entry and diff-scoped gates
- **spec_ref**: `openspec/changes/lesson-authoring-ai-delegate/proposal.md`
- **files**: `CHANGELOG.md`
- **acceptance_criteria**:
  - GIVEN `CHANGELOG.md` WHEN read THEN Unreleased/Added names the delegate, its four actions and that it is off by default
  - GIVEN the touched files WHEN `php -l`, `phpcs`, `phpstan` and `phpunit --filter` run THEN they report zero NEW findings
- [x] Implement
- [x] Test

## Verification
- [ ] All tasks checked off
- [ ] `openspec validate` passes
- [ ] Code review against spec requirements

## Quality checklist

- All new business logic covered by PHPUnit unit tests (`tests/Unit/`).
- Newman/Postman: not added. Hermiq has no Newman suite for any endpoint; the PHPUnit controller tests cover auth, validation and shape, as for every other Hermiq controller.
- Playwright: not applicable, no UI ships in this change.
- Documentation in `docs/`: not applicable, a server-to-server contract with no user-facing surface; `contract.md` is the consumer documentation.
- i18n: not applicable, no user-facing strings ship. `draftNotice` is an English API fallback; learniq renders its own translated label from `draft: true`.
- `openspec validate` passes.
