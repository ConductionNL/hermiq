---
kind: code
depends_on: [agents-sharing-and-catalog-columns]
---

# Proposal: agents-bound-to-their-app

## Summary

An agent owner ties an agent to the app it serves, and an organisation admin picks which agent answers in each app's assistant. That agent answers from the app's own data, so a person in a built app asks about that app's records and not about everything in the instance. On a record's page the agent can write a short summary of the record, labelled as AI-written. An app can also offer its own agent template to hermiq, which an admin reviews before anyone uses it.

## Why

Three rows, decided in the OpenSpec pass of 2026-09-27. Two come from buildiq's capability matrix (`ConductionNL/buildiq` `openspec/parity/capabilities.json`), where `built.owner` is `ConductionNL/hermiq`.

| row | rating | decision |
|---|---|---|
| `hermiq:ag-app-slug` | no (built.state built, backend only) | build: core area (agents) |
| `buildiq:ai-in-app-assistant` | partial, built | build: three competitors rate yes for the missing half, an assistant grounded in the app's data and chosen per app |
| `buildiq:ai-record-summary` | no | build: a roadmap demand row and two competitors rate yes |

Demand, quoted from buildiq's matrix:

- `ai-record-summary`: roadmap https://learn.microsoft.com/en-us/power-platform/release-plan/2026wave1/power-apps/planned-features (Power Apps 2026 wave 1).

Competitor cells rated yes, quoted from the matrix evidence:

- `ag-app-slug`, Dify 1.17.1: `api/models/agent.py:407` "WorkflowAgentNodeBinding ties a roster agent to a workflow app node"; `roster.py:474` "published references listed on the agent's Access page".
- `ai-in-app-assistant`, NocoBase v2.2.18: `AIEmployeeShortcutModel.tsx:507` "AI employee shortcut placed on pages and blocks, opening the chatbox with block context; data answers come from the data-query skill". Mendix: https://docs.mendix.com/agents/agents-kit-2/reference-guide/conversational-ui/ "builds a chat interface for end users over any LLM and knowledge base, so it answers from the app's data". Power Apps: https://learn.microsoft.com/en-us/power-apps/maker/canvas-apps/microsoft-365-copilot-canvas-app "app users ask questions about the app's Dataverse or SharePoint data in plain language".
- `ai-record-summary`, Budibase v3.46.0: `packages/server/src/utilities/rowProcessor/utils.ts:171-215` "an AI column with operation SUMMARISE_TEXT ... shows on any record screen". Power Apps: https://learn.microsoft.com/en-us/power-apps/user/record-summaries "row summaries in model-driven apps show an AI-written summary of a row on its form".

This change also answers two questions other lanes raised: shillinq's `platform-help-agent` asks whether another app can offer an agent template (it cannot today, see below), and buildiq's note that the companion is "not scoped to or grounded in the built app's own register data".

## What hermiq already has

- `Agent.applicationSlug` (`lib/Settings/hermiq_register.json:2453`), set only by repair steps (`lib/Repair/SeedHydraTriageAgent.php`, `lib/Repair/BackfillAgentApplicationSlug.php`). The capability spec `hermiq-agent-application-slug` calls it a backend-only write path. `src/modals/AgentFormModal.vue` has no field for it.
- The chat stream picks the agent whose `applicationSlug` matches the companion's `context.appId` when no agent was chosen, else the first agent the user can use (`lib/Controller/ChatStreamController.php:258-262`, `pickFallbackAgentForUser()` at `:680-700`). The non-streaming `ChatController::sendMessage()` (`lib/Controller/ChatController.php:266`) does not.
- Retrieval scope on an agent: `views`, `ragIncludeObjects`, `searchObjects` (`lib/Settings/hermiq_register.json`, Agent), read by `ContextRetrievalHandler`.
- The agent leaf on any OpenRegister object page in any OpenBuild app: a chat tab over the tool-free `POST /api/assistant/converse` and a runs widget (`lib/Listener/RegisterAgentLeafListener.php`, `src/integration-leaf.js`, `src/components/CnAgentRunsWidget/CnAgentRunsWidget.vue`).
- Templates enter the catalog only by a person: `AgentTemplateService::importPackage()` (`lib/Service/AgentTemplateService.php:305`) is called from the Store's import and the GitHub store (`lib/Controller/AgentTemplateController.php:369,591`). A non-local source lands quarantined and content-scanned (`:341-342`). No app can offer a package.

## What this change builds

1. "App" on the agent form: the app an agent serves, from the installed apps.
2. "Assistant for this app": an organisation admin picks one agent per app; the companion in that app uses it, on both chat endpoints.
3. The app's data as the agent's default retrieval scope: an agent bound to a built app searches that app's registers first.
4. A record summary on the agent leaf: generate, see and refresh an AI-written summary of the object on screen, labelled as AI-written.
5. `CollectAgentTemplatesEvent`: an app offers template packages for itself; hermiq imports them quarantined, and an admin approves them in the Store.

## Out of scope

- Where buildiq places the summary on its detail pages. That is buildiq's page editor; hermiq provides the leaf widget.
- buildiq's own copilot, which plans and applies changes to an app (`CopilotService.php`, row `buildiq:ai-streaming-chat`, deferred).
- Tools that write the app's data. The assistant surface stays tool-free (`case-assistant-surface`); write tools remain per-agent grants.
