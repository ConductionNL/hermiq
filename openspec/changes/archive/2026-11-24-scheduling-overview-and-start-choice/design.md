# Design: scheduling-overview-and-start-choice

Kind: code. Size M. Rows `hermiq:sc-list`, `sc-manual-event-choice`, `sc-nl`.

## Context at development db6b74dc

- Schedule schema (`lib/Settings/hermiq_register.json:57`): `name`, `agentId`, `kind`, `cronExpr`, `intervalMinutes`, `runAt`, `prompt`, `deliver`, `enabled`, `nextRun`, `lastStatus`, `lastError`, `engineFlowId` and retry fields; `authorization.read: ["authenticated"]`.
- Agent page widget `agent-run-operations` (`src/widgets/AgentRunOperationsWidget.vue`) combines schedule, dry run, run now, budget and webhook (spec `manifest-driven-pages`).
- `RunNowController` (`lib/Controller/RunNowController.php:105`) loads an owned schedule and calls `ScheduleService::runNow()`; there is no manual run without a schedule.
- `ScheduleFormModal.vue:300-310` kind options; next-run computation in `ScheduleService` (`:2041-2070`) by kind and owner time zone.
- Flow triggers: `hermiq.agent-step` among the nodes hermiq registers (`lib/Flow/HermiqFlowNodeListener.php:73-76`); flows live in OpenRegister and are authored on the shared canvas (`/flows`).
- Text generation through the chokepoint: `ProviderFactory::generateText()`.

## D1. A Schedules page

A new manifest page `Schedules` at `/schedules`, `type: index`, reading `GET /api/schedules` (new, `ScheduleListController`), which returns schedules the caller owns or of agents the caller may use (`AgentAccessService`), and every schedule of the organisation for an organisation admin. Columns: Name, Agent, When (a readable sentence from the rule, D3), Next run, Last result (`lastStatus` with `lastError` on hover), Enabled. Filters: agent, enabled, last result failed. Row click opens the agent page at its schedule. The main navigation gains "Schedules" under "Agents".

Rejected: an index over OpenRegister's object API for `schedule`. Its read rule is `authenticated`, so it would list every schedule of the organisation to everyone, the same leak `agents-sharing-and-catalog-columns` records for agents.

## D2. One place to choose how an agent starts

The agent page's run operations widget becomes "How this agent starts" with three rows:

- By hand: "Run now" with a prompt box, always available to users who may use the agent. New `POST /api/agents/{id}/run` (a sibling of `agentRun#runOnObject` in `lib/Controller/AgentRunController.php:144`) runs through `runAgentAsOwner()` with the same gates as run now, as the calling user, and records `trigger: manual`.
- On a schedule: the agent's schedules (there can be several), "Add schedule".
- On an event: the flows whose `hermiq.agent-step` targets this agent, read from OpenRegister's flow objects, and the webhook trigger. "Add event" offers two starting points: "When an object is created or changed" creates a draft flow with a trigger node and this agent's step, opened on the canvas for the person to finish and activate; "When another system calls a webhook" opens the existing webhook panel.

Each row shows whether it is active. Nothing is removed; the three surfaces become one choice.

## D3. Plain words to a rule

`POST /api/schedules/parse-when` with `{text, locale}` returns `{kind, cronExpr | intervalMinutes | runAt, readback, nextRuns[3], source: "rules" | "model"}` or 422.

1. A deterministic parser covers the common phrases in English and Dutch: every N minutes or hours, every day or weekday at a time, every named weekday at a time, the first day of the month, once on a date and time, "tomorrow at", and their Dutch forms ("elke werkdag om acht uur", "iedere maandag om 9:00", "elk kwartier").
2. Otherwise the text goes to `ProviderFactory::generateText()` with an instruction to answer only with JSON of that shape. The answer is validated with `CronExpression` and the interval limits; invalid output is a 422 "I could not read that. Try for example: every weekday at 8:00."

The form shows the readback ("Every weekday at 08:00, Europe/Amsterdam") and the next three runs; the person saves the rule, never the words. The raw cron field stays under "Advanced".

## Declarative versus imperative

| behaviour | path | why |
|---|---|---|
| list filtering by access | imperative, `ScheduleListController` | per-object access rule outside the object |
| parsing plain words | imperative, `ScheduleWhenParser` | NLP, an ADR-031 exception |
| the page and widget | declarative manifest and one custom widget | a page definition |

## Seed data

None. The seeded example schedules show on the new page.

## Risks

- A misread phrase firing at the wrong time. Mitigation: the readback and the next three runs before saving; the rule, not the text, is stored.
- A manual run bypassing governance. Mitigation: `runAgentAsOwner()` with the kill switch, budget and the availability check of `agents-switch-off-and-stop`.
