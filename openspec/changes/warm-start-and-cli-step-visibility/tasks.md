# Tasks: warm-start-and-cli-step-visibility

## Implementation Tasks

### Task 1: Warm the pooled CLI process without running a turn
- **spec_ref**: `openspec/changes/warm-start-and-cli-step-visibility/specs/warm-start-and-cli-step-visibility/spec.md`
- **files**: `lib/Service/Llm/ProviderFactory.php`
- **acceptance_criteria**:
  - Starting the process sends no prompt, so it costs no inference and no tokens
  - Only `anthropic` + `executionMode: cli` has anything to warm; every other provider reports "not warmed"
  - Exactly one token is minted per warm-up — minting is a side effect, not a default value

- [ ] Add `warmAnthropicCli()`

### Task 2: Expose the warm-up as an endpoint the chat calls on open
- **spec_ref**: `openspec/changes/warm-start-and-cli-step-visibility/specs/warm-start-and-cli-step-visibility/spec.md`
- **files**: `lib/Controller/ChatController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - `POST /api/chat/warm` answers 200 in every case, including a conversation id that no longer resolves
  - Missing identifiers report `warmed: false` rather than a 4xx
  - Called when the chat opens or an agent is picked — not on the first question, which is too late to help it

- [ ] Add `chat#warm`

### Task 3: Make a cli turn's tool calls visible in the chat
- **spec_ref**: `openspec/changes/warm-start-and-cli-step-visibility/specs/warm-start-and-cli-step-visibility/spec.md`
- **files**: `lib/Service/Engine/RunStepBus.php`
- **acceptance_criteria**:
  - A tool executed over the governed MCP transport records a step against the turn's conversation
  - The correlation comes from the per-run token's existing binding, not a new identifier
  - Steps live in a TTL cache and are read once — no schema, no migration, no cleanup job

- [ ] Add `RunStepBus` with record/read/drain/clear

### Task 4: Cover the contract the endpoint actually promises
- **spec_ref**: `openspec/changes/warm-start-and-cli-step-visibility/specs/warm-start-and-cli-step-visibility/spec.md`
- **files**: `tests/Unit/Controller/ChatControllerTest.php`
- **acceptance_criteria**:
  - A warm-up with no identifiers is 200, not an error
  - A warm-up whose lookup throws is still 200 — the live path, since `findConversation()` throws on a miss

- [ ] Cover both warm-up outcomes

## Quality

- `composer check:strict` and `npm run check:specs` pass
- The warm-up path is never on the critical path of a turn: a failure must not surface to the chat
