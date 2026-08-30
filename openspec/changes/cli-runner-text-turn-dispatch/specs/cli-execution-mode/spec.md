# cli-execution-mode Specification

**Status**: planned
**Scope**: hermiq
**OpenSpec changes**:
- `cli-runner-text-turn-dispatch`

## Purpose

Anthropic refuses a Claude Max/Pro subscription OAuth token on the raw Messages API, so the only ToS-compliant
way to back a Hermiq agent with a subscription is to run the **official `claude` CLI**. This capability owns
the transport that makes that possible: `executionMode` on a CLI-capable chat provider selects between `http`
(the default — the direct `BrokerHttpClient` path) and `cli` (dispatch the assembled turn to the
`hermiq-llm-runner` ExApp via AppAPI). It owns the credential resolution for that path, the dispatch itself,
the mapping of the CLI's output back into the `ChatDriver` response and the six-event SSE envelope, and — most
importantly — the **refusal** of anything the transport cannot honour.

This capability is deliberately **text-only**. `claude -p` cannot accept a Messages-API-style tool schema:
`--tools` selects from the **built-in** set, `--allowedTools`/`--disallowedTools` filter tool **names**, and
custom tools reach the CLI **only via MCP**. Governed tool transport is `governed-cli-mcp-transport`
(`cli-runner-governed-mcp-and-egress`), and this capability's job until then is to fail loudly rather than
serve a tool-requiring turn tool-less.

