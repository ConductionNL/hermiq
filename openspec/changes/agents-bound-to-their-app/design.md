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
