## MODIFIED Requirements

### Requirement: Per-agent tool-invocation oversight surface (AI Act art.12/14)
The system MUST provide, per agent and tenant-scoped, an oversight view of that agent's tool
invocations — tool id, acting identity, parameter summary, result summary, data touched, and
timestamp — sourced from OpenRegister's MCP invocation audit log, with a retention note and an
export (CSV + JSON). The system MUST NOT fabricate rows when no invocations have been recorded.

#### Scenario: An operator reviews an agent's tool activity

- **GIVEN** an agent that has invoked several tools across past runs
- **WHEN** an authorized operator opens the agent's oversight view
- **THEN** the system MUST list the recorded invocations (newest first) with tool id, acting
  identity, parameter summary, result summary, data touched, and timestamp, scoped to the operator's
  tenant
- **AND** the system MUST offer an export of those rows

#### Scenario: The richer invocation audit shape is not yet available

- **GIVEN** OpenRegister has not yet written the richer per-invocation MCP audit entries
- **WHEN** the oversight view loads
- **THEN** the system MUST degrade to the coarser `run`/tool-call audit entries already available
- **AND** the system MUST indicate the reduced detail rather than erroring or fabricating rows

#### Scenario: An agent has no recorded invocations

- **GIVEN** an agent that has never invoked a tool
- **WHEN** the oversight view loads
- **THEN** the system MUST render an empty state
- **AND** the system MUST NOT display any fabricated invocation row

#### Scenario: An operator marks a tool call as misuse

- **GIVEN** an operator on the tool activity of the agent "Woo-triage", which called `hermiq.sendMail` although sending mail is not part of its task
- **WHEN** they select that call, choose "Mark" and enter the reason "Sending mail is not part of this agent's task"
- **THEN** the call shows the label "Misuse" with the operator's name and the date
- **AND** the agent's audit trail records that the operator marked the call, with the reason
- **AND** a mark without a reason is refused
