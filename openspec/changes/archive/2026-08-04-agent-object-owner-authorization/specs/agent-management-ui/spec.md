# agent-management-ui Specification

## Purpose

Close the demonstrated write hole on agent objects: only an agent's owner (and
instance admins) may change it, while everyone who may see it keeps seeing it.

## ADDED Requirements

### Requirement: Only an agent's owner may change it

An agent object MUST reject `create`, `update` and `delete` from any principal
that is neither its owner nor an instance admin, and this MUST be enforced by
OpenRegister on the object itself rather than by any Hermiq controller — the
app's own editor writes straight to the OpenRegister objects API and never
passes through Hermiq's guarded endpoints, so a controller-side check cannot see
the request at all.

#### Scenario: A non-owner cannot rewrite an agent's tool grants

- **GIVEN** an agent owned by one user, granting only a read tool
- **WHEN** a different authenticated user PUTs that agent with a grant for an
  irreversible external tool
- **THEN** the write MUST be refused with 403
- **AND** the stored grants MUST remain exactly as the owner left them
@e2e exclude Cross-user write against the OpenRegister objects API; asserted by the live four-way check recorded in the proposal and by the change's verification task.

#### Scenario: A non-owner cannot repoint an agent's model or instructions

- **GIVEN** an agent owned by one user
- **WHEN** a different authenticated user PUTs that agent with a changed
  `prompt`, `model`, `requiresApproval` or `delegationAllowlist`
- **THEN** the write MUST be refused
@e2e exclude Same request shape and same gate as the scenario above; a second browser-driven copy would assert the same code path twice.

### Requirement: Closing the write path MUST NOT close the read path

Agents MUST remain readable by the principals who can read them today, because
Hermiq shares agents through its own `invitedUsers` and `groups` fields — checked
in PHP after the object is fetched — and never projects those into OpenRegister
object grants. An authorization block that admitted only the owner would close
the hole and break sharing in the same commit.

#### Scenario: A non-owner who may see an agent still sees it

- **GIVEN** an agent owned by one user
- **WHEN** a different authenticated user reads that agent
- **THEN** the read MUST succeed
@e2e exclude Cross-user read against the OpenRegister objects API, outside the browser session model.

#### Scenario: The owner retains full control

- **GIVEN** an agent owned by one user
- **WHEN** its owner updates it
- **THEN** the update MUST succeed
@e2e exclude The control row that distinguishes a working fix from one that denies everybody; covered by the live four-way check.
