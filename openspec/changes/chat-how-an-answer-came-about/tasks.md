# Tasks: chat-how-an-answer-came-about

Kind: code. Size M. Rows `hermiq:td-explain`, `dm-show-reasoning`, `dm-context-meter`.

## Implementation tasks

### Task 1: The provenance record on both transports
- **spec_ref**: `openspec/changes/chat-how-an-answer-came-about/specs/answer-transparency/spec.md#requirement-every-answer-records-how-it-came-about-req-atrn-001`
- **files**: `lib/Settings/hermiq_register.json`, `lib/Service/Engine/Engine.php`, `lib/Service/Engine/MessageHistoryHandler.php`, `lib/Controller/ChatStreamController.php`
- **acceptance_criteria**:
  - GIVEN a turn with a source and a tool step WHEN it is stored THEN `provenance` holds model, sources and `{type, name, outcome}` steps and no arguments or results
  - GIVEN the stream path WHEN a turn completes THEN a trace collector was passed and the record matches the send path
- [x] Implement
- [x] Test (PHPUnit on the record builder, including a refused step whose argument must not be copied)

### Task 2: "How this answer came about" on the Chat page
- **spec_ref**: `openspec/changes/chat-how-an-answer-came-about/specs/answer-transparency/spec.md#requirement-a-person-can-read-how-an-answer-came-about-in-plain-language-req-atrn-002`
- **files**: `lib/Service/Engine/AnswerProvenanceWriter.php`, `lib/Controller/SessionController.php`, `src/views/Chat.vue`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN a turn with provenance WHEN a Dutch reader opens the section THEN the sentences are in Dutch and end with the final-say line
- [x] Implement
- [ ] Test (PHPUnit on the writer per sentence; Playwright under `tests/e2e/spec-coverage/answer-transparency.spec.ts`)

### Task 3: Reasoning requested, filtered and stored
- **spec_ref**: `openspec/changes/chat-how-an-answer-came-about/specs/answer-transparency/spec.md#requirement-the-models-reasoning-is-shown-only-when-the-owner-allows-it-and-the-provider-returns-it-req-atrn-003`
- **files**: `lib/Settings/hermiq_register.json`, `lib/Service/Llm/ProviderFactory.php`, `lib/Service/Engine/ResponseGenerationHandler.php`, `lib/Service/Engine/Engine.php`, `patches/llphant-ollama-think-keepalive.patch`, `patches.lock.json`
- **acceptance_criteria**:
  - GIVEN `showReasoning` off WHEN any agent runs THEN no reasoning is requested and Ollama still sends `think: false`
  - GIVEN `showReasoning` on for an Anthropic agent WHEN it answers THEN the thinking blocks are filtered, redacted and stored as `reasoning`
- [x] Implement
- [x] Test (PHPUnit on the request body and on filtering before persist)
  - Anthropic: `thinking` with a quarter of `max_tokens` (at least 1024, none when that leaves no room), `thinking` blocks parsed apart from the answer and joined over the tool loop (`ProviderFactory::lastCallReasoning()`). Ollama: `ReasoningOllamaChat` asks `think: true` per call and keeps `message.thinking`; the vendor patch needed no change, because its `$json + [...]` union already lets a call's own `think` win, so agents without reasoning still send `think: false`. An Ollama turn that asks for reasoning runs blocking, since only the blocking answer carries `thinking`. `TurnReasoning` filters (no trace step) then redacts before `MessageHistoryHandler` stores `reasoning` / `reasoningWithheld`; no RedactionService means nothing is stored. Tests: ProviderFactoryAnthropicHttpTest, AnthropicMessageFormatTest, ReasoningOllamaChatTest, TurnReasoningTest, EngineReasoningAndContextTest, LlphantChatCallTest, ResponseGenerationHandlerTest, MessageHistoryHandlerTest.

### Task 4: Reasoning on the wire and on the page
- **spec_ref**: `openspec/changes/chat-how-an-answer-came-about/specs/answer-transparency/spec.md#requirement-the-models-reasoning-is-shown-only-when-the-owner-allows-it-and-the-provider-returns-it-req-atrn-003`
- **files**: `lib/Controller/ChatStreamController.php`, `lib/Controller/ChatController.php`, `src/views/Chat.vue`, `src/modals/AgentFormModal.vue`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN a streamed answer with reasoning WHEN the frames are read THEN no `token` event carries reasoning and `final.reasoning` does
  - GIVEN the agent form WHEN the owner picks a Fireworks model THEN the setting says "This provider does not return reasoning"
- [x] Implement
- [ ] Test (PHPUnit on the SSE frames; Playwright for the folded section)
  - `final.reasoning` / `final.reasoningWithheld` (never a token or a new event type), the send response carries the engine envelope, the turn serializer returns both; folded "Model reasoning" section on the Chat page and the withheld line; "Show the model's reasoning" switch on the agent form, disabled with "This provider does not return reasoning" for openai/fireworks/nextcloud. PHPUnit: ChatStreamFinalReasoningTest; vitest: chat-template-wiring. Left: the Playwright check of the folded section (needs a live instance).

### Task 5: Context usage readout
- **spec_ref**: `openspec/changes/chat-how-an-answer-came-about/specs/answer-transparency/spec.md#requirement-a-person-sees-how-full-the-models-context-is-req-atrn-004`
- **files**: `lib/Service/Llm/ModelCapabilityRegistry.php`, `lib/Service/Engine/ResponseGenerationHandler.php`, `lib/Service/Engine/Engine.php`, `lib/Controller/ChatStreamController.php`, `src/views/Chat.vue`, `src/modals/LlmProviderModal.vue`
- **acceptance_criteria**:
  - GIVEN Ollama reporting prompt tokens and a declared window WHEN a turn completes THEN `contextUsage` holds both and `estimated` is false
  - GIVEN Fireworks WHEN a turn completes THEN `estimated` is true and the page says "about"
  - GIVEN any turn WHEN it completes THEN `metadata.token_count` is not written
- [x] Implement
- [ ] Test (PHPUnit per driver; Playwright under `tests/e2e/spec-coverage/answer-transparency.spec.ts`)
  - `ContextUsageMeter`: the provider's count for a turn without tool calls, else characters / 4 marked `estimated`; window from `ModelContextWindows` (own app config key, set on the LLM provider modal, validated in LlmSettingsController); stored as `metadata.contextUsage`, never `metadata.token_count`; on the send response and `final`; readout under the message box with the warning above 80%. PHPUnit: ContextUsageMeterTest, ModelContextWindowsTest, LlmSettingsContextWindowsTest, ConversationContextUsageTest; vitest: context-usage, chat-template-wiring, llm-provider-modal. Left: the Playwright scenarios.

## Verification
- [ ] `openspec validate chat-how-an-answer-came-about --type change --strict` passes
- [ ] PHPUnit, Newman and the Playwright file run, exit codes read
- [ ] A live check on the dev instance: one streamed answer with a tool call shows its account, and the meter moves after the next message
