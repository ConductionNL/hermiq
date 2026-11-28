## ADDED Requirements

### Requirement: An agent MUST be able to discover tools it does not hold

A meta-tool MUST list tools beyond the agent's resolved grants, returning for each an
id, a description, the owning app, and whether its reach is read or write.

Today `searchTools` is backed by `ToolSearchService`, which holds *this run's resolved,
grant-filtered, default-denied* set. An agent therefore cannot distinguish "no such
tool exists" from "such a tool exists and I may not use it", and answers both the same
way.

#### Scenario: A capability in another app is discoverable

- **GIVEN** a client tool provided by another installed app
- **AND** an agent whose grants do not include it
- **WHEN** the agent searches for a client capability
- **THEN** the tool MUST be listed
- **AND** the result MUST name the app that provides it

#### Scenario: Discovery does not distinguish held from unheld by omission

- **GIVEN** an agent with some granted and some ungranted tools
- **WHEN** it lists available tools
- **THEN** each entry MUST state whether the agent currently holds it

### Requirement: Discovery MUST NOT be a route to invocation

The discovery result MUST NOT carry anything dispatchable. Invoking a discovered but
ungranted tool MUST fail exactly as invoking an unknown tool fails.

Two surfaces are kept apart deliberately: if a discovery result were accepted by the
dispatcher, the grant check would be one bug away from optional.

#### Scenario: A discovered tool is still refused

- **GIVEN** an agent that has discovered an ungranted tool
- **WHEN** it attempts to call that tool in the same run
- **THEN** the call MUST be refused
- **AND** the refusal MUST be the same as for an unknown tool

### Requirement: Discovery MUST be scoped to what the acting user may see

The listing MUST be limited to tools from apps the acting user can access, and MUST
NOT be the unfiltered instance catalogue.

The catalogue names which apps are installed and what they do. Returning it wholesale
to any agent discloses the shape of the deployment to anyone who can start a chat.
This is scoped to the USER, not to the agent's grants — seeing past the grant is the
point of the feature.

#### Scenario: Tools from an inaccessible app are not listed

- **GIVEN** an app the acting user has no access to
- **WHEN** the agent lists available tools
- **THEN** that app's tools MUST NOT appear

### Requirement: An agent MUST be able to request access, and MUST NOT be able to grant it

A meta-tool MUST let an agent raise an access request naming a tool and a
justification. Raising it MUST NOT change the agent's grants.

#### Scenario: A request does not confer access

- **GIVEN** an agent that has requested a tool
- **WHEN** it attempts to call that tool before any decision
- **THEN** the call MUST be refused
- **AND** the agent's grants MUST be unchanged

#### Scenario: The agent reports honestly that it has asked

- **GIVEN** a raised request
- **WHEN** the agent replies to the user
- **THEN** it MUST be able to state that access was requested and is pending

### Requirement: Only the agent's owner MUST be able to grant a request

A pending request MUST be resolvable only by the user recorded as the agent's owner.
Any other caller MUST be refused, and the refusal MUST NOT reveal whether the request
exists.

The owner is the party accountable for what the agent can do. Note this constrains
THIS surface: an administrator can still edit `Agent.tools` directly, and this
requirement does not claim otherwise.

#### Scenario: A non-owner cannot grant

- **GIVEN** a pending request on an agent owned by another user
- **WHEN** a non-owner attempts to grant it
- **THEN** it MUST be refused
- **AND** the agent's grants MUST be unchanged

### Requirement: The owner MUST be notified when a request is raised and when access is granted

Raising a request MUST notify the owner. Granting MUST emit both a notification to the
owner and an alert on the agent, each naming the tool id, its owning app, and whether
the reach is read or write.

A grant visible only by re-reading `Agent.tools` is how an agent's capability drifts
from what its owner believes it has — measured precedent: 89% of agents were receiving
the entire catalogue and nothing on the agent showed it.

#### Scenario: A grant is announced with its reach

- **GIVEN** an owner grants a write-reaching tool
- **WHEN** the notification is delivered
- **THEN** it MUST name the tool, its owning app, and that the reach is write

### Requirement: The approval surface MUST show the facts beside the agent's argument

The surface MUST present the tool id, owning app and read/write reach with the same
prominence as the justification, and MUST mark the justification as agent-authored.

⚠️ The justification is written by the model to persuade the person holding the
permission. That is an influence channel aimed at a human decision, and the mitigation
is presentation, not trust: a surface showing only the reason invites a decision about
the sentence rather than about the capability.

#### Scenario: The justification is attributed

- **GIVEN** a pending request
- **WHEN** the owner views it
- **THEN** the justification MUST be marked as written by the agent
- **AND** the tool's identity and reach MUST be visible without expanding anything

### Requirement: Requests MUST be bounded and a refusal MUST persist

A pending request for the same agent and tool MUST NOT be duplicated. A refused
request MUST NOT be re-raisable by the agent for that tool unless the owner re-opens
it. Requests per agent per interval MUST be bounded.

An agent that can ask can ask repeatedly, and a persistent model against a tired human
is an approval mechanism with a known outcome: a wildcard grant, issued to stop being
asked — the same pressure that produced the legacy default this programme removed.

#### Scenario: A refused request is not re-raisable

- **GIVEN** a request the owner refused
- **WHEN** the agent requests the same tool again
- **THEN** no new request MUST be created
- **AND** the owner MUST NOT be notified again
