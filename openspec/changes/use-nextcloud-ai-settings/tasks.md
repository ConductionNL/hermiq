# Tasks: use-nextcloud-ai-settings

## 1. Nextcloud AI is the default provider

- [x] 1.1 `LlmSettingsHandler::effectiveChatProvider()` answers `nextcloud` when `chatProvider` is empty; `ProviderFactory::createChatDriver()` resolves through it.
  - **spec_ref**: `specs/nextcloud-ai-provider/spec.md#requirement-nextcloud-ai-is-the-default-provider`

## 2. Every hermiq LLM call goes through TaskProcessing in Nextcloud AI mode

- [x] 2.1 `NextcloudTaskClient` runs a task as the acting user: inline through `runTask()` for a synchronous provider, scheduled with a bounded wait otherwise.
  - **spec_ref**: `specs/nextcloud-ai-provider/spec.md#requirement-hermiq-sends-its-llm-work-as-taskprocessing-tasks-as-the-acting-user`
- [x] 2.2 Chat without tools uses `core:text2text:chat`; agent turns with tools use `core:text2text:chatwithtools`, tool calls mapped both ways and run through the governed executor.
  - **spec_ref**: `specs/nextcloud-ai-provider/spec.md#requirement-tool-calls-map-both-ways-onto-chatwithtools`
- [x] 2.3 Plain generation (`generateText`, `generateViaNextcloud`) uses `core:text2text` with the bounded wait.
  - **spec_ref**: `specs/nextcloud-ai-provider/spec.md#requirement-hermiq-sends-its-llm-work-as-taskprocessing-tasks-as-the-acting-user`
- [x] 2.4 `ResponseGenerationHandler` runs chat and agent turns on the `nextcloud` driver instead of refusing them.
  - **spec_ref**: `specs/nextcloud-ai-provider/spec.md#requirement-tool-calls-map-both-ways-onto-chatwithtools`

## 3. Missing provider gives the admin message

- [x] 3.1 No provider for the needed task type throws `ProviderUnavailableException` with "Configure an AI provider under Administration settings > Artificial intelligence."
  - **spec_ref**: `specs/nextcloud-ai-provider/spec.md#requirement-a-missing-nextcloud-provider-gives-a-clear-admin-message`
- [x] 3.2 The chat health probe answers `unconfigured` when Nextcloud AI has no chat provider.
  - **spec_ref**: `specs/nextcloud-ai-provider/spec.md#requirement-a-missing-nextcloud-provider-gives-a-clear-admin-message`

## 4. No provider loop

- [x] 4.1 Remove the `core:text2text`, `:summary` and `:headline` provider registrations and classes; keep `ContextAgentProvider`.
  - **spec_ref**: `specs/nextcloud-ai-provider/spec.md#requirement-hermiq-provides-no-task-type-it-consumes`
- [x] 4.2 The client refuses when the preferred provider for a consumed type is hermiq's own.
  - **spec_ref**: `specs/nextcloud-ai-provider/spec.md#requirement-hermiq-provides-no-task-type-it-consumes`

## 5. Admin settings page

- [x] 5.1 The AI provider section explains Nextcloud AI, links to Administration settings > Artificial intelligence and shows provider readiness per task type (en + nl).
  - **spec_ref**: `specs/nextcloud-ai-provider/spec.md#requirement-the-admin-page-points-to-nextclouds-ai-settings`
- [x] 5.2 Remove the duplicated "Organisation credentials" heading and its Doriath copy.
  - **spec_ref**: `specs/nextcloud-ai-provider/spec.md#requirement-the-admin-page-points-to-nextclouds-ai-settings`

## 6. Verify

- [x] 6.1 Unit tests that fail on the old code: task type, input and userId; tools both ways; no provider for consumed types; missing provider message.
- [x] 6.2 Live on :8099: an Assistant chat and a hermiq chat each create a task handled by integration_openai that fails only on the missing key.
