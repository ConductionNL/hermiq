# Design: models-prompt-and-answer-cache

Kind: code. Size M. The Anthropic request builder and its usage parser, the system prompt assembly, one new schema `AnswerCacheEntry`, one new agent setting, and the lookup around the provider call.

## Context at development db6b74dc

- `lib/Service/Llm/ProviderFactory.php`: `MAX_TOOL_ITERATIONS` `:219`; the payload loop in `callAnthropicChat()` `:1009-1054`; `mapHistoryToAnthropicMessages()` `:2032` hoists every system turn into one `system` string; `buildAnthropicTools()` `:2074`; `parseAnthropicResponse()` `:2127-2177` with usage at `:2160-2167`; `postToAnthropic()` `:2238`.
- `lib/Service/Llm/LlmSettingsHandler.php:180-196` `anthropicConfig` (`credentialId`, `chatModel`, `authMode`, `scope`, `baseUrl`, `executionMode`).
- `lib/Service/Engine/ResponseGenerationHandler.php`: tools listed at `:214`, system prompt built at `:325-363` (agent prompt and preamble, then the app context block at `:343`, then retrieved context up to `:358`), the provider branches at `:378`, `:436`, `:478`.
- `lib/Service/Engine/ContextRetrievalHandler.php:149-156` semantic and hybrid degrade to keyword; open change `vector-rag` specifies OpenRegister's facade.
- `lib/Controller/ChatStreamController.php:392` emits the SSE `final` event.
- `lib/Service/RedactionService.php:221` `redact()`; `lib/BackgroundJob/RunRetentionCleanupJob.php` is the existing purge job pattern.

## D1. Three breakpoints, on by default

Anthropic accepts up to four `cache_control` breakpoints. Hermiq sets three, all `{"type": "ephemeral"}`:

1. On the last entry of `tools`, so the tool list is cached.
2. On the stable system block (D2).
3. On the last message before the newest user message, so the conversation so far is cached. Inside the tool loop the breakpoint moves to the last `tool_result` block, which is where each round's prefix ends.

`anthropicConfig` gains `promptCaching` (default `true`) and `promptCacheTtl` (`5m` default, or `1h`). A prefix shorter than the model's minimum is simply not cached by Anthropic; there is no error to handle.

Rejected: a switch per agent. Caching changes cost and speed, not the answer, so there is nothing for an agent owner to decide.

OpenAI and Fireworks cache long prefixes on their side without a request flag. For them hermiq only keeps the prefix stable (D2) and reads the counts (D3). Ollama keeps its model loaded through `keep_alive`, which is unchanged.

## D2. A stable block and a per-turn block

Today the app context and the retrieved context are appended to the agent's prompt, so the prefix changes on every turn and a cache on it would never hit. The system prompt becomes two parts: the stable part (the agent's prompt and its context preamble) and the per-turn part (current app context and retrieved context). For Anthropic, `system` is sent as two text blocks with the breakpoint on the first. For the LLPhant path both parts stay system messages in the same order.

## D3. Cache tokens on the run

`parseAnthropicResponse()` also reads `cache_read_input_tokens` and `cache_creation_input_tokens`. The OpenAI path reads `usage.prompt_tokens_details.cached_tokens`. The run's `usage` gains `cacheReadTokens` and `cacheWriteTokens`, next to the token counts `models-several-models-per-turn` starts returning. `promptTokens` keeps counting every input token the model read, cached or not, so budgets stay conservative. The run detail shows "1,840 of 2,300 input tokens from the provider's cache".

Rejected: discounting cached tokens in the budget. `Budget` counts tokens, not prices, and a discount would make one token worth different amounts per provider.

## D4. The answer cache is opt-in and tool-free

`Agent` gains `answerCache`: `{enabled, ttlMinutes, similarity}`. `ttlMinutes` defaults to 1440 and is capped at 43200. `similarity` is `off`, `user` or `organisation`, default `off`.

