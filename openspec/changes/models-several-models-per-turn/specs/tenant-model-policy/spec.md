# tenant-model-policy Specification

**Status**: proposed
**Scope**: hermiq
**OpenSpec changes**:
- models-several-models-per-turn

## Purpose

An organisation's model policy names a fallback chain, and a turn moves to the next allowed provider when the first one fails. Rows `hermiq:mo-failover` and `integriq:gw-ai-proxy` (integriq's matrix).

## ADDED Requirements

### Requirement: A model policy may name a fallback chain (REQ-MFAIL-001)

A `ModelPolicy` MAY carry `fallbackChain`, an ordered list of at most four provider and model pairs. Hermiq MUST refuse to save a chain entry that the same policy does not allow.

#### Scenario: An organisation admin adds a local model as fallback
- GIVEN an organisation admin on the Tenant operations page, in the Model policy section, with a policy allowing `anthropic` and `ollama`
- WHEN they add `ollama` with model `qwen2.5` under "Fallback when a provider fails" and save
- THEN the policy shows the fallback, and `GET /api/model-policy/effective` returns it in `fallbackChain`

#### Scenario: A fallback outside the policy is refused
- GIVEN a policy that allows only `ollama`
- WHEN the organisation admin adds `openai` with `gpt-4o-mini` as a fallback and saves
- THEN the save is refused with "This fallback is not allowed by the model policy." and the stored policy is unchanged

### Requirement: A temporary provider failure moves the turn to the next allowed hop (REQ-MFAIL-002)

When a provider call fails with a rate limit, a server error, a timeout, a connection error or an unavailable provider, and no answer text has been shown and no tool has run in the turn, hermiq MUST retry the turn on the next hop of the effective fallback chain. Before each hop hermiq MUST run the same model policy check and, when the run names an AI feature, the same residency and redaction checks as for the first provider. A hop that fails a check MUST be skipped and recorded, and MUST NOT be called.

#### Scenario: The hosted model hits its rate limit
- GIVEN an agent in organisation "Gemeente Voorbeeld" whose policy lists `anthropic` first and `ollama` `qwen2.5` as fallback
- WHEN a case handler sends a message in the chat and Anthropic answers HTTP 429
- THEN the reply comes from `ollama` `qwen2.5` in the same turn, and the run's steps show "Anthropic was rate limited. Answered by ollama qwen2.5."

#### Scenario: A fallback that breaks the feature's residency is skipped
- GIVEN an agent whose AI feature requires residency `eu`, and a chain whose first hop `openai` is declared `outside-eu` and whose second hop `fireworks` is declared `eu`
- WHEN the first provider fails with HTTP 503
- THEN hermiq does not call `openai`, records it as skipped with step `residency`, and answers from `fireworks`
- @e2e exclude needs a provider that fails on demand; covered by PHPUnit on the fallback loop with stubbed drivers

#### Scenario: A failure after a tool call is not retried elsewhere
- GIVEN a turn in which the model already called `hermiq.createCalendarEvent`
- WHEN the provider then fails with HTTP 500
- THEN the turn fails as it does today, no other provider is called, and the run says "Not retried: a tool already ran in this turn"
- @e2e exclude needs a provider that fails on demand after a tool call; covered by PHPUnit on the fallback loop

### Requirement: A refusal or a configuration error never triggers a fallback (REQ-MFAIL-003)

A model policy refusal, a residency refusal, a redaction refusal, an authentication failure (HTTP 401 or 403) and a request the provider rejects as malformed (HTTP 400) MUST end the turn without trying another hop.

#### Scenario: A revoked key is shown, not hidden
- GIVEN a fallback chain and a first provider whose key was revoked in the credential broker
- WHEN a scheduled run starts and the provider answers HTTP 401
- THEN the run fails with the provider's authentication message, and no other provider is called
- @e2e exclude needs a revoked provider key; covered by PHPUnit with a stubbed broker response

### Requirement: A failing provider cools down per organisation (REQ-MFAIL-004)

After a hop fails with a rate limit or a server error, hermiq MUST skip that provider for that organisation for the provider's `retry-after`, or 60 seconds when none was sent. A cooldown MUST NOT last longer than 600 seconds and MUST NOT affect another organisation.

#### Scenario: The next turn starts at the healthy hop
- GIVEN Anthropic answered HTTP 429 with `retry-after: 30` for "Gemeente Voorbeeld" ten seconds ago
- WHEN another case handler in the same organisation sends a message
- THEN hermiq does not call Anthropic, records it as cooling, and answers from the next hop
- @e2e exclude timing across two turns against a failing provider; covered by PHPUnit with a fake cache and clock

### Requirement: Every attempt is on the run record (REQ-MFAIL-005)

Hermiq MUST record each attempt of a turn on the run: provider, model, outcome (`answered`, `failed`, `skipped` or `cooling`), reason and duration. The provider disclosure MUST name the provider and model that answered and MUST list in `receivedBy` every provider and model that was sent the prompt.

#### Scenario: A privacy officer reads where a prompt went
- GIVEN a scheduled run that fell back from `anthropic` to `ollama`
- WHEN a privacy officer opens that run on the Runs page
- THEN the run shows both attempts in order, the first marked "rate limited", and the disclosure lists both providers under "Received the prompt"
