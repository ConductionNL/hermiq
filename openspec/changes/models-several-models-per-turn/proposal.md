---
kind: code
depends_on: [a-provider-and-a-place-per-ai-feature]
---

# Proposal: models-several-models-per-turn

## Summary

An organisation admin names a fallback chain of providers and models on the model policy. When the first provider fails or hits its rate limit, hermiq moves the turn to the next provider, checks that provider against the model policy and the feature's residency first, and records every attempt on the run. An agent owner can also switch an agent to ensemble answers: two or three models answer the same prompt, and the agent's own model merges their answers into one reply. Every model call counts against the organisation's budget.

## Why

Three rows, decided in the OpenSpec pass of 2026-09-27.

| row | own rating | decision |
|---|---|---|
| `hermiq:mo-failover` | no | build: two competitors rate yes |
| `hermiq:dm-model-ensemble` | no | build: a changelog demand row and two competitors rate yes |
| `integriq:gw-ai-proxy` | no | build here. This row comes from integriq's capability matrix at development `413357ec` (`openspec/parity/capabilities.json:9003`), where it is decided no (`openspec/parity/gap-decisions.json:459`): under hydra ADR-034 decision 2 the LLM call path is hermiq's. It is the same fallback as `mo-failover`. |

Demand and competitor cells rated yes, quoted from the pack:

- `dm-model-ensemble`: changelog https://github.com/NousResearch/hermes-agent/releases/tag/v2026.7.1.
  - hermes-agent v2026.9.24: "hermes_cli/commands.py:134 /moa runs a prompt through the Mixture of Agents preset; agent/moa_loop.py:1 reference models gathered before each iteration, aggregator answers".
  - open-webui v0.11.4: "several models answer one prompt side by side (permission chat.multiple_models; src/lib/components/chat/Messages/MultiResponseMessages.svelte) and 'Merge Responses' (:439) calls backend/open_webui/routers/tasks.py:610 POST /tasks/moa/completions to fuse them".
- `mo-failover`:
  - hermes-agent v2026.9.24: "hermes_cli/config_defaults.py:24 fallback_providers chain; hermes_cli/subcommands/fallback.py:10-24 `hermes fallback add|list|remove|clear`; ... agent/fallback_cooldown.py cools a failing provider".
  - n8n@2.40.7: "packages/@n8n/nodes-langchain/nodes/agents/Agent/V3/AgentV3.node.ts:37-40,118-119 'Enable Fallback Model' adds a second model input used when the primary fails".
- `gw-ai-proxy` (integriq matrix): changelog https://github.com/apache/apisix/pull/13676.
  - apisix 3.18.0: "apisix/plugins/ai-proxy-multi.lua:44 balances over several providers ... and apisix/plugins/ai-proxy/schema.lua:438 fallback_strategy moves to another instance on failure or rate limit (ai-proxy-multi.lua:637)".
  - mulesoft, https://docs.mulesoft.com/general/model-proxy-create-model-proxy.md: "'You can configure a model proxy to route LLM traffic across different models and providers', and the OpenAI API format 'Supports multi-routing and fallback mechanisms'".
  - wso2 v4.7.0: "product-apim/all-in-one-apim/modules/distribution/resources/operation_policies/definitions/modelFailover_v1.j2 and ... FailoverMediator.java fall back to another model or endpoint".

## What hermiq already has

- `ProviderFactory::createChatDriver()` (`lib/Service/Llm/ProviderFactory.php:587-653`) resolves exactly one provider: `hermiq.llm.chatProvider` (`:596`) or the AI feature's own binding (`resolveFeatureBinding()`, `:519-537`). It builds one driver (`instantiateChatDriver()`, `:461-496`) and then applies the model policy (`:650`, `enforceModelPolicy()` at `:672-696`) or the feature gates (`:639`, `FeatureProviderResolver::enforceForRun()` at `lib/Service/AiFeature/FeatureProviderResolver.php:183`).
- `ResponseGenerationHandler::generateResponse()` calls `createChatDriver()` once (`lib/Service/Engine/ResponseGenerationHandler.php:283`) and then one provider: Fireworks (`:378`), Anthropic (`:436`), OpenAI and Ollama (`:478`). Any failure ends the turn with "Failed to generate response" (`:542-551`).
- A rate limit is recognised and named, and nothing acts on it: `postToAnthropic()` turns HTTP 429 into "Rate limit exceeded" and reads `retry-after` (`ProviderFactory.php:2284-2307`); the Fireworks call does the same (`:828-829`).
- `ModelPolicy` holds `allowed` and `defaultModel` only (`lib/Settings/hermiq_register.json:1172-1245`).
- The run carries one provider disclosure (`RunTraceCollector::recordProviderDisclosure()`, `lib/Service/Engine/RunTraceCollector.php:189`) and one `usage` block (`lib/Service/ScheduleService.php:1783`). Budgets sum `promptTokens` and `completionTokens` from run audit entries (`lib/Service/BudgetService.php:956-990`).

## What this change builds

1. A fallback chain on the organisation's `ModelPolicy`: an ordered list of provider and model pairs, each one allowed by the same policy.
2. Fallback at run time. On a temporary failure (rate limit, server error, timeout, connection error, provider unavailable) before any answer text or tool call, hermiq builds the next hop's driver, runs the model policy and feature checks on it, and retries the turn there.
3. A short cooldown per organisation and provider, so the next turns start at the first healthy hop.
4. Every attempt on the run record, and a disclosure that names the provider that answered and every provider that received the prompt.
5. Ensemble answers on an agent: two or three reference models answer without tools, then the agent's own model merges their answers into one reply, with the agent's granted tools.
6. Budget counting for the ensemble: usage per model on the run, summed into the run's `usage`, and the hard cap checked before each reference call.
7. The screens for both settings: the model policy section on Tenant operations and the agent form.

## Out of scope

- A provider router or AI proxy in integriq. Decided no in integriq's matrix under hydra ADR-034 decision 2.
- Rotating several credentials of one provider. Credentials live in OpenRegister's credential broker, and ADR-001 rules out a credential pool in hermiq.
- Falling back after a model policy, residency or redaction refusal. Those are governance answers, not failures.
- Comparing two models side by side for a builder. That is a testing view, not an answer mode.
