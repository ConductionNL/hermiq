# lesson-authoring Specification

**Status**: idea
**Scope**: hermiq
**OpenSpec changes**:
- _(none yet)_

## Purpose

Lets a teacher get AI help while writing a lesson, through a delegate Hermiq governs. Four actions are offered: draft an outline from learning goals, suggest questions, rewrite a text at a lower reading level, and suggest which given goals a lesson covers. The only content the model sees is lesson text and goal titles. Every result is a draft the teacher accepts, and the feature is off until a DPO acknowledges it in Hermiq's AI Act register (hermiq ADR on EU AI Act gating; learniq plan assumption A5). It mirrors the message translation delegate's gate, degrade and controller shape.

## ADDED Requirements

### Requirement: REQ-001: Lesson authoring is gated by a disabled-by-default AiFeature

The system SHALL NOT run any lesson authoring action unless the `lesson-authoring` `AiFeature` (`agentaifeature` schema, register `hermiq`) is `lifecycle: enabled`. A repair step MUST seed that feature with `riskCategory: limited`, `lifecycle: disabled` and `tenantId: ''`, and MUST skip it when the slug already exists. The gate MUST be checked before any provider call, so a disabled or missing feature has zero provider footprint. Enabling MUST go through the existing DPO acknowledgement guard (`AiFeatureDpoAckGuard`) unchanged.

#### Scenario: A fresh install seeds the feature disabled

- GIVEN no `lesson-authoring` AiFeature exists
- WHEN the repair step runs
- THEN one `agentaifeature` object is saved with slug `lesson-authoring`, `riskCategory: limited`, `lifecycle: disabled` and `tenantId: ''`
- AND the write runs under a system identity

#### Scenario: A re-run does not duplicate the feature

- GIVEN the `lesson-authoring` AiFeature already exists
- WHEN the repair step runs again
- THEN nothing is saved

#### Scenario: A disabled feature makes no provider call

- GIVEN the `lesson-authoring` AiFeature is `lifecycle: disabled`
- WHEN any of the four actions is requested
- THEN the result is `{available: false, action, reason: "feature-not-enabled"}`
- AND no provider method is called

#### Scenario: A missing feature makes no provider call

- GIVEN the `lesson-authoring` AiFeature has not been seeded
- WHEN any action is requested
- THEN the result is `{available: false, action, reason: "feature-not-enabled"}`
- AND nothing is thrown

### Requirement: REQ-002: Only lesson text and goal titles reach the model

The system SHALL build every prompt from `lessonText`, `goalTitles` and the bounded control values (`language`, `questionCount`, `readingLevel`) only. Any other request parameter MUST be ignored and MUST NOT reach the engine or the prompt. Every prompt MUST tell the model to leave out names and other personal data. The goal suggestion prompt MUST refer to goals by their position number, never by a caller id.

#### Scenario: An extra parameter never reaches the engine

- GIVEN an authenticated request to any endpoint that also carries a `learnerName` parameter
- WHEN the controller calls the engine
- THEN the engine receives only the allowlisted fields
- AND the `learnerName` value appears in no argument

#### Scenario: The prompt forbids personal data

- GIVEN the feature is enabled
- WHEN any action builds its prompt
- THEN the prompt contains an instruction not to include names or other personal data

### Requirement: REQ-003: Every result is marked as a draft

Every successful response SHALL carry `draft: true` and a non-empty `draftNotice`, together with `available: true`, the `action` and the resolved `provider`.

#### Scenario: A success carries the draft marker

- GIVEN the feature is enabled and the provider returns usable text
- WHEN any action completes
- THEN the response has `available: true`, `draft: true` and a non-empty `draftNotice`
- AND it names the `action` and the `provider`

### Requirement: REQ-004: Draft a lesson outline from learning goals

The `outline` action SHALL take one or more goal titles, optional lesson text and a language, and SHALL return the model's outline as `draftText`.

#### Scenario: Goals and language reach the outline prompt

