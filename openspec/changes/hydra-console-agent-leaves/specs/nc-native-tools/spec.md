# nc-native-tools (delta)

The existing rule already says what the architectural pivot says: remote systems route
through OpenConnector, and Hermiq's tool providers do not open HTTP clients to them.
The pre-pivot draft of this change proposed a `hermiq.setForgeIssueLabel` tool backed by
a `ForgeLabelService` — which would have needed a THIRD named exception to that rule,
alongside `hermiq.webSearch` / `hermiq.webFetch`. It is dropped.

This delta makes the rule's reach explicit rather than widening it. Reading a remote
system and COMMANDING one were never distinguished in the original wording; the
exception list was written for reads (a URL the agent learns of at call time), and it
must not be read as precedent for a write. A command to a remote system routes through
an OpenConnector-backed endpoint or flow node, invoked through the existing
flow-invocation tool under the argument-scoped grant specified in the
`agent-tool-governance` delta.

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

The exception is READ-ONLY in scope. A tool that MUTATES a third-party or remote system
MUST NOT rely on it, and MUST NOT be added to it. An agent command to a remote system
MUST be issued by invoking an OpenConnector-backed endpoint or flow node — reached
through the existing flow-invocation tool under an argument-scoped, approval-gated grant
— and Hermiq MUST NOT author a tool, service or handler that performs the remote write
itself. Where such a command appears to require Hermiq code, the correct response is to
identify and specify the missing flow abstraction, not to add a third exception here.

<!-- Previous behavior: the requirement carved out `hermiq.webSearch`/`hermiq.webFetch` and
said "no other tool gains this exception implicitly", but did not distinguish reading a
remote system from commanding one. Both exempted tools are reads, and their stated
justification (a destination known only at call time) is a read-shaped argument; a write
tool could nonetheless have argued its way onto the list by analogy, which is exactly what
this change's pre-pivot draft did with a proposed forge-label tool. Naming the exception
read-only, and naming the OpenConnector-backed flow/endpoint as the command path, closes
that. The two existing exceptions are unchanged and no new one is added. -->

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

#### Scenario: A remote WRITE cannot use the read exception
- GIVEN a capability that must mutate a third-party system, such as writing a label on a
  forge issue
- WHEN it is designed
- THEN it MUST NOT be implemented as a Hermiq tool calling the remote system directly
- AND it MUST NOT be added to this requirement's exception list
- AND the mutation MUST be performed by an OpenConnector-backed endpoint or flow node

#### Scenario: An agent commands a remote system through a flow, not a bespoke tool
- GIVEN an agent that must issue a command to a remote system
- WHEN it acts
- THEN it MUST invoke an OpenConnector-backed endpoint or flow node through the existing
  flow-invocation tool
- AND the invocation MUST be constrained by an argument-scoped, approval-gated grant
- AND Hermiq's tool catalog MUST NOT contain a bespoke tool for that remote system

#### Scenario: Missing flow capability is specified, not coded around
- GIVEN a remote command that the flow layer cannot yet express
- WHEN the gap is found
- THEN the missing flow abstraction MUST be specified as the deliverable
- AND Hermiq MUST NOT add a bespoke service or tool to bridge it

## Acceptance Criteria

- Hermiq's tool catalog contains no forge, label or issue tool, and no third exception is
  added to this requirement.
- No Hermiq tool provider or service opens an HTTP client to a forge host.
- The forge label write is performed by an OpenConnector-backed endpoint or flow node
  owned outside this repository.
- The agent reaches that write only through the flow-invocation tool under an
  argument-scoped, approval-gated grant.
- Every scenario above is referenced by a Playwright e2e test or carries a reason-bearing
  `@e2e exclude` (gate-19).

## Notes

- **This delta removes code rather than adding it.** Its whole effect on the
  implementation is that `lib/Service/Forge/ForgeLabelService.php` and a
  `hermiq.setForgeIssueLabel` descriptor are NOT written.
- **OpenConnector contributes neither an MCP tool provider nor a flow node today.** The
  command path this requirement mandates therefore has an unbuilt upstream half. That is
  a cross-repo prerequisite recorded as a deferred question; it is deliberately not
  worked around here.
- Related ADRs: ADR-001 (Hermiq delegates connectors to OpenConnector), ADR-022 (consume
  the fleet's abstractions), ADR-031 (declarative over imperative), ADR-065 (one flow
  engine).
