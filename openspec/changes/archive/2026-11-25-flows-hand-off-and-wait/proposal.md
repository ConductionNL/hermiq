---
kind: code
depends_on: [tools-custom-tools-without-code]
---

# Proposal: flows-hand-off-and-wait

## Summary

An agent can start a long-running flow, an n8n workflow, or a task for an agent on another server, and carry on. The tool returns at once with "started", and when the work finishes, its result comes back into the same conversation and the agent answers on it, even hours later. Remote agents are reached over the A2A protocol through integriq, never by a call hermiq makes itself, and asking one is a tool like any other: granted per agent, default-denied, with its reach and the approval gate applied.

## Why

Three rows, decided in the OpenSpec pass of 2026-09-27.

| row | own rating | decision |
|---|---|---|
| `hermiq:dm-async-flow-step` | partial, built | build: changelog demand and two competitors rate yes for the missing half, an agent that calls a flow and receives its late result |
| `hermiq:dm-remote-agent` | no | build: a featureRequest demand row and three competitors rate yes |
| `hermiq:fl-n8n` | no | build: two competitors rate yes; the agent side, calling an n8n workflow as a tool, is hermiq's |

Demand and competitor cells rated yes, quoted from the pack:

- `dm-async-flow-step`: changelog https://learn.microsoft.com/en-us/microsoft-copilot-studio/whats-new.
  - copilot-studio, docs-only, https://learn.microsoft.com/en-us/microsoft-copilot-studio/flow-asynchronous-response: "Asynchronous flows continue running beyond the previous two-minute limit while still returning a response to the agent" (new infrastructure only).
  - n8n@2.40.7: "packages/cli/src/modules/agents/agent-workflow-tool-resume.service.ts:24-48 wakes the agent tool call when the workflow it started leaves 'waiting' (timer, webhook, form, auto-resume)".
- `dm-remote-agent`: featureRequest https://github.com/NousResearch/hermes-agent/issues/689.
  - hermes-agent v2026.9.24: "plugins/platforms/a2a/plugin.yaml:6 bundled A2A plugin, outbound a2a_discover/a2a_call/a2a_orchestrate; plugins/platforms/a2a/tools.py:173 a2a_call sends a task to a configured peer or URL and returns the reply".
  - copilot-studio, docs-only, https://learn.microsoft.com/en-us/microsoft-copilot-studio/add-agent-agent-to-agent: "connect an agent on another server over the A2A protocol (GA April 2026) or a Foundry agent; the main agent sends tasks and uses the answer".
  - n8n@2.40.7: "the instance MCP server exposes 'call_agent' under the agent:execute scope (packages/cli/src/modules/mcp/mcp-scopes.ts:73), and an agent on another n8n calls it through the MCP Client Tool node".
- `fl-n8n`:
  - hermes-agent v2026.9.24: "optional-mcps/n8n-official/manifest.yaml:1-12 Nous-approved catalog entry connects the agent to an n8n instance's official MCP server with OAuth, so the agent can call n8n workflows as tools".
  - n8n@2.40.7: "an AI Agent calls another workflow through the Call n8n Workflow Tool (packages/@n8n/nodes-langchain/nodes/tools/ToolWorkflow), and Agents-module agents attach published workflows as tools".

## What hermiq already has

- The async pattern, inside flows only: `hermiq.workload-step` with `async: true` starts a stage, the flow suspends on `openregister.wait`, and `hermiq.workload-collect` reads the outcome on a later pass (`lib/Flow/HermiqWorkloadCollectNode.php:3-40`, `lib/Service/AsyncStageDispatchService.php:86` `dispatchAsync()`, `:164` `collect()`), registered by `lib/Flow/HermiqFlowNodeListener.php:73-76`. The late result flows into the next node, never back to an agent.
- An agent can queue a flow through OpenRegister's `openregister.runFlow` tool; hermiq refuses it without a resolvable owner and passes the owner in (`lib/Service/Engine/FacadeToolInvoker.php:98-104`, `:258`, `:273`). The tool queues; nothing brings the flow's result back.
- Delegation reaches only agents in the same organisation on this instance (`lib/Service/DelegationService.php:3-16`, cross-organisation refusal at `:235`). `OutsideAgentController` is inbound only (`lib/Controller/OutsideAgentController.php:3-10`, routes `appinfo/routes.php:646-647`).
- Integriq is reached through its `CallService`, looked up by canonical app name so both namespaces work (`lib/Service/SkillMarketplaceService.php:458-468`, `lib/Support/FleetAppId.php:85`, `:108`). No agent tool uses it yet.
- n8n appears only as an example caller of hermiq's inbound agent webhook (`src/widgets/AgentRunOperationsWidget.vue:221`).
- Governed background runs go through `ScheduleService::runAgentAsOwner()` (`lib/Service/ScheduleService.php:2199`), queued from a job such as `lib/BackgroundJob/AgentRunRequestedJob.php`.

## What this change builds

1. A `Handoff`: the record of work an agent started and is waiting for, with its conversation, its kind, a deadline and a status.
2. Late results back into the conversation: when a handoff finishes, hermiq runs a follow-up turn of the same agent in the same conversation with the result as untrusted input, through every gate a turn passes today, and notifies the user.
3. Flows: `openregister.runFlow` called by an agent opens a handoff, and OpenRegister's flow-run terminal event closes it with the flow's output.
4. n8n and other webhook workflows: a custom tool (from `tools-custom-tools-without-code`) aimed at an n8n workflow through integriq can wait for a callback; hermiq hands the workflow a one-time result address.
5. Remote agents: an admin registers an agent on another server as a `RemoteAgent` backed by an integriq source; the tool `hermiq.askRemoteAgent` sends it a task over A2A through integriq and waits for the answer as a handoff.
6. The chat shows what an agent is waiting for, and says so when a handoff runs out of time.

## Out of scope

- An A2A server in hermiq that lets remote agents call hermiq's agents. The inbound surface is `OutsideAgentController`.
- A2A in integriq's gateway (integriq's row `acc-a2a-gateway`, deferred in its matrix).
- Direct HTTP calls from a tool to n8n or to a remote agent. Every outbound call goes through integriq (`nc-native-tools`, "Remote systems route through OpenConnector").
- Building n8n workflows. Heavy visual workflows are n8n's (hermiq ADR-001).