- GIVEN the feature is enabled
- WHEN an outline is requested for goal "Breuken vergelijken" in language `nl`
- THEN the prompt contains the goal title and the language tag
- AND the response carries the trimmed model output as `draftText`

### Requirement: REQ-005: Suggest questions for a lesson

The `questions` action SHALL take lesson text, optional goal titles, a question count (1 to 10, default 5) and a language. It SHALL return `questions` as a list of strings, with list numbering and bullets stripped, empty lines dropped, and at most `questionCount` entries.

#### Scenario: Numbered lines become a clean list capped at the count

- GIVEN the feature is enabled and the provider returns four numbered lines
- WHEN three questions are requested
- THEN `questions` holds exactly three strings
- AND none of them starts with a number or a bullet

### Requirement: REQ-006: Rewrite a text at a lower reading level

The `simplify` action SHALL take lesson text and a target reading level (`A1`, `A2`, `B1`, `B2`, `1F`, `2F` or `3F`, default `B1`). It SHALL tell the model to keep the text's own language and meaning, and SHALL return `draftText` and the `readingLevel` used. It rewrites a text only. It MUST NOT assess, rank or place a pupil.

#### Scenario: The target level reaches the prompt

- GIVEN the feature is enabled
- WHEN a rewrite at level `A2` is requested
- THEN the prompt names level `A2` and tells the model to keep the original language
- AND the response carries `draftText` and `readingLevel: "A2"`

### Requirement: REQ-007: Suggest which given goals a lesson covers

The `goal-suggestions` action SHALL take lesson text and a list of goal titles. It SHALL return `suggestedGoals` as `{index, title}` entries. `index` is the 0-based position in the request list. The system MUST drop any number outside the list, MUST remove duplicates, MUST sort by index and MUST NOT return a goal the caller did not send. An answer of `NONE` SHALL yield an empty list.

#### Scenario: Only listed goals come back, by position

- GIVEN the feature is enabled and three goal titles are sent
- WHEN the provider answers "3, 1, 7, 1"
- THEN `suggestedGoals` is `[{index: 0, ...}, {index: 2, ...}]`
- AND each title equals the title the caller sent at that index

#### Scenario: NONE is a valid empty answer

- GIVEN the feature is enabled
- WHEN the provider answers "NONE"
- THEN the response is `available: true` with an empty `suggestedGoals`

### Requirement: REQ-008: A provider failure or unusable output degrades to unavailable

The system SHALL catch every provider failure and return `{available: false, action, reason: "provider-error"}` instead of throwing. Output that yields nothing usable SHALL also return `provider-error`: empty text, a question list with no entries, a goal answer with neither a number nor `NONE`, or a goal answer whose numbers all fall outside the list.

#### Scenario: A provider exception is not thrown to the caller

- GIVEN the feature is enabled and the provider throws
- WHEN any action is requested
- THEN the result is `{available: false, action, reason: "provider-error"}`

#### Scenario: A garbage goal answer is not read as "no goals"

- GIVEN the feature is enabled
- WHEN the provider answers goal suggestions with text holding no number and no `NONE`
- THEN the result is `provider-error`, not an empty `suggestedGoals`

#### Scenario: Only out-of-range numbers are not read as "no goals"

- GIVEN the feature is enabled and three goal titles are sent
- WHEN the provider answers "7"
- THEN the result is `provider-error`, not an empty `suggestedGoals`

### Requirement: REQ-009: Every call is logged without its content

The engine SHALL write one `info` log line per call through the PSR-3 logger, gated or not, successful or not. The line carries the action, the user id, the outcome (`ok`, `feature-not-enabled` or `provider-error`), the provider when known, the lesson text length and the goal count. It MUST NOT carry the lesson text, the goal titles or the model output.

#### Scenario: A gated call is logged

- GIVEN the feature is disabled
- WHEN an action is requested by user `teacher1`
- THEN one info line is logged with action, user `teacher1` and outcome `feature-not-enabled`

#### Scenario: A successful call is logged without the text

- GIVEN the feature is enabled and the provider answers
- WHEN an action runs on a lesson text
- THEN one info line is logged with outcome `ok`
- AND neither the message nor the context contains the lesson text or the model output

