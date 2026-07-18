# agent-guardrails Specification

**Status**: in-progress
**Scope**: hermiq
**OpenSpec changes**:
- hermiq-guardrail-preamble-filter

## Purpose

Guardrails let an organisation constrain what an agent may be told, what it may say, and what it
may do — via a per-organisation `GuardrailPolicy` with a fully-open fallback. This change closes
the gap between the existing "input is filtered before every LLM turn" requirement and its
implementation: the *assembled context preamble* (ADR-024) is model input and MUST be filtered
like any other. See ADR-024 Rule 3 (context is untrusted-by-default input) and ADR-023 (runs
inherit the acting user's identity — which does not authenticate context *content*).

## MODIFIED Requirements

### Requirement: Input is filtered before every LLM turn
The system MUST apply the effective policy's input filters to **every** untrusted text it sends to
the LLM for a turn — both the user/prompt text and the assembled context preamble (the
concatenation of every `Context` object the agent references, per ADR-024) — before either is sent:
a PII/secret match MUST be redacted (masked) when `piiAction: redact`, or the turn MUST be refused
before the LLM is ever called when `piiAction: block`; a detected prompt-injection pattern MUST
refuse the turn before the LLM is ever called when `promptInjectionAction: block`. The redacted
(not the original) text MUST be what is both sent to the LLM and persisted, when the action is
`redact`.

The two texts MUST be filtered **separately**, so that a match is attributable to the boundary it
came from: a refusal caused by the context preamble MUST carry a reason distinct from the same
match in the user's message, and MUST be distinguishable in the run's step timeline. A turn refused
at either boundary MUST persist no user or assistant `Message`.

#### Scenario: PII in the input is redacted, not blocked
- **GIVEN** an organisation's policy sets `inputFilters.piiAction: redact`
- **WHEN** a user's message contains a detectable secret or PII pattern
- **THEN** the system MUST send the redacted (masked) text to the LLM
- **AND** the persisted copy of the message MUST also be the redacted text, never the original

#### Scenario: A detected prompt-injection pattern blocks the turn before the LLM runs
- **GIVEN** an organisation's policy sets `inputFilters.promptInjectionAction: block`
- **WHEN** an incoming prompt matches a known instruction-override pattern
- **THEN** the system MUST NOT call the LLM at all for this turn
- **AND** the block MUST be recorded as a guardrail violation (see the run-history requirement
  below)

#### Scenario: A prompt-injection inside an attached Context document blocks the turn
- **GIVEN** an organisation's policy sets `inputFilters.promptInjectionAction: block`
- **AND** an agent references a `Context` object whose content matches a known
  instruction-override pattern
- **WHEN** a user sends a message that itself contains no injection
- **THEN** the system MUST NOT call the LLM at all for this turn
- **AND** the refusal reason MUST identify the context preamble as the source, distinct from the
  reason used when the user's own message matches
- **AND** no user or assistant `Message` MUST be persisted for this attempt

#### Scenario: PII in an attached Context document is redacted before the model sees it
- **GIVEN** an organisation's policy sets `inputFilters.piiAction: redact`
- **AND** an agent references a `Context` object whose content contains a detectable secret
- **WHEN** an agent turn runs
- **THEN** the context preamble sent to the LLM MUST be the redacted (masked) text, never the
  original

#### Scenario: An agent with no attached Context is unaffected
- **GIVEN** an agent that references no `Context` object, so the assembled preamble is empty
- **WHEN** an agent turn runs under any policy
- **THEN** the system MUST NOT record a context-preamble guardrail step
- **AND** the turn MUST behave exactly as it did before context-preamble filtering existed

#### Scenario: A blocked scheduled run is recorded and alerted like any other run failure
- **GIVEN** a schedule whose configured `prompt` is blocked by the effective input policy
- **WHEN** the dispatcher fires the schedule
- **THEN** the run MUST fail with a guardrail-block reason exactly like any other agent-turn
  failure, inheriting the existing retry/dead-letter/circuit-breaker handling and owner alerting
  — no new failure-handling path is introduced

## Non-Functional Requirements

- **Performance:** The context-preamble filter MUST add no measurable cost to a turn whose agent
  has no attached `Context` (the assembled preamble is empty). For an agent with attached Context,
  the filter runs once per turn over the assembled preamble, and MUST NOT introduce a second
  `GuardrailPolicy` read — the policy resolved for the user-message boundary MUST be reused.
- **Accessibility:** No UI surface — not applicable.
- **Internationalization:** The refusal reason is a stable machine-matchable code, not
  user-facing prose; the frontend keys its existing translated message off the unchanged
  `errorCode: guardrail_blocked` (ADR-005).

## Acceptance Criteria

- A prompt-injection pattern present only in the assembled context preamble refuses the turn when
  `promptInjectionAction: block`, and the LLM is never called.
- The refusal reason for a preamble match is distinct from the reason for the same match in the
  user's message.
- A PII match in the preamble under `piiAction: redact` means the masked preamble reaches the LLM.
- The user message and the preamble are filtered by two separate `filterInput()` calls.
- A fully-open policy with a non-empty preamble records zero guardrail steps.
- An organisation with no `GuardrailPolicy` sees an unchanged step timeline.
- A test exists that fails against the pre-change Engine and passes after.

## Notes

- Deliberately **not** covered: the RAG context block from `ContextRetrievalHandler::retrieveContext()`
  is a separate unfiltered path into the same prompt. This requirement's "every untrusted text"
  wording is scoped by the two texts it names; closing the RAG path needs its own change.
- The false-positive class is real: a `Context` that *documents* prompt injection (a security
  policy quoting an override phrase) will refuse every turn while `promptInjectionAction: block`.
  The escape hatch is the per-organisation policy and the `off` default.
