# llm-provider-connections Specification

**Status**: proposed
**Scope**: hermiq
**OpenSpec changes**:
- models-provider-connections

## Purpose

An admin connects any OpenAI-compatible endpoint, gives the OpenAI provider a base URL, and chooses between chat completions and the Responses API. Rows `hermiq:mo-openai-compat` and `hermiq:dm-responses-api`.

## ADDED Requirements

### Requirement: An admin can connect an OpenAI-compatible endpoint by name (REQ-PCONN-001)

Hermiq MUST offer a provider `openai-compatible`, shown as "OpenAI-compatible endpoint", with a label, a base URL, a model and an optional credential. The provider MUST be usable in a model policy and MUST carry a residency declaration like every other provider.

#### Scenario: An admin connects a municipal vLLM server
- GIVEN an admin in Admin settings, LLM provider
- WHEN they choose "OpenAI-compatible endpoint", enter the label "vLLM Rekencentrum", the base URL `https://llm.rekencentrum.example.nl/v1` and the model `mistral-small-3.2`, and save
- THEN the provider list shows "vLLM Rekencentrum (OpenAI-compatible)", and an agent turn is answered by that server

### Requirement: A chosen host is reached without hermiq holding a key (REQ-PCONN-002)

For `openai-compatible` and for an OpenAI provider with a base URL, hermiq MUST call the base URL directly only when no credential is set. When a credential is set, hermiq MUST send the call through the credential broker and MUST NOT hold the key. The Fireworks form MUST NOT present a host that the call does not use.

#### Scenario: A keyed endpoint waits for the broker
- GIVEN an OpenRegister without an OpenAI-compatible credential type
- WHEN the admin opens the credential field of "OpenAI-compatible endpoint"
- THEN the field is disabled with "A key needs OpenRegister's OpenAI-compatible credential type." and the keyless option stays available

#### Scenario: The Fireworks form shows where calls go
- GIVEN an admin on the Fireworks provider form with a credential selected
- WHEN they read the address fields
- THEN the host is shown read-only as the credential's host, and the editable field is labelled "API path"

### Requirement: The OpenAI provider accepts a base URL (REQ-PCONN-003)

The OpenAI provider MUST accept an optional base URL. When it is empty hermiq MUST keep using `https://api.openai.com/v1`.

#### Scenario: A regional endpoint
- GIVEN an admin on the OpenAI provider form
- WHEN they leave the base URL empty and save
- THEN calls go to `https://api.openai.com/v1` exactly as before

### Requirement: A provider can run in Responses API mode with governed tools (REQ-PCONN-004)

The OpenAI and OpenAI-compatible providers MUST offer `apiMode` `chat_completions` (default) or `responses`. In `responses` mode hermiq MUST post to `{base}/responses` with `store: false`, and MUST run every function call the model asks for through hermiq's governed tool executor, with the same grants, guardrails, approval gate and redaction as the other paths.

#### Scenario: An agent calls a tool through the Responses API
- GIVEN an agent on OpenAI in Responses API mode with `hermiq.listCalendarEvents` granted
- WHEN a user asks "Wat staat er morgen in mijn agenda?"
- THEN the model's function call runs through the governed executor, appears as a tool step in the chat, and the answer names tomorrow's events
- @e2e exclude needs a live OpenAI account; covered by PHPUnit on the request and response mapping and one live check

#### Scenario: A tool the agent was not granted is refused
- GIVEN an agent in Responses API mode without `hermiq.sendMail`
- WHEN the model asks for `hermiq.sendMail`
- THEN the executor refuses it as for any other path, and no mail is sent
- @e2e exclude requires a model that asks for an ungranted tool; covered by PHPUnit on the executor mapping
