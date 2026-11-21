# answer-cache Specification

**Status**: proposed
**Scope**: hermiq
**OpenSpec changes**:
- models-prompt-and-answer-cache

## Purpose

An agent answers a repeated question from a stored answer, within one organisation, without a model call. Row `integriq:gw-ai-cache` (integriq's matrix).

## ADDED Requirements

### Requirement: The answer cache is opt-in per agent and only for turns without tools (REQ-ACACHE-001)

Hermiq MUST read or write the answer cache only when the agent has `answerCache.enabled`, the turn offers no tools, the turn is not a dry run or a replay, and the message has no attachment. The lookup MUST run after the kill switch, approval, budget and model policy gates.

#### Scenario: An agent owner switches the cache on
- GIVEN an agent owner editing "Veelgestelde vragen afvalinzameling", an agent without tools
- WHEN they switch on "Reuse answers to repeated questions" with a lifetime of 7 days and save
- THEN the agent shows "Answer cache: on, 7 days"

#### Scenario: An agent with tools never uses the cache
- GIVEN an agent with `hermiq.listCalendarEvents` granted and the answer cache on
- WHEN a user asks the same question twice
- THEN both turns call the model, and neither run records a cache entry
- @e2e exclude needs two live model turns; covered by PHPUnit on the eligibility check

### Requirement: An exact repeat within one organisation gets the stored answer (REQ-ACACHE-002)

Hermiq MUST key an entry on the organisation, the agent, the agent version, the provider, the model, the temperature and the full assembled input. A hit MUST only be served when the entry's provider and model are still allowed by the effective model policy. An entry MUST NOT be read by another organisation.

#### Scenario: A resident's question is answered from the cache
- GIVEN a cached answer to "Wanneer wordt het oud papier opgehaald?" for this agent version in "Gemeente Voorbeeld"
- WHEN another user in the same organisation asks exactly that question
- THEN the reply appears without a model call, and the chat shows "Answered from cache" with an "Ask the model again" button

#### Scenario: The same question in another organisation is not served
- GIVEN that cached answer in "Gemeente Voorbeeld"
- WHEN a user in "Gemeente Anders" asks the same question to an agent with the same prompt
- THEN the model is called, and no entry of "Gemeente Voorbeeld" is read
- @e2e exclude needs two organisations with live model turns; covered by PHPUnit and a Newman check against the object API

### Requirement: Similar questions match only through OpenRegister's vector facade (REQ-ACACHE-003)

Hermiq MUST match similar questions only through OpenRegister's public vector facade, only for the first question of a session, and only when the agent's `similarity` is `user` or `organisation`. Hermiq MUST re-check every returned row for organisation, agent version, scope, expiry and a similarity of at least 0.95 before serving it. `organisation` scope MUST be refused for an agent with retrieval on. Hermiq MUST NOT compute or store embeddings itself.

#### Scenario: No vector search, no similarity layer
- GIVEN an instance whose OpenRegister has no vector facade
- WHEN an agent owner opens the answer cache settings
- THEN the similarity choice is disabled with "Similar questions are not matched: OpenRegister has no vector search here."

#### Scenario: A differently worded question within scope
- GIVEN the vector facade is available and an agent with `similarity: organisation` and retrieval off
- WHEN a user asks "Wanneer halen jullie oud papier op?"
- THEN the stored answer is served when the facade returns the cached question with similarity 0.95 or more, and the run records `layer: similar` with the score
- @e2e exclude needs an OpenRegister embedding backend; covered by PHPUnit with a stubbed facade

### Requirement: Cached answers can be cleared and expire (REQ-ACACHE-004)

Hermiq MUST remove an entry when it expires, when the agent owner chooses "Clear cached answers", and when a user gives a thumbs down on a message served from it. The stored question MUST be redacted.

#### Scenario: A thumbs down removes a wrong answer
- GIVEN a reply served from the cache
- WHEN the user gives it a thumbs down
- THEN the entry is removed, and the same question next time calls the model
