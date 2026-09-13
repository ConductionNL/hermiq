# Design: agent-engine-port

## Context

Hermiq already runs an agent-adjacent dispatch loop (`ScheduleService`, `agent-schedule-dispatcher`)
and a governance layer on top of it (`ApprovalService`, `TenantControlService`,
`human-approval-gate-enforcement`) — both of which call OUT to OpenRegister's `ChatService` today.
This change ports the thing being called: the actual chat orchestration, LLM provider selection, and
tool loop, so that call becomes in-process instead of cross-app.

This is a **relocation, not a reimplementation** (ADR-001 Amendment explicitly distinguishes this
from the rejected Alternative (a), "standalone engine"). The behavior being ported — RAG-augmented
chat, multi-turn tool calling, SSE streaming — is not redesigned here; it is moved and re-pointed at
hermiq-register objects instead of OR's QBMapper tables. Where this change *does* make a design
decision beyond pure relocation, it is called out explicitly below.

Ground truth (file names, sizes, wiring) re-verified against openregister HEAD 2026-07-06; see
`agent-engine-schemas/design.md` "Adaptations vs plan §7" for the corrections this rests on (4 live
tables not 7, two-service tool wiring not one, `EndpointService` already stubbed not fatal).

## Goals / Non-Goals

**Goals:**
- Port `ChatService` + `Chat/*` handlers into `lib/Service/Engine/*`, reading/writing OR objects in
  the `hermiq` register (via `agent-engine-schemas`) instead of OR's QBMapper tables.
- Port the LLPhant provider layer + both Ollama patches into `lib/Service/Llm/ProviderFactory` +
  `hermiq.llm` config.
- Add the `nextcloud` TaskProcessing driver (plan §8 move 1 only — consume, not provide).
- Mirror OR's ~30 chat/conversation/agent routes route-for-route, including the SSE stream contract
  (six-event envelope, unchanged).
- Feature-flag the whole engine; ship a parity harness; do not flip `ScheduleService`'s default path
  in production until parity is demonstrated.
- Retire `src/api/agents.js`'s `createObjectStore` bypass now that `Agent` is a plain OR object.

**Non-Goals:**
- Declaring the `Agent`/`Conversation`/`Message`/`Feedback` schemas — `agent-engine-schemas`
  (upstream, already merged by the time this builds).
- Copying existing production data from OR's tables — `agent-data-migration` (downstream).
- The OR-side facade Hermiq's `ToolLoop` consumes, or the nextcloud-vue `chatAppId` flip — both
  cross-repo, narrated in `agent-engine-schemas/design.md` Appendix.
- Deleting OR's runtime code/routes/tables — `or-chat-proxy-deprecation` (compat window) and a
  further, not-yet-specified removal change (plan §7.4 step 7).
- TaskProcessing moves 2-5 (Provide `ISynchronousProvider`s, ContextAgent provider, MCP-into-stock-
  Assistant, hermiq-exec task types) — plan §8, each its own future change.

## Decisions

**Feature flag, not a big-bang cutover.** The ported engine lands fully wired but inert by default
(an `IAppConfig` flag, e.g. `hermiq.engine.enabled`). `ScheduleService::runAgentAsOwner()` checks the
flag: when off (default at merge), it calls OR's `ChatService` exactly as it does today (zero
behavior change for existing installs); when on, it calls the new in-app `Engine` facade against
hermiq-register objects. This is the standard expand-then-contract shape ADR-032 recommends for
migrations — the flag flip to "on" is a separate, low-risk deploy step, not bundled into this PR.

**Parity harness (plan §7.5).** A test harness runs an identical `(agent, prompt)` pair through both
paths — old (`ChatService` against OR tables) and new (`Engine` against hermiq objects) — and diffs
the result. Given LLM non-determinism, exact text equality is not the bar; the harness instead
asserts structural parity: same tool-call sequence (which tools, which arguments, in what order),
same RAG source count/shape, same final message role, and a human-reviewed spot-check of response
quality for a fixed prompt set. The precise automated threshold is `DEFERRED_QUESTIONS` item 2 in
proposal.md — this change ships the harness runnable, not a CI-blocking pass/fail gate, since an
automated semantic-similarity bar needs its own calibration pass against real traffic.

**`ContextRetrievalHandler` calls OR's vectors as a data query, not a re-embedding.** Per the amended
ADR-001, OR keeps vector embeddings + semantic/hybrid search. The ported `ContextRetrievalHandler`
in `lib/Service/Engine/` issues a search request against OR's existing vector-search surface (the
same way any other leaf app queries OR's RAG substrate) — it does not duplicate `VectorEmbeddings`/
`VectorSearchHandler` inside Hermiq. This keeps Hermiq a true ADR-022 leaf even for its own most
AI-specific module.

**`ToolLoop` consumes the facade, never `ToolRegistry`/`McpProviderBridge` directly.** The ported
`ToolManagementHandler` → `lib/Service/Engine/ToolLoop` calls `or-tool-registry-facade`'s
`listTools()`/`invokeTool()` (Appendix A of `agent-engine-schemas/design.md`) instead of constructing
OR's internal `ToolRegistry` or `McpProviderBridge` objects itself. This is the one architectural
change beyond pure relocation: it trades a wide, undocumented internal dependency for a narrow,
documented one, directly satisfying `hydra-gate-no-phantom-cross-app-rpc` (gate-27)'s push toward an
explicit OR public-API contract, and is why `or-tool-registry-facade` must merge before this spec can
build (declared `depends_on`).

