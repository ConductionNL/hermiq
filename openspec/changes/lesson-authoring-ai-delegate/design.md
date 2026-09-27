# Design: lesson-authoring-ai-delegate

## Architecture Overview

Same shape as the message translation delegate (`feat/message-translation-delegate`, `75c66d16`) and, before it, `course-recommendations`. A repair step seeds a disabled governance row. An engine checks that row before it does any provider work. A thin controller maps HTTP to the engine. `AiFeatureService` (the gate) and `ProviderFactory` (the model call) are reused unchanged.

```
learniq LessonComposer (wave 2, browser, @nextcloud/axios)
  -> POST /apps/hermiq/api/lesson-authoring/{outline|questions|simplify|goal-suggestions}
       -> LessonAuthoringController      auth (401), allowlist + limits (400), rate limit (429)
            -> LessonAuthoringEngine::{draftOutline|suggestQuestions|simplify|suggestGoals}()
                 -> AiFeatureService::findBySlug('lesson-authoring')        [gate]
                 -> ProviderFactory::getLlmConfig()                         [provider name]
                 -> ProviderFactory::generateText(prompt, userId)           [model]
                      -> 'nextcloud' driver: OCP\TaskProcessing\IManager::runTask(core:text2text)
                 -> parse output into typed fields
                 -> LoggerInterface::info(action, user, outcome, sizes)     [call log]
```

## API Design

The full request and response contract is in `contract.md`, which is the document the learniq lane builds against. Summary:

| Endpoint | Content in | Control in | Result out |
|---|---|---|---|
| `POST /api/lesson-authoring/outline` | `goalTitles` (req), `lessonText` (opt) | `language` | `draftText` |
| `POST /api/lesson-authoring/questions` | `lessonText` (req), `goalTitles` (opt) | `questionCount`, `language` | `questions[]` |
| `POST /api/lesson-authoring/simplify` | `lessonText` (req) | `readingLevel` | `draftText`, `readingLevel` |
| `POST /api/lesson-authoring/goal-suggestions` | `lessonText` (req), `goalTitles` (req) | none | `suggestedGoals[{index,title}]` |

Every success: `{available: true, action, draft: true, draftNotice, provider, ...result}`.
Every degrade: `{available: false, action, reason: "feature-not-enabled" | "provider-error"}`.
Validation failure: HTTP 400 `{error}`. No session: HTTP 401 `{error: "Unauthenticated"}`.

## Database Changes

None. One new row on the existing `agentaifeature` schema (register `hermiq`), written by a repair step. No schema change, so no register version bump.

## Declarative-vs-imperative decision

| Behaviour | Path | Rationale |
|---|---|---|
| Feature on/off with DPO acknowledgement | Declarative, reused | The `agentaifeature` schema already declares `x-openregister-lifecycle` with the `AiFeatureDpoAckGuard` on `enable`. This change adds a row, not a lifecycle. |
| Calling a language model | Imperative (`LessonAuthoringEngine`) | ADR-031 exception: an external integration. A prompt, a provider call and output parsing cannot be declared on a schema. |
| Seeding the governance row | Imperative (`IRepairStep`) | Same as the shipped `SeedCourseRecommendationFeature`: a governance row, not demo data, written once under a system identity. |

## Nextcloud Integration

- Controllers: `OCA\Hermiq\Controller\LessonAuthoringController` (new). Attributes `#[NoAdminRequired]` and `#[UserRateLimit(limit: 30, period: 60)]`. CSRF stays on.
- Services: `OCA\Hermiq\Service\LessonAuthoringEngine` (new). Reused, unchanged: `OCA\Hermiq\Service\AiFeatureService` and `OCA\Hermiq\Service\Llm\ProviderFactory`.
- Repair steps: `OCA\Hermiq\Repair\SeedLessonAuthoringFeature` (new), in `appinfo/info.xml` `post-migration` and `install`, right after `SeedCourseRecommendationFeature`.
- OCP: `IRequest`, `IUserSession`, `IRepairStep`, `IOutput`, `TaskProcessing\IManager` (inside `ProviderFactory`), and `Psr\Log\LoggerInterface`.
- Mappers/Entities: none. Reads and writes go through OpenRegister's `ObjectService`.
- Events/Hooks: none.

## Security Considerations

