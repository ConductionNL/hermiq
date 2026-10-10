# agent-management-ui Specification

**Status**: proposed
**Scope**: hermiq
**OpenSpec changes**:
- agents-instruction-variables

## Purpose

Placeholders in an agent's instructions and fields a person fills in before a conversation. Rows `hermiq:dm-prompt-placeholders` and `hermiq:dm-agent-form-fields`.

## ADDED Requirements

### Requirement: Placeholders in an agent's instructions are filled in per turn (REQ-AGVAR-001)

The system MUST replace the placeholders `{{user.displayName}}`, `{{user.id}}`, `{{user.language}}`, `{{organisation.name}}`, `{{today}}`, `{{now}}`, `{{agent.name}}`, `{{app.id}}` and `{{field.<key>}}` in `Agent.prompt` at the start of every turn, for the acting person. It MUST NOT treat any other text of the turn as a template. An unknown placeholder MUST be left as written.

#### Scenario: An agent greets a person by name and knows the date
- GIVEN an agent whose instructions say "Address {{user.displayName}}. Today is {{today}}."
- WHEN the case handler Fatima el Amrani sends it a message on 27 September 2026
- THEN the model receives "Address Fatima el Amrani. Today is 2026-09-27."
- @e2e exclude model input, covered by PHPUnit on PromptVariableResolver and ResponseGenerationHandler

#### Scenario: The owner previews the filled-in instructions
- GIVEN the owner of that agent on its agent form
- WHEN they choose "Preview"
- THEN the preview shows the instructions with their own name and today's date

### Requirement: An agent can ask for fields before a conversation starts (REQ-AGVAR-002)

The system MUST let an agent owner declare up to ten start fields of type short text, long text, choice, number or date. When a person starts a session with that agent, the chat page MUST show the fields before the composer and MUST NOT send the first message until every required field is filled. The answers MUST be stored on the session and usable as `{{field.<key>}}`.

#### Scenario: A person picks a department before asking
- GIVEN an agent with a required choice field "Department" with the options "Permits" and "Taxes"
- WHEN a person starts a new session with it
- THEN the chat page asks for the department first, and after choosing "Permits" and sending a question the session header shows "Department: Permits"

#### Scenario: A scheduled run uses the default answers
- GIVEN an agent with a start field whose default is "Permits" and a schedule with no values of its own
- WHEN the schedule runs
- THEN the instructions receive "Permits" for that field
- @e2e exclude background job, covered by PHPUnit on ScheduleService
