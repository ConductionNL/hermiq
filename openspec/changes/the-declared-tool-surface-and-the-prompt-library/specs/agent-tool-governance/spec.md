# agent-tool-governance

## ADDED Requirements

### Requirement: An outside agent MUST reach declared tools through a registration

The system MUST publish a tool surface an AI agent outside the instance can call. The
tools it offers MUST be declared by the app that owns the data. hermiq MUST publish
the surface and MUST ship no tool of its own, in the outbound direction as in the
inbound one (ADR-063, gate 27).

An outside agent MUST hold a registration naming the tools it may call. The
registration MUST default-deny every tool that writes, reusing the default-deny rule
this capability already states for per-agent grants rather than stating a second one.

Candidate C-integrations-19 (`integrations.tsv:16`), relevance `should`, driven
passers itop and openproject. OpenProject's evidence: `mount API::Mcp => "/mcp"`,
`app/models/mcp_configuration.rb`, `/admin/mcp_configurations`,
`app/services/mcp_output_filters/`, enterprise. The sweep's note: "Both expose the
product to an agent outside it, not an assistant inside it."

#### Scenario: The owning app declares, hermiq publishes

- **GIVEN** an app declaring two of its tools as reachable by an outside agent
- **WHEN** the surface is read
- **THEN** exactly those two MUST be offered, and hermiq MUST declare no tool of its
  own

#### Scenario: A write tool is denied unless granted

- **GIVEN** a registration that names no tools explicitly
- **WHEN** it calls a tool that writes
- **THEN** the call MUST be refused

### Requirement: Every outside call MUST be authorised as the calling principal

The system MUST authenticate an outside agent as a principal and MUST have every call
authorised by the owning app for that principal, exactly as a call from a person would
be. A registration MUST NOT carry rights of its own and MUST NOT raise what its
principal may do.

Revoking a person's access MUST therefore revoke their agent's on the next call, with
nothing to update in hermiq.

#### Scenario: An agent cannot exceed its principal

- **GIVEN** a registration granted a read tool, whose principal may not read a given
  case
- **WHEN** it calls that tool on that case
- **THEN** the owning app MUST refuse it

#### Scenario: Revocation reaches the agent without an edit

- **GIVEN** a working agent registration
- **WHEN** its principal's access to a case is revoked
- **THEN** the next call on that case MUST be refused, and no hermiq object MUST have
  been edited

### Requirement: Both gates MUST open before a tool runs

The system MUST check, per call and in this order: that the registration lists the
tool, and that the owning app authorises the act for the calling principal. Both MUST
pass. Neither MUST be treated as sufficient alone.

#### Scenario: A permitted caller without the grant is refused

- **GIVEN** a principal who may write a case and a registration not granted the write
  tool
- **WHEN** the write is attempted
- **THEN** it MUST be refused by the grant check

#### Scenario: A granted agent without the right is refused

- **GIVEN** a registration granted every tool and a principal who may not read the
  case
- **WHEN** a read is attempted
- **THEN** it MUST be refused by the owning app

### Requirement: A registration MAY narrow what a tool response carries

The system MUST let a registration declare an allowlist of fields a tool response may
carry. A field outside the allowlist MUST be absent from the response. The filter MUST
narrow only, and MUST NOT rename, reshape or compute a value.

A filter that transformed values would hold a second copy of the owning app's data
model, and a field renamed in that app would then silently produce a wrong shape here.

#### Scenario: A reading agent does not receive every field

- **GIVEN** a registration allowing three fields of a case
- **WHEN** it reads a case carrying twelve
- **THEN** the response MUST carry those three and no others

#### Scenario: Nothing is renamed on the way out

- **WHEN** a filtered response is compared to the owning app's own field names
- **THEN** every field present MUST carry the owning app's name for it

### Requirement: An outside agent's calls MUST be recorded like an internal agent's

The system MUST record every call from an outside agent on the same audit trail it
records internal agent runs on, carrying the registration, the calling principal, the
tool and the outcome. The oversight surface this capability already specifies MUST
show them beside internal invocations.

#### Scenario: One place to read who called what

- **GIVEN** calls from an internal agent and from an outside registration
- **WHEN** the oversight surface is read
- **THEN** both MUST appear, each naming its caller
