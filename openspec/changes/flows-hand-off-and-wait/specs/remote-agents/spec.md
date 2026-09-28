# remote-agents Specification

**Status**: proposed
**Scope**: hermiq
**OpenSpec changes**:
- flows-hand-off-and-wait

## Purpose

An agent hands a task to an agent on another server over A2A, through integriq, governed like any tool. Row `hermiq:dm-remote-agent`.

## ADDED Requirements

### Requirement: An admin registers a remote agent through an integriq source (REQ-A2A-001)

Hermiq MUST let an admin register a `RemoteAgent` with a name, a description and an integriq source. Hermiq MUST fetch the remote agent card once through that source, keep it as a snapshot, and MUST NOT store the remote server's credential.

#### Scenario: An admin adds the KvK agent
- GIVEN an admin on Admin settings, Remote agents, and an integriq source "kvk-a2a"
- WHEN they add "KvK-assistent" with that source and save
- THEN the list shows the agent with the skill "Bedrijfsgegevens opzoeken op KvK-nummer" read from its card, and switched off until they turn it on

### Requirement: Asking a remote agent is a default-denied external tool (REQ-A2A-002)

Hermiq MUST expose `hermiq.askRemoteAgent` with reach `external`, `destructiveHint: true` and `scope: create`, so an agent can use it only when granted, and so the approval gate applies when the organisation's policy classifies it `confirm`. A grant MAY name one remote agent, and a call to another MUST be refused.

#### Scenario: A grant for one remote agent only
- GIVEN an agent granted `hermiq.askRemoteAgent?remoteAgent=kvk-assistent`
- WHEN the model asks another remote agent
- THEN the call is refused with `grant_constraint_violated`, and nothing leaves the instance

#### Scenario: An approval before the task leaves
- GIVEN an organisation whose guardrail policy classifies `hermiq.askRemoteAgent` as `confirm`
- WHEN an agent asks "KvK-assistent" for the details of KvK-nummer 12345678
- THEN an approval is created, and the task is sent only after a reviewer approves it

### Requirement: A remote task travels through integriq and may finish later (REQ-A2A-003)

Hermiq MUST send the A2A request through integriq's `CallService` on the remote agent's source, and MUST NOT open an HTTP connection to the remote server itself. A task that is still working MUST become an `a2a` handoff that hermiq checks through the same source until it ends or its deadline passes.

#### Scenario: A remote task that takes a while
- GIVEN the remote agent answers `message/send` with a task in state `working`
- WHEN the task completes twenty minutes later
- THEN the handoff closes as `done` and the answer arrives as a new turn in the conversation
- @e2e exclude needs a live A2A peer; covered by PHPUnit with a stubbed CallService and a live check against a test peer
