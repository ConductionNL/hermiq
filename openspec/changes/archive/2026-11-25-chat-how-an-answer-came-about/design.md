# Design: chat-how-an-answer-came-about

Kind: code. Size M. `Engine`, `ResponseGenerationHandler`, the Anthropic and Ollama drivers, `ChatStreamController`, two optional `SessionTurn` properties, one optional `Agent` property, and the Chat page. The schema properties are incidental to the code, so the change is `kind: code` under hydra ADR-032. It depends on `chat-attachments-and-images` for the model registry (its D4), which this change extends with a context window size.

## Context at development db6b74dc

- `lib/Service/Engine/Engine.php:262` `processMessage()`; `:355-381` input guardrail and its trace step; `:465-481` output guardrail; `:488-493` assistant turn stored with `sources`; `:509-532` the returned `message`, `messageId`, `sources`, `timings`, `usage`, `steps`, `skillsUsed`.
- `lib/Service/Engine/RunTraceCollector.php:111` `startStep()`, `:149` `endStep()` with `$extra`, `:189` `recordProviderDisclosure()` (feature, provider, model, residency, location), `:221` `toArray()`.
- `lib/Service/Engine/FacadeToolInvoker.php:568-575`, `:1256-1260`, `:1280-1290`, `:1452-1456` the extras a tool step may carry.
- `lib/Service/Engine/ResponseGenerationHandler.php:94` `lastUsage`; `:295-303` provider disclosure; `:388` Fireworks latency only; `:451-453` Anthropic latency only; `:491-496` Ollama usage.
- `lib/Service/Llm/ProviderFactory.php:955` `callAnthropicChat()`, `:2127` `parseAnthropicResponse()`, `:2159-2166` Anthropic usage parsed.
- `patches/llphant-ollama-think-keepalive.patch:9-14` forces `think: false` and `keep_alive: -1` on every Ollama call.
- `lib/Service/Engine/ConversationManagementHandler.php:81` `MAX_TOKENS_BEFORE_SUMMARY = 4000`; `:278-287` `checkAndSummarize()` reads `metadata.token_count`.
- `lib/Service/GuardrailPolicyService.php:326` `filterOutput()`; `lib/Service/RedactionService.php:221` `redact()`.
- `lib/Controller/ChatController.php:316` trace on the send path; `lib/Controller/ChatStreamController.php:329-338` no trace on the stream path, `:378-392` the `final` payload.
- nextcloud-vue development at ca0c56e77 (read only): `src/composables/useAiChatStream.js:150-151` appends every `token` delta to the answer text.
- `src/views/Chat.vue:266-292` sources under an answer, `:384-398` tool steps while streaming, `:414-450` composer.

## D1. Provenance is a record, copied onto the turn

After the output filter, the engine writes `provenance` on the assistant turn:

- `model`: `{ feature, provider, model, residency, location }`, copied from `providerDisclosure()`, so relabelling a provider later does not rewrite it;
- `sources`: `{ id, type, name }` per source, the list already stored as `sources`, without excerpts;
- `steps`: `{ type, name, outcome }` per trace step, plus `artefact` when a write tool produced one. Nothing else from `extra` is copied: not a refused argument, not a dry run's arguments, not a web target;
- `approvals`: the approval ids raised during the turn, if any.

The record carries identities and outcomes, never content, in line with hydra ADR-088 decision 4. It is saved with the turn, so it is on OpenRegister's audit trail and follows the turn's retention.

The stream path gets a `RunTraceCollector` too, so streamed and sent answers get the same record.

Rejected: reading the run audit entry at view time. A chat turn is not a scheduled run, and joining a turn to audit entries after the fact is the investigation the record exists to avoid.

## D2. The account is built from the record, in the reader's language

`AnswerProvenanceWriter` turns the record into short sentences at read time, with `IL10N` for the reader:

- "Answered by Claude Sonnet 4.5 (Anthropic), which runs in the EU (Frankfurt)."
- "Used 3 documents: Inkoopbeleid 2026, Offerte dakrenovatie, Raadsbesluit 2025-114."
- "Called 2 tools: Search contacts (done), Read file (done). One call was refused: Send email, because this agent may not send email."
- "The organisation's output filter masked part of the answer."
- "You decide what to do with this answer."

The last line is always there. It is the Art. 14 point the tender rows ask for: a person keeps the final say.