Governance stays in Hermiq throughout (ADR-001 — Hermiq owns the agent core; ADR-023 — action authorization is
the app's job). The runner in this capability has **no Nextcloud access at all**: it receives a payload and
returns text.

## ADDED Requirements

### Requirement: Execution mode selects the Anthropic transport and defaults to http
An `anthropic` chat provider MUST accept an `executionMode` of `http` (default) or `cli`. `http` MUST use the
existing direct `BrokerHttpClient` Messages-API path with no dependency on AppAPI or the ExApp. `cli` MUST
dispatch the assembled turn to the `hermiq-llm-runner` ExApp. The resolved `ChatDriver` MUST carry the
selected mode, and every Anthropic call site MUST honour the driver's mode rather than assuming `http` — a
transport the driver selects but the call site drops is the same defect as no transport at all. A provider
config that sets no `executionMode` MUST behave exactly as it does today.

@e2e exclude No UI surface — this is a backend provider-transport seam with no page, widget or user-visible
control of its own. A live `cli` turn additionally needs a real, billed Claude Max subscription token and the
ExApp container, so the happy path cannot run in CI or against the dev instance. Covered by PHPUnit against
`ProviderFactory` (mode selection, threading, fail-closed guards) and by the ExApp's own container tests
(`exapp/llm-runner/test/test.sh`).

#### Scenario: default execution mode is http

- **GIVEN** an `anthropic` provider config with no `executionMode` key
- **WHEN** the chat driver is resolved
- **THEN** the driver's `executionMode` is `http`
- **AND** the turn goes through the direct `BrokerHttpClient` path
- **AND** neither AppAPI nor the ExApp is consulted

#### Scenario: cli mode reaches the dispatch rather than a stub

- **GIVEN** an `anthropic` provider config with `executionMode: cli` and the ExApp installed and enabled
- **WHEN** the chat driver is resolved
- **THEN** the driver is returned carrying `executionMode: cli`
- **AND** no exception is raised at driver-resolution time

#### Scenario: the call site cannot drop the selected mode

- **GIVEN** a driver resolved with `executionMode: cli`
- **WHEN** any Anthropic call site issues the turn
- **THEN** the mode reaches the transport branch
- **AND** the turn is not served over `http` in silence

### Requirement: A cli turn that carries tools is refused, never run tool-less
When `executionMode` is `cli` and the turn carries one or more tools, the system MUST raise
`ProviderUnavailableException` naming tools as the reason and MUST NOT dispatch the turn. It MUST NOT log a
warning and proceed text-only. The refusal MUST happen before the ExApp is called, so no subscription quota is
spent on a turn that cannot honour its own contract.

This is the capability's central rule. The runner **cannot** carry tools — `server.js:110` and `runner.js:112`
destructure no `tools`, so `providers.js pickToolCalls()` can only ever return `[]` — while a comment at
`server.js:121` claims the opposite. A tool-requiring turn dispatched anyway would run tool-less and look
completely healthy, which is the orphaned-capability defect class. `ProviderFactory::callAnthropicChat()`
already contains exactly this fail-open (`lib/Service/Llm/ProviderFactory.php:624-635`: tools present, no
executor, warn and run text-only); the `cli` path MUST NOT copy it.

@e2e exclude No UI surface — a backend transport guard with no page or user-visible control. Mechanically
verified by PHPUnit against `ProviderFactory`, which is the appropriate seam: the assertion is that a specific
exception is raised and that no dispatch occurs.

#### Scenario: a tool-bearing cli turn raises

- **GIVEN** `executionMode: cli` and a turn assembled with a non-empty tool set
- **WHEN** the turn is issued
- **THEN** a `ProviderUnavailableException` is raised whose message names tools as the reason
- **AND** no request is made to the ExApp
- **AND** no credential is resolved

#### Scenario: the fail-open pattern is not reproduced

- **GIVEN** `executionMode: cli` and a turn carrying tools
- **WHEN** the turn is issued
- **THEN** the turn does not proceed text-only under any circumstance
- **AND** a logged warning is not accepted in place of raising

#### Scenario: the false tools-passthrough claim is gone

- **GIVEN** the runner destructures no `tools` from the `/run` payload
- **WHEN** the runner source is read
- **THEN** no comment claims that `tools` is accepted and passed through

### Requirement: The subscription token is resolved through the broker and never persisted by Hermiq
A `cli` turn MUST obtain the provider credential through OpenRegister's
`CredentialBrokerService::resolveInjectable()`, naming `hermiq` as the calling app and passing the credential
owner's UID, and MUST pass it to the ExApp as a `credentialEnv` map keyed by an environment-variable name the
runner's provider adapter allowlists. Hermiq's stored provider config MUST continue to carry only a
`credentialId` — never a secret. The resolved token MUST NOT be logged, echoed in an error body, or persisted
by Hermiq. A Claude Max/Pro OAuth credential MUST be personal-scope; an organisation-scope one MUST be
refused. When the broker is unavailable, the credential has no id, or the broker returns no token, the system
MUST fail closed with `ProviderUnavailableException` — there MUST be no direct, app-authenticated fallback.

`resolveInjectable()` returns null unless the provider is inject-only, and it enforces the owner/IDOR guard
and the `allowedApps` guard regardless
(`openregister/lib/Service/Credential/CredentialBrokerService.php:250-290`). The `anthropic-cli` inject-only
provider that makes it return a token is declared by `cli-runner-credential-declaration`.

@e2e exclude No UI surface — backend credential resolution with no page of its own; the credential-picker UI
it consumes is already covered by the settings Playwright run. A live resolution additionally needs a real,
billed subscription token. Mechanically verified by PHPUnit: the fail-closed guards, the personal-scope
refusal, and that no token reaches a log line.

#### Scenario: the token is resolved app-side for the CLI

- **GIVEN** `executionMode: cli` and a personal-scope `anthropic-cli` credential owned by the calling user
- **WHEN** a text-only turn is dispatched
- **THEN** the token is resolved via `resolveInjectable()` naming `hermiq` as the calling app
- **AND** it is sent as a `credentialEnv` entry under a key the runner's adapter allowlists
- **AND** it appears in no log line and in no error body

#### Scenario: an organisation-scope subscription token is refused

- **GIVEN** `executionMode: cli` and a Claude Max/Pro OAuth credential at organisation scope
- **WHEN** a turn is issued
- **THEN** a `ProviderUnavailableException` is raised stating the token must be personal-scope
- **AND** no turn is dispatched

#### Scenario: no token means no turn

- **GIVEN** `executionMode: cli` and a credential the broker resolves to nothing
- **WHEN** a turn is issued
- **THEN** a `ProviderUnavailableException` is raised
- **AND** no request is made to the ExApp
- **AND** no direct, app-authenticated call to Anthropic is attempted instead

### Requirement: The turn is dispatched over AppAPI with an explicit timeout and every failure is surfaced
A `cli` turn MUST be dispatched to the `hermiq-llm-runner` ExApp's `POST /run` route through AppAPI's public
seam, resolved lazily so Hermiq still boots and still serves `http` turns when AppAPI is absent. The dispatch
MUST pass an explicit request timeout that exceeds the runner's own CLI timeout; it MUST NOT rely on AppAPI's
default. The system MUST treat an AppAPI **return value** as the failure channel — checking for an error
result and for a non-success status before reading any body — and MUST convert every failure into
`ProviderUnavailableException` rather than surfacing an error payload as a model completion. When AppAPI or
the ExApp is not installed or not enabled, the system MUST raise `ProviderUnavailableException` (503) naming
the missing component, before any credential is resolved.

Both rules are forced by verified AppAPI behaviour, and both are invisible from its signature. AppAPI defaults
`timeout` to **3 seconds** (`AppAPIService::prepareRequestToExApp():189-191`) while the runner allows the CLI
**120 seconds** (`runner.js:28`), so an omitted timeout breaks every turn while the container still bills it.
AppAPI never throws: it catches `\Exception` and returns `['error' => ...]` (`:101-113`), returns
`['error' => 'ExApp ... not found']` for a missing ExApp (`PublicFunctions.php:36-41`), and sets
`http_errors => false` (`:184`) so a 5xx arrives as an ordinary response.

@e2e exclude No UI surface — an ExApp transport seam with no page or user-visible control. A live dispatch
needs the ExApp container and a real, billed subscription token, so it cannot run in CI or against the dev
instance. Mechanically verified by PHPUnit (timeout option present and exceeding the runner's; each AppAPI
failure shape converted to `ProviderUnavailableException`; the not-installed guard) plus the ExApp's own
container tests for the `/run` contract.

#### Scenario: the dispatch outlives the CLI it is waiting for

- **GIVEN** a `cli` turn is dispatched
- **WHEN** the AppAPI request is built
- **THEN** it carries an explicit timeout greater than the runner's own CLI timeout
- **AND** AppAPI's 3-second default is not in effect

#### Scenario: an AppAPI error result is never mistaken for a completion

- **GIVEN** AppAPI returns an error result instead of a response
- **WHEN** the dispatch reads the outcome
- **THEN** a `ProviderUnavailableException` is raised carrying the reason
- **AND** the error text is not returned to the user as the model's answer

#### Scenario: a non-success status from the runner is not decoded as a turn

- **GIVEN** the runner responds with a non-success status
- **WHEN** the dispatch reads the outcome
- **THEN** the status is checked before the body is decoded
- **AND** a `ProviderUnavailableException` is raised

#### Scenario: cli mode without the ExApp fails clearly

- **GIVEN** `executionMode: cli` but AppAPI or the `hermiq-llm-runner` ExApp is not installed or not enabled
- **WHEN** a turn is issued
- **THEN** a `ProviderUnavailableException` (503) is raised naming the missing component
- **AND** no credential is resolved and no turn is attempted

#### Scenario: an absent AppAPI does not break the http path

- **GIVEN** AppAPI is not installed
- **WHEN** Hermiq boots and serves an `executionMode: http` turn
- **THEN** the turn succeeds unaffected

### Requirement: The CLI completion is mapped back into the driver response and the SSE envelope
The CLI's structured output MUST be mapped back into the same `ChatDriver` response shape the `http` Anthropic
path returns: the completion text as the turn's answer, and the reported usage recorded in the same usage
shape the `http` branch records. The turn MUST be surfaced through the existing six-event SSE envelope
(`token` / `tool_call` / `tool_result` / `heartbeat` / `final` / `error`) using its already-contractual
non-streaming shape — zero `token` events plus exactly one terminal `final` carrying the full text. No new
event type, no new envelope, and no client-side branching on transport MUST be introduced.

`claude -p --output-format json` emits one complete result, which is exactly the shape
`ChatStreamController` already documents for non-streaming providers (`:12-14, :57-59`). A caller MUST NOT be
able to tell from the envelope which transport served the turn.

@e2e exclude No new UI surface — the chat widget and its SSE envelope are unchanged by this capability and are
already covered by the existing chat coverage; this requirement is precisely that nothing user-visible
changes. A live `cli` turn needs a real, billed subscription token. Mechanically verified by PHPUnit on the
mapping.

#### Scenario: the completion becomes the agent's answer

- **GIVEN** a successful `cli` turn whose CLI output carries completion text and usage
- **WHEN** the response is mapped
- **THEN** the text is returned as the turn's answer
- **AND** the usage is recorded in the same shape the `http` Anthropic branch records

#### Scenario: the envelope does not change shape for cli

- **GIVEN** a `cli` turn served over the chat stream
- **WHEN** the SSE stream is consumed
- **THEN** it carries zero `token` events and exactly one terminal `final` with the full text
- **AND** no new event type is emitted
- **AND** the client cannot tell which transport served the turn

## Notes

### Relationship to `llm-cli-runner-exapp`

`llm-cli-runner-exapp` requires `ProviderFactory` to dispatch "system prompt + message history + **tool
schema**" to `POST /run`. **That requirement is not implementable** — the `claude` CLI has no tool-schema
injection. This capability implements the implementable half (the text turn) and **refuses** the rest rather
than pretending to serve it. The correction of that spec's tool-schema language, and the governed MCP
transport that replaces it, belong to `cli-runner-governed-mcp-and-egress` (link 3), which owns the
`governed-cli-mcp-transport` capability.

`llm-cli-runner-exapp` is **not archived** — there is no canonical `openspec/specs/llm-cli-runner-exapp/` — so
it cannot be the target of a MODIFIED delta from this change. This capability is therefore additive and does
not restate that change's runner-hardening requirements, which remain in force **unmodified** here: in this
link the runner still has **no Nextcloud access at all**. Link 3 revises that to two token-gated Hermiq
origins; this link does not pre-empt it.

### Relationship to `agent-engine-port`

`agent-engine-port`'s "Hermiq holds no LLM API key" requirement is scoped by its own normative text to "an
OpenAI or Fireworks API key", so an Anthropic subscription token is outside it and this capability does not
modify it. Its structural rule is honoured regardless: the stored config carries `credentialId`, never a
secret. That the token nonetheless transits Hermiq's process on the `cli` path is a conscious, bounded
weakening — forced, because a CLI needs the token in its environment and an inject-only credential cannot be
proxied — and is recorded in this change's design.md.
