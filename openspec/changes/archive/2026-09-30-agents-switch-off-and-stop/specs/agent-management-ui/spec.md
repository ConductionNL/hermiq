# agent-management-ui Specification

**Status**: proposed
**Scope**: hermiq
**OpenSpec changes**:
- agents-switch-off-and-stop

## Purpose

Switch one agent off and on again, stop it everywhere at once, and remove its schedules when it is deleted. Rows `hermiq:ag-enable`, `hermiq:ov-kill-agent` and `hermiq:ag-delete`.

## ADDED Requirements

### Requirement: An agent can be switched off and on without deleting it (REQ-AGOFF-001)

The system MUST let the agent owner, an instance admin, or the owner of the agent's organisation switch an agent off and on from the agent page. Switching off MUST require a reason. The system MUST record who switched the agent, when and why, on the agent and in the audit trail. Any other user MUST get HTTP 403.

#### Scenario: An organisation admin switches off an agent that sends wrong reminders
- GIVEN an organisation admin on the page of the agent "Permit reminder" owned by a colleague
- WHEN they choose "Switch off", enter the reason "Sends reminders for closed permits" and confirm
- THEN the page shows "Switched off" with their name, the time and the reason, and the agent catalog shows the agent as switched off

#### Scenario: A colleague without rights cannot switch the agent
- GIVEN a user who neither owns the agent nor administers its organisation
- WHEN they call `POST /api/agents/{id}/availability` with `active` false
- THEN the answer is HTTP 403 and the agent stays on
- @e2e exclude authorization contract on the endpoint, covered by PHPUnit and Newman

#### Scenario: Switching on again
- GIVEN a switched-off agent
- WHEN its owner chooses "Switch on"
- THEN the agent runs again from chat and its schedules fire at their next due time

### Requirement: A switched-off agent does not run on any path (REQ-AGOFF-002)

The system MUST refuse to start a turn for a switched-off agent from chat, the chat stream, Talk, the ContextAgent provider, the case assistant surface, a schedule, run now, a webhook, a flow and a delegation. A scheduled occurrence MUST be recorded as `skipped_agent_off` and its next run MUST advance. A chat request MUST get HTTP 409 with the message "This agent is switched off."

#### Scenario: A schedule of a switched-off agent is skipped and recorded
- GIVEN a switched-off agent with a schedule due now
- WHEN the scheduler runs
- THEN no model is called, the run history shows the occurrence as skipped because the agent is switched off, and the next run moves to the following due time
- @e2e exclude background job, covered by PHPUnit on ScheduleService

#### Scenario: A person opens chat with a switched-off agent
- GIVEN a switched-off agent
- WHEN a user sends it a message on the chat page
- THEN the chat shows "This agent is switched off." and no answer is generated

### Requirement: A run in progress stops when its agent is switched off (REQ-AGOFF-003)

The system MUST stop a running turn of an agent that is switched off while it runs, no later than the turn's next tool call. The run trace MUST record the step "Stopped: agent switched off".

#### Scenario: An agent in a loop is stopped by its owner
- GIVEN an agent in the middle of a turn that keeps calling a search tool
- WHEN its owner switches it off
- THEN the next tool call is not made, the turn ends, and the run trace shows "Stopped: agent switched off"
- @e2e exclude timing-dependent engine behaviour, covered by PHPUnit on FacadeToolInvoker and ToolLoop

### Requirement: Deleting an agent removes its schedules (REQ-AGOFF-004)

The system MUST delete every schedule of an agent when the agent is deleted, declared as `onDelete: CASCADE` on `Schedule.agentId`. The delete confirmation MUST say how many schedules are deleted with the agent.

#### Scenario: An agent with two schedules is deleted
- GIVEN the owner of an agent with two schedules on the agent catalog
- WHEN they choose delete on its row
- THEN the confirmation says "2 schedules are deleted with this agent", and after confirming neither schedule exists or fires
