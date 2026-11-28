# Design: flows-hand-off-and-wait

Kind: code. Size L. Two new schemas (`Handoff`, `RemoteAgent`), one listener, one callback route, one tool, one background job, and the chat's waiting state.

## Context at development db6b74dc

- `lib/Flow/HermiqWorkloadCollectNode.php:3-40` the dispatch, suspend, collect pattern and its terminal answers (`done`, `failed`, `unknown`, only `running` loops); node id `hermiq.workload-collect` at `:92`. `lib/Flow/HermiqWorkloadNode.php:117` `hermiq.workload-step`. `lib/Service/AsyncStageDispatchService.php:86` `dispatchAsync()`, `:164` `collect()`.
- `lib/Flow/HermiqFlowNodeListener.php:73-76` the four contributed nodes.
- `lib/Service/Engine/FacadeToolInvoker.php`: owner attribution for flow-queueing tools `:98-104`, `FLOW_QUEUEING_TOOL_IDS` `:258`, `FLOW_OWNER_ARGUMENT` `:273`, `withFlowOwner()` `:680`, the single `__call()` chain `:448`, `dispatchToFacade()` `:1213`.
- `lib/Service/DelegationService.php:203` `delegate()`, the ordered refusal checks `:3-16`, cross-organisation refusal `:235`.
- `lib/Mcp/HermiqToolProvider.php:513-553` the `hermiq.delegateAgent` descriptor (reach `instance`, `destructiveHint: true`, `scope: create`), `:675` `invokeTool()`.
- `lib/Service/SkillMarketplaceService.php:458-470` and `lib/Support/FleetAppId.php:85`, `:108`: integriq's `CallService` looked up by canonical name. Integriq at `413357ec`: `CallService::call()` `lib/Service/CallService.php:2957`, `callAsync()` `:3099`.
- `lib/AppInfo/Application.php:156-161` and `:265-266`: OpenRegister events registered only when their class exists, or by string FQN (`TaskTerminalListener`).
- `lib/Service/ScheduleService.php:2199` `runAgentAsOwner()`; `lib/BackgroundJob/AgentRunRequestedJob.php` a queued job that performs one governed run.
- `lib/Controller/OutsideAgentController.php` inbound only.

## D1. One record for everything an agent waits on

`Handoff` is an OpenRegister object in the `hermiq` register: `kind` (`flow`, `webhook`, `a2a`), `agentId`, `conversationId`, `requestedBy` (the owning user), `label` (what the user sees, for example "Vergunningcheck"), `target` (flow id, custom tool slug, or remote agent id), `externalRef` (flow run id, A2A task id), `status` (`waiting`, `done`, `failed`, `expired`), `result`, `requestedAt`, `deadline`, `closedAt`. The terminal answers follow `HermiqWorkloadCollectNode`: `done` carries a result even when the work reports an error inside it, `failed` means the work could not be carried out, `expired` means the deadline passed. Only `waiting` is not terminal.

The deadline defaults to 24 hours and is capped at 7 days, set per tool call from the tool's configuration.

Rejected: keeping the turn open until the work ends. A PHP request and a model tool round cannot hold for minutes, and `FlowRunWorker` runs serially, the reason `HermiqWorkloadCollectNode` exists.

## D2. The result comes back as a new turn

When a handoff closes, a queued job (the `AgentRunRequestedJob` pattern) runs one turn of the same agent in the same conversation, as the handoff's `requestedBy`, through the normal governed path, so the kill switch, the budget, the model policy and the tool grants apply as for any turn. The result enters the turn delimited as untrusted external content, the same way `hermiq.webFetch` returns a page, and the guardrail input filters apply to it.

The turn starts with a system note that names the work: "Result of 'Vergunningcheck', started 14:02: …". The user gets a Nextcloud notification "Your assistant has the result of 'Vergunningcheck'." with a link to the conversation. When the agent was switched off or deleted in the meantime, the result is kept on the handoff and no turn runs.

## D3. Flows

When `openregister.runFlow` is called from an agent turn, hermiq opens a `flow` handoff with the queued run id from the tool's result, and tells the model "The flow is running. Its result will arrive in this conversation." The existing owner rule stays: no resolvable owner, no run.

The handoff closes on OpenRegister's flow-run terminal event, registered by string FQN like `TaskTerminalListener`, so hermiq still boots when OpenRegister has none. The flow's final items become the result. When the deployed OpenRegister has no such event, hermiq does not open a handoff: the tool result says "This OpenRegister cannot report when the flow ends, so its result will not come back here." and the flow still runs.

