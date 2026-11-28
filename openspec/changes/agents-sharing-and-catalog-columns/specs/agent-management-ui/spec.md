# agent-management-ui Specification

**Status**: proposed
**Scope**: hermiq
**OpenSpec changes**:
- agents-sharing-and-catalog-columns

## Purpose

Decide who can see and use an agent, and list every agent with its owner and status. Rows `hermiq:ag-visibility` and `hermiq:ag-list`.

## ADDED Requirements

### Requirement: An agent owner decides who can use the agent (REQ-AGSHARE-001)

The system MUST let an agent owner choose on the agent form between "Only me", "People and groups I choose" and "Everyone in my organisation". The choice MUST be stored in `isPrivate`, `invitedUsers` and `groups`. Only the owner MUST be able to change it.

#### Scenario: A team lead shares an agent with the planning desk
- GIVEN the owner of the agent "Permit intake helper" on its agent form
- WHEN they choose "People and groups I choose", pick the group "Planning desk" and save
- THEN a member of the planning desk sees the agent in their catalog and can chat with it, and a colleague outside the group does not see it

#### Scenario: A new agent is private
- GIVEN a user creating an agent without touching the sharing field
- WHEN they save it
- THEN the agent form shows "Only me" and no colleague sees the agent

### Requirement: Group sharing is enforced wherever an agent is read or run (REQ-AGSHARE-002)

The system MUST grant read and use of a private agent to a member of any group in its `groups`, in the one access predicate every hermiq route uses. A user outside the owner, the invited users and the groups MUST get HTTP 404 for the agent.

#### Scenario: A member of a shared group opens the agent
- GIVEN a private agent shared with the group "Planning desk"
- WHEN a member of that group calls `GET /api/agents/{id}`
- THEN the answer is HTTP 200 with the agent
- @e2e exclude authorization contract on the endpoint, covered by PHPUnit and Newman

#### Scenario: Someone outside the group cannot confirm the agent exists
- GIVEN the same agent
- WHEN a user who is not the owner, not invited and not in the group calls `GET /api/agents/{id}`
- THEN the answer is HTTP 404
- @e2e exclude authorization contract on the endpoint, covered by PHPUnit and Newman

### Requirement: The agent catalog shows owner, sharing and status (REQ-AGSHARE-003)

The system MUST list in the agent catalog only the agents the user may use, with the columns Name, Owner, Who can use it, Status and Model. Paging and the total count MUST count only those agents.

#### Scenario: A case handler looks for an agent to use
- GIVEN a case handler with access to twelve agents in an organisation of forty
- WHEN they open the agent catalog
- THEN they see twelve rows, each with its owner, who can use it, and "On" or "Switched off"

### Requirement: An organisation admin sees every agent of the organisation (REQ-AGSHARE-004)

The system MUST show an organisation admin every agent of their organisation in the catalog, with the same columns. For a private agent the admin could not otherwise use, the agent page MUST show name, owner, status, sharing and run history, and MUST NOT show its prompt or tools.

#### Scenario: An organisation admin reviews all agents
- GIVEN an organisation admin whose organisation has forty agents, ten of them private
- WHEN they open the agent catalog
- THEN they see forty rows, and opening a private agent shows its owner and run history but not its prompt
