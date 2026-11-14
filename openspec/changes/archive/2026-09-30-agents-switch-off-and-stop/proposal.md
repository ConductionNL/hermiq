---
kind: code
depends_on: []
---

# Proposal: agents-switch-off-and-stop

## Summary

An agent owner or an organisation admin can switch one agent off without deleting it, and switch it on again later. Switching off stops the agent everywhere at once: no new run starts from chat, a schedule, a webhook, a flow or a delegation, and a run already in progress stops at its next tool call. Deleting an agent also removes its schedules, so nothing keeps firing for an agent that is gone. An agent owner sets how many tool calls one turn may make, so a looping agent stops by itself.

## Why

Four rows of hermiq's capability matrix, decided in the OpenSpec pass of 2026-09-27.

| row | rating | decision |
|---|---|---|
| `hermiq:ag-enable` | no | build: core area (agents) |
| `hermiq:ov-kill-agent` | no | build: four competitors rate yes |
| `hermiq:ag-delete` | partial, built | build: four competitors rate yes for the missing half, schedules that outlive their agent |
| `hermiq:dm-tool-call-cap` | partial, built | build: a featureRequest demand row and three competitors rate yes |

Demand, quoted from the matrix:

- `dm-tool-call-cap`: featureRequest https://community.n8n.io/t/feat-ai-tools-add-max-tool-interactions-to-ai-agent-nodes-to-prevent-infinite-loops/295587

Competitor cells rated yes, quoted from the matrix evidence:

- `ag-enable`, Hermes Agent v2026.9.24: `hermes_cli/subcommands/pause.py:1-27` "`hermes pause` / `hermes resume`" hold one profile's cron and new turns without deleting it. n8n 2.40.7: `packages/cli/src/workflows/workflows.controller.ts:475` "POST /:workflowId/deactivate turns off an agent workflow's triggers without deleting it". Open WebUI v0.11.4: `backend/open_webui/models/models.py:122` "is_active soft-disable", `routers/models.py:881` "POST /model/toggle".
- `ov-kill-agent`, Copilot Studio: https://learn.microsoft.com/en-us/microsoft-copilot-studio/admin-api-quarantine "admins quarantine one agent through the Power Platform API; it immediately stops working in every channel". Hermes Agent: `gateway/slash_commands.py:425-437` "/stop interrupts a running agent". n8n: `packages/cli/src/executions/executions.controller.ts:102-110` "POST /:id/stop halts a running execution". Open WebUI: `routers/models.py:881` toggle and `main.py:2160` "POST /api/tasks/chat/{chat_id}/stop".
- `ag-delete`, Copilot Studio: https://learn.microsoft.com/en-us/microsoft-copilot-studio/authoring-first-bot "all data associated with the agent is deleted permanently" (triggers are part of the agent). Dify 1.17.1: `api/tasks/remove_app_and_related_data_task.py:90,632,681` "deletes the app's triggers and WorkflowSchedulePlan rows so schedules stop". n8n: `packages/cli/src/modules/agents/agents.service.ts:386-432` delete "calls AgentTaskService.requestReconcile to stop its schedules". Hermes Agent: `hermes_cli/profiles.py:1682` "delete_profile removes the profile home (jobs live under it)".
- `dm-tool-call-cap`, Dify 1.17.1: `api/core/app/app_config/easy_ui_based_app/agent/manager.py:82` "max_iteration per agent (default 10)". Hermes Agent: `hermes_cli/config_defaults.py:53-56` "agent.max_turns caps iterations per run". Open WebUI: `backend/open_webui/env.py:1084-1096` "CHAT_RESPONSE_MAX_TOOL_CALL_ITERATIONS (default 256)".

## What hermiq already has

- `Agent.active` (`lib/Settings/hermiq_register.json:2504`, boolean, default true). Nothing sets it from a screen and no run path reads it: `grep "'active'"` over `lib/Service/ScheduleService.php`, `lib/Service/Engine/` and the chat controllers finds no check.
- The organisation kill switch: `TenantControlController` (`lib/Controller/TenantControlController.php:127` toggle, `:173` mayAdminister: instance admin or the organisation's owner), read by `ScheduleService::dispatch()` as gate 1 (`lib/Service/ScheduleService.php:962`) and reused by flows, delegation, webhooks and evals through `ScheduleService::isOrganisationEngaged()`.
- Delete: `AgentsController::destroy()` (`lib/Controller/AgentsController.php:485`), owner-only, deletes the Agent object. `Schedule.agentId` is a `$ref: "agent"` (`lib/Settings/hermiq_register.json:84-91`) with no `onDelete`, and the only `ObjectDeletedEvent` listener (`lib/AppInfo/Application.php:147`) removes the Talk bot.
- A tool loop cap only on the Anthropic HTTP path: `ProviderFactory::MAX_TOOL_ITERATIONS = 10` (`lib/Service/Llm/ProviderFactory.php:219`, loop at `:1009`). Other providers run the tool loop inside LLPhant (`lib/Service/Engine/ResponseGenerationHandler.php:475` `setTools`) with every call passing through `FacadeToolInvoker::__call()` (`lib/Service/Engine/FacadeToolInvoker.php:448`), built once per turn in `ToolLoop` (`lib/Service/Engine/ToolLoop.php:476`).

## What this change builds

1. A switch on the agent page: "Switch off" and "Switch on", with a reason, for the agent owner and for an organisation admin. It writes `active` plus who switched it and why.
2. One check in the engine: a switched-off agent does not start a turn, on any path, and the refusal is recorded like a kill-switch skip.
3. An in-flight run of a switched-off agent stops at its next tool call.
4. Deleting an agent deletes its schedules, declared as `onDelete: CASCADE` on `Schedule.agentId`.
5. `Agent.maxToolCalls`, set by the owner, enforced on every provider path, with the stop visible in the run trace.

## Out of scope

- The organisation-wide kill switch. It exists (`TenantControl`) and is unchanged.
- Quarantine of an agent by an outside policy engine. An admin switches an agent off by hand.
- Per-agent budgets. `Budget` with `scope: agent` exists (archived `cost-guardrails`); who may set it is row `ag-quota`, deferred.
