---
kind: code
depends_on: []
---

# Proposal: scheduling-overview-and-start-choice

## Summary

A person sees every schedule they may see on one page, with its agent, next run and last result. On an agent's page they choose how the agent starts, by hand, on a schedule or on an event, in one place instead of three. And when they create a schedule they say in plain words when it runs, such as "every weekday at eight" or "elke maandag om negen uur", and hermiq shows the next three runs before they save.

## Why

Three rows of hermiq's capability matrix, scheduling area, decided in the OpenSpec pass of 2026-09-27.

| row | rating | decision |
|---|---|---|
| `hermiq:sc-list` | partial, built | build: two competitors rate yes for the missing half, one list of every schedule |
| `hermiq:sc-manual-event-choice` | partial, built | build: four competitors rate yes for the missing half, one per-agent choice |
| `hermiq:sc-nl` | no | build: four competitors rate yes |

Competitor cells rated yes, quoted from the matrix evidence:

- `sc-list`, Hermes Agent v2026.9.24: `web/src/pages/CronPage.tsx` with "cron.last 'Last', cron.next 'Next', overdueSince". Open WebUI v0.11.4: `src/routes/(app)/automations/+page.svelte:509` "list with Active/Paused filter, :100 last run time".
- `sc-manual-event-choice`, Copilot Studio: https://learn.microsoft.com/en-us/microsoft-copilot-studio/authoring-triggers-about "per agent: chat by hand, add event triggers or a Recurrence trigger". Dify 1.17.1: "a workflow chooses its start node(s): User Input, Schedule Trigger, Webhook Trigger or a plugin trigger". n8n 2.40.7: "each workflow picks its start: Manual Trigger, Schedule Trigger, Webhook or one of 103 app trigger nodes". Hermes Agent: `hermes_cli/subcommands/webhook.py:47-51` "a webhook route can fire an existing cron job".
- `sc-nl`, Nextcloud Assistant v4.0.0: `context_agent:ex_app/lib/all_tools/assignments.py:14-37` "the agent translates it into an RRULE and start time via create_scheduled_task". Hermes Agent: `cron/jobs.py:780-790` "natural phrases ('every monday 9am', 'weekdays at 9am') convert to cron". n8n: Instance AI "builds Schedule Trigger workflows from plain prompts". Open WebUI: `backend/open_webui/tools/builtin.py:3709` "the chat model turns 'every weekday at eight' into an RRULE".

## What hermiq already has

- `src/widgets/AgentRunOperationsWidget.vue:100-130` shows one schedule on the agent page: trigger, delivery, enabled and next run. No page lists schedules across agents (`src/manifest.json` has no index on `schema: schedule`).
- `src/modals/ScheduleFormModal.vue:300-310` offers kinds Once, Interval and Cron; cron is typed as a raw expression.
- Run now needs a schedule: `RunNowController::run(scheduleId)` (`lib/Controller/RunNowController.php:105-118`).
- Events: a flow's `hermiq.agent-step` node runs an agent (`lib/Flow/HermiqFlowNodeListener.php:73-76`, archived `flow-agent-listener`), and a per-agent webhook trigger (archived `agent-webhook-trigger`), managed inside the same widget.
- Cron parsing with `dragonmantank/cron-expression` (`lib/Service/ScheduleService.php:33,2063`).
- The open change `schedules-onto-engine-triggers` moves eligible schedules' clocks onto OpenRegister's flow engine.

## What this change builds

1. A Schedules page listing every schedule the user may see, with agent, when, next run, last result, enabled, and filters.
2. A "How this agent starts" section on the agent page with three choices side by side: by hand (Run now, with no schedule needed), on a schedule, on an event (a flow trigger or the webhook).
3. A "When should it run?" field on the schedule form that reads plain English and Dutch, falls back to the model, and shows the resulting rule and the next three runs.

## Out of scope

- An agent that creates its own schedules. The open `hermiq-mcp-adoption` refuses write tools on hermiq's schemas.
- Moving the clock to OpenRegister. That is `schedules-onto-engine-triggers`; the list reads schedule objects either way.
