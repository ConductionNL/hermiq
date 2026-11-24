# agent-management-ui Specification

**Status**: proposed
**Scope**: hermiq
**OpenSpec changes**:
- agents-plain-language-builder

## Purpose

Describe an agent in plain language and turn the draft into a full agent without starting over. Rows `hermiq:ag-nl-builder` and `hermiq:dm-builder-upgrade`.

## ADDED Requirements

### Requirement: A described agent becomes a draft in chat (REQ-AGBUILD-001)

The system MUST provide a seeded "Agent builder" agent that answers a description of an agent with a draft in a `hermiq-agent-draft` block, holding name, description, instructions, provider, model, tools, sharing and an optional schedule. The builder MUST NOT create, change or delete any agent itself.

#### Scenario: A team lead describes an agent
- GIVEN a team lead chatting with "Agent builder"
- WHEN they write "An agent that summarises new objections every Monday at eight and posts them in the legal team's Talk room"
- THEN the answer explains the proposed agent and carries a draft, and the agent catalog still has no new agent

### Requirement: A draft opens in the full agent form after a check (REQ-AGBUILD-002)

The system MUST offer "Open as agent" on an assistant message that carries a draft. It MUST check the draft against the tool catalogue, the organisation's model policy, the groups the user can see and the schedule syntax, and MUST open the full agent form pre-filled with the draft and each finding shown next to its field. The agent MUST only exist after the person saves the form.

#### Scenario: The team lead opens the draft and fixes the model
- GIVEN the draft from the previous scenario names a model the organisation's policy does not allow
- WHEN the team lead chooses "Open as agent"
- THEN the agent form opens with every field filled in, the model field says "Not allowed by your organisation" and suggests an allowed model, and after choosing it and saving the agent appears in the catalog

#### Scenario: The proposed schedule is offered after saving
- GIVEN a saved agent from a draft with a weekly schedule
- WHEN the agent form closes
- THEN the schedule form opens pre-filled with "every Monday at 08:00", and the team lead saves or skips it

#### Scenario: The draft check refuses a malformed draft
- GIVEN a draft block that is not valid JSON
- WHEN `POST /api/agents/draft-check` receives it
- THEN the answer is HTTP 422 with "This draft could not be read" and no form opens
- @e2e exclude API contract, covered by PHPUnit and Newman
