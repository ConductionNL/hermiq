---
kind: code
depends_on: [agent-engine-schemas, or-tool-registry-facade]
chain:
  - agent-engine-schemas    # hermiq, config — merged first
  - or-tool-registry-facade # openregister, code — cross-repo, must merge before this spec builds
  - agent-engine-port       # this spec (hermiq, code)
  - agent-data-migration    # hermiq, code — depends_on this spec
---

# Proposal: agent-engine-port

## Why

This is **spec 3 of the agent-core-port chain** (see `agent-engine-schemas/proposal.md` for the
full 5-change, 3-repo chain diagram) and the largest single change in the Spectr program. It ports
the actual agent execution engine — chat orchestration, LLM provider selection, the tool loop, the
API surface, and the chat/agents SPA — from OpenRegister into Hermiq, per the 2026-07-05 amendment
to hermiq ADR-001 and the paired hydra ADR-034/035 amendments.

It depends on two changes: `agent-engine-schemas` (this repo, merged first — the `Agent`/
`Conversation`/`Message`/`Feedback` schemas this engine reads and writes) and
`or-tool-registry-facade` (a different repo, openregister — the small read/invoke surface this
engine's `ToolLoop` consumes instead of reaching into OR's internal `ToolRegistry`/
`McpProviderBridge` wiring directly). Per ADR-032, cross-repo dependency tracking by issue number is
new ground for Hydra's dependency checker until both changes have issues — see `DEFERRED_QUESTIONS`.

The engine is built **behind a feature flag**, with storage as OR objects (via
`agent-engine-schemas`) from day one, and a **parity harness** that runs the same prompt/agent
through both the old OR path and the new Hermiq path and diffs the result — this change does not
flip any default or delete any OR code; that is `or-chat-proxy-deprecation` and the future removal
change (both out of scope here, see `agent-engine-schemas/design.md` Appendix).

## What Changes

**Engine (`lib/Service/Engine/*`).** Port the five chat-path modules from
`openregister/lib/Service/Chat/*` (verified sizes at HEAD 2026-07-06: `ResponseGenerationHandler.php`
844 lines, `ConversationManagementHandler.php` 585, `ContextRetrievalHandler.php` 497,
`ToolManagementHandler.php` 410, `MessageHistoryHandler.php` 257, `StreamYieldChannel.php` 221 — six
files, ~2.8k lines) plus the 455-line `ChatService.php` facade, adapted to:
- read/write `Agent`/`Conversation`/`Message`/`Feedback` as OR objects (via `ObjectService`)
  instead of the QBMapper entities/mappers OR uses today;
- `ContextRetrievalHandler`'s RAG calls stay a **data query against OR's vector search** (OR keeps
  vector embeddings + semantic/hybrid search per the amended ADR-001 — this module calls out to OR,
  it does not reimplement RAG);
- `ToolManagementHandler`'s tool-loop glue becomes `lib/Service/Engine/ToolLoop`, consuming the new
  `or-tool-registry-facade` (`listTools()`/`invokeTool()`) instead of constructing
  `ToolRegistry`/`McpProviderBridge` instances itself.

**LLM provider layer (`lib/Service/Llm/ProviderFactory`).** Port OR's LLPhant provider selection +
the `patches/llphant-ollama-think-keepalive.patch` (and `llphant-ollama-usage-capture.patch`, which
backs the per-run cost/usage reporting `ScheduleService::runAgentAsOwner()` already consumes) into a
`hermiq.llm` `IAppConfig` key (mirrors OR's existing `getValueString($appName, 'llm', ...)` pattern
at `openregister.llm`). Add the 4th `nextcloud` driver — TaskProcessing-backed, non-streaming,
`hasProviders()`-guarded — per plan §8 workstream F move 1 (background/non-interactive LLM work
only; LLPhant stays for SSE chat + embeddings, which TaskProcessing cannot do).

**Routes (`/apps/hermiq/api/{chat,conversations,agents}`).** Route-for-route mirror of OR's ~30
routes across `ChatController` (961 lines), `ChatStreamController` (656 — the SSE endpoint, six-
event envelope: `token`/`tool_call`/`tool_result`/`heartbeat`/`final`/`error`, unchanged contract
per hydra ADR-034 Decision 6), `ChatHealthController` (152), `ConversationController` (984),
`AgentsController` (675).

**Chat SPA.** Merge OR's chat/agents pages into Hermiq's existing SPA, which already has
`AgentCatalog.vue`/`AgentDetail.vue`/`AgentFormModal.vue`. `src/api/agents.js` currently documents
itself as a deliberate `createObjectStore` bypass ("OpenRegister agents are a first-class OR
resource ... served at `/apps/openregister/api/agents` ... so they cannot be read through
createObjectStore") — once `Agent` is a plain OR object in the `hermiq` register (via
`agent-engine-schemas`), that constraint no longer holds. This change retires the bespoke
`/api/agents` resource fetch and moves agent CRUD onto the generic
`/apps/hermiq/api/objects/{register}/{schema}` path through `createObjectStore`, closing the
project's standing store-pattern exception for this one file.

