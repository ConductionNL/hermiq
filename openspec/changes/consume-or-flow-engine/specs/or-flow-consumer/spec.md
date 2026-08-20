## ADDED Requirements

### Requirement: The agent step is an OpenRegister flow node (REQ-ORC-001)

hermiq SHALL contribute the agent step as an OpenRegister `IFlowNode`
(`hermiq.agent-step`) through `RegisterFlowNodesEvent`, so it appears in the
shared engine's palette and runs as a step of any flow. The turn SHALL remain
`ScheduleService::runAgentAsOwner()`. An agent step with no agent SHALL be
refused at save time.

#### Scenario: The agent node is in the shared palette

- **GIVEN** hermiq and OpenRegister both installed
- **WHEN** OpenRegister builds its flow palette
- **THEN** `hermiq.agent-step` is in it

### Requirement: OpenRegister can load and trigger hermiq flows (REQ-ORC-002)

hermiq SHALL contribute an `IFlowResolver` that turns an agentflow id into a
flow document, loads a run's subject, and lists enabled agentflows wired to a
fired event. An id that is not a hermiq flow SHALL resolve to null so another
resolver can own it.

#### Scenario: A stored agentflow resolves

- **GIVEN** a saved agentflow
- **WHEN** its id is resolved through OpenRegister's resolver registry
- **THEN** its flow document is returned

#### Scenario: An unknown id falls through

- **WHEN** an id no hermiq flow owns is resolved
- **THEN** the resolver returns null

@e2e exclude cross-app flow-engine consumption is backend — verified live on an
instance with both apps; hermiq CI has no OpenRegister