Rejected: asking the model to explain itself. A model's account of its own answer can be wrong in ways a reader cannot check, and it costs a second call. The tender asks how an outcome came about; the record says that, and the model's reasoning (D3) is shown as what it is, the model's own text.

## D3. Reasoning on request, and how it travels

`Agent` gains `showReasoning` (boolean, default false), set on the agent form by its owner. When it is on:

- `anthropic`: the request asks for extended thinking with a budget of a quarter of `maxTokens`, and `parseAnthropicResponse()` collects the `thinking` blocks;
- `ollama`: the call sends `think: true` for this agent. The vendor patch sets `think: false` on every call today, so it gains a per-call override (patches/llphant-ollama-think-keepalive.patch), and the response's `thinking` field is collected;
- `openai`, `fireworks` and `nextcloud`: no reasoning is requested. The setting shows "This provider does not return reasoning" for those.

The reasoning passes the same output guardrail filter as the answer and then `RedactionService::redact()` before it is stored as `reasoning` on the assistant turn (hermiq ADR-004, redaction before persist). A blocked reasoning is not stored; the turn says "The reasoning was withheld by the organisation's filter."

On the wire, reasoning travels only on the `final` event, as `reasoning`. It never travels as `token` events and never as a new event type. The SSE envelope has exactly six event types (hydra ADR-034 Decision 6), and the companion appends every `token` delta to the answer (`useAiChatStream.js:150-151`), so a reasoning delta sent as a token would print as the answer on every older companion. While the model is still thinking, the Chat page shows its existing typing indicator.

Rejected: a seventh event type `reasoning`. It breaks the contract every consuming app's companion was built against.

## D4. Context usage from the provider, or an estimate that says so

The engine computes `contextUsage` after each turn: `{ usedTokens, windowTokens, estimated }`.

- `usedTokens` is the provider's prompt token count for the turn when the driver reports it: Ollama today, Anthropic once its parsed usage is passed on (`ResponseGenerationHandler.php:451-453`), and OpenAI from the response usage. Otherwise it is an estimate: the characters of the system prompt, history and message divided by four, with `estimated: true`.
- `windowTokens` comes from the model registry of `chat-attachments-and-images`, which gains a `contextWindow` per model. Undeclared means the readout shows tokens used only.

It is stored on the session as `metadata.contextUsage`, returned on the send response and the `final` event, and shown under the message box: "Context: 12,400 of 128,000 tokens (10%)", or "Context: about 12,400 tokens" when estimated. Above 80% the text reads "The session is close to the model's limit. Start a new session or fork from an earlier answer."

## D5. What the page shows

Under each assistant answer a folded "How this answer came about" section shows the sentences of D2. When the turn has `reasoning`, a second folded section "Model reasoning" shows it as plain text, with the line "This is the model's own text. It can be wrong." Both sections are keyboard operable and announce their state (hydra ADR-059).

## D6. The meter does not switch on summarisation

`ConversationManagementHandler::checkAndSummarize()` summarises when `metadata.token_count` passes 4000, but nothing in `lib/` or `src/` writes `token_count`, so it returns at `:286` on every turn. Writing the meter's number into `token_count` would quietly switch on a summariser that has never run in production. The meter writes `metadata.contextUsage` instead, and the dead summariser is reported to the lane as a separate finding.

## Declarative versus imperative

`SessionTurn.provenance`, `SessionTurn.reasoning` and `Agent.showReasoning` are declared in `lib/Settings/hermiq_register.json` with a register version bump. Building the record, the sentences and the token count is request-time logic inside the engine, which hydra ADR-031 leaves imperative.

## Seed data

The demo session "Offerte dakrenovatie Stadskantoor" gets one assistant turn with `provenance`: model `{ "feature": "chat-companion", "provider": "anthropic", "model": "claude-sonnet-4-5", "residency": "eu", "location": "Frankfurt" }`, two sources (`Inkoopbeleid 2026.pdf`, `Offerte dakrenovatie.pdf`) and one step `{ "type": "tool", "name": "hermiq.readFile", "outcome": "ok" }`.

## Risks

- Extended thinking costs tokens. Mitigation: off by default, per agent, with the budget capped at a quarter of `maxTokens`, and the budget page counts it like any other output.
- Reasoning can quote sensitive input verbatim. Mitigation: the same output filter and redaction as the answer, before it is stored.
- The four-characters estimate is rough for Dutch text and code. Mitigation: it is labelled "about", and a driver that reports tokens replaces it.
