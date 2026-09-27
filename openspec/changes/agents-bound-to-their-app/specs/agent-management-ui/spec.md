# agent-management-ui Specification

**Status**: proposed
**Scope**: hermiq
**OpenSpec changes**:
- agents-bound-to-their-app

## Purpose

Tie an agent to the app it serves and pick the agent that answers in each app. Rows `hermiq:ag-app-slug` and `buildiq:ai-in-app-assistant`.

## ADDED Requirements

### Requirement: An agent owner ties an agent to the app it serves (REQ-APPAG-001)

The system MUST let an agent owner choose on the agent form the app the agent serves, from the installed apps and OpenBuild applications, and store it in `applicationSlug`.

#### Scenario: An owner ties an agent to a built app
- GIVEN the owner of the agent "Subsidy desk helper" on its agent form
- WHEN they choose the app "subsidies" under "App this agent serves" and save
- THEN the agent page shows "App: subsidies"

### Requirement: An organisation admin picks the agent that answers in an app (REQ-APPAG-002)

The system MUST let an organisation admin mark one agent per app per organisation as the app's assistant. When a chat request names no agent, both chat endpoints MUST answer with that app's assistant if the user may use it, else with an accessible agent of that app, else with the first accessible agent. A second assistant for the same app MUST be refused with HTTP 409.

#### Scenario: The companion in a built app answers with that app's agent
- GIVEN the agent "Subsidy desk helper" marked as the assistant for "subsidies"
- WHEN a user opens the companion on a page of the subsidies app and asks a question
- THEN the answer comes from "Subsidy desk helper", named at the top of the chat panel

#### Scenario: A second assistant for the same app is refused
- GIVEN an app that already has an assistant
- WHEN an organisation admin marks another agent as its assistant
- THEN the save is refused with "Another agent already answers in this app"

### Requirement: An app's agent answers from the app's data first (REQ-APPAG-003)

The system MUST scope object retrieval of an agent tied to an OpenBuild application, and with no views of its own, to that application's registers. Each source in the answer MUST name the register it came from.

#### Scenario: A question in a built app is answered from its records
- GIVEN a built app "subsidies" with a register of applications and an agent tied to it
- WHEN a user asks the companion "How many applications are waiting for a decision?"
- THEN the answer cites records from the subsidies register only
