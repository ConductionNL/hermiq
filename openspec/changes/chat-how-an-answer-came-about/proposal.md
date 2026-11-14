---
kind: code
depends_on: [chat-attachments-and-images]
---

# Proposal: chat-how-an-answer-came-about

## Summary

Under each answer on `/chat` you can open "How this answer came about": a short account in plain language of which model answered and where it runs, which documents it used, which tools it called and with what result, and what the organisation's filters changed, ending with the reminder that you decide what to do with the answer. When an agent owner switches it on and the provider returns it, you can also read the model's reasoning next to the answer. A small meter under the message box shows how much of the model's context window the session uses.

## Why

Three rows of hermiq's capability matrix, decided in the OpenSpec pass of 2026-09-27.

| row | own rating | decision |
|---|---|---|
| `hermiq:dm-show-reasoning` | no | build: changelog demand and five competitors rate yes; shown next to the answer on the chat screen |
| `hermiq:td-explain` | partial, built | build: tender demand (Tilburg University 402469, Juridisch Loket 307676, Molenlanden 415259) for the missing half, a plain-language account of how an outcome came about |
| `hermiq:dm-context-meter` | no | build: core area (chat); a featureRequest demand row and two competitors rate yes |

Demand rows:

- `dm-show-reasoning`: changelog https://github.com/nextcloud/assistant/pull/595
- `td-explain`: tender https://www.tenderned.nl/aankondigingen/overzicht/402469, "Tilburg University DMS (2025-11-26) requirement 57596; also Juridisch Loket 307676 requirement 102700 and Molenlanden 415259 requirement 71423"
- `dm-context-meter`: featureRequest https://github.com/langgenius/dify/issues/42309

Competitor cells rated yes, quoted from the matrix. No competitor rates `td-explain` yes; every one of them is partial with the same note, for example n8n: "Technical trace, not a plain-language explanation."

- `dm-show-reasoning`, Nextcloud Assistant: "reasoning output is stored per message (assistant:lib/Listener/ChattyLLMTaskListener.php:130-131, lib/Migration/Version030500Date20260630083738.php:34) and shown in a 'Reasoning content' popover and while streaming (assistant:src/components/ChattyLLM/Message.vue:36-47,79-80)."
- `dm-show-reasoning`, Hermes agent: "hermes_cli/commands.py:190-192 /reasoning show|hide|full; hermes_cli/config_defaults.py:830 display.show_reasoning (default true)".
- `dm-show-reasoning`, Dify: "web/app/components/base/chat/chat/answer/reasoning-panel.tsx:6-28 collapsible panel of streamed reasoning deltas per LLM node, mounted at chat/answer/index.tsx:264 above the answer".
- `dm-show-reasoning`, n8n: "packages/frontend/editor-ui/src/features/agents/components/AgentChatMessageList.vue:15-24,469,617 renders AiReasoningBlock and AiThinkingBlock with thinking segments and duration beside the answer".
- `dm-show-reasoning`, Open WebUI: "backend/open_webui/utils/middleware.py:2253-2260,3748-3760 captures reasoning_content, reasoning or thinking deltas into a reasoning block".
- `dm-context-meter`, Hermes agent: "hermes_cli/cli_status_bar_mixin.py:340 context_percent, :1046-1058 status bar shows used/total context and a percentage".
- `dm-context-meter`, Open WebUI: "src/lib/components/chat/MessageInput.svelte:1837 'Context usage' readout fed by :535 statusContextUsage".

## What hermiq already has

- Each answer lists its RAG sources with a match score (`src/views/Chat.vue:266-292`), and the streaming bubble shows "Using tool" and "Used tool" per step (`:384-398`).
- `RunTraceCollector` records every tool step and every guardrail action with a name and an outcome (`lib/Service/Engine/RunTraceCollector.php:111`, `:149`, `:221`). It is meant to carry no tool results (hydra ADR-088, context), but `FacadeToolInvoker` does add extras: a refused call's argument name and constraint (`lib/Service/Engine/FacadeToolInvoker.php:568-575`), the target of a web tool or an artefact identity (`:1256-1260`, `:1280-1290`), and in a dry run the redacted arguments (`:1452-1456`). `Engine::processMessage()` records a guardrail step only when a filter acted (`lib/Service/Engine/Engine.php:369-381`, `:470-481`) and returns `steps`, `sources` and `usage` (`:509-532`).
- Which feature, provider, model and residency served a run is recorded on the trace (`RunTraceCollector::recordProviderDisclosure()`, `:189`, called from `lib/Service/Engine/ResponseGenerationHandler.php:295-303`).
- The chat send path passes a trace collector (`lib/Controller/ChatController.php:316`). The stream path does not (`lib/Controller/ChatStreamController.php:329-338`), and its `final` frame carries only `messageId`, `conversationUuid`, `fullText`, `context` and `pendingApprovals` (`:378-392`).
- Token usage reaches the engine only on Ollama (`ResponseGenerationHandler.php:491-496`). Anthropic usage is parsed (`lib/Service/Llm/ProviderFactory.php:2159-2166`) but not passed on (`ResponseGenerationHandler.php:451-453`); Fireworks and OpenAI record latency only.
- Ollama reasoning is switched off for every call by a vendor patch that adds `think: false` (`patches/llphant-ollama-think-keepalive.patch:14`). No other driver asks for reasoning, and nothing stores it.
- The AI oversight page records a person's decision on an AI suggestion (`src/manifest.json`, page `AiOversight`).

## What this change builds

1. A `provenance` record on each assistant turn: provider, model, residency, feature, the sources used, and the tool and guardrail steps with their outcomes. It holds no tool arguments, no results and no document text.
2. "How this answer came about" under each answer on `/chat`: sentences built from that record in the reader's language, not generated by a model.
3. A trace collector on the stream path, so a streamed answer gets the same record as a sent one.
4. An agent setting "Show the model's reasoning", off by default. When on, the Anthropic and Ollama drivers ask for reasoning, and the reasoning is filtered and redacted like the answer, stored on the turn, and shown folded next to the answer.
5. A context usage readout under the message box: tokens used against the model's context window, from the provider's own count where it gives one and an estimate marked "about" where it does not.

## Out of scope

- Streaming the reasoning live. It arrives with the answer, because the SSE envelope has six fixed event types (hydra ADR-034 Decision 6) and the current companion appends every `token` delta to the answer text.
- A model writing its own explanation after the fact. A model's account of its own reasoning is not a record, and a tender that asks how an outcome came about asks for facts.
- Rendering provenance and reasoning in the floating companion. The data is on the `final` frame; the rendering is a nextcloud-vue change.
- Switching on session summarisation. `ConversationManagementHandler::checkAndSummarize()` reads `metadata.token_count`, which nothing writes today; see design D6.
