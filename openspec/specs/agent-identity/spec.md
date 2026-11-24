# Agent Identity Specification

**Status**: active

**Feature tier**: V1

**OpenSpec changes:** `agent-identity-narrows` — DONE (8 of 8 tasks): a node's config can no longer
name the identity its step runs as, and an agent declaring an `actingUser` that resolves to no
enabled user refuses the run instead of falling back to the schedule owner. Implements ADR-099 in
Hermiq. Supersedes `agent-capability-profile` task 3-1.

## Purpose

Whose rights an agent turn executes with. Implements ADR-099 in hermiq: identity
narrows along an invocation chain, and a declared identity is never silently
replaced by another.

## Requirements

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

### Requirement: The agent edit page sets whose rights the agent works with (REQ-AGID-001)

The agent edit page SHALL show the choice "Namens wie werkt de agent" as drawn on
`hermiq/HmAgentBewerken`, with two options: on behalf of the person who uses the agent, and as its
own service account with a picker of enabled Nextcloud users. Saving the first option SHALL store an
empty `actingUser`; saving the second SHALL store the chosen user id in `actingUser`. Reopening the
agent SHALL show the stored choice.

Rows: `ag-identity`. Board: `hermiq/HmAgentBewerken`.

#### Scenario: An administrator gives an agent its own service account
- **GIVEN** an administrator editing the agent "Woo-triage" and an enabled user `woo-triage`
- **WHEN** they choose "Als eigen serviceaccount", pick `woo-triage` and save
- **THEN** the agent's `actingUser` is `woo-triage`
- **AND** reopening the agent shows "Als eigen serviceaccount: woo-triage" selected

#### Scenario: Going back to the person who uses it
- **GIVEN** an agent whose `actingUser` is `woo-triage`
- **WHEN** its owner chooses "Namens de persoon die hem gebruikt" and saves
- **THEN** the agent's `actingUser` is empty
- **AND** its next scheduled run runs as the schedule's owner

### Requirement: Naming a service account is a governance action checked on every write (REQ-AGID-002)

A write of an agent object that sets `actingUser` to a non-empty value different from the stored
value SHALL succeed only when the writer passes the governance action `agent.set-acting-user` of
`action-authorization` and the named user exists and is enabled. hermiq SHALL check this on the
server before the object is saved, for every write path, including a direct write through the
OpenRegister objects API, and SHALL refuse the write with an error naming the reason. The action
SHALL be seeded as admin-only. Clearing `actingUser` SHALL stay allowed for anyone who may modify
the agent. The edit page SHALL disable the service account option, with a sentence saying an
administrator sets it, for a viewer without the action.

Rows: `ag-identity`. Board: `hermiq/HmAgentBewerken`.

#### Scenario: An owner without the action names an account through the API
- **GIVEN** Pieter owns an agent and is not in a group the matrix names for `agent.set-acting-user`
- **WHEN** he writes the agent object with `actingUser` set to `burgemeester` through the objects API
- **THEN** the write is refused with an error naming `agent.set-acting-user`
- **AND** the stored `actingUser` is unchanged

@e2e exclude needs a second, non-admin Nextcloud user; covered by a unit test of AgentActingUserGuardListener.

#### Scenario: A disabled account cannot be named
- **GIVEN** an administrator and a disabled user `oud-account`
- **WHEN** they save an agent with `actingUser` set to `oud-account`
- **THEN** the write is refused with an error saying the account does not exist or is disabled

@e2e exclude needs a disabled Nextcloud user; covered by a unit test of AgentActingUserGuardListener.

#### Scenario: The option is disabled for a viewer without the action
- **GIVEN** a person without `agent.set-acting-user` editing an agent they own
- **WHEN** the edit page opens
- **THEN** the service account option is disabled and says an administrator sets it

@e2e exclude needs a second, non-admin Nextcloud user; covered by a Vitest test of the edit page.

### Requirement: The edit page shows a declared account that no longer resolves (REQ-AGID-003)

When an agent's `actingUser` names a user that no longer exists or is disabled, the edit page SHALL
show that user id under "Als eigen serviceaccount" with a warning that runs are refused until the
account is changed or the agent goes back to the person who uses it, so the refusal of
`agent-identity` is visible where it can be fixed.

Rows: `ag-identity`. Board: `hermiq/HmAgentBewerken`.

#### Scenario: A departed service account shows a warning
- **GIVEN** an agent whose `actingUser` names the disabled user `oud-account`
- **WHEN** an administrator opens the agent's edit page
- **THEN** "Als eigen serviceaccount: oud-account" is selected with a warning that runs are refused

@e2e exclude needs a disabled Nextcloud user; covered by a Vitest test of the edit page.