- **Authentication.** Every endpoint needs a session: 401 without a user, before any parameter is read.
- **Authorisation.** No caller-supplied identifier is looked up, and nothing is read from storage on the caller's behalf. The caller sends content and gets a draft back, so there is no object to authorise. Gate 7 (no-admin IDOR) is satisfied with a reason-bearing `@no-admin-idor-exempt` tag per method, not with the authentication check, which gate 7 rightly does not count as a guard.
- **CSRF.** Kept on, unlike the translation delegate. The consumer is a browser (`LessonComposer`), and `@nextcloud/axios` sends the token. A forged cross-site POST would spend the teacher's model budget, so the check earns its place.
- **Rate limit.** 30 calls per user per minute per endpoint. Size caps: `lessonText` 20,000 characters, `goalTitles` 100 items of 300 characters each.
- **Pupil data.** The request shape has no pupil field. The controller reads only allowlisted keys, so any other parameter is dropped before the engine. Every prompt tells the model to leave out names and personal data. Goal ids never reach the model: the caller sends titles and gets indexes back. Free text cannot be screened reliably, so the consumer must warn the teacher (contract.md, obligation 2). The DPO acknowledgement is the formal control.
- **Prompt injection.** The lesson text is fenced in the prompt as material to work on, not as instructions. The output is a draft a teacher reviews and is never executed, so an injected instruction can at worst produce odd text.
- **Logging.** One info line per call: action, uid, outcome, provider, `lessonTextLength`, `goalCount`. Never content. A provider failure adds a warning with the exception message only, as in the translation delegate.

## File Structure

```
lib/
  Controller/
    LessonAuthoringController.php     (new)
  Service/
    LessonAuthoringEngine.php         (new)
  Repair/
    SeedLessonAuthoringFeature.php    (new)
appinfo/
  routes.php                          (+4 routes)
  info.xml                            (+1 repair step, both blocks)
tests/Unit/
  Controller/LessonAuthoringControllerTest.php  (new)
  Service/LessonAuthoringEngineTest.php          (new)
  Repair/SeedLessonAuthoringFeatureTest.php      (new)
CHANGELOG.md                          (+1 Unreleased entry)
```

## Seed Data

No demo content objects (ADR-016 does not apply: no new schema). The change seeds exactly one governance object through the repair step:

### Schema: `agentaifeature`
| Field | Value |
|---|---|
| slug | `lesson-authoring` |
| name | `Lesson authoring assistance` |
| description | Drafts a lesson outline from learning goals, suggests questions, rewrites a text at a lower reading level and suggests which given goals a lesson covers. Input is lesson text and goal titles only, never pupil data. Every output is a draft a teacher accepts. Limited risk under the EU AI Act: it drafts content and decides nothing about a pupil. |
| riskCategory | `limited` |
| lifecycle | `disabled` |
| tenantId | `''` (fleet-wide, like `course-recommendations`) |
| doel | Help teachers draft lesson material faster, while the teacher stays the author. |
| dataBronnen | Lesson text and learning goal titles the teacher supplies. No pupil data. |
| humanIntervention | Every output is a draft. The teacher accepts, edits or discards it before anything is saved or published. |

`requiresRedaction` is left unset on purpose. It means "refuses a document filinq has not redacted", and this delegate reads no filinq document. Setting it would claim a check that does not run on this path.

**Related items per object:** none.

## Trade-offs

**One controller with four routes, not one generic `action` parameter.** Four explicit routes give four clear contracts, four rate-limit buckets and four gate-scannable methods. A single `POST /api/lesson-authoring` with an `action` field would save three route lines and cost a switch on user input.

**Plain-text parsing, not JSON mode.** A local model behind task processing does not reliably return strict JSON. Questions are parsed one per line with numbering stripped. Goal suggestions accept numbers or `NONE`. Anything else is `provider-error`, so a garbage answer never looks like "no goals".

**Indexes, not ids, for goal suggestions.** The contract allows goal titles only. Returning 0-based positions lets learniq map back to its own `competencyIds` without any id reaching the model, and lets the engine refuse an invented goal.

**The instance chat provider, not the feature's own binding.** `generateText()` resolves the instance's configured chat provider, exactly as the translation delegate does. The feature's `provider`, `model` and `requiredResidency` fields, which `FeatureProviderResolver` enforces for agent runs, are not consulted on this path. Wiring them in means a `generateText()` variant that takes a feature slug. That belongs in `ProviderFactory` for every delegate at once, so it is a follow-up, not a one-off here.

**One log line per call, not only on failure.** The translation delegate logs failures only. This change logs every call with metadata, so the school can answer who used the assistant, when and with which provider, without any lesson content in the log.

**No shared base class with the translation engine.** Two engines now share the three-line gate check. The translation design chose to inline it until a pattern proved itself. With the second example the pattern is proven, but the translation delegate is not on `development` yet, so extracting now would couple this PR to an unmerged branch. Extract once both have landed.