Rejected: polling OpenRegister's flow run store. It is an OpenRegister internal, and reading it is the cross-app reach-in hydra ADR-022 forbids. Rejected: a reply node the flow author must add. Most flows an agent starts were not written for it.

## D4. n8n and other webhook workflows

An n8n workflow is a custom tool from `tools-custom-tools-without-code` whose target is an integriq endpoint pointing at the workflow's webhook, on an n8n reached as an integriq source (the n8n ExApp on this instance or an n8n elsewhere). This change adds `waitForCallback` to such a tool. When it is on, hermiq opens a `webhook` handoff and adds `hermiqResultUrl` and `hermiqResultToken` to the payload it sends through integriq. The workflow answers its webhook at once and, at its end, posts its result to that address.

The result route `POST /api/handoffs/{id}/result` is `#[PublicPage]` and `#[NoCSRFRequired]` and accepts only the handoff's token: 32 random bytes, stored as a hash, single use, dead when the handoff closes. A wrong token answers 404 so the route leaks nothing. The body is capped at 1 MB.

The custom tool editor offers an "n8n workflow" preset that fills the webhook path and shows what to add to the workflow: "Respond to Webhook" right after the trigger, and an "HTTP Request" node at the end that posts the result to `{{ $json.hermiqResultUrl }}` with the token.

Rejected: calling n8n's own API from hermiq. That is an outside call from a tool, which `nc-native-tools` routes through integriq.

## D5. Remote agents over A2A, carried by integriq

An admin registers a `RemoteAgent`: `name`, `description`, `source` (the integriq source that holds the remote server's address and authentication), `agentCard` (the card fetched once at registration, kept as a snapshot and shown to the admin), `enabled`. Hermiq never stores the remote server's credential; integriq's source does, through the credential broker.

`hermiq.askRemoteAgent` takes `remoteAgent` (slug) and `task` (text). Hermiq builds the A2A JSON-RPC `message/send` body and sends it with integriq's `CallService::call()` on the agent's source. When the answer is a completed task, the tool returns its text. When it is a task still `working`, hermiq opens an `a2a` handoff with the task id, and a background job asks `tasks/get` through the same source every minute until the task ends or the deadline passes.

The descriptor: `reach: external` (the task text leaves the instance), `destructiveHint: true`, `scope: create`, `idempotentHint: false`. So the tool is default-denied, shows as external in the grant editor, and goes through the approval gate when the policy classifies it `confirm`. A grant can name one remote agent through the structured grant grammar (`hermiq.askRemoteAgent?remoteAgent=kvk-assistent`), which `FacadeToolInvoker` already enforces for argument constraints.

Rejected: an A2A adapter in integriq. The protocol's task semantics map onto hermiq's handoff and delegation, which are hermiq's; integriq carries the call. Rejected: a direct HTTP client in hermiq. `nc-native-tools` forbids it.

## D6. What the user sees

The chat shows an open handoff under the message that started it: "Waiting for 'Vergunningcheck' (started 14:02)". It changes to "Result received" or "No result within 24 hours" when the handoff closes. An expired handoff also posts a short system note in the conversation, so the user is not left waiting.

## Declarative versus imperative

`Handoff` and `RemoteAgent` are declared in the register. The deadline expiry could be an `x-openregister` lifecycle rule if OpenRegister offers a timed transition; until then the polling job closes expired handoffs. Opening, closing and delivering are run-time orchestration on the engine path and stay in PHP.

## Seed data

- `RemoteAgent` "KvK-assistent" for "Gemeente Voorbeeld": `source` an integriq source "kvk-a2a" with base URL `https://agents.example-kvk.nl/a2a` and credential `YOUR_API_KEY_HERE` in the broker, `agentCard` with one skill "Bedrijfsgegevens opzoeken op KvK-nummer", `enabled: false` until an admin turns it on.
- `Handoff` example: `kind: "flow"`, `label: "Vergunningcheck"`, `status: "done"`, `result` `{"vergunningVereist": true, "grondslag": "APV artikel 2:10"}`.

## Risks

- A late result arrives in a conversation the user has moved on from. Mitigation: it is labelled with the work and the start time, and it comes with a notification.
- A forged callback. Mitigation: a hashed single-use token per handoff, 404 on any mismatch, a body cap.
- A remote agent returns instructions aimed at the local agent. Mitigation: the result is untrusted input behind the guardrail input filters, and every tool the follow-up turn calls is still gated by its own grant.
- OpenRegister has no flow-run terminal event. Mitigation: the tool says so and no handoff is opened; the flow runs as today.
