## Purpose

Whose rights an agent turn executes with. Implements ADR-099 in hermiq: identity
narrows along an invocation chain, and a declared identity is never silently
replaced by another.

## ADDED Requirements

### Requirement: A flow document MUST NOT name the identity a step runs as

A node's configuration MUST NOT be able to set the identity its execution uses.
A node is a callee: it acts as whoever invoked it, and that identity reaches it
through the run context.

A flow document is authored by anyone who may edit flows, so a configuration key
naming an identity is an authoring-time privilege escalation regardless of intent.

#### Scenario: A config-supplied owner does not override the caller
- **GIVEN** a run context carrying an acting identity
- **WHEN** a node is executed whose config also names a different owner
- **THEN** the step runs as the context's identity
- **AND** the config-supplied value has no effect on what the step may do or on
  what the result is attributed to

### Requirement: A declared acting identity MUST NOT be replaced by another

An agent that declares an `actingUser` which does not resolve to an existing,
enabled user MUST refuse the run.

It MUST NOT fall back to the schedule owner. An author who named an identity did
not consent to a different one, and substituting one executes the turn with
somebody else's rights and attributes it to them.

🔴 This deliberately supersedes `agent-capability-profile` task 3-1, which
required that a misconfigured profile field must not fail the run. Disabling an
account is how a departure is normally processed, so the likeliest trigger for
this branch is precisely the case where substituting the owner is least
acceptable.

#### Scenario: A declared identity that no longer resolves refuses
- **GIVEN** an agent declaring an `actingUser` that names no enabled user
- **WHEN** its schedule fires
- **THEN** the run is refused, naming the agent and the unresolvable identity
- **AND** no agent turn is started
- **AND** the schedule owner is not substituted

#### Scenario: An UNDECLARED acting identity still falls back
- **GIVEN** an agent that declares no `actingUser` at all
- **WHEN** its schedule fires
- **THEN** the run proceeds as the schedule's own identity
- **AND** this is not treated as a refusal — expressing no preference is not the
  same as naming an identity that has gone
