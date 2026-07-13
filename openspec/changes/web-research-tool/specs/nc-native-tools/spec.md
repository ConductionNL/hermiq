# nc-native-tools (delta)

Carves a narrow, explicitly-named exception into the "remote calls route through
OpenConnector" rule for exactly two tool ids (`hermiq.webSearch`, `hermiq.webFetch`).
`discovery.md` documents why: OpenConnector's `CallService` is built around
admin-pre-registered `Source` entities with a fixed `location`, and is structurally
incapable of fetching a URL an LLM only learns of at call time (e.g. from a search
result). Every other Hermiq tool provider remains bound by the original rule unchanged.

## MODIFIED Requirements

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
