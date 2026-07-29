# Nextcloud-Native Tools Specification

**Status**: in-progress (was: active — providers registered + IDOR-guarded; agent-invocation BLOCKED on OR#269; new change in flight)

**Feature tier**: V1

**OpenSpec changes:**
- `openspec/changes/archive/2026-07-13-agent-tool-governance-and-disclosure/` — adds a `hermiq.searchTools` meta-tool through this same `IMcpToolProvider` mechanism for progressive disclosure over the ADR-063 derived catalog (kind: code) — **DONE** (the provider now exposes seven `hermiq.*` tools; `searchTools` enumerates through the normal registration path but is short-circuited Hermiq-internally at invoke time, never reaching this provider's `invokeTool()`)
- `openspec/changes/hydra-console-agent-leaves/` — MODIFIED delta: the "Remote systems route through OpenConnector" requirement is scoped so the `webSearch`/`webFetch` exception is explicitly read-only, and outbound *commands* (e.g. forge label writes) are named as OpenConnector-backed flow/endpoint territory — the delta's entire effect is that no bespoke forge code is written (kind: code) — **in-progress**
- `nc-native-tools` — DONE (Hermiq surface): `NcNativeToolProvider` implementing `IMcpToolProvider` with six `hermiq.*` tools (listFiles, readFile, searchContacts, listCalendarEvents, sendMail, listDeckBoards), each IDOR-guarded (scoped to the acting user), registered under the `hermiq` MCP alias; `invokeTool` never throws. **BLOCKED (OR#269):** an OpenRegister agent turn cannot yet invoke a tool (Ollama tool-calling 400) — the LLM-selects-and-calls path is documented as blocked, not verified end-to-end. Verified: registration + enumeration in OR's tool registry + direct invocation (unit).

## Purpose

Exposes Nextcloud-native capabilities — Files, Contacts, Calendar, Deck, and outbound email — as
agent tools through OpenRegister's MCP `IMcpToolProvider` interface, so Hermiq agents can act inside
the host Nextcloud instance without a second tool-registration mechanism. Anything outside Nextcloud
routes through OpenConnector's `CallService` instead of a bespoke integration layer.
## Requirements
### Requirement: NC-native capabilities registered as IMcpToolProvider tools
The system MUST register Files (`IRootFolder`), Contacts (addressbook `IManager`), Calendar
(`ICalendarManager`), Deck, and outbound email (`IMailer`) as tools through OpenRegister's
`IMcpToolProvider` interface, so they appear in the same tool registry as OR's other MCP tools.

#### Scenario: An agent lists available tools including NC-native ones
- GIVEN Hermiq's NC-native tool providers are registered with OR's `ToolRegistry`
- WHEN an agent run queries available tools via MCP
- THEN the Files, Contacts, Calendar, Deck, and email tools MUST appear in the returned tool list
- AND they MUST be indistinguishable in registration mechanism from OR's own MCP tools

### Requirement: Per-object IDOR guard on every provider
Every NC-native tool provider MUST enforce a per-object authorization guard before acting on a
specific Files/Contacts/Calendar/Deck object, so an agent acting on behalf of one user cannot access
another user's objects by ID.

#### Scenario: An agent tool call targets an object outside the caller's access
- GIVEN an agent is running on behalf of user U
- WHEN a tool call requests a Files/Contacts/Calendar/Deck object ID that U does not have access to
- THEN the system MUST deny the tool call
- AND the system MUST NOT return any content from the inaccessible object

### Requirement: Remote systems route through OpenConnector
The system MUST NOT implement direct HTTP/API calls to third-party or remote systems
inside Hermiq's tool providers; such calls MUST route through OpenConnector's
`CallService` — **except** for the `hermiq.webSearch` and `hermiq.webFetch` tools
(`web-research-tool`), which MAY call directly via `OCP\Http\Client\IClientService`
because their destination is either an admin-configured search endpoint (not a
per-call, agent-supplied one) or a URL the agent only learns of at call time, neither of
which `CallService`'s pre-registered-`Source` model can express. Both exempted tools MUST
apply their own SSRF/allowlist/denylist/size/timeout governance in place of the safety
guarantees `CallService`'s admin-owned `Source.location` would otherwise have provided.

<!-- Previous behavior: the requirement had no exceptions — every remote call from any
Hermiq tool provider was required to route through OpenConnector's CallService, with no
carve-out. web-research-tool's discovery.md establishes that CallService's Source model
(a fixed, admin-registered base URL) cannot express "fetch a URL the agent only learns of
at call time," which the requirement did not previously contemplate. -->

#### Scenario: An agent tool needs to reach an external, non-Nextcloud system (unchanged for existing tools)
- GIVEN a tool call requires contacting a third-party API and is NOT `hermiq.webSearch`
  or `hermiq.webFetch`
- WHEN the tool provider handles the call
- THEN the system MUST delegate the outbound call to OpenConnector's `CallService`
- AND Hermiq's own code MUST NOT open a direct HTTP client connection to the third-party
  system

#### Scenario: web.search or web.fetch calls a destination directly
- GIVEN an agent invokes `hermiq.webSearch` or `hermiq.webFetch`
- WHEN the tool handles the call
- THEN the system MAY call the destination directly via `OCP\Http\Client\IClientService`
  without routing through OpenConnector's `CallService`
- AND the system MUST have applied the `web-research-tool` egress guard (SSRF/allowlist/
  denylist/size/timeout) to that destination before issuing the request

#### Scenario: No other tool gains this exception implicitly
- GIVEN a future Hermiq tool provider needs to reach a remote system
- WHEN it is implemented
- THEN the system MUST route that call through OpenConnector's `CallService` unless that
  specific tool is named in this requirement's exception list
- AND merely resembling `hermiq.webSearch`/`hermiq.webFetch` MUST NOT be treated as
  sufficient justification to bypass `CallService` without an equivalent, explicitly
  documented spec change

## User Stories

- As an agent builder, I want my agent to read and write Files/Contacts/Calendar/Deck so that it can act on real Nextcloud data.
- As an agent builder, I want my agent to send email on my behalf so that it can complete tasks that require outbound communication.
- As a security reviewer, I want every NC-native tool to enforce per-object authorization so that agents cannot access data outside the acting user's permissions.
- As a platform architect, I want remote-system calls centralized in OpenConnector so that Hermiq doesn't grow a second integration layer.

## Acceptance Criteria

- [ ] Files, Contacts, Calendar, Deck tool providers implement OR's `IMcpToolProvider`
- [ ] An `IMailer`-backed outbound email tool is registered the same way
- [ ] Every provider enforces a per-object IDOR guard before returning or mutating data
- [ ] No direct third-party HTTP calls exist in Hermiq tool provider code; all route via OpenConnector `CallService`
- [ ] Tools appear in the standard OR `ToolRegistry` tool listing alongside OR's native MCP tools

## Notes

Depends on OpenRegister's MCP stack (`McpServerController`, `McpToolsService`, `McpProviderBridge`,
`ToolRegistry`) and OpenConnector's `CallService`. Related: ADR-001 (Option C+ — Hermiq delegates
tools/governance to OR, connectors to OpenConnector). IDOR guard pattern should follow the
`hydra-gate-no-admin-idor` convention used elsewhere in the fleet.
