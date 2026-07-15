# anthropic-agent-provider Specification

## Purpose

Add Anthropic Claude as a first-class Hermiq chat provider, supporting both an Anthropic API key and a Claude Max OAuth subscription token, with the secret held by the OpenRegister credential broker (never by Hermiq).

## ADDED Requirements

### Requirement: Anthropic is a selectable chat provider

@e2e exclude Provider config + LLPhant/HTTP adapter — covered by PHPUnit

`anthropic` MUST be a valid `chatProvider` value alongside `openai`, `ollama`, `fireworks`, and `nextcloud`. The `llm` app-config MUST carry an `anthropicConfig` sub-block with `credentialId` (broker credential reference), `chatModel` (string; free-text, with current Claude model IDs offered as suggestions), and `authMode` (`api_key` | `oauth`). Saving `chatProvider: "anthropic"` with a non-empty `credentialId` MUST make `ChatHealthController::health()` return 200.

#### Scenario: anthropic accepted as chatProvider

- **GIVEN** an operator sets `chatProvider` to `anthropic` with a valid `anthropicConfig.credentialId`
- **WHEN** the `llm` config is saved and `/api/chat/health` is probed
- **THEN** the save is accepted and health returns 200 `{status:"ok"}`

#### Scenario: anthropic rejected without a credential

- **GIVEN** `chatProvider: "anthropic"` with an empty `anthropicConfig.credentialId`
- **WHEN** the driver is resolved
- **THEN** a `ProviderUnavailableException` (503) is raised naming the missing credential — no request is sent

### Requirement: Both API-key and Claude Max OAuth auth modes are supported

The Anthropic driver MUST send auth headers determined by `authMode`, and MUST route the outbound `POST /v1/messages` request through `BrokerHttpClient` so the real secret is injected at egress and never handled by Hermiq:

- `authMode: "api_key"` → `x-api-key: <broker-injected>` + `anthropic-version: 2023-06-01`.
- `authMode: "oauth"` (Claude Max) → `Authorization: Bearer <broker-injected>` + `anthropic-version: 2023-06-01` + `anthropic-beta: oauth-2025-04-20`.

The driver MUST be constructed as a descriptor (Fireworks-style direct HTTP), NOT via LLPhant's `AnthropicChat` — LLPhant requires a concrete Guzzle client and exposes no seam for the OAuth header set.

#### Scenario: OAuth mode sends Bearer + oauth beta header

- **GIVEN** `anthropicConfig.authMode: "oauth"`
- **WHEN** the driver builds the outbound request
- **THEN** the request carries `Authorization: Bearer` and `anthropic-beta: oauth-2025-04-20`, and no `x-api-key` header

#### Scenario: API-key mode sends x-api-key

- **GIVEN** `anthropicConfig.authMode: "api_key"`
- **WHEN** the driver builds the outbound request
- **THEN** the request carries `x-api-key` and no `Authorization: Bearer` / oauth beta header

#### Scenario: secret is broker-injected, never in Hermiq

- **GIVEN** any `authMode`
- **WHEN** the driver constructs the request
- **THEN** the auth header value handed to the transport is the broker placeholder, and the real key/token is injected by `BrokerHttpClient` at egress

### Requirement: Anthropic models pass through model-policy enforcement

An `anthropic` (provider, model) pair MUST be checked against the calling organisation's effective `ModelPolicy` at the existing `createChatDriver()` chokepoint (`enforceModelPolicy`), identically to the other providers — a per-org allowlist that excludes a Claude model MUST block it.

#### Scenario: out-of-policy Claude model is blocked

- **GIVEN** an org whose `ModelPolicy` does not permit `claude-opus-4-8`
- **WHEN** an agent configured for `anthropic` + `claude-opus-4-8` resolves its driver
- **THEN** a `ModelPolicyViolationException` is raised before any network call
