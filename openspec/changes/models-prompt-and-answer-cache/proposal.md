---
kind: code
depends_on: [models-several-models-per-turn, vector-rag]
---

# Proposal: models-prompt-and-answer-cache

## Summary

Hermiq marks the stable part of every Anthropic request for the provider's prompt cache, on by default, so long system prompts, tool lists and conversation prefixes are not paid for in full on every turn and every tool round. The run shows how many tokens came from the cache. An agent owner can also switch on an answer cache for an agent: a turn without tools that repeats an earlier question in the same organisation gets the stored answer at once, without a model call. A second layer that matches similar questions runs only through OpenRegister's vector facade, and no cached answer ever crosses an organisation.

## Why

Two rows, decided in the OpenSpec pass of 2026-09-27.

| row | own rating | decision |
|---|---|---|
| `hermiq:dm-prompt-cache` | no | build: a featureRequest demand row and two competitors rate yes |
| `integriq:gw-ai-cache` | no | build here. This row comes from integriq's capability matrix at development `413357ec` (`openspec/parity/capabilities.json:9411`), where it is decided no (`openspec/parity/gap-decisions.json:443`): under hydra ADR-034 decision 2 the LLM call path is hermiq's, so caching model answers belongs here. |

Demand and competitor cells rated yes, quoted from the pack:

- `dm-prompt-cache`: featureRequest https://github.com/open-webui/open-webui/discussions/7873.
  - hermes-agent v2026.9.24: "agent/prompt_caching.py:1-5 four cache_control breakpoints set automatically on the system prefix and last messages; hermes_cli/config_defaults.py:675-679 prompt_caching.cache_ttl 5m or 1h for Claude via OpenRouter or the native API; hermes_cli/cli_status_bar_mixin.py:991 status bar shows cache_hit".
  - n8n@2.40.7: "packages/@n8n/nodes-langchain/nodes/llms/LMChatAnthropic/LmChatAnthropic.node.ts:531-590 promptCaching option with 5m or 1h lifetime; Agents-module agents set promptCaching per agent".
- `gw-ai-cache` (integriq matrix): changelog https://github.com/apache/apisix/pull/13578.
  - apisix 3.18.0: "apisix/plugins/ai-cache.lua:19-23 loads the exact-key cache and the semantic layer (apisix/plugins/ai-cache/semantic.lua), added in 3.18.0".
  - mulesoft, https://docs.mulesoft.com/general/model-proxy-semantic-caching-service.md: "'Semantic caching services store and retrieve LLM responses based on semantic similarity. When an incoming request is semantically similar to a previous request, Model Proxy returns the cached' response".
  - wso2 v4.7.0: "the bundled SemanticCache policy ... caches AI answers and serves them for semantically similar prompts, using the embedding providers in the gateway".

## What hermiq already has

- `callAnthropicChat()` builds each request from `model`, `max_tokens`, `messages`, `system` and `tools` only (`lib/Service/Llm/ProviderFactory.php:1010-1022`) and resends the whole prefix on each of up to ten tool rounds (`MAX_TOOL_ITERATIONS`, `:219`). No `cache_control` anywhere.
- The system prompt is one string: the agent's prompt and context preamble, then the per-turn app context and retrieved context appended to it (`lib/Service/Engine/ResponseGenerationHandler.php:325-363`). The stable part and the per-turn part are not separated.
- `parseAnthropicResponse()` reads `input_tokens` and `output_tokens` only (`ProviderFactory.php:2160-2167`); cache token counts are not read.
- No answer cache. Semantic retrieval degrades to keyword search because OpenRegister has no public vector facade yet (`lib/Service/Engine/ContextRetrievalHandler.php:149-156`); the open change `vector-rag` specifies that facade (`searchSemantic`, `embedTexts`, `isAvailable`).
- A turn knows whether tools are offered: `ToolLoop::listAgentFunctions()` (`lib/Service/Engine/ToolLoop.php:195`), read at `ResponseGenerationHandler.php:214`.

## What this change builds

1. Anthropic prompt caching on the `http` path, on by default: `cache_control` breakpoints on the tool list, on the stable system block and on the conversation prefix, with a setting to switch it off and to choose a 5 minute or 1 hour lifetime.
2. A system prompt split into a stable block and a per-turn block, so the cached prefix survives from one turn to the next.
3. Cache read and cache write tokens on the run's usage, for Anthropic and OpenAI, shown on the run.
4. An answer cache, opt-in per agent, for turns that offer no tools: exact match on the full assembled input within one organisation and one agent version, with a lifetime.
5. A similarity layer on top, only through OpenRegister's vector facade, only for the first question of a session, scoped to the same user unless the agent owner widens it.
6. Clearing: per agent, on a thumbs down, on a model policy change, and by expiry.
7. "Answered from cache" in the chat, with a way to ask the model again.

## Out of scope

- An AI cache in integriq. Decided no in integriq's matrix under hydra ADR-034 decision 2.
- Caching in the `cli` transport. The `claude` CLI in the llm-runner ExApp does its own caching; that is vendor behaviour, not hermiq code.
- Computing or storing embeddings in hermiq. Vectors are OpenRegister's (hermiq ADR-001).
- Caching turns that offer tools. Their answers depend on live data a tool reads.
