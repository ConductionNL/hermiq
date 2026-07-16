## ADDED Requirements

### Requirement: Recommendation generation is gated by a high-risk, DPO-acknowledged AiFeature

The system SHALL NOT compute or serve course recommendations for any learner unless
the `course-recommendations` `AiFeature` object (seeded `riskCategory: high`, EU AI
Act Annex III §3) is `lifecycle: enabled` for the caller's tenant. The gate MUST be
checked before any cross-app data read, LLM call, or scoring — a disabled feature
MUST have zero data-access footprint, not merely a hidden UI. The feature MUST use
Hermiq's existing `AiFeatureDpoAckGuard`-gated `enable` transition unchanged — no new
governance mechanism is introduced.

#### Scenario: Recommendations are unavailable before DPO acknowledgement

- **GIVEN** the `course-recommendations` `AiFeature` exists in `lifecycle: disabled`
- **WHEN** an authenticated learner requests their recommendations
- **THEN** the system MUST return a `status: unavailable` result
- **AND** MUST NOT query any Scholiq object or invoke any LLM provider

#### Scenario: Recommendations compute once the feature is DPO-acknowledged and enabled

- **GIVEN** the `course-recommendations` `AiFeature` has been acknowledged
  (`AiFeatureController::acknowledge`) and enabled (`AiFeatureController::enable`)
  for the caller's tenant
- **WHEN** the same learner requests their recommendations
- **THEN** the system MUST proceed to read signals and compute a ranked list

@e2e exclude Backend gate logic with no dedicated UI in this change (the AiFeature
register UI already exists and is covered by prior changes); covered by a PHPUnit
test asserting the engine short-circuits before any `ObjectService`/LLM call when the
feature is absent or disabled.

### Requirement: Cross-app signal reads degrade gracefully at every seam

The system SHALL read the learner's `Enrolment`, `Course`, `XapiStatement`, and
`LearningPlan` objects from Scholiq's register (`register: scholiq`) via
`ObjectService`, filtered to the caller's own learner identity. Each read MUST be
independently wrapped so that Scholiq being not installed
(`IAppManager::isInstalled('scholiq') === false`), a read failure, or an unknown
schema (e.g. the optional competency-gap signal, whose schema does not exist in
Scholiq at this revision) degrades only that signal to "unavailable" and MUST NOT
raise an exception that aborts the whole computation. When Scholiq is not installed
at all, the system SHALL return a `status: unavailable` result rather than a partial
ranking.

#### Scenario: Scholiq not installed

- **GIVEN** Scholiq is not installed on this Nextcloud instance
- **WHEN** a learner requests their recommendations
- **THEN** the system MUST return `status: unavailable`
- **AND** MUST NOT throw an unhandled exception

#### Scenario: Optional competency-gap signal is absent

- **GIVEN** Scholiq is installed but does not ship a `competency-attainment` schema
  (the wave-2 `competency-framework` capability has not shipped yet)
- **WHEN** a learner requests their recommendations
- **THEN** the system MUST compute a ranked list from the remaining four signal
  sources (`Enrolment`, `Course`, `XapiStatement`, `LearningPlan`)
- **AND** `signalsUsed.competencyDataAvailable` MUST be `false`
- **AND** no `matchedSignals` entry MUST claim a competency-gap match

#### Scenario: A single signal read fails without failing the whole computation

- **GIVEN** the `XapiStatement` read throws (e.g. a transient OpenRegister error)
- **WHEN** the engine computes recommendations
- **THEN** the failure MUST be logged
- **AND** the ranking MUST still be produced from the remaining available signals

@e2e exclude Backend-only degrade paths with no UI surface in this change; covered
by PHPUnit tests stubbing `ObjectService`/`IAppManager` for each of the three
degrade scenarios.

### Requirement: Ranking is deterministic and reproducible; explanation phrasing is optional and never changes it

The system SHALL compute the candidate ranking via a deterministic, weighted
multi-signal score over explicit signals (goal alignment, curriculum-path
continuation, mandatory-renewal proximity, engagement recency, and — when
available — competency-gap closure). The ranking and its underlying `matchedSignals`
breakdown MUST be reproducible from the same input data and MUST NOT depend on any
LLM call. An LLM MAY be used only to phrase the human-readable `explanation` string
for an already-ranked candidate, grounded strictly in that candidate's own
`matchedSignals`; the LLM response MUST NOT alter the candidate's rank, score, or
`matchedSignals`, and MUST NOT introduce a course outside the deterministically
computed candidate set.

#### Scenario: Re-running the deterministic stage on the same data yields the same ranking

- **GIVEN** an unchanged set of Scholiq signal data for a learner
- **WHEN** the deterministic scoring stage is run twice
- **THEN** both runs MUST produce the same `rank`, `score`, and `matchedSignals` for
  every candidate

#### Scenario: LLM phrasing does not change which courses are recommended

- **GIVEN** a fixed, deterministically-ranked candidate list
- **WHEN** the optional LLM explanation step runs
- **THEN** the resulting `recommendations[]` array MUST contain the same
  `courseId`s, in the same `rank` order, as before the LLM call
- **AND** the LLM call's prompt MUST include only that one candidate's own signal
  breakdown, never the full candidate list or another learner's data

@e2e exclude Deterministic scoring is a pure-function unit-test target; covered by
PHPUnit asserting idempotent output and that the LLM step is prompt-scoped to a
single candidate's data.

### Requirement: A recommendation is never returned without an explanation

