## 1. Build what the new scenarios say

- [x] 1.1 Let an operator mark a tool call as misuse on the agent's tool activity, with a reason, and show the "Misuse" label with who marked it and when; write the mark to the agent's audit trail (row `ov-tool-oversight`). Verify: a unit test that a mark without a reason is refused and a mark is recorded with the operator's uid.
- [x] 1.2 Cover the contact lookup and send-mail scenarios with a unit test on `HermiqToolProvider` (rows `tl-contacts`, `tl-mail`).
- [x] 1.3 Cover the kill-switch read scenario with a unit test that `show()` returns `engagedBy` and `engagedAt` (row `co-disable-dept`).

## Build notes

- 1.1 (backend done, interface open): `POST /api/agents/{agentId}/tool-invocations/{callId}/mark` (`ToolOversightController::markToolInvocation`, owner-only like the grant write) refuses a mark without a reason with 400 and writes the mark, with the operator's uid and the time, on the agent (`toolCallMarks`, agent schema 0.14.0), so OpenRegister's audit trail of the agent records it. Each tool activity row now carries a `callId` (its audit entry's uuid), and a marked row carries `mark`. Tested in `ToolCallMarksTest` and `ToolOversightControllerTest`. The tool activity view (`ToolInvocationTable`) has an Oversight column: a recorded call offers "Mark", which opens `src/modals/ToolCallMarkModal.vue` (the Mark button stays disabled until a reason is entered; the server refusal is shown); a marked call shows the "Misuse" label with who marked it and when. Tested in `tests/vitest/tool-call-mark.spec.js`.
- 1.2: `tests/Unit/Mcp/HermiqToolProviderRowScenariosTest.php` (contact lookup by name and email, mail sent from the case handler's own address with the asked text).
- 1.3: `OrganisationControlControllerTest::testShowSaysWhoEngagedTheSwitchAndWhen` and `testShowOfANeverEngagedSwitchNamesNobody`.
