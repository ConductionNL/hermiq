# Design: agent-engine-schemas

## Context

Hermiq is a thin Nextcloud app that owns no database tables — all persistence goes through
OpenRegister's `ObjectService` against declarative schemas in `lib/Settings/hermiq_register.json`
(ADR-001). The 2026-07-05 ADR-001 amendment flips ownership of the agent engine from OpenRegister to
Hermiq; this change declares the durable object shape the ported engine (`agent-engine-port`) will
read/write and the data migration (`agent-data-migration`) will populate. Per ADR-032, the full
"agent-core-port" feature is a multi-repo, config+code migration and MUST be chained, not built as
one `mixed` spec — see proposal.md's chain diagram.

Ground truth for this change was re-verified against openregister HEAD (commit tree as of
2026-07-06) rather than trusted from the plan text, per the "sonnet fabricates" project note —
several details below are corrections to `SPECTR-NEXTCLOUD-PLAN.md` §7.3's table.

## Goals / Non-Goals

**Goals:**
- Declare `Agent`, `Conversation`, `Message`, `Feedback` schemas in the `hermiq` register, field-
  compatible with what `agent-data-migration` needs to copy from OR's QBMapper tables.
- Make `Conversation.agentId` / `Message.conversationId` native uuid string references from the
  start (not integer FKs), so the later data-migration step is a pure copy+ref-resolve, not a second
  schema change.
- Keep the change strictly config-only and union-import-safe (existing `Example`, `Schedule`,
  `Approval`, `Tenant control`, `Agent memory`, `User profile`, `Session`, `Session turn`, `Skill`,
  `Skill source` schemas untouched).

**Non-Goals:**
- Any PHP: no `Engine/*`, no `ProviderFactory`, no `ToolLoop`, no controllers, no routes. All of
  that is `agent-engine-port`.
