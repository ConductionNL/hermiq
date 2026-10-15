# agent-handoffs Specification

**Status**: proposed
**Scope**: hermiq
**OpenSpec changes**:
- flows-hand-off-and-wait

## Purpose

An agent starts long-running work, keeps going, and receives the late result in the same conversation. Rows `hermiq:dm-async-flow-step` and `hermiq:fl-n8n`.

## ADDED Requirements

### Requirement: Work an agent starts and waits for is recorded as a handoff (REQ-HANDOFF-001)

Hermiq MUST record every piece of work an agent starts without waiting for it as a `Handoff` with its conversation, its kind, its owner and a deadline of at most 7 days. A handoff MUST end in exactly one of `done`, `failed` or `expired`.

#### Scenario: A case handler starts a check and keeps working
- GIVEN a chat agent granted `openregister.runFlow` for the flow "Vergunningcheck"
- WHEN a case handler asks "Controleer of voor Kerkstraat 12 een vergunning nodig is"
- THEN the agent answers at once that the check is running, and the chat shows "Waiting for 'Vergunningcheck' (started 14:02)"

### Requirement: A finished handoff returns its result as a new turn in the same conversation (REQ-HANDOFF-002)

When a handoff closes as `done` or `failed`, hermiq MUST run one turn of the same agent in the same conversation as the handoff's owner, through the same kill switch, budget, model policy and tool grant checks as any turn. The result MUST enter that turn as untrusted external content. Hermiq MUST notify the owner. When the agent is inactive, hermiq MUST keep the result on the handoff and MUST NOT run a turn.

#### Scenario: The flow's answer arrives an hour later
- GIVEN the "Vergunningcheck" handoff from the case handler's conversation
- WHEN the flow run ends with `{"vergunningVereist": true}`
- THEN the conversation shows a new reply from the agent based on that result, and the case handler gets the notification "Your assistant has the result of 'Vergunningcheck'."
- @e2e exclude needs a flow run that ends later; covered by PHPUnit on the listener and job, and a live check

#### Scenario: A result for a switched-off agent is kept, not answered
- GIVEN an open handoff whose agent was switched off
- WHEN the work ends
- THEN the handoff is `done` with its result, and no turn runs
- @e2e exclude background event on a switched-off agent; covered by PHPUnit

### Requirement: A flow started by an agent closes its handoff when the flow run ends (REQ-HANDOFF-003)

When an agent calls `openregister.runFlow`, hermiq MUST open a `flow` handoff with the queued run id, and MUST close it from OpenRegister's flow-run terminal event with the flow's final items as the result. When the deployed OpenRegister has no such event, hermiq MUST NOT open a handoff and MUST tell the model that the result will not come back.

#### Scenario: An OpenRegister without the event
- GIVEN an OpenRegister that dispatches no flow-run terminal event
- WHEN an agent calls `openregister.runFlow`
- THEN the flow is queued, no handoff is opened, and the tool result says "This OpenRegister cannot report when the flow ends, so its result will not come back here."
- @e2e exclude depends on the OpenRegister version; covered by PHPUnit with the event class absent

### Requirement: A webhook workflow returns its result to a one-time address (REQ-HANDOFF-004)

For a custom tool with `waitForCallback`, hermiq MUST send the workflow a result address and a single-use token through integriq, and MUST accept the result only with that token while the handoff is `waiting`. Any other request MUST get 404. Hermiq MUST NOT call the workflow's host itself.

#### Scenario: An n8n workflow posts its result back
- GIVEN a custom tool "Subsidieaanvraag beoordelen" aimed at an n8n workflow through integriq with `waitForCallback` on
- WHEN the workflow's final HTTP Request node posts its result with the token
- THEN the handoff closes as `done`, and a second post with the same token gets 404
- @e2e exclude needs a running n8n; covered by Newman on the result route and PHPUnit on the token

#### Scenario: A workflow never answers
- GIVEN the same tool with a deadline of 24 hours
- WHEN no result arrives in time
- THEN the handoff is `expired`, and the conversation shows "No result from 'Subsidieaanvraag beoordelen' within 24 hours."
- @e2e exclude needs a 24 hour wait; covered by PHPUnit with a fake clock