**Kill-switch / approval-gate enforcement is unaffected — and MUST be re-verified, not assumed.**
Hermiq's `ApprovalService`/`TenantControlService` (from `human-approval-gate-enforcement`) call
`ScheduleService`, which calls the (feature-flagged) engine. The gate — checking `TenantControl
.engaged` and creating a pending `Approval` instead of running — happens in `ScheduleService` before
either engine path is reached, so it is architecturally unaffected by which engine backs the call.
This change's tasks.md nonetheless requires re-running the full kill-switch/approval regression
suite (95 tests per plan §7.5) with the feature flag ON, because the engine swap changes what
`runAgentAsOwner()` calls into and a regression here would be a silent EU-AI-Act Art. 14 compliance
break, not merely a feature bug — the kind of thing that must be proven, not inferred from the code
being "architecturally unaffected".

**`nextcloud` TaskProcessing driver is additive and narrow-scoped.** Added as a 4th `chatProvider`
value in `ProviderFactory`, guarded by `hasProviders()` (decidesk's existing 503-without-provider
pattern). Scope is explicitly non-streaming, non-embedding background work only (conversation
titles/summaries) — LLPhant remains the path for SSE chat and any embeddings work, because
TaskProcessing supports neither (verified against NC 33/34 in plan §8: "no token streaming, no
embeddings task type").

**`src/api/agents.js` moves onto `createObjectStore`.** Once `Agent` is a plain OR object in the
`hermiq` register, the file's own documented rationale for bypassing `createObjectStore` ("cannot be
read through createObjectStore") no longer holds. This is the one frontend store-pattern exception in
the project closed by this migration (`feedback_store-pattern.md`) — `listAgents()`/`createAgent()`/
`updateAgent()` become thin wrappers over the generic `/apps/hermiq/api/objects/hermiq/agent` path,
same as every other Hermiq schema object. `listTools()` (agent-configuration tool catalogue) stays a
bespoke call — it is not an object read, it queries `or-tool-registry-facade`.

## Declarative vs imperative

Everything this change adds is imperative code: chat orchestration, tool invocation, streaming,
provider selection are all behavior, not data (ADR-031's declarative side was fully discharged by
`agent-engine-schemas`). The one place a declarative option was considered and rejected: encoding
the `nextcloud` TaskProcessing driver selection as a schema-level `Agent.provider` enum value
(`ollama`|`openai`|`fireworks`|`nextcloud`) is declarative-compatible and already covered by
`agent-engine-schemas`'s `Agent.provider` free string — no schema change needed here, since
`agent-engine-schemas` deliberately left `provider` as an unconstrained string rather than a closed
enum (new drivers should not require a schema migration).

## NC34 compatibility

Per the fleet's NC34 compat sweep pattern (`reference_nc34-fleet-compat-sweep-2026-06-16.md`), ported
code MUST NOT use `\OC::$server->getXxx()` — use `\OCP\Server::get()` throughout the new `Engine`/
`Llm` classes (OR's current code already follows this at HEAD; the port must not regress it). The
ported background job wiring (if any cron/job registration accompanies the `nextcloud` driver) MUST
NOT introduce a second `<background-jobs>` block in `info.xml` — Hermiq's existing block is extended,
never duplicated (the double-background-jobs-crash class of bug).

## Risks / Trade-offs

- **Largest single PR in the program (~11.6k LOC to relocate + adapt).** Mitigated by the feature
  flag (inert by default) and the parity harness (behavioral proof before flip) — the size risk is
  about review/build budget, not runtime risk, since nothing changes for existing installs at merge
  time. Per ADR-032's `kind: code` budget rules, this spec is a strong candidate for
  `HYDRA_BUILDER_MAX_TURNS=400` / `budget:large` rather than further chain-splitting, because the
  engine's internals (chat orchestration ↔ LLM provider ↔ tool loop) are too tightly coupled to
  usefully split into independently-buildable sub-specs without duplicating the parity-harness
  scaffolding three times.
- **SSE-through-Apache-plus-mod_php risk is inherited, not new.** hydra ADR-034 already flags SSE
  reliability as unverified at ADR-acceptance time with a chunked-HTTP → long-poll → `/api/chat/send`
  fallback ladder. The port carries the same contract and the same fallback ladder — it neither
  improves nor worsens this known risk.
- **Two Ollama LLPhant patches must survive the port unmodified in behavior.**
  `llphant-ollama-think-keepalive.patch` and `llphant-ollama-usage-capture.patch` are vendor patches
  applied to a third-party library; the port must re-verify both apply cleanly against whatever
  LLPhant version Hermiq's composer.json resolves (may differ from OR's pinned version) rather than
  assume identical patch application.
- **`ScheduleService`'s per-run cost/usage capture (`lastRunUsage`) must keep working.** Today it
  reads `$result['usage']` from `ChatService::processMessage()`'s return shape. The new `Engine`
  facade's equivalent method MUST return the same `usage` shape (token/latency) or `run-analytics`
  silently loses per-run cost data — a regression the parity harness's structural-diff check should
  catch (tool-call-sequence + usage-shape parity, not just final text).
- **`Agent.type` and other loosely-defined fields carry no enforced semantics from OR.** The port
  does not invent meaning for them beyond what OR's code actually reads (see
  `agent-engine-schemas/design.md`); over-specifying here would be exactly the fabrication risk this
  program's ground-truth-first approach is designed to avoid.