- The data migration itself (repair step, uuid resolution, dropping OR's tables) — `agent-data-
  migration`.
- The OR-side tool-registry facade or the nextcloud-vue `chatAppId` flip — cross-repo, narrated in
  the Appendix below, materialized as their own changes in their own repos.

## Decisions

**Four schemas, not one.** Mirrors OR's own modeling (`Agent`, `Conversation`, `Message`,
`Feedback` are separate QBMapper entities today) rather than folding messages into a
`Conversation.messages` array. Rationale: (a) messages accrue unboundedly per conversation — an
array-valued property would make every conversation object grow without bound and be re-serialized
on every append, which is the same objection the `human-approval-gate-schema` design raised against
folding `Approval` into `Schedule`; (b) `Feedback` references a specific `Message`, so it needs its
own object identity to be queried/aggregated for `run-analytics` independent of the conversation.

**`Agent.tools` becomes the seed for ADR-035's future `toolWhitelist`.** OR's `Agent` entity already
has a `tools: array|null` field (`getTools()`/`setTools()`). Hydra ADR-035 Decision 4 names a
follow-up `Agent.toolWhitelist: string[]` field ("a future OR schema change ... tracked as a
follow-up issue") that `McpToolsService` would filter tool discovery by. Since the Agent object is
moving into Hermiq's own register anyway, this change declares `Agent.tools` as that whitelist
directly (`array` of `{appId}.{toolName}` strings, empty = all discovered tools allowed — same
default-empty semantics ADR-035 specifies) rather than porting the OR field name unchanged and
leaving the ADR-035 follow-up unresolved on a table about to be dropped. This closes the ADR-035
follow-up as a side effect of the migration rather than requiring a sixth change.

**Every RAG/quota/view knob on `Agent` is preserved verbatim.** `provider`, `model`, `prompt`,
`temperature`, `maxTokens`, `configuration`, `active`, `enableRag`, `ragSearchMode`,
`ragNumSources`, `ragIncludeFiles`, `ragIncludeObjects`, `requestQuota`, `tokenQuota`, `views`,
`searchFiles`, `searchObjects`, `isPrivate`, `invitedUsers`, `groups`, `user` all carry over
1:1 from `openregister/lib/Db/Agent.php` (verified field list at HEAD) — the schema declares data,
not behavior, so RAG-vs-non-RAG semantics remain exactly what `agent-engine-port`'s `ContextRetrieval`
module reads off the object. `type` (a loosely-defined free string on OR's entity, no observed
enum/consumer at HEAD) is preserved as an optional string rather than invented into an enum — a
speculative enum here would be un-verifiable against HEAD and is exactly the kind of guess this
change's ground-truth-first approach avoids.

**No `if`/`then` conditionals.** Per prior burns (OpenRegister's importer rejects JSON-Schema
conditionals; `SchemaMapper::loadSchema` expects a string identifier), all four schemas are flat:
required arrays + enums only. Cross-field rules (e.g. `Feedback.type` value set, `Message.role`
enum) are plain enums, not conditional requirements.

**Declarative vs imperative split (ADR-031).** These four schemas are pure data declarations —
zero calculated fields, zero aggregations, zero lifecycle hooks. All engine *behavior* (generating a
response, resolving RAG context, running the tool loop, streaming SSE) is imperative code that reads
and writes these objects via `ObjectService`, and lives entirely in `agent-engine-port`. This mirrors
the split every other Hermiq schema+enforcement pair in this repo already follows
(`human-approval-gate-schema` / `-enforcement`, `agent-schedule-schema` / `-dispatcher`).

## Adaptations vs plan §7 (HEAD-verified, 2026-07-06)

- **4 live tables, not 7.** `openregister_{agents,conversations,messages,feedback}` are the only
  QBMapper tables with live callers. `openregister_chat_history`
  (`Version002004000Date20251013000000.php`) has zero non-migration references at HEAD — it is dead
  code, not a fifth chat-adjacent table to migrate. `feedback_*` in the plan's table (implying
  multiple feedback tables) is also singular at HEAD: one `openregister_feedback` table, one
  `Feedback` entity.
- **`EndpointService`'s agent-endpoint path is already a graceful stub, not a live fatal.** The plan
  states `callOllamaWithTools()` is "an undefined method; that endpoint type fatals today". At HEAD,
  `executeAgentEndpoint()` in `lib/Service/EndpointService.php` has already been rewritten to a
  documented "pending the agent-core [migration]" stub that returns a structured response instead of
  fataling — someone on the OR side pre-emptively fixed the crash ahead of this migration. The
  deletion this change's downstream port still performs is deleting an already-dead code path, not
  fixing a live fatal.
- **The tool-loop wiring is two services, not one.** The plan's table names `McpProviderBridge` as
  the sole bridge. At HEAD, the chat path is actually: `ToolRegistry` (generic tool container,
  `lib/Service/ToolRegistry.php`) holds `McpProviderBridge` instances (`lib/Tool/McpProviderBridge
  .php`, wraps one `IMcpToolProvider` as a `ToolInterface`), and `ToolRegistrationListener`
  (`lib/Listener/ToolRegistrationListener.php`) is what actually constructs and registers each
  bridge. `McpToolsService` (`listTools()`/`invokeTool()`) is a *separate* facade used by the MCP
  JSON-RPC endpoint — the chat orchestrator's `ResponseGenerationHandler` reads from `ToolRegistry`,
  not `McpToolsService`. The `or-tool-registry-facade` appendix below is written against this
  corrected wiring, not the plan's single-bridge simplification.
- **`getChatStats` org-filter bug confirmed live.** `ChatController::getChatStats()` scopes agent/
  conversation counts to the active organisation only when one is resolved (`$organisationUuid !==
  null`); when no active organisation resolves, the counts silently fall back to instance-wide,
  unscoped totals — the multi-tenant leak plan §7.4 step 7 references. Confirmed still present at
  HEAD; the fix ships wherever this code lives when step 7 executes (tracked, not fixed here).

## Seed Data (ADR-001)

Illustrative objects (declarative — schemas are data, not code). All UUIDs use the NIL UUID
`00000000-0000-0000-0000-000000000000`; user ids and org values are `<angle-bracket>` placeholders.

An `Agent` (RAG-enabled, Ollama-backed, tool-whitelisted to two apps):

```json
{
  "name": "Permit drafting assistant",
  "description": "Drafts first-pass permit decisions for caseworker review.",
  "type": "chat",
  "provider": "ollama",
  "model": "qwen3.5",
  "prompt": "You are a careful municipal permit assistant. Draft, never send.",
  "temperature": 0.2,
  "maxTokens": 2048,
  "configuration": {},
  "active": true,
  "enableRag": true,
  "ragSearchMode": "hybrid",
  "ragNumSources": 5,
  "ragIncludeFiles": true,
  "ragIncludeObjects": true,
  "requestQuota": 200,
  "tokenQuota": 500000,
  "views": [],
  "searchFiles": true,
  "searchObjects": true,
  "isPrivate": false,
  "invitedUsers": [],
  "groups": ["<permit-team-group-id>"],
  "tools": ["opencatalogi.searchPublications", "hermiq.listSchedules"],
  "user": "<service-account-uid>"
}
```

A `Conversation` bound to that agent (uuid ref, not int FK):

```json
{
  "title": "Hermiq scheduled run",
  "userId": "<owner-uid>",
  "agentId": "00000000-0000-0000-0000-000000000000",
  "metadata": {}
}
```

A `Message` carrying an AI Chat Companion context snapshot:

```json
{
  "conversationId": "00000000-0000-0000-0000-000000000000",
  "role": "user",
  "content": "Summarize the open permit applications for this week.",
  "sources": [],
  "context": {
    "appId": "opencatalogi",
    "pageKind": "index",
    "registerSlug": "permits",
    "schemaSlug": "application",
    "capturedAt": "2026-07-06T08:00:00+00:00"
  }
}
```

A `Feedback` on that message:

```json
{
  "messageId": "00000000-0000-0000-0000-000000000000",
  "conversationId": "00000000-0000-0000-0000-000000000000",
  "agentId": "00000000-0000-0000-0000-000000000000",
  "userId": "<owner-uid>",
  "type": "positive",
  "comment": "Good first draft, minor date error."
}
```

## Risks / Trade-offs

- **Four new OR objects → four new magic tables.** Accepted: matches every other OR-object-as-
  storage decision already made in this repo (Schedule, Approval, TenantControl, Memory, Skill, ...).
- **Union import collisions.** Re-parse `hermiq_register.json` after editing; verify `Example`,
  `Schedule`, `Approval`, `AI feature`, `Tenant control`, `Agent memory`, `User profile`, `Session`,
  `Session turn`, `Skill`, `Skill source` are unchanged (union merge, no regression per
  `feedback_union-merge-no-regression.md`).
- **Soft references.** `Conversation.agentId`, `Message.conversationId`, `Feedback.messageId` /
  `.conversationId` / `.agentId` are soft uuid references; OpenRegister does not enforce referential
  integrity. `agent-data-migration` is responsible for correct uuid resolution during the copy;
  `agent-engine-port`'s Engine layer is responsible for handling a dangling reference gracefully at
  read time.
- **This schema declares no `toolWhitelist`-shaped default enforcement.** `Agent.tools` empty means
  "all discovered tools allowed" per ADR-035 — that filtering behavior is implemented in
  `agent-engine-port`'s ported `ToolLoop`, not here.

## Appendix — cross-repo changes (narrated only, NOT materialized in this repo)

The following three changes complete the program per plan §7.4 but live in different repos
(openregister, nextcloud-vue). They are written here as proposal-shaped design text for the Hydra
orchestrator to materialize as real `openspec/changes/<slug>/` directories in their own repos when
the build sequence reaches them. Each stub below is intentionally proposal.md-shaped (Why / What
Changes / Impact) so the orchestrator's per-repo `opsx-ff` pass has a concrete starting brief rather
than a one-line pointer.

### Appendix A — `or-tool-registry-facade` (openregister, `kind: code`, depends_on: [])

**Why.** Per the amended ADR-001, OpenRegister keeps the MCP tool registry (`ToolRegistry`,
`McpProviderBridge`, `ToolRegistrationListener`, `IMcpToolProvider` ABI, `McpToolsService` JSON-RPC
discovery) but no longer owns the engine that consumes it. Hermiq's ported `ToolLoop`
(`agent-engine-port`) needs a small, additive, stable read/invoke surface to call across the app
boundary instead of depending on OR's internal `ToolRegistry`/`McpProviderBridge` wiring directly —
which is exactly the kind of un-contracted cross-app dependency `hydra-gate-no-phantom-cross-app-rpc`
(gate-27) flags. This satisfies gate-27's push toward an explicit OR public-API contract (ADR-022).

**What Changes.**
- A new public service class, e.g. `OCA\OpenRegister\Service\Mcp\ToolRegistryFacade`, exposing
  exactly two methods: `listTools(?string $agentToolWhitelist = null): array` (returns LLPhant-
  compatible function/tool descriptors, filtered by an optional whitelist — the ADR-035
  `toolWhitelist` semantics) and `invokeTool(string $toolId, array $arguments): array` (delegates to
  the same `ToolRegistry`/`McpProviderBridge` path `ResponseGenerationHandler` uses today, preserving
  per-object auth flowthrough per hydra ADR-034 Decision 7 — no impersonation, no elevation).
- No behavior change to OR itself: the facade wraps existing `ToolRegistry`/`McpProviderBridge`
  construction (today assembled ad hoc by `ToolRegistrationListener` + `ChatService`'s constructor);
  it does not change how per-app `IMcpToolProvider` implementations register.
- Marked as OR's supported public API surface for this purpose (the class docblock and an entry in
  OR's own `openspec/architecture` notes it as a stable contract other apps may depend on), so a
  future OR refactor cannot silently remove it out from under Hermiq the way `ObjectService->
  publish()` was removed out from under opencatalogi (the incident `hydra-gate-no-phantom-cross-app-
  rpc` was built to catch).

**Impact.** New file(s) in `lib/Service/Mcp/`; no schema change; no route change (in-process PHP
call, same as Hermiq's existing `ScheduleService` → `ChatService` dependency today, just against a
narrower, intentionally-public surface instead of the internal `ChatService`).

### Appendix B — `chat-appid-flip` (nextcloud-vue, `kind: code`, depends_on: [agent-engine-port])

**Why.** Four hardcoded call sites across three files assume the chat/agents backend is always
OpenRegister: `useAiChatStream.js:24-25,358`, `CnAiCompanion.vue:40`, `CnAiHistoryDialog.vue:134`.
Once Hermiq owns the engine, every one of the 17 apps consuming `CnAiCompanion` needs to point at
Hermiq's routes instead — hydra ADR-034 Decision 2 and the amended ADR-034 language ("the
`CnAiCompanion` contract now points at Hermiq's routes"). The 404 latent bug (conversation/history
URLs matching no OR route) was already fixed independently and merged (ncvue#83, per project memory)
— only the app-id parameterization remains.
- **What Changes.** Introduce a `chatAppId` config/prop (default value TBD by the nextcloud-vue
  maintainers — likely a `CnAppRoot` provide value or a build-time constant) threaded through the
  four call sites so the target app is a single configuration point, not four hardcoded strings.
  Flip the default from `openregister` to `hermiq` on the coordinated beta bump so all 17 consuming
  apps pick it up on rebuild, per plan §7.4 step 5.
- **Impact.** `useAiChatStream.js`, `CnAiCompanion.vue`, `CnAiHistoryDialog.vue`. No schema, no new
  route — a routing-target parameterization in the shared Vue library.

### Appendix C — `or-chat-proxy-deprecation` (openregister, `kind: code`, depends_on: [agent-engine-port])

**Why.** Consuming apps and any bookmarked/cached URLs may still hit OR's `/api/chat/*` and
`/api/agents` routes for at least one release after Hermiq's engine goes live. Per plan §7.4 step 6,
OR keeps a compat window rather than a hard cutover.
- **What Changes.** OR's existing `Chat`/`ChatStream`/`ChatHealth`/`Conversation`/`Agents`
  controllers' routes become thin 308-redirect (or transparent proxy, whichever preserves streaming
  semantics for `ChatStreamController`'s SSE endpoint) forwards to the equivalent Hermiq route.
  OR's changelog gains an explicit deprecation notice with a removal-target release.
  Note the `getChatStats` org-filter bug (Adaptations section above) is fixed as part of whichever
  change eventually deletes this code (plan §7.4 step 7), not as part of standing up the proxy.
- **Impact.** `lib/Controller/{Chat,ChatStream,ChatHealth,Conversation,Agents}Controller.php` become
  proxy shims; underlying `ChatService`/`Chat/*`/tables are NOT deleted yet (that is the separate,
  not-yet-specified future removal change referenced in proposal.md — plan §7.4 step 7 is
  deliberately out of scope for this program's specified chain).