### Requirement: REQ-010: The endpoints authenticate, validate and rate limit before the gate

The four endpoints (`POST /api/lesson-authoring/{outline,questions,simplify,goal-suggestions}`) SHALL require a Nextcloud session and keep the CSRF check on. Each SHALL carry a per-user rate limit. Each SHALL answer 401 without a user and 400 for a missing or malformed field, before the engine is called. The limits are: `lessonText` 1 to 20,000 characters, `goalTitles` 1 to 100 non-empty strings of at most 300 characters, `language` a BCP-47 tag, `questionCount` 1 to 10, `readingLevel` from the enum. An unexpected engine exception SHALL map to 500.

#### Scenario: No session is refused

- GIVEN no authenticated user
- WHEN any endpoint is called
- THEN the status is 401
- AND the engine is not called

#### Scenario: A missing required field is refused

- GIVEN an authenticated user
- WHEN `goal-suggestions` is called without `goalTitles`
- THEN the status is 400
- AND the engine is not called

#### Scenario: A value outside its limits is refused

- GIVEN an authenticated user
- WHEN `questions` is called with `questionCount: 50`, or `simplify` with `readingLevel: "C2"`
- THEN the status is 400
- AND the engine is not called

### Requirement: REQ-011: The model runs through the Hermiq provider factory

The engine SHALL call the model only through `ProviderFactory::generateText()`, passing the requesting user's id. When the admin selects the `nextcloud` driver, this runs as a Nextcloud task processing `core:text2text` task (`OCP\TaskProcessing\IManager`) attributed to that user. The engine MUST NOT open its own vendor client.

#### Scenario: The requesting user is passed to the provider

- GIVEN the feature is enabled
- WHEN user `teacher1` requests an action
- THEN `generateText()` is called once with a prompt and user id `teacher1`

## Non-Functional Requirements

- **Performance:** one provider call per request, within the existing `ProviderFactory` timeout. No new timeout, queue or cache.
- **Accessibility:** not applicable. No UI ships in this change; the learniq consumer owns the buttons and states.
- **Internationalization:** Dutch and English MUST be supported (ADR-005). `language` accepts any BCP-47 tag and defaults to `nl`; `simplify` keeps the input's language. `draftNotice` is an English fallback; consumers render a translated label from `draft: true`.
- **Security:** no caller-supplied identifier is looked up, so there is no object to authorise; the IDOR gate exemption is recorded on each method with its reason.

## Acceptance Criteria

- [ ] A disabled or missing `lesson-authoring` feature makes zero provider calls for all four actions.
- [ ] The seed creates the feature disabled at limited risk, once.
- [ ] Every success carries `draft: true` and a non-empty `draftNotice`.
- [ ] Questions come back as a clean list capped at the requested count.
- [ ] Goal suggestions return only indexes inside the caller's list; `NONE` is empty, garbage is `provider-error`.
- [ ] Each call writes one info log line with no lesson text, goal titles or output.
- [ ] Each endpoint answers 401 without a user and 400 on a bad field, before the engine runs.

## Notes

- OCP interfaces used: `OCP\TaskProcessing\IManager` (through `ProviderFactory`, `nextcloud` driver), `OCP\IUserSession`, `OCP\IRequest`, `OCP\AppFramework\Http\Attribute\NoAdminRequired`, `OCP\AppFramework\Http\Attribute\UserRateLimit`, `OCP\Migration\IRepairStep`, and `Psr\Log\LoggerInterface`.
- Entities: no new schema. The governance row is an `agentaifeature` object; the register carries no Schema.org type for it, and the nearest is `schema:SoftwareApplication`. The drafts are unsaved `schema:CreativeWork` text until learniq stores them in a `Lesson` (`schema:LearningResource`).
- EU AI Act: limited risk, because the actions draft content for a teacher and decide nothing about a person. A level-assignment use would be Annex III 3(b) high risk and is out of scope.
- Pattern: hermiq `feat/message-translation-delegate` (`75c66d16`).