The system SHALL NOT persist or return a `recommendations[]` entry that lacks a
non-empty `explanation` and at least one `matchedSignals` entry. When the optional
LLM-phrasing step is unavailable — `ProviderUnavailableException` from the
credential-broker-backed `ProviderFactory`, or the tenant's kill-switch
(`ScheduleService::isOrganisationEngaged()`) is engaged — the system SHALL fall back
to a deterministic, template-built explanation derived from the same
`matchedSignals` breakdown, and SHALL record `explanationMode: template` (as
opposed to `llm-assisted`) on the persisted object.

#### Scenario: LLM provider unavailable falls back to a deterministic explanation

- **GIVEN** no LLM provider credential is configured for the tenant
- **WHEN** the engine computes recommendations
- **THEN** every returned entry MUST still have a non-empty `explanation`
- **AND** `explanationMode` on the persisted `CourseRecommendation` MUST be `template`

#### Scenario: Tenant kill-switch engaged skips the LLM call entirely

- **GIVEN** the caller's organisation has an engaged `TenantControl` kill-switch
- **WHEN** the engine computes recommendations
- **THEN** the system MUST NOT call `ProviderFactory::generateText()`
- **AND** MUST still return a fully-explained ranked list using the deterministic
  template

@e2e exclude No LLM provider is configured in CI/dev by default, matching the
project's existing `agent-engine-port` exclusion rationale; covered by a PHPUnit
test asserting the fallback template path and the kill-switch short-circuit.

### Requirement: Recommendations are advisory only — no automated enrolment, no profiling of protected characteristics

The system SHALL NOT write, modify, or trigger any `Enrolment` object, and SHALL NOT
expose any mutation that marks a recommendation as "accepted" or "actioned". The
`CourseRecommendation` schema SHALL carry no field representing enrolment or
acceptance state. The signal set consulted SHALL be limited to learning-activity and
self-declared data the learner already has visibility into in Scholiq (enrolments,
completions, xAPI activity, `LearningPlan` goals, and — when available —
competency-gap data); it SHALL NOT include demographic or protected-characteristic
data.

#### Scenario: No enrolment side-effect from generating recommendations

- **GIVEN** a learner requests and receives a ranked recommendation list
- **WHEN** the response is inspected
- **THEN** no `Enrolment` object MUST have been created, modified, or scheduled for
  creation as a result

#### Scenario: The schema exposes no acceptance/enrolment mutation

- **GIVEN** the `CourseRecommendation` schema and `CourseRecommendationController`
- **WHEN** their full field list and route table are inspected
- **THEN** no field or endpoint MUST exist that marks a recommendation as accepted,
  actioned, or that creates an enrolment

@e2e exclude Structural/negative-space requirement verified by schema inspection +
route-table review, not runtime behaviour; enforced by code review and the
`hydra-gate-route-reachability`/`redundant-controller` mechanical gates finding no
such route.

### Requirement: Recommendation access is self-scoped to the caller's own learner identity

The system SHALL resolve the `learnerId` used to read signals and generate
recommendations exclusively from the caller's own authenticated session
(`IUserSession`), never from a caller-supplied identifier. Both the REST endpoint
(`CourseRecommendationController::index()`) and the MCP tool
(`hermiq.recommendCourses`) SHALL apply this same rule — neither accepts a
`learnerId` parameter from the request in this change.

#### Scenario: A caller cannot request another learner's recommendations

- **GIVEN** an authenticated user
- **WHEN** they call the recommendations endpoint or MCP tool
- **THEN** the system MUST return recommendations for that caller's own NC user id
  only
- **AND** no request parameter MUST be capable of substituting a different learner

#### Scenario: Unauthenticated callers are refused

- **GIVEN** an unauthenticated request
- **WHEN** it reaches the recommendations endpoint
- **THEN** the system MUST refuse it (401) before any signal read occurs

@e2e exclude No UI surface ships in this change (self-scope enforced at the
controller/MCP-tool boundary); covered by a PHPUnit test asserting `learnerId` is
never read from request input and an unauthenticated call is refused.

### Requirement: Ranked recommendations are chat-companion-reachable via a domain MCP tool

The system SHALL expose the same recommendation capability as an MCP tool
(`hermiq.recommendCourses`) on the existing `HermiqToolProvider`, delegating to the
identical `CourseRecommendationEngine` call the REST endpoint uses — no duplicated
scoring, gating, or signal-read logic. The tool SHALL follow the provider's existing
no-throw convention (`invokeTool()` never raises across the MCP boundary; failures
return a structured `{isError: true, error, message}` response).

#### Scenario: The chat companion can answer "what should I study next"

- **GIVEN** a learner chatting with the Hermiq-backed chat companion
- **WHEN** they ask what course to take next
- **THEN** the companion MUST be able to invoke `hermiq.recommendCourses`
- **AND** receive the same ranked, explained list the REST endpoint would return
  for that same learner

#### Scenario: A tool failure never crosses the MCP boundary as an exception

- **GIVEN** any failure inside the recommendation engine (e.g. Scholiq absent)
- **WHEN** `hermiq.recommendCourses` is invoked
- **THEN** the response MUST be a structured result (success with `status:
  unavailable`, or `{isError: true, ...}`), never an uncaught exception

@e2e exclude Mirrors the existing `ai-companion-tools`/`nc-native-tools` exclusion
rationale — no browser-driven UI exercises MCP tool calls directly; covered by a
PHPUnit test asserting the tool catalogue includes `hermiq.recommendCourses` and
that `invokeTool()` never throws for any exercised failure path.
