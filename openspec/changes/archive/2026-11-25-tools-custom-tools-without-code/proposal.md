---
kind: code
---

# Proposal: tools-custom-tools-without-code

## Summary

An organisation admin can add a tool of the organisation's own without writing code: a name, a description the model reads, the inputs it takes, and what it calls, either an OpenRegister flow or an endpoint of a system connected through integriq. Hermiq offers it to agents as a named tool next to the built-in ones. Like every tool that can act, it is off until an agent owner grants it to an agent, and it goes through the same approval gate, reach and audit as the rest.

## Why

One row, decided in the OpenSpec pass of 2026-09-27.

| row | own rating | decision |
|---|---|---|
| `hermiq:tl-custom-tool` | no | build: six competitors rate yes; the generic `openregister.runFlow` tool exists, but not a named tool of my own |

Competitor cells rated yes, quoted from the pack:

- nextcloud-assistant (assistant v4.0.0, context_agent v2.8.0): "admins add any MCP server in the 'MCP Config' setting without code changes (context_agent:ex_app/lib/main.py:103-110, context_agent:ex_app/lib/all_tools/mcp.py:14-30)".
- hermes-agent v2026.9.24: "hermes_cli/plugins.py:456 register_tool in the plugin context; hermes_cli/subcommands/plugins.py:18 `hermes plugins install` from a git URL".
- copilot-studio, docs-only, https://learn.microsoft.com/en-us/microsoft-copilot-studio/agent-extend-action-rest-api: "add a REST API (OpenAPI), custom connector, MCP server, agent flow or prompt as a tool from the maker UI without app code changes".
- dify 1.17.1: "api/controllers/console/workspace/tool_providers.py:685 add a custom tool from an OpenAPI schema (web/i18n/en-US/tools.json createTool.schema), :904 publish a workflow as a tool".
- n8n@2.40.7: "packages/@n8n/nodes-langchain/nodes/tools/ToolCode (custom code tool), ToolHttpRequest (any API) and ToolWorkflow (a workflow as a tool) are added on the canvas without code changes".
- open-webui v0.11.4: "backend/open_webui/routers/tools.py:346 POST /tools/create with Python code ...; OpenAPI and MCP tool servers added in settings (src/lib/components/AddToolServerModal.svelte:43)".

## What hermiq already has

- One `IMcpToolProvider`, `HermiqToolProvider`, whose catalogue is compiled into the app: a constant descriptor table merged with two more constant tables (`lib/Mcp/HermiqToolProvider.php:648-657`). Adding a tool means shipping PHP.
- `openregister.runFlow`, the generic tool that queues any OpenRegister flow, with hermiq's owner rule applied (`lib/Service/Engine/FacadeToolInvoker.php:258`, `:273`, `:680` `withFlowOwner()`). An agent granted it sees one tool for every flow, with a `flowId` argument, not a named tool that says what it does. A grant can already pin it to one flow and waive approval for that flow (`:1017-1041` `isWaived()`).
- Integriq's `CallService`, looked up by canonical name so both namespaces work (`lib/Service/SkillMarketplaceService.php:458-470`, `lib/Support/FleetAppId.php:85`, `:108`); no agent tool uses it.
- The grant grammar lives in OpenRegister's `ToolGrantResolver`: an exact id grants a tool, and a three-segment `{app}.{schema}.*` grants that schema's read verbs (`lib/Settings/hermiq_register.json:2590-2597`, `Agent.tools` description).
- The "MCP tools" page lists every tool an agent can be given, read-only (`src/views/McpTools.vue:4-16`, route `/mcp-tools` in `src/manifest.json`).

## What this change builds

1. A `CustomTool` object: slug, name, description for the model, input schema, what it changes, and a target (a flow, or an integriq source with a method, a path and a body template).
2. Hermiq exposes each enabled custom tool of the caller's organisation as `hermiq.custom_<slug>` through `HermiqToolProvider`, with hints and a reach derived from its target and from what the admin declared.
3. Invocation: arguments validated against the input schema; a flow target runs through `openregister.runFlow` for that one flow; an integriq target runs through integriq's `CallService`; the answer comes back capped and delimited as untrusted.
4. Default-deny: a custom tool is granted only by its exact id, never by a wildcard, and it passes the approval gate and the guardrail policy like any tool.
5. A "Custom tools" page and an editor with a "Try it" run, linked from the MCP tools page.

## Out of scope

- An MCP client in hermiq, or adding an outside MCP server as a tool. Hermiq ADR-001 rules out an MCP client of its own; outside MCP servers belong in OpenRegister's tool registry if anywhere.
- Custom code as a tool. `tools-code-sandbox` runs code; a custom tool calls a flow or an endpoint.
- Waiting for a late answer from a workflow. `flows-hand-off-and-wait` adds that to custom tools.
