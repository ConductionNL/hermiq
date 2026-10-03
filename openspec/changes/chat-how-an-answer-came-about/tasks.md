# Tasks: chat-how-an-answer-came-about

Kind: code. Size M. Rows `hermiq:td-explain`, `dm-show-reasoning`, `dm-context-meter`.

## Implementation tasks

### Task 1: The provenance record on both transports
- **spec_ref**: `openspec/changes/chat-how-an-answer-came-about/specs/answer-transparency/spec.md#requirement-every-answer-records-how-it-came-about-req-atrn-001`
- **files**: `lib/Settings/hermiq_register.json`, `lib/Service/Engine/Engine.php`, `lib/Service/Engine/MessageHistoryHandler.php`, `lib/Controller/ChatStreamController.php`
- **acceptance_criteria**:
  - GIVEN a turn with a source and a tool step WHEN it is stored THEN `provenance` holds model, sources and `{type, name, outcome}` steps and no arguments or results
  - GIVEN the stream path WHEN a turn completes THEN a trace collector was passed and the record matches the send path
- [ ] Implement
- [ ] Test (PHPUnit on the record builder, including a refused step whose argument must not be copied)

### Task 2: "How this answer came about" on the Chat page
- **spec_ref**: `openspec/changes/chat-how-an-answer-came-about/specs/answer-transparency/spec.md#requirement-a-person-can-read-how-an-answer-came-about-in-plain-language-req-atrn-002`
- **files**: `lib/Service/Engine/AnswerProvenanceWriter.php`, `lib/Controller/SessionController.php`, `src/views/Chat.vue`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN a turn with provenance WHEN a Dutch reader opens the section THEN the sentences are in Dutch and end with the final-say line
- [ ] Implement
- [ ] Test (PHPUnit on the writer per sentence; Playwright under `tests/e2e/spec-coverage/answer-transparency.spec.ts`)

### Task 3: Reasoning requested, filtered and stored
- **spec_ref**: `openspec/changes/chat-how-an-answer-came-about/specs/answer-transparency/spec.md#requirement-the-models-reasoning-is-shown-only-when-the-owner-allows-it-and-the-provider-returns-it-req-atrn-003`
- **files**: `lib/Settings/hermiq_register.json`, `lib/Service/Llm/ProviderFactory.php`, `lib/Service/Engine/ResponseGenerationHandler.php`, `lib/Service/Engine/Engine.php`, `patches/llphant-ollama-think-keepalive.patch`, `patches.lock.json`
- **acceptance_criteria**:
  - GIVEN `showReasoning` off WHEN any agent runs THEN no reasoning is requested and Ollama still sends `think: false`
  - GIVEN `showReasoning` on for an Anthropic agent WHEN it answers THEN the thinking blocks are filtered, redacted and stored as `reasoning`
- [ ] Implement
- [ ] Test (PHPUnit on the request body and on filtering before persist)

### Task 4: Reasoning on the wire and on the page
- **spec_ref**: `openspec/changes/chat-how-an-answer-came-about/specs/answer-transparency/spec.md#requirement-the-models-reasoning-is-shown-only-when-the-owner-allows-it-and-the-provider-returns-it-req-atrn-003`
- **files**: `lib/Controller/ChatStreamController.php`, `lib/Controller/ChatController.php`, `src/views/Chat.vue`, `src/modals/AgentFormModal.vue`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN a streamed answer with reasoning WHEN the frames are read THEN no `token` event carries reasoning and `final.reasoning` does
  - GIVEN the agent form WHEN the owner picks a Fireworks model THEN the setting says "This provider does not return reasoning"
- [ ] Implement
- [ ] Test (PHPUnit on the SSE frames; Playwright for the folded section)

### Task 5: Context usage readout
- **spec_ref**: `openspec/changes/chat-how-an-answer-came-about/specs/answer-transparency/spec.md#requirement-a-person-sees-how-full-the-models-context-is-req-atrn-004`
- **files**: `lib/Service/Llm/ModelCapabilityRegistry.php`, `lib/Service/Engine/ResponseGenerationHandler.php`, `lib/Service/Engine/Engine.php`, `lib/Controller/ChatStreamController.php`, `src/views/Chat.vue`, `src/modals/LlmProviderModal.vue`
- **acceptance_criteria**:
  - GIVEN Ollama reporting prompt tokens and a declared window WHEN a turn completes THEN `contextUsage` holds both and `estimated` is false
  - GIVEN Fireworks WHEN a turn completes THEN `estimated` is true and the page says "about"
  - GIVEN any turn WHEN it completes THEN `metadata.token_count` is not written
- [ ] Implement
- [ ] Test (PHPUnit per driver; Playwright under `tests/e2e/spec-coverage/answer-transparency.spec.ts`)

## Verification
- [ ] `openspec validate chat-how-an-answer-came-about --type change --strict` passes
- [ ] PHPUnit, Newman and the Playwright file run, exit codes read
- [ ] A live check on the dev instance: one streamed answer with a tool call shows its account, and the meter moves after the next message
