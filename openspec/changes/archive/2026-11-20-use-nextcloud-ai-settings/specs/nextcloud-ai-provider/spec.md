## ADDED Requirements

### Requirement: Nextcloud AI is the default provider

When no hermiq-specific chat provider is configured (`chatProvider` empty), hermiq MUST use
Nextcloud AI (`nextcloud`) as its chat provider. A configured direct provider (openai,
ollama, fireworks, anthropic) MUST keep working when selected.

#### Scenario: No provider saved

- **GIVEN** `hermiq.llm` has no `chatProvider`
- **WHEN** hermiq resolves its chat driver
- **THEN** the driver MUST be `nextcloud`
- **AND** hermiq MUST NOT answer "Chat provider is not configured"

@e2e exclude provider resolution is a server-side default with no UI flow; covered by ProviderFactoryNextcloudAiTest.

#### Scenario: A direct provider is saved

- **GIVEN** `chatProvider` is `ollama`
- **WHEN** hermiq resolves its chat driver
- **THEN** the driver MUST be `ollama`

@e2e exclude covered by the existing ProviderFactory unit tests.

### Requirement: Hermiq sends its LLM work as TaskProcessing tasks as the acting user

In Nextcloud AI mode every hermiq LLM call MUST go through `OCP\TaskProcessing\IManager`.
Chat without tools MUST use `core:text2text:chat`, agent turns with tools MUST use
`core:text2text:chatwithtools`, and plain generation MUST use `core:text2text`. The task's
`userId` MUST be the user hermiq works for: the explicit user a caller passes, else the
session user (a scheduled run impersonates its acting user). The task's `appId` MUST be
`hermiq`. When the preferred provider is synchronous, hermiq MUST run the task inline
through `runTask()`. Otherwise it MUST schedule the task and wait at most a bounded time
(120 seconds for chat, 600 seconds for background work), then cancel the task and fail with
a clear error.

#### Scenario: A chat turn without tools

- **GIVEN** Nextcloud AI mode and user `alice` in session
- **WHEN** alice sends "Hello" with a system prompt and one earlier exchange
- **THEN** hermiq MUST run a `core:text2text:chat` task with `userId` `alice`
- **AND** the input MUST carry `system_prompt`, `input` "Hello" and `history` as JSON
  `{role, content}` entries

@e2e exclude TaskProcessing round-trip verified live on :8099 against integration_openai; unit-covered in NextcloudTaskClientTest.

#### Scenario: An asynchronous provider does not answer in time

- **GIVEN** the preferred provider is not synchronous and never finishes
- **WHEN** hermiq waits for an interactive chat task
- **THEN** hermiq MUST stop waiting after the bound, cancel the task and fail with an
  error saying the AI provider did not answer in time

@e2e exclude timing behaviour, unit-covered with an injected clock.

### Requirement: Tool calls map both ways onto chatwithtools

Hermiq MUST send its agent's tools as OpenAI-style `tools` JSON. When the output carries
`tool_calls` (`[{id, name, args}]`), hermiq MUST run each call through its governed tool
executor, then send a follow-up task with the assistant turn and its `tool_calls` in
`history` and the results in `tool_message` (`[{tool_call_id, name, content}]`), until the
model answers without tool calls or the agent's tool-call cap is reached. When
`core:text2text:chatwithtools` has no provider, a turn with tools MUST fail with the admin
message instead of silently running without tools or on another provider.

#### Scenario: One tool call round trip

- **GIVEN** an agent with tool `search`
- **WHEN** the first task answers with a `tool_calls` entry for `search`
- **THEN** hermiq MUST execute `search` with the given args
- **AND** the second task MUST carry the result in `tool_message` with the call's id
- **AND** the reply MUST be the second task's `output`

@e2e exclude tool loop over TaskProcessing is unit-covered; a live tool call needs a real key.

### Requirement: A missing Nextcloud provider gives a clear admin message

When no Nextcloud provider serves the task type hermiq needs, hermiq MUST fail with
"Configure an AI provider under Administration settings > Artificial intelligence." and
MUST NOT fall back to another provider. The chat health probe MUST report `unconfigured`
when Nextcloud AI is the effective provider and no provider serves `core:text2text:chat`.

#### Scenario: Nothing installed

- **GIVEN** Nextcloud AI mode and no provider for `core:text2text:chat`
- **WHEN** a user sends a chat message
- **THEN** hermiq MUST fail with the admin message

@e2e exclude unit-covered; the live instance has a provider installed.

### Requirement: Hermiq provides no task type it consumes

Hermiq MUST NOT register a TaskProcessing provider for `core:text2text`,
`core:text2text:chat`, `core:text2text:chatwithtools`, `core:text2text:summary` or
`core:text2text:headline`. It MAY keep providing `core:contextagent:interaction`, which it
does not consume. If the preferred provider for a type hermiq consumes is a hermiq provider,
hermiq MUST refuse the call instead of looping.

#### Scenario: Registration

- **WHEN** hermiq registers its TaskProcessing providers
- **THEN** none of them MUST have a task type hermiq consumes

@e2e exclude static registration check, unit-covered.

### Requirement: The admin page points to Nextcloud's AI settings

The hermiq admin settings AI provider section MUST say that hermiq uses the AI provider set
up for the whole instance, MUST link to Administration settings > Artificial intelligence,
and MUST show for chat, chat with tools and plain text whether a provider is ready. When
one is missing it MUST show the admin message. The section MUST NOT carry a duplicated
"Organisation credentials" heading or copy naming Doriath. All new text MUST exist in en
and nl, without em-dashes.

#### Scenario: Admin opens the page with no provider

- **GIVEN** no provider for `core:text2text:chat`
- **WHEN** an admin opens Administration settings > Hermiq
- **THEN** the AI provider section MUST show "Configure an AI provider under Administration
  settings > Artificial intelligence." and a link to that page

@e2e exclude verified live on :8099 by screenshot.
