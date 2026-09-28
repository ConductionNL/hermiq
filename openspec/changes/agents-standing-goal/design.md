# Design: agents-standing-goal

Kind: code. Size M. Row `hermiq:dm-standing-goal`.

## Context at development db6b74dc

- Dispatch: `ScheduleService::dispatch()` (`lib/Service/ScheduleService.php:937`) with gate 1 kill switch (`:962`), gate 2 budget, gate 3 approval, then `runDue()` (`:1385`), which runs the agent under the owner's identity with commit-before-run (archived `agent-schedule-dispatcher`).
- Session per run: `runAgentViaEngine()` (`:2644`) creates a conversation for every run (`:2665-2673`) and passes its uuid to `Engine::processMessage()` (`:2704-2705`).
- Judge: `EvalScoringService` (`lib/Service/EvalScoringService.php`) scores with a rubric through `ProviderFactory::generateText()`, so model policy and budget apply to the judge call.
- Object reads as the acting user: `ContextAssembler` object queries (`lib/Service/Engine/ContextAssembler.php:354,394`).
- Delivery of a result: Talk, notification, email, webhook (archived `talk-delivery`, `delivery-channels`).

## D1. A goal is a schedule that continues one session

New schema `Goal`: `agentId` ($ref agent, `onDelete: CASCADE`), `sessionId`, `statement`, `check` (`{kind: "objectCount", register, schema, filters, target: 0}` or `{kind: "judge", question}`), `intervalMinutes` (15 to 1440), `maxTurns` (1 to 50, default 10), `turnsUsed`, `status` (`active`, `reached`, `stopped`, `exhausted`, `blocked`), `lastCheckResult`, `owner`.

A goal turn is dispatched by the same background job as schedules: `ScheduleService` loads due goals next to due schedules and sends each through `dispatch()`'s three gates with a goal-shaped occurrence. `runAgentViaEngine()` gains `?string $continueSessionUuid`; for a goal it continues the goal's session instead of creating one, so the agent sees its earlier turns. The turn's message is "Continue working on the goal: <statement>. Last check: <result>."

Rejected: a loop inside one run until the check passes. A run is one synchronous engine call; a long loop would hold a background job and could not be stopped between turns.

## D2. The check runs after every turn

- `objectCount`: count the objects matching the filters as the goal owner; reached when the count equals `target`. No model call.
- `judge`: `EvalScoringService` with the rubric "Answer yes only if the goal is reached: <question>" over the session's last answer; reached when it passes. The judge call counts toward the same budget.

The check result is stored on the goal and shown in the session. `reached` sends one delivery to the goal owner through the agent's schedule delivery settings or a notification. `maxTurns` reached without success sets `exhausted` and notifies. A gate skip sets `blocked` for that turn and retries at the next interval.

## D3. Setting and stopping a goal

In a session, the header menu gains "Set a goal". A modal (`src/modals/GoalFormModal.vue`) asks for the goal, the check kind with its fields, how often to continue and the turn limit. The session header then shows "Goal: <statement>, turn 3 of 10, last check: 4 left" and "Stop goal". Only the goal owner and the agent owner may stop it. One active goal per session.

## Declarative versus imperative

| behaviour | path | why |
|---|---|---|
| `Goal` and its status values | declarative, schema with `x-openregister-lifecycle` for `active` to `reached`, `stopped`, `exhausted` | ADR-031 lifecycle |
| goal removed with its agent | declarative, `onDelete: CASCADE` on `Goal.agentId` | referential integrity |
| due goal turns | imperative, `ScheduleService` | scheduled bulk work on the existing dispatcher |
| checks | imperative, `GoalCheckService` | a model call or an object query per turn |

## Seed data

One example goal, stopped, on the seeded starter agent "Permit reminder": statement "Every overdue permit application has had a reminder this week", check `objectCount` on the permits register with filters `{status: "overdue", reminderSentThisWeek: false}` and target 0, interval 60 minutes, max turns 10.

## Risks

- A goal that never ends and burns budget. Mitigation: `maxTurns` capped at 50, the budget gate on every turn, and the kill switch.
- A judge that says yes too early. Mitigation: `objectCount` is offered first and preferred in the form's help text; the judge's rationale is shown with each check.
- A session that grows long. Mitigation: the turn limit, and the existing history handling of the engine.
