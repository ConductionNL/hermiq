## ADDED Requirements

### Requirement: Hermiq owns a feature-flagged agent execution engine

The system MUST provide an in-app agent execution engine (`lib/Service/Engine/*`) capable of
running a chat/tool-loop turn against `Agent`/`Conversation`/`Message`/`Feedback` objects stored in
the `hermiq` OpenRegister register, gated by an `IAppConfig` feature flag defaulting to disabled.
When the flag is disabled, `ScheduleService::runAgentAsOwner()` MUST continue calling OpenRegister's
`ChatService` exactly as before this change, with no observable behavior difference. When the flag
is enabled, `runAgentAsOwner()` MUST call the new in-app engine instead and MUST NOT call
OpenRegister's `ChatService`.

#### Scenario: Flag disabled preserves current behavior

- **WHEN** the `hermiq.engine.enabled` flag is unset or `false` and a schedule fires
- **THEN** `ScheduleService` MUST invoke OpenRegister's `ChatService::processMessage()` exactly as
  it does today, against an OpenRegister `Conversation`

#### Scenario: Flag enabled routes through the in-app engine

- **WHEN** the `hermiq.engine.enabled` flag is `true` and a schedule fires
- **THEN** `ScheduleService` MUST invoke the in-app `Engine` facade against a `Conversation` object
  in the `hermiq` register
- **AND** MUST NOT call `OCA\OpenRegister\Service\ChatService`
- **AND** the returned result MUST include a `usage` shape (token/latency) so per-run cost recording
  (`run-analytics`) does not lose data

### Requirement: The tool loop consumes the OR tool-registry facade only

The in-app `ToolLoop` MUST discover and invoke MCP-registered tools exclusively through
`or-tool-registry-facade`'s public `listTools()`/`invokeTool()` surface. It MUST NOT construct or
depend on OpenRegister's internal `ToolRegistry` or `McpProviderBridge` classes directly.

#### Scenario: Tool discovery goes through the facade

- **WHEN** the engine runs a turn for an `Agent` with a non-empty `tools` whitelist
- **THEN** `ToolLoop` MUST call the facade's `listTools()` and receive only tools matching that
  whitelist
- **AND** an empty `tools` whitelist MUST result in every tool the facade discovers being available

### Requirement: The SSE streaming contract is unchanged

The ported `/apps/hermiq/api/chat/stream` endpoint MUST emit the same six-event envelope as
OpenRegister's existing SSE endpoint — `token`, `tool_call`, `tool_result`, `heartbeat` (every 15s
with no other event), and exactly one terminal `final` or `error` event per request. Non-streaming
providers MUST degrade to zero `token` events plus one `final` event carrying the full text.

#### Scenario: A successful streamed turn emits a single terminal event

- **WHEN** a chat turn streams successfully via `/apps/hermiq/api/chat/stream`
- **THEN** the response MUST contain exactly one `final` event and zero `error` events
- **AND** every `tool_call` event MUST be followed by a corresponding `tool_result` event before
  the `final` event

#### Scenario: A failed turn emits a single terminal error event

- **WHEN** a chat turn fails (LLM error, tool error propagated as fatal)
- **THEN** the response MUST contain exactly one `error` event and zero `final` events

### Requirement: A `nextcloud` TaskProcessing driver is available for non-interactive work

`lib/Service/Llm/ProviderFactory` MUST support a 4th `chatProvider` value, `nextcloud`, backed by
`OCP\TaskProcessing\IManager`, and MUST guard its use behind a `hasProviders()` check, returning a
clear unavailable response when no TaskProcessing provider is installed. This driver MUST be used
only for non-streaming, non-embedding work (e.g. conversation titles, summaries); it MUST NOT be
selected for SSE chat or embeddings requests.

#### Scenario: nextcloud driver used for a title-generation call with a provider installed

- **WHEN** `ProviderFactory` is asked for the `nextcloud` driver to generate a conversation title
  and a TaskProcessing provider is installed
- **THEN** the call MUST succeed via `OCP\TaskProcessing\IManager`

#### Scenario: nextcloud driver is unavailable without an installed provider

- **WHEN** `ProviderFactory` is asked for the `nextcloud` driver and `hasProviders()` returns false
- **THEN** the call MUST fail with a clear "no provider installed" response, not a fatal error

#### Scenario: nextcloud driver is never selected for streaming chat

- **WHEN** a request is for SSE chat streaming
- **THEN** `ProviderFactory` MUST NOT select the `nextcloud` driver regardless of configuration,
  falling back to an LLPhant-backed provider

### Requirement: Agent CRUD moves onto the generic objects path

The frontend `src/api/agents.js` MUST read and write `Agent` objects through the generic
`createObjectStore` path (`/apps/hermiq/api/objects/hermiq/agent`) rather than a bespoke
`/apps/openregister/api/agents` (or equivalent Hermiq-side bespoke) resource fetch, once `Agent` is
declared as a plain OR object schema.

#### Scenario: Listing agents uses createObjectStore

- **WHEN** the Agent Catalog view lists agents
- **THEN** the request MUST go through `createObjectStore`'s generic objects endpoint
- **AND** MUST NOT call a bespoke `/api/agents` resource route for the list operation

### Requirement: Existing kill-switch and approval-gate enforcement is unaffected by the engine swap

Enabling the feature flag MUST NOT change the outcome of the kill-switch (`TenantControl.engaged`)
or human-approval-gate (`Schedule.requiresApproval`) checks in `ScheduleService`. Both checks MUST
still execute, and produce the same decision, before either engine path is invoked.

#### Scenario: Kill-switch still halts runs with the engine flag on

- **WHEN** an organisation's `TenantControl.engaged` is `true` and the feature flag is enabled
- **THEN** `ScheduleService` MUST NOT invoke either engine (old or new) for that organisation's
  schedules

#### Scenario: Approval gate still creates a pending Approval with the engine flag on

- **WHEN** a `Schedule.requiresApproval` is `true` and the feature flag is enabled
- **THEN** `ScheduleService` MUST create a pending `Approval` and MUST NOT invoke either engine
  directly, exactly as it does with the flag off
