# Design: models-several-models-per-turn

Kind: code. Size L. `ModelPolicy` and `Agent` gain one field each; the fallback loop and the ensemble turn live around `ProviderFactory::createChatDriver()` and `ResponseGenerationHandler::generateResponse()`.

## Context at development db6b74dc

- `lib/Service/Llm/ProviderFactory.php`: `instantiateChatDriver()` `:461-496` (one match arm per provider), `resolveFeatureBinding()` `:519-537`, `createChatDriver()` `:587-653` (provider from `hermiq.llm.chatProvider` at `:596`, feature gates at `:639`, model policy at `:650`), `enforceModelPolicy()` `:672-696`, `resolveCredentialOverride()` `:715`, the Anthropic payload loop `:1009-1054`, `postToAnthropic()` `:2238-2319` with the 429 branch at `:2284-2307`, the Fireworks 429 at `:828-829`.
- `lib/Service/Engine/ResponseGenerationHandler.php`: driver resolved at `:283`, provider disclosure at `:296`, the three provider branches at `:378`, `:436` and `:478`, `lastUsage` at `:94` and `:491`, the catch that ends the turn at `:542-551`.
- `lib/Service/AiFeature/FeatureProviderResolver.php:183-248` `enforceForRun()`: model policy, then residency, then redaction, each with its own exception (`ModelPolicyViolationException::STEP` `model-policy`, `ResidencyViolationException::STEP` `residency`).
- `lib/Service/TenantModelPolicyService.php`: `effectivePolicyFor()` `:172`, `isAllowed()` `:227`, `upsertForOrganisation()` `:248`, `update()` `:303`, `normaliseAllowed()` `:355`, `normaliseDefaultModel()` `:398`.
- `lib/Settings/hermiq_register.json:1172-1245` `ModelPolicy` (`allowed`, `defaultModel`); `:2469-2503` `Agent.provider`, `model`, `aiFeature`, `configuration`.
- `lib/Service/Engine/RunTraceCollector.php:189` `recordProviderDisclosure()`; `lib/Service/Engine/RunStepBus.php:125` uses `ICacheFactory`.
- `lib/Service/BudgetService.php:189` `isBlocked()`, `:956-990` `currentUsageTokens()`; `lib/Service/ScheduleService.php:1783` writes `usage` on the run audit entry.
- `src/views/TenantOps.vue:148-171` the Model policy section; `src/modals/AgentFormModal.vue:114-150` the policy-filtered provider and model pickers.

## D1. The chain lives on the model policy

`ModelPolicy` gains `fallbackChain`: an ordered list of `{provider, model}`, at most four entries. Every entry must be allowed by the same policy (`isAllowed()`), checked on write in `TenantModelPolicyService` next to `normaliseDefaultModel()`, and checked again on every hop at run time, so a policy narrowed later takes effect without anyone editing the chain. The instance-wide default policy may carry a chain too and applies to organisations without their own, exactly as `effectivePolicyFor()` already resolves `allowed`.

Rejected: a chain on the agent. It lets an agent owner route around the organisation's choice of provider. Rejected: a chain in `hermiq.llm`. It is instance-wide, and the row asks for a per-organisation choice.

## D2. What moves a turn to the next hop

The provider calls raise one typed `ProviderCallFailedException` carrying a `kind`: `rate_limit` (HTTP 429), `server` (HTTP 5xx), `timeout`, `connection`, `unavailable` (the existing `ProviderUnavailableException`, for example a broker that is down), `auth` (HTTP 401 or 403) or `bad_request` (HTTP 400), plus `retryAfterSeconds` when the provider sent it. `postToAnthropic()` and the Fireworks call already branch on these codes; they throw the typed exception instead of a bare `Exception`. The LLPhant path (OpenAI and Ollama) wraps the client's exceptions once in `invokeChat()`.

Only `rate_limit`, `server`, `timeout`, `connection` and `unavailable` move a turn. `auth` and `bad_request` end it: a revoked key or a malformed request must reach the admin, not be hidden by an answer from somewhere else. A `ModelPolicyViolationException`, `ResidencyViolationException` or `RedactionRequiredException` never moves a turn: those are refusals.

## D3. A turn moves only while nothing has happened yet

A turn may move only when no answer text has been emitted on the `StreamYieldChannel` and no tool has run. The channel gains a flag set by `emitToken()`, and the tool executor sets one when it dispatches. A failure after that point ends the turn as today, with the reason "Not retried: a tool already ran in this turn" or "Not retried: part of the answer was already shown".

Rejected: replaying the turn from the start after a tool call. A replay runs the tool again, and `createCalendarEvent` or `sendMail` cannot be taken back.

## D4. Every hop passes the same gates

