## 1. ContextAgent provider adapter

- [ ] 1.1 Create `lib/TaskProcessing/ContextAgentProvider.php` — an
      `ISynchronousProvider` for `core:contextagent:interaction` (using
      `EmptyOptionalShapesTrait`) reporting task-type id `core:contextagent:interaction`
      and a 30s expected runtime.
- [ ] 1.2 `process()` extracts `input` / `confirmation` (nullable Number) /
      `conversation_token`, delegates to `ContextAgentInteractionService::interact()`,
      reports progress once, and returns the `{output, conversation_token, actions}`
      shape.
- [ ] 1.3 Create `lib/TaskProcessing/EmptyOptionalShapesTrait.php` — the eight empty
      optional-shape/enum/default accessors, shared with the text2text providers.
- [ ] 1.4 Register the provider in `Application.php`.

## 2. Governed interaction service

- [ ] 2.1 Create `lib/Service/ContextAgentInteractionService.php`.
- [ ] 2.2 `interact()` — requires a user context + non-empty message; resolves the
      serving agent (`contextagent_agent` app-config UUID, else first active);
      GATE: org kill-switch (`ScheduleService::isOrganisationEngaged`) halts + audits
      before the agent runs; binds `conversation_token` ↔ a `Conversation` (reuse when
      owned by the user, else create); maps `confirmation` ↔ approve/deny of the user's
      pending Approval for the agent; runs one `Engine::processMessage` turn; returns
      `output` + the conversation UUID + `actions` (the agent tool allowlist as JSON);
      writes a redacted `contextagent-interaction` audit entry.

## 3. Tests

- [ ] 3.1 `tests/Unit/Service/ContextAgent/ContextAgentInteractionServiceTest.php` —
      a null user throws; an engaged kill-switch throws + never runs the engine;
      a new conversation is created when the token is empty and reused when it resolves
      to a user-owned conversation; `confirmation=1` approves and `confirmation=0`
      denies a matching pending Approval; `actions` carries the agent tool allowlist;
      a happy turn returns the engine's message + conversation token.
- [ ] 3.2 `tests/Unit/TaskProcessing/ContextAgentProviderTest.php` — `process()`
      forwards the parsed input to the service and returns its result; the task-type
      id is `core:contextagent:interaction`.
