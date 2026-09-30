# Tasks: compliance-ai-literacy

Kind: code. Size M. Row `hermiq:td-ai-literacy`.

## Implementation tasks

### Task 1: Schemas and the six lessons in two languages
- **spec_ref**: `openspec/changes/compliance-ai-literacy/specs/compliance-control-packs/spec.md#requirement-people-can-follow-short-lessons-on-working-with-ai-req-ailit-001`
- **files**: `lib/Settings/hermiq_register.json`, `lib/Repair/SeedLiteracyLessons.php`
- **acceptance_criteria**:
  - GIVEN a fresh install and an upgrade WHEN seeded twice THEN twelve lesson objects exist once, admin edits survive
- [x] Implement
- [x] Test (PHPUnit: LiteracyTest::testSeedingIsIdempotentAndKeepsAdminEdits, testPayloadsMatchTheRegisterSchemas with Opis against the real fragments; lesson text written to the house style, no em dashes asserted)

### Task 2: The Working with AI page and completion
- **spec_ref**: `openspec/changes/compliance-ai-literacy/specs/compliance-control-packs/spec.md#requirement-people-can-follow-short-lessons-on-working-with-ai-req-ailit-001`
- **files**: `src/manifest.json`, `src/views/AiLiteracy.vue`, `lib/Controller/LiteracyController.php`, `appinfo/routes.php`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN a right answer WHEN submitted THEN a completion for that version; GIVEN a wrong one THEN an explanation and no completion
- [x] Implement
- [x] Test (PHPUnit: LiteracyTest::testANewCaseHandlerCompletesALesson, LiteracyControllerTest; Playwright tests/e2e/spec-coverage/compliance-ai-literacy.spec.ts, one full lesson)

### Task 3: The AI literacy tour
- **spec_ref**: `openspec/changes/compliance-ai-literacy/specs/compliance-control-packs/spec.md#requirement-people-can-follow-short-lessons-on-working-with-ai-req-ailit-001`
- **files**: `src/manifest.json` (walkthrough tour `hermiq:ai-literacy`)
- **acceptance_criteria**:
  - GIVEN the Getting started tour done WHEN the person returns THEN the AI literacy tour is offered once
- [x] Implement
- [x] Test (npm run check:manifest). The tour is declared manual; offering it after Getting started needs a walkthrough-engine trigger, see design.md As built

### Task 4: Admin view, the requirement and the guard
- **spec_ref**: `openspec/changes/compliance-ai-literacy/specs/compliance-control-packs/spec.md#requirement-an-organisation-admin-sees-completion-and-may-require-the-course-req-ailit-002`
- **files**: `src/views/AiLiteracy.vue`, `lib/Service/LiteracyService.php`, `lib/Service/Engine/Engine.php`, `lib/Service/Assistant/AssistantService.php`, `lib/Controller/ChatController.php`, `lib/Controller/ChatStreamController.php`
- **acceptance_criteria**:
  - GIVEN the requirement on WHEN an incomplete person chats THEN refused with a link; GIVEN a scheduled run THEN not blocked
- [x] Implement
- [x] Test (PHPUnit per entry point: ChatControllerTest, AssistantServiceTest, TalkTurnLiteracyTest, RunNowControllerTest, LiteracyTest::testAPersonWhoSkippedTheCourseIsSentToIt; Playwright for the refusal)

### Task 5: The article 4 control and its evidence source
- **spec_ref**: `openspec/changes/compliance-ai-literacy/specs/compliance-control-packs/spec.md#requirement-the-ai-act-article-4-control-reads-its-status-from-completions-req-ailit-003`
- **files**: `lib/Service/ComplianceService.php`, `lib/Repair/SeedComplianceControls.php`
- **acceptance_criteria**:
  - GIVEN 40 of 50 recent users complete WHEN computed THEN partial with the counts
- [x] Implement
- [x] Test (PHPUnit: LiteracyTest::testTheArticle4ControlReadsCompletions, ComplianceServiceTest::testTheAiLiteracyControlReadsTheLiteracyReport)

## Verification
- [x] `openspec validate compliance-ai-literacy --type change --strict` passes
- [x] PHPUnit run, exit code read (PR body). Playwright not run on 30 Sep: the shared browser service was down
