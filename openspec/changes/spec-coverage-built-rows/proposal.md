---
kind: spec
depends_on: []
---

# Write down fifteen built capabilities that no requirement says yet

## Why

The coverage matrix records, per row, the requirement that says it. For fifteen rows the
spec the row cites has requirements, but none of their scenarios says what the row says.
The capability works, so the gap is in the spec, not in the code.

| row | what a person or system does | where it runs today |
|---|---|---|
| `ag-capability-profile` | See on one page which skills, tools and model an agent may use. | `AgentDetail` page: `agent-skills`, `agent-tool-governance` and `agent-core` widgets |
| `dm-temperature` | Set the temperature an agent's model answers with. | `AgentFormModal.vue` Temperature field, `ResponseGenerationHandler` passes it to the provider |
| `sc-interval` | Run an agent every so many minutes. | `ScheduleFormModal.vue` kind `interval`, `Schedule.intervalMinutes` |
| `sc-once` | Run an agent once at a set date and time. | `ScheduleFormModal.vue` kind `once`, `Schedule.runAt` |
| `sc-timezone` | Keep a schedule on local time across daylight saving changes. | `ScheduleService::computeNextRun()` with the owner's time zone |
| `tl-files` | Let an agent read, create and move files in Files. | `hermiq.listFiles`, `hermiq.readFile` |
| `tl-calendar` | Let an agent read the calendar and create events. | `hermiq.listCalendarEvents`, `hermiq.createCalendarEvent` |
| `tl-catalog` | See every tool an agent could use, with what each one does. | `/mcp-tools` page over `GET /api/agents/tools` |
| `dm-app-tools` | Let other installed apps add their own tools. | `ToolRegistryFacade` reads every app's `IMcpToolProvider` |
| `me-consolidate` | Let an agent tidy its memory. | Memory page Consolidate button, `POST /api/agents/{agentId}/memory/consolidate` |
| `me-compression` | Keep a long conversation going by summarising older turns. | `ConversationManagementHandler::checkAndSummarize()` |
| `sk-export` | Export a skill to a file. | Skill row action Export, `GET /api/skills/{id}/export` |
| `dl-target-pref` | Choose where my agents deliver by default. | Talk delivery setting, `/api/preferences/delivertarget` |
| `op-health` | See whether chat and its model provider are ready. | `GET /api/chat/health` |
| `op-setup-wizard` | Get guided through setting up the first provider and agent. | Manifest `setup` steps and the `hermiq:getting-started` walkthrough |

## What changes

- Eight main specs gain a requirement each, two gain two: `agent-management-ui`,
  `agent-schedule`, `nc-native-tools`, `agent-tool-governance`, `agent-memory`,
  `agent-engine-port`, `skills-catalog`, `talk-delivery`, `observability` and
  `first-time-setup`. Ids REQ-SPCOV-001 to REQ-SPCOV-013.
- `openspec/parity/capabilities.json` records `built.requirement` for each row.

## Non-goals

- No code change. The requirements write down what runs.
- `tl-files` asks for creating and moving files. Only listing and reading are built. The
  requirement says the whole row, and task 1.2 builds the missing part.