The loop sits in `ResponseGenerationHandler`, around `createChatDriver()` and the provider branch. For each hop it calls `createChatDriver()` with the hop's provider and model as an explicit override, so the hop goes through `instantiateChatDriver()`, `resolveCredentialOverride()`, and then `enforceForRun()` or `enforceModelPolicy()` exactly like the first provider. A hop that throws one of the three refusals is recorded as `skipped` with the step name and the loop continues. When the run names an AI feature with a provider binding, the binding stays the first hop; later hops must still meet the feature's `requiredResidency`.

`createChatDriver()` gains two optional parameters, `providerOverride` and `modelOverride`. Existing call sites pass neither and keep their behaviour.

## D5. Cooldown

A `rate_limit` or `server` failure puts the provider in cooldown for the organisation in Nextcloud's distributed cache (`ICacheFactory`, the same factory `RunStepBus` uses), key `fallback:{organisation}:{provider}`. The period is the provider's `retry-after` when given, else 60 seconds, capped at 600. A provider in cooldown is recorded as `cooling` and skipped. When every hop is cooling or failed, the turn fails with "No provider in the fallback chain is available right now."

Rejected: a cooldown stored as an OpenRegister object. It is a cache entry that expires by itself, and writing an object per rate limit would put audit rows on the hot path.

## D6. What the run records

The trace collector gains `recordProviderAttempt(provider, model, outcome, reason, durationMs)`. The run audit entry carries `providerAttempts` next to `steps`. The provider disclosure keeps its shape and gains `receivedBy`: every provider and model that was sent the prompt, including one that failed after receiving it. A privacy officer then reads which providers saw the text, not only which one answered.

## D7. Ensemble answers

`Agent` gains `ensemble`: `{enabled, referenceModels: [{provider, model}]}`, two or three entries. An ensemble turn:

1. Assembles the system prompt, context and history once, as today.
2. Calls each reference model with that message history and no tools. Each call goes through `createChatDriver()` with the reference model as override, so the model policy and feature gates apply. A reference that fails or is refused is recorded and left out.
3. Runs the agent's normal turn with the reference answers appended to the system prompt as a labelled block ("Draft answers from other models, for you to weigh and merge"), with the agent's granted tools and its usual streaming.

Tools run only in step 3, so a tool runs at most as often as in a single-model turn. The reference answers go on the run record, not in the chat. With fewer than one reference answer, the turn runs as a normal single-model turn and says so in its steps. Reference calls run one after the other; parallel calls need an async HTTP layer hermiq does not have.

Rejected: letting every reference model call tools. Two models each creating the same calendar event is the failure this design exists to avoid. Rejected: showing all answers side by side in the chat. That is a builder's testing view and the row asks for one merged reply.

## D8. Budget

Before each reference call, `BudgetService::isBlocked()` is checked for the organisation and agent. When it blocks, no more reference calls are made and the merge runs on what arrived. `lastUsage` becomes a sum over all calls, and the run gains `usageByModel`. Because `currentUsageTokens()` already sums `usage` from run audit entries, the budget counts the whole ensemble without a change in `BudgetService`. The agent form shows "Each answer costs up to four model calls."

## Declarative versus imperative

Both new fields are declared in the register: `fallbackChain` on `ModelPolicy` and `ensemble` on `Agent`, with their enums and item shapes. The loop, the gates and the budget checks are run-time decisions on the provider path and stay in PHP; no `x-openregister-*` lifecycle, aggregation or notification applies to them.

## Seed data

- Instance default `ModelPolicy`: `allowed` `[{provider: "anthropic", models: ["claude-opus-4-8"]}, {provider: "ollama", models: []}]`, `fallbackChain` `[{provider: "ollama", model: "qwen2.5"}]`.
- `ModelPolicy` for organisation "Gemeente Voorbeeld": `allowed` `[{provider: "fireworks", models: []}, {provider: "ollama", models: []}]`, `fallbackChain` `[{provider: "ollama", model: "qwen2.5"}]`.
- `Agent` "Beleidsadviseur" with `ensemble` `{enabled: true, referenceModels: [{provider: "anthropic", model: "claude-opus-4-8"}, {provider: "ollama", model: "qwen2.5"}]}`.

## Risks

- A provider that answers slowly without failing. Mitigation: the existing per-provider timeouts produce `timeout`, which moves the turn.
- Cost surprise from ensembles. Mitigation: at most three reference models, the budget check before each call, and the cost line on the agent form.
- The prompt reaches more providers than before. Mitigation: every hop passes residency and redaction, and `receivedBy` records each one.
- Inherited, and not changed here: the run path reads `Agent.model` (`ResponseGenerationHandler.php:232`) but takes the provider from `hermiq.llm.chatProvider` (`ProviderFactory.php:596`), so an agent's own `provider` field is not honoured today. Reference models name their provider explicitly and are not affected.