A turn is eligible only when all hold: the agent has `answerCache.enabled`, the turn offers no tools (`listAgentFunctions()` returned nothing), it is not a dry run or a replay, and the message carries no attachment. Otherwise the cache is neither read nor written.

The lookup sits exactly where the provider call is, after the kill switch, approval, budget and model policy gates, so a blocked run stays blocked.

## D5. The exact layer

The key is SHA-256 over: organisation, agent uuid, agent version id (`AgentVersionService::currentVersionId()`), provider, model, temperature, and the full assembled input (both system blocks, the history and the new message). Because retrieved context is in the input, a change in the organisation's documents changes the key.

A hit returns the stored answer. Before serving it hermiq checks that the entry's provider and model are still allowed by the effective model policy; if not, it deletes the entry and calls the model. An entry stores the key hash, never the prompt.

Rejected: a key on the user's message alone. Two users asking the same words with different retrieved context would get each other's answers.

## D6. The similarity layer runs only through OpenRegister

For the first question of a session, and only when the vector facade from `vector-rag` is present and `isAvailable()` is true, hermiq asks the facade for the nearest cached questions of this agent. The cached question is stored redacted (`RedactionService::redact()`), so OpenRegister's pipeline indexes the redacted text. Hermiq then re-checks every returned row itself: same organisation, same agent version, scope (`user`: same user id; `organisation`: any user of the organisation), not expired, and similarity at least 0.95. Any row that fails a check is ignored.

`organisation` scope is accepted only for an agent with retrieval off (`enableRag: false`), because then the answer was built from the question alone and holds no one's documents. The agent form disables the choice otherwise and says why.

When the facade is absent the layer is off, and the agent form says "Similar questions are not matched: OpenRegister has no vector search here."

## D7. Where entries live and how they go

`AnswerCacheEntry` is an OpenRegister object in the `hermiq` register, so OpenRegister's multitenancy scopes it to the organisation and its RBAC applies. Fields: `agentId`, `agentVersionId`, `keyHash`, `question` (redacted, only when the similarity layer is on), `answer`, `provider`, `model`, `userId`, `createdAt`, `expiresAt`, `hitCount`, `lastHitAt`.

An entry goes when it expires (a daily job next to `RunRetentionCleanupJob`), when the agent owner presses "Clear cached answers" on the agent, and when anyone gives a thumbs down on a message that was served from it.

## D8. The person reading the answer knows

A cache hit is still a run. It records `cache: {layer, entryId, similarity}` and zero model tokens. The SSE `final` event gains `fromCache: true`. Hermiq's chat view shows "Answered from cache" under the reply with an "Ask the model again" button that resends the question with the cache bypassed.

## Declarative versus imperative

`AnswerCacheEntry` and the agent's `answerCache` setting are declared in the register, with their enums and bounds. The expiry could be an `x-openregister` retention rule if OpenRegister offers one for this schema; until then the daily job removes expired entries. Lookup, eligibility and the re-checks are run-time decisions on the provider path and stay in PHP.

## Seed data

- `Agent` "Veelgestelde vragen afvalinzameling" of "Gemeente Voorbeeld": no tools, `enableRag: false`, `answerCache` `{enabled: true, ttlMinutes: 10080, similarity: "organisation"}`.
- One `AnswerCacheEntry` for it: question "Wanneer wordt het oud papier opgehaald?", answer "Oud papier wordt elke tweede dinsdag van de maand opgehaald. Zet de container voor 7.30 uur aan de straat.", provider `ollama`, model `qwen2.5`, `expiresAt` seven days after `createdAt`.

## Risks

- A stale answer after the facts change. Mitigation: retrieved context is in the key, the lifetime is bounded, a thumbs down removes the entry, and every hit says it came from the cache.
- A cached answer shown to a person who should not see it. Mitigation: the exact key covers the full input; similarity is per user unless retrieval is off; every row is re-checked in hermiq after the facade returns it.
- Cached text is personal data. Mitigation: the question is stored redacted, entries expire, and they sit in the organisation's own register.
