# Design: agents-switch-off-and-stop

Kind: code. Size M. Rows `hermiq:ag-enable`, `ov-kill-agent`, `ag-delete`, `dm-tool-call-cap`.

## Context at development db6b74dc

- `Agent.active` at `lib/Settings/hermiq_register.json:2504` (boolean, default true). The Configuration card on `/agents/:id` lists it (`src/manifest.json` widget `agent-core`, `content.include`), but a `type:"data"` widget renders values only, which is why the eval baseline field needed its own widget (`src/manifest.json:374-378`, `agent-eval-baseline`). `src/modals/AgentFormModal.vue` has no active field.
- Run entry points: `Engine::processMessage()` (`lib/Service/Engine/Engine.php:262`, agent loaded at `:311-316`) serves chat (`lib/Controller/ChatController.php:322`), the stream (`lib/Controller/ChatStreamController.php:329`), Talk (`lib/Service/Talk/TalkTurnService.php:109`), the ContextAgent provider (`lib/Service/ContextAgentInteractionService.php:193`) and scheduled runs through `ScheduleService::runAgentViaEngine()` (`lib/Service/ScheduleService.php:2704`). `ScheduleService::runAgentAsOwner()` (`:2199`) is the shared entry of schedules, run now, webhooks, flows and delegation, and keeps a legacy `ChatService` branch when the engine flag is off (`:2292-2316`). The case assistant surface has its own pipeline, `AssistantService::converse()` (`lib/Service/Assistant/AssistantService.php:184`, agent at `:219`).
- Gate recording: `ScheduleService::dispatch()` (`:937`) writes `skipped_killswitch`, `skipped_budget` or `awaiting_approval` through `recordGateSkip()` (`:1318`).
- The kill switch authorises an instance admin or the organisation's owner: `TenantControlController::mayAdminister()` (`lib/Controller/TenantControlController.php:173`).
- Delete: `AgentsController::destroy()` (`lib/Controller/AgentsController.php:485`) guarded by `canUserModifyAgent()` (`:711`, owner only). `Schedule.agentId` is `$ref: "agent"` (`lib/Settings/hermiq_register.json:84-91`).
- OpenRegister honours `onDelete` on a `$ref` property: `CASCADE`, `RESTRICT`, `SET_NULL`, `SET_DEFAULT`, `NO_ACTION` (openregister development ae898b0, `lib/Service/Schemas/PropertyValidatorHandler.php:952-965`, walked by `lib/Service/Object/ReferentialIntegrityService.php:901`).
- Tool calls: `FacadeToolInvoker::__call()` (`lib/Service/Engine/FacadeToolInvoker.php:448`) is built once per turn by `ToolLoop` (`lib/Service/Engine/ToolLoop.php:476`). The Anthropic HTTP loop has its own fixed cap (`lib/Service/Llm/ProviderFactory.php:219`, `:1009`).

## D1. One availability check, called at three seams

A new `AgentAvailabilityService::assertRunnable(ObjectEntity $agent): void` throws `AgentSwitchedOffException` when `active` is false. It is called:

1. in `Engine::processMessage()` right after the agent is loaded (covers chat, stream, Talk, ContextAgent and the engine branch of scheduled runs);
2. in `ScheduleService::runAgentAsOwner()` before impersonation (covers the legacy ChatService branch, run now, webhooks, flows and delegation);
3. in `AssistantService::converse()` after the agent is loaded.

`ScheduleService::dispatch()` checks `active` before gate 1 as well, so a scheduled occurrence of a switched-off agent is recorded as `skipped_agent_off` through `recordGateSkip()` and its `nextRun` advances, exactly like a kill-switch skip. The chat answers with the sentence "This agent is switched off." and HTTP 409.

Rejected: a check inside each controller. Nine entry points exist today and every new one would have to remember it.

## D2. Who may switch, and what is recorded

`POST /api/agents/{id}/availability` with `{active: bool, reason: string}`. Allowed for the agent owner (`canUserModifyAgent()`) and for whoever `TenantControlController::mayAdminister()` allows for the agent's organisation. The reason is required when switching off. Three new Agent properties record it: `availabilityChangedBy`, `availabilityChangedAt`, `availabilityReason`. The change is also written to the run audit on OpenRegister's AuditTrail with action `agent.availability`, so the Art. 12 export carries it.

`active` is removed from the Configuration card's `content.include`, and `AgentsController::stripProtectedKeys()` gains `active` and the three new fields, so hermiq offers one write path. The owner can still write `active` through OpenRegister's generic object API. That write is audited by OpenRegister like any save, and the agent page then shows "No reason given". Marking the fields `readOnly` was rejected: OpenRegister enforces `readOnly` on every update (openregister `lib/Service/Object/ValidateObject.php:151`), which would block the availability endpoint too.

## D3. An in-flight run stops at its next tool call

`FacadeToolInvoker::__call()` re-reads the agent's `active` before it invokes a tool, at most once every five seconds per turn (an in-memory timestamp on the invoker). When the agent is switched off it returns a tool error "The agent was switched off" and sets a stop flag that `ToolLoop` reads to end the turn after the current model response. The run trace records the step "Stopped: agent switched off". A turn with no tool calls finishes its one model response; that is the smallest unit hermiq can stop without a checkpoint layer, which the archived `run-replay-and-dry-run` states hermiq does not have.

## D4. Deleting an agent removes its schedules

