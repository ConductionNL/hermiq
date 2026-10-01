# Design: agents-bound-to-their-app

Kind: code. Size L. Rows `hermiq:ag-app-slug`, `buildiq:ai-in-app-assistant`, `buildiq:ai-record-summary`.

## Context at development db6b74dc

- `Agent.applicationSlug` at `lib/Settings/hermiq_register.json:2453`; written only by `lib/Repair/SeedHydraTriageAgent.php` and `lib/Repair/BackfillAgentApplicationSlug.php`; the capability spec `openspec/specs/hermiq-agent-application-slug/spec.md` marks each requirement "Backend-only repair-step write path with no UI surface".
- Agent choice for the companion: `ChatStreamController::stream()` falls back to `pickFallbackAgentForUser(userId, applicationSlug: context.appId)` when neither a session nor an agent is given (`lib/Controller/ChatStreamController.php:258-262`), matching `applicationSlug` case-insensitively and otherwise returning the first accessible agent (`:680-700`). `ChatController::sendMessage()` (`lib/Controller/ChatController.php:266`) has no app match.
- Retrieval: `ContextRetrievalHandler` reads `ragSearchMode`, `ragNumSources`, `searchFiles`, `searchObjects` and `views` from the agent (`lib/Service/Engine/ContextRetrievalHandler.php:123-140`) and applies the view filters.
- The agent leaf: `RegisterAgentLeafListener` declares `render-surface` and `agent-runner` on OpenRegister's `RegisterLeafProvidersEvent` (ADR-066); `src/integration-leaf.js` mounts hermiq's own Vue app into the host element; the chat tab uses `POST /api/assistant/converse` (`appinfo/routes.php:550`), tool-free by the `case-assistant-surface` spec.
- Templates: `AgentTemplateService::importPackage()` (`lib/Service/AgentTemplateService.php:305-351`): `local` lands active, anything else quarantined and scanned; approval through `approveQuarantined()` (`:372`). Callers are only the Store import and the GitHub store (`lib/Controller/AgentTemplateController.php:369,591`).
- Cross-app offers: hydra ADR-066 allows a sibling app to contribute through a typed collect event for render surfaces and read or append data; ADR-041 keeps commands on typed events. hermiq already listens to such events (`lib/Listener/RegisterAgentLeafListener.php`, `lib/Listener/ShareableConfigTypeListener.php`) but dispatches none of its own apart from `AiOversightRecordedEvent`.

## D1. The app field on the agent form

