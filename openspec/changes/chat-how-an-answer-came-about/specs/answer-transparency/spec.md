# answer-transparency Specification

**Status**: proposed
**Scope**: hermiq
**OpenSpec changes**:
- chat-how-an-answer-came-about

## Purpose

A person sees how an answer came about, can read the model's reasoning when the agent owner allows it, and sees how full the model's context is. Rows `hermiq:td-explain`, `hermiq:dm-show-reasoning` and `hermiq:dm-context-meter`.

## ADDED Requirements

### Requirement: Every answer records how it came about (REQ-ATRN-001)

Hermiq MUST store on each assistant turn a provenance record with the feature, provider, model, residency and location that served it, the sources it used, and the type, name and outcome of each tool and guardrail step. The record MUST NOT contain tool arguments, tool results, document text or reasoning. The send path and the stream path MUST produce the same record.

#### Scenario: A streamed answer gets the same record as a sent one
- GIVEN an agent that reads one file with `hermiq.readFile` to answer
- WHEN the same question is asked once through `/api/chat/send` and once through `/api/chat/stream`
- THEN both assistant turns carry a provenance record naming the model, the file as a source and the `hermiq.readFile` step with outcome `ok`, and neither holds the file's text
- @e2e exclude compares stored records across two transports; covered by PHPUnit on the engine and a Newman pair

### Requirement: A person can read how an answer came about in plain language (REQ-ATRN-002)

The Chat page MUST offer under each assistant answer a folded section "How this answer came about". Hermiq MUST build its sentences from the provenance record in the reader's language, MUST NOT ask a model to write them, and MUST end them with "You decide what to do with this answer."

#### Scenario: A legal aid worker checks what an answer rests on
- GIVEN a legal aid worker at Het Juridisch Loket with an answer that used two documents and one refused tool call
- WHEN they open "How this answer came about"
- THEN they read which model answered and where it runs, the two document names, the refused call with its reason, and "You decide what to do with this answer."
- e2e: `tests/e2e/spec-coverage/answer-transparency.spec.ts`

### Requirement: The model's reasoning is shown only when the owner allows it and the provider returns it (REQ-ATRN-003)

Hermiq MUST request reasoning only for an agent whose owner switched on "Show the model's reasoning", and only from a provider that returns it. It MUST pass the reasoning through the organisation's output filter and redaction before storing it on the turn. It MUST deliver the reasoning inside the `final` stream event and MUST NOT send it as `token` events or as a new event type.

#### Scenario: A researcher reads the reasoning next to the answer
- GIVEN an agent on an Anthropic model with "Show the model's reasoning" switched on
- WHEN a researcher asks it to compare two subsidy schemes
- THEN the answer shows a folded "Model reasoning" section with the model's text and the line "This is the model's own text. It can be wrong."
- e2e: `tests/e2e/spec-coverage/answer-transparency.spec.ts`

#### Scenario: An older companion does not print reasoning as the answer
- GIVEN an agent with reasoning switched on, used from a companion that appends every token delta to the answer
- WHEN it streams an answer
- THEN every `token` event carries answer text only, and the reasoning arrives once, in the `final` event
- @e2e exclude inspects the raw SSE frames; covered by PHPUnit on `ChatStreamController`

### Requirement: A person sees how full the model's context is (REQ-ATRN-004)

Hermiq MUST report after each turn the tokens the session uses and, when the model's context window is declared, that window. It MUST use the provider's own count when the driver reports one and MUST mark any other number as an estimate. The Chat page MUST show the readout under the message box and MUST warn above 80% of a declared window.

#### Scenario: An analyst sees the session filling up
- GIVEN a session on a model with a declared window of 128,000 tokens that has used 105,000
- WHEN the analyst sends the next message
- THEN the readout shows "Context: 106,300 of 128,000 tokens (83%)" and "The session is close to the model's limit. Start a new session or fork from an earlier answer."
- e2e: `tests/e2e/spec-coverage/answer-transparency.spec.ts`

#### Scenario: A provider without a token count gets an honest estimate
- GIVEN an agent on Fireworks, which reports no token count
- WHEN a person sends a message
- THEN the readout starts with "Context: about"
- e2e: `tests/e2e/spec-coverage/answer-transparency.spec.ts`