`Schedule.agentId` gains `"onDelete": "CASCADE"` in `lib/Settings/hermiq_register.json`. OpenRegister's referential integrity then deletes the schedules in the same delete. The delete confirmation on the catalog row names how many schedules go with it, read from `GET /api/agents/{id}` which gains a `scheduleCount`.

A mirrored engine flow (`Schedule.engineFlowId`, open change `schedules-onto-engine-triggers`) is that change's to clean up; this change notes the dependency in a task and does not delete flows.

Rejected: an `ObjectDeletedEvent` listener that disables schedules. It keeps dead schedules that point at nothing, and it is imperative code for a rule OpenRegister declares.

## D5. A tool call cap per turn

`Agent.maxToolCalls`: integer, default 10, minimum 1, maximum 100. `ToolLoop` passes it to `FacadeToolInvoker`, which counts calls in the turn and, past the cap, returns the error "Tool call limit reached for this turn" without invoking the tool and sets the stop flag of D3. `ProviderFactory`'s Anthropic loop uses the same value in place of the constant. The field is on the agent form under "Limits", with the help text "The agent stops after this many tool calls in one answer."

## Declarative versus imperative

| behaviour | path | why |
|---|---|---|
| schedules removed with their agent | declarative, `onDelete: CASCADE` on `Schedule.agentId` | OpenRegister's referential integrity owns it |
| `active` and the availability fields | declarative, schema properties | plain data |
| refusing a run of a switched-off agent | imperative, `AgentAvailabilityService` | a run-time guard on the engine path (ADR-031 exception: lifecycle guard) |
| the tool call cap | imperative, `FacadeToolInvoker` | enforcement inside the tool loop |

## Seed data

The seeded starter agents keep `active: true` and get `maxToolCalls: 10`. One seeded example agent, "Weekly supplier digest", is seeded switched off with `availabilityReason: "Paused until the new supplier list is approved"` so the switched-off state shows on a fresh install.

## Risks

- A switch that is not honoured by a path added later. Mitigation: PHPUnit asserts each of the three seams refuses, and a test lists the callers of `Engine::processMessage()` and `runAgentAsOwner()` so a new caller fails the test until it is reviewed.
- Cascade deleting more than expected. Mitigation: only `Schedule.agentId` gets `onDelete`; `EvalDataset`, `Memory` and run audit entries are untouched and keep their history.
- The cap stopping a legitimate long task. Mitigation: the owner raises it to 100, and the trace says why the turn stopped.

## As built (2026-09-30)

What changed against the design above, and why:

- **D1.** The check is `AgentAvailability::assertRunnable()` (a pure rule over the agent record), called in `Engine::processMessage()`, `ScheduleService::runAgentAsOwner()` and `AssistantService::converse()`. `AssistantService` now loads the agent before it stores the question, so a refused question leaves nothing behind. `ScheduleService::dispatch()` checks it as gate 0, before the kill switch, and `evaluateGates()` (dry run and replay) returns `skipped_agent_off` too. `EngineSwitchedOffTest::testEveryCallerOfTheRunEntryPointsIsKnown` lists every caller of both entry points.
- **D2.** The switch lives on its own controller, `AgentAvailabilityController` (`GET` and `POST /api/agents/{id}/availability`), with `AgentAvailabilityService`, rather than on `AgentsController`, which is already at its coupling limit. `GET` also answers `canSwitch` and `scheduleCount`. `AgentsController::PROTECTED_KEYS` gains `active` and the three availability fields. The agent form saves through OpenRegister with the stored payload spread in, so it keeps whatever the switch wrote.
- **D3.** The invoker reads the agent's switch before every tool call, not at most once every five seconds: one agent read per tool call is small next to the tool call itself, and "the next tool call" in REQ-AGOFF-003 is then exact. The rule sits in `TurnGuard`, held by `FacadeToolInvoker`. The first refused call returns an error result naming the reason and records it on the trace; a later call throws `TurnStoppedException`, which `ResponseGenerationHandler` turns into the turn's answer.
- **D4.** `scheduleCount` is on the availability endpoint, not on `GET /api/agents/{id}`. The confirmation is `AgentDeleteDialog`, mounted by the AgentCatalog page's `slots.delete-dialog` in place of the generic one. Task 6's question: `schedules-onto-engine-triggers` is still open and no schedule carries `engineFlowId` at HEAD, so there is no mirrored flow to clean up yet; that change owns it.
- **D5.** On the CLI runner every tool call is its own request to `McpRunController`, so no invoker sees the whole turn. The count of a run lives in the distributed cache under the run id from the verified run token (`RunToolCallCounter`). Without a distributed cache that can count, the cap is not enforced on that transport; the switch still is, because the invoker reads it before each call.
- **Task 5.** The dialog is `src/dialogs/AgentAvailabilityDialog.vue` (an NcDialog, so it lives in `src/dialogs/` by the modal-isolation rule), opened by the "Switch off or on" header action. The Configuration card drops `active` and shows `maxToolCalls`; the catalog gains a "Switched on" column.
- **Seed.** "Weekly supplier digest" is seeded switched off in the demo register (`lib/Settings/hermiq_mock_register.json`), which is where Hermiq's example agents live; the starter templates are not agents.
- **Run history.** The two run widgets label `skipped_agent_off` "Halted (agent switched off)" and "Blocked (agent switched off)".