`AgentFormModal.vue` gains "App" (`NcSelect`, `inputLabel` "App this agent serves"), listing installed Nextcloud apps plus OpenBuild applications by slug (OpenRegister's application list), empty meaning "hermiq only". It writes `applicationSlug`. The capability spec `hermiq-agent-application-slug` is modified so the backend-only wording no longer holds.

## D2. One assistant per app, chosen by an organisation admin

A new `Agent.appAssistant` boolean. On save, `AgentsController` refuses a second `appAssistant: true` agent for the same `applicationSlug` in the same organisation with HTTP 409 "Another agent already answers in this app". Only an organisation admin (`TenantControlController::mayAdminister()` rule) may set it; the owner sets the app. A settings page section "Assistant per app" lists apps with their chosen agent.

Picking, in one shared `AppAssistantResolver` used by both chat endpoints when the request names no agent: the `appAssistant` agent for `context.appId` the user may use, else the first accessible agent with that `applicationSlug`, else the existing first accessible agent. `ChatController::sendMessage()` gains the same fallback, which fixes the non-streaming path the companion uses when SSE is not available (hydra ADR-034 decision 6).

Rejected: an app-to-agent map in app config. An agent is an OpenRegister object with owner, organisation and audit; a map beside it would be a second source of truth.

## D3. The app's data first

When an agent has an `applicationSlug` that names an OpenBuild application, and its `views` are empty, `ContextRetrievalHandler` scopes object search to that application's registers, read from OpenRegister's application object. An agent with explicit `views` keeps them. The answer's sources show which register each came from. This is what buildiq's note asks for: answers grounded in the built app's register data.

## D4. A record summary on the agent leaf

The leaf widget gains a "Summary" section above the run list with the button "Write a summary". It calls a new `POST /api/assistant/summarise` with the object reference; the server loads the object as the caller (OpenRegister RBAC), asks the app's assistant agent (D2) through the same tool-free pipeline as `converse` with a fixed, administered prompt (an `AssistantPrompt` with `usageScope: record-summary`, from the open change `the-declared-tool-surface-and-the-prompt-library`), and returns the text. The summary is stored on a hermiq `RecordSummary` object keyed by object uuid and object version, so it shows again without a model call until the record changes. It is labelled "Written by AI on <date>. Check it before you rely on it." The button is hidden when the user cannot read the object or no agent answers for the app.

It runs as an `AiFeature` (`record-summary`, risk `limited`), so an organisation can switch it off in the AI feature register.

## D5. An app offers its own agent template

hermiq dispatches `OCA\Hermiq\Event\CollectAgentTemplatesEvent` from a repair step on install and upgrade, and from the Store's "Check apps for templates" action. A listener in the offering app calls `$event->offer(appId, package)` with a package in the existing `AgentTemplateSerializer` format. hermiq imports each through `importPackage()` with `source: 'app:<appId>'`, so it lands quarantined and scanned, with the reason "Offered by the app <appId>. Review before use." The template records `offeredBy` and, when instantiated, the agent gets `applicationSlug: <appId>`. A package that is unchanged since the last collect is skipped by a content hash, so re-running the step creates no duplicate. This is a collect event carrying data, not a command, which ADR-066 allows.

Answer for shillinq's task 1.1: today no app can offer a template; after this change the listener route in shillinq's design D2 is the one that works.

## As built (part 1, 1 Oct 2026)

The change ships in three PRs: part 1 is tasks 2 to 4 with the schema half they need; part 2 the record summary (task 5, with RecordSummary and the feature and prompt seed); part 3 the app template offer (task 6, with AgentTemplate.offeredBy). Where part 1 differs from D1 to D3:

- D2, the flag. The agent form saves through OpenRegister's object API, not through `AgentsController`, so a save guard there would not run. The choice therefore has its own endpoint, `POST /api/agents/{id}/app-assistant` (`AgentAppAssistantController`, `AppAssistantService`): 404 when the caller cannot read the agent, 403 when they do not administer its organisation, 422 when the agent serves no app, 409 "Another agent already answers in this app". The stored property is `appAssistantFor` (string, the app it was chosen for) instead of a boolean, and it holds only while it equals `applicationSlug`: an owner who moves the agent to another app ends the choice instead of carrying it there past the uniqueness rule. The property carries an `authorization.update` rule for the `admin` group, so through the object API only an instance admin can change it. The form shows the switch on an existing agent with an app and calls the endpoint. The "Assistant per app" settings list is not built in part 1.
- D2, picking. `AppAssistantResolver` pages through every agent (no 20-row cap) and skips agents the user may not use or that are switched off. `ChatController::sendMessage()` uses it when a fresh chat names no agent.
- D3, the app's registers. OpenRegister's Application record has no slug, and a built app keeps its data under buildiq's version records. What every app does have is the `application` field OpenRegister sets on each register it imports to the app id, so an agent tied to an app with no views of its own searches the registers whose `application` is that app, read with the caller's RBAC and organisation (`AppRegisterScope`). Hits from all of them are merged by score, and each source names its register ("Source: ... (register: ...)"). The App field offers the instance's enabled apps and accepts a typed slug for a built app.

## As built (part 2, 1 Oct 2026)

Task 5, the record summary. Where it differs from D4:

- The endpoints live on a controller of their own, `RecordSummaryController`, not on `AssistantController`: `GET /api/assistant/summary` answers what the leaf may show (`{enabled, agent, summary}`) without a model call, `POST /api/assistant/summarise` returns the summary. Both read the record as the caller first, so an unreadable or missing record is 404 before any agent, feature or model is consulted.
- The app is the app that imported the record's register (OpenRegister's `application` on the register, `AppRegisterScope::appOf()`), not a parameter the page sends, so a page cannot ask another app's assistant to read a record.
- The summary is kept per record content: `RecordSummary.contentHash` is the sha256 of the record's key-sorted data, and `objectVersion` records OpenRegister's version beside it. A matching hash shows the stored summary with no model call; a different one writes a new summary over the stored one (one summary per record per organisation).
- The `RecordSummary` schema lets only an admin read or write it through the object API, because a summary repeats what its record says; the service writes and reads it with `_rbac: false` after the record check.
- Tool-free by construction: the call passes the `__none__` sentinel as the selected tools, which the tool loop intersects with any agent's grants to nothing, so the app's assistant writes the summary without its tools.
- The `record-summary` AI feature is seeded limited risk and **disabled**, like every other seeded feature: a DPO enables it in the AI feature register. While it is disabled the leaf shows no summary section and the endpoint answers 403. Seeding it enabled would skip the DPO acknowledgement every other feature passes.
- The prompt is the library prompt whose `usageScope` is exactly `record-summary`; an unscoped prompt (offered everywhere) does not count. Without one the design's text is used, and `SeedRecordSummary` ships that text as the "Record summary" prompt.
- The organisation's guardrail input and output filters run on the record text and the reply, as on `converse`.

## Declarative versus imperative

| behaviour | path | why |
|---|---|---|
| `appAssistant`, `RecordSummary` and `offeredBy` | declarative, schema properties | plain data |
| one assistant per app per organisation | imperative, a save guard in `AgentsController` | a uniqueness rule across objects, not expressible as a property rule |
| summary regeneration when the record changes | declarative comparison of stored object version | no job; checked on read |
| collecting templates from apps | imperative, a typed collect event | ADR-066 cross-app contribution |

## Seed data

- The seeded "Hydra Triage" agent gets `appAssistant: true` for `hydra-console`.
- An example `RecordSummary` for a seeded object is not seeded; summaries are produced on request.
- An `AssistantPrompt` "Record summary" with `usageScope: record-summary` and the text "Summarise this record in at most five sentences for a colleague who has not seen it. Name its status, the last change and anything that needs action."

## Risks

- A summary that states something wrong about a record. Mitigation: the label, the AI feature switch, and the summary is text beside the record, never written into it.
- An app offering a hostile system prompt. Mitigation: the existing quarantine and scan, and admin approval before any agent is created from it.
- Scoping retrieval to the wrong registers when an application slug is reused. Mitigation: registers are read from OpenRegister's application object for that slug in the caller's organisation only.