**`nextcloud` TaskProcessing driver (plan §8).** `ProviderFactory` gains the 4th driver consuming
`OCP\TaskProcessing\IManager`, guarded by `hasProviders()` (matches decidesk's existing pattern —
503 without a provider). Scope for this change is **Consume only** (plan §8 move 1): route
background/non-interactive work (conversation titles, summaries) through it when a TaskProcessing
provider is installed. Moves 2-5 (Provide `ISynchronousProvider`s, the ContextAgent provider,
plugging OR's MCP server into stock Assistant, `hermiq-exec` task types) are explicitly NOT in this
change — each is its own future change once this port lands.

**`ScheduleService` pivot.** `lib/Service/ScheduleService.php::runAgentAsOwner()` currently imports
`OCA\OpenRegister\Service\ChatService` directly and calls `processMessage()` against an OR
`Conversation`/`ConversationMapper` insert. Behind the feature flag, this call site switches to the
new in-app `Engine` facade and hermiq-register `Conversation` object — this is the one call site the
plan's "ScheduleService now calls its own in-app engine" (step 7) actually starts happening at,
years ahead of OR's table removal, because the port itself makes the local path available.

**Deleted (dead code, not a live fatal).** `EndpointService`'s `agent` endpoint type on the OR side
is out of scope for this change (it lives in a different repo); see `agent-engine-schemas/design.md`
"Adaptations vs plan §7" — HEAD already replaced the previously-fatal `callOllamaWithTools()` call
with a graceful "pending agent-core" stub, so there is no live crash this port needs to race to fix.

### MCP coverage

No MCP surface — this change relocates the AI *consumer* of the tool registry (the chat/tool loop),
it does not add a new `IMcpToolProvider`-callable tool. Hermiq's own `HermiqToolProvider` (existing,
unaffected) is the app's MCP-tool-publishing surface; this change is orthogonal to it (ADR-035
Decision 2, answer 3).

## Impact

- Affected specs: NEW `agent-engine-port` capability (Engine, Llm, ToolLoop, routes, SPA merge).
- Affected code (new): `lib/Service/Engine/*` (5-6 modules), `lib/Service/Llm/ProviderFactory.php`,
  `lib/Controller/{Chat,ChatStream,ChatHealth,Conversation,Agents}Controller.php`,
  `appinfo/routes.php` (~30 new route entries), `src/views/chat/*` (new, merged from OR's SPA),
  `src/api/agents.js` (rewritten onto `createObjectStore`).
- Affected code (modified): `lib/Service/ScheduleService.php::runAgentAsOwner()` (feature-flagged
  engine call site).
- Depends on: `agent-engine-schemas` (this repo), `or-tool-registry-facade` (openregister, cross-
  repo — see `DEFERRED_QUESTIONS`).
- Downstream: unblocks `agent-data-migration` (copies OR's live data into the schemas this engine
  reads) and `chat-appid-flip` (nextcloud-vue's default-target flip, once this engine's routes are
  live to flip to).

## DEFERRED_QUESTIONS

1. **Cross-repo `depends_on` resolution.** Hydra's dependency checker (`hydra.json` → `depends_on`
   → issue-closed check) operates within one repo's issue tracker today. `or-tool-registry-facade`
   is a different repo (openregister). Does the orchestrator need a cross-repo `depends_on` syntax
   (e.g. `openregister#123`) before this spec can be dispatched to build, or is the existing
   same-repo-slug-then-issue-translation convention (ADR-032) sufficient once both issues exist?
   Left to the human/orchestrator planning this program's rollout.
2. **Parity harness ownership and pass/fail bar.** Plan §7.5 names a "parity harness" that runs the
   same prompt/agent through both paths and diffs the result, but does not specify where it lives
   (a new test suite in this change, or a separate harness change) or what diff tolerance counts as
   pass (exact text match is unrealistic for a non-deterministic LLM; semantic similarity threshold?
   tool-call-sequence match only?). This change's tasks.md treats "build and run the parity harness"
   as one task with a human-reviewed pass bar; a follow-up may formalize an automated threshold.
3. **`nextcloud` TaskProcessing driver default-on or opt-in.** Plan §8 move 1 doesn't state whether
   the new driver is selectable in the same admin settings screen as `provider`/`model` today or a
   separate toggle. Deferred to whoever implements — this change's tasks.md scopes it as
   `hasProviders()`-guarded but does not prescribe the settings UI shape.
4. **`Agent.type` field semantics.** OR's `Agent.type` has no observed enum or consumer at HEAD
   (design.md, agent-engine-schemas). Whether the ported Engine gives it real meaning (e.g.
   `chat`|`scheduled`) or leaves it an unused free string is deferred to implementation; no plan
   text specifies it.
