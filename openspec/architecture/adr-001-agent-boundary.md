# ADR-001 — Hermiq is a thin scheduling + management-UX app over OpenRegister's agent core (Option C+)

- **Status:** Accepted — amended 2026-07-05
- **Date:** 2026-07-03
- **Deciders:** Ruben van der Linde (Conduction)
- **App:** Hermiq
- **Supersedes:** —
- **Related:** OpenRegister agent core, OpenConnector connectors, n8n-nextcloud ExApp; company principle "one write path / shared abstraction"; ADR-022 (apps consume OpenRegister abstractions)

## Context

Hermiq is the Nextcloud-native, multi-tenant, OpenRegister-governed port of **NousResearch Hermes**
(`github.com/NousResearch/hermes-agent`, MIT, Python 3.11, SQLite, ~175k stars) — a self-hosted **autonomous
personal agent**: persistent memory + self-improving skills (agentskills.io) + a cron scheduler + a 22-platform
chat gateway. Hermes is single-user, CLI-first, and stores everything locally.

A source-level study of Hermes against the Conduction fleet established that **OpenRegister already ships the
agent engine**:

- `Agent` entity + `AgentHandler`/`ChatService` (run loop, RAG, tool calls) — the `Agent.user` field docblock even
  states it exists "for running agent in cron/background scenarios".
- Full **MCP** stack (`McpServerController`, `McpToolsService`, `McpProviderBridge`) + `ToolRegistry` + `AgentTool`.
- **LLPhant** function-calling with a native `OllamaChat` path (local Qwen on :11434).
- **`AuditTrail`** with a `hash`/`previousHash` tamper-evident chain, `organisation` tenant scoping, GDPR Art. 30
  `verwerkingsregister`, DSAR endpoints (`inzageverzoek`/`vergetelheid`/`portabiliteit`), a hash-chain `verify()`
  endpoint, and a separate read-processing log.
- **`ObjectEntity`** with `owner`/`organisation`/`groups`/`authorization` (native NC-group RBAC), `version`, `locked`,
  `deleted` (soft-delete) — a complete multi-tenant state + rollback model.
- `SearchTrail` + `VectorizationService` for logged, semantic recall.

The **only engine gap** is scheduling: the `Agent` entity has no schedule fields and `openregister/lib/Cron/`
has no agent-firing job. OpenConnector's `JobTask`/`JobService` scheduler is fixed-interval polling only — no cron
expressions.

The fleet already has two automation surfaces (**OpenConnector** = connectors/integrations; **n8n-nextcloud** =
visual workflow engine). A third overlapping engine would violate the "one write path / shared abstraction"
principle and fragment the fleet.

## Decision

**Hermiq owns the agent core.** *(Amended 2026-07-05 — this reverses the "thin app" framing in the title above;
see the "Amendment" section below.)* It now owns:

1. **Agent execution** — the chat/tool-loop orchestrator, the LLM provider layer, and conversations/messages/
   feedback, plus the chat UI + SSE stream (migrated from OpenRegister; stored as OR objects in the `hermiq`
   register — leaf-consistent).
2. **Scheduling & triggers** — the cron/interval/webhook layer that fires Hermiq's own agents (previously
   OpenRegister's).
3. **A fuller agent-management UX (the "+" in Option C+)** — memory editor, skills/context, a skills
   **catalog/marketplace**, and run analytics — realized as OpenRegister objects.

Everything else is **delegated**:

| Concern | Owner |
|---|---|
| Agent execution, the tool loop, LLM provider layer (LLPhant + the Ollama `think`/`keep_alive` patch + `hermiq.llm` config + a TaskProcessing-backed `nextcloud` driver), conversations/messages/feedback, skills/memory/context, chat UI + SSE | **Hermiq** |
| Object storage, RBAC, audit, multitenancy (generic abstractions) · vector embeddings + semantic/hybrid search (RAG substrate) · MCP tool registry + `IMcpToolProvider` ABI + MCP discovery/JSON-RPC server | **OpenRegister** |
| Connectors / external-system integrations | **OpenConnector** |
| Heavy visual, branching workflows | **n8n-nextcloud** |
| Identity, RBAC, groups, multi-tenancy, notifications, Talk, Files, Mail | **Nextcloud** |

Hermiq's tool loop **consumes** OR's tool registry through a new, small OR-exposed read/invoke facade rather than
reimplementing MCP discovery or the JSON-RPC server — Hermiq now owns the engine but still depends only on generic
OR abstractions, which keeps it a true ADR-022 leaf (see Alternatives considered, option (a), for the alternative
this deliberately is *not*: a duplicated engine).

**The fleet boundary (say this in docs and UI):**
> **agents + agent-core + governance** = Hermiq · **workflows** = n8n · **integrations** = OpenConnector · **data, vectors + tool registry** = OpenRegister

### What Hermiq builds (NET-NEW)
- OpenRegister `Schedule`/`Trigger` schema object + `ScheduleTask extends TimedJob` (copying OpenConnector's
  pattern) using `dragonmantank/cron-expression`; dispatch delegates to Hermiq's own agent engine (the ported
  `AgentHandler`/`ChatService` — see "Amendment" below), not OR's.
- Memory/skills schemas (`Memory`, `UserProfile`, `Session`, `SessionTurn`, `Skill`, `SkillSource`), a bidirectional
  **`SkillSerializer`** guaranteeing agentskills.io round-trip fidelity, a `SkillCatalogService` (hub adapters +
  quarantine + security scan), and a Curator background job.
- One **Nextcloud Talk** delivery adapter + thin `IMcpToolProvider` wrappers for NC-native tools
  (Files/Contacts/Calendar/Deck) + IMailer outbound.
- Durable **Approval** and tenant **kill-switch** OR object states, enforced synchronously in Hermiq's own
  dispatch loop (previously OR's).
- A PHP port of Hermes' `agent/redact.py`, applied **before** any AuditTrail persist.
- *(Amendment, 2026-07-05)* The agent engine itself, ported from OpenRegister: `lib/Service/Engine/*` (chat
  orchestration, context retrieval, tool loop), `lib/Service/Llm/ProviderFactory` (+ `hermiq.llm` config), the
  `/apps/hermiq/api/{chat,conversations,agents}` routes (incl. SSE), and the `Agent`/`Conversation`/`Message`/
  `Feedback` OR-object schemas in the `hermiq` register. See "Amendment" below for the migration sequence.

### What Hermiq must NOT build (delegated / dropped)
- The ONLY agent/tool-loop/LLM engine lives in Hermiq; OR retains none. Hermiq consumes only generic OR
  abstractions (objects, RBAC, audit, vectors, tool registry) — a true ADR-022 leaf.
- No 22-platform gateway, no provider-profile layer, no MCP client/server of its own (consumed via OR's registry +
  discovery + the new facade above, not reimplemented).
- No SQLite/FTS5 store, no credential-pool, no secret-scope, no shadow-git checkpoints (use OR objects + NC RBAC).

## Consequences

**Positive**
- Complexity ~4/10 for the original scheduling + UX scope — a schedulable, audited agent MVP in ~4–6 weeks; full
  port ~12–17 weeks (backend). The 2026-07-05 amendment adds the agent-core migration on top (~11.6k LOC, 7
  tables → OR objects, ~30 routes, a 7-step sequence — see "Amendment" below), staged behind its own feature flag
  and parity harness rather than folded into this estimate.
- EU AI Act (Art. 12/13/14/19) record-keeping, transparency, and human-oversight obligations are **inherited**
  from OpenRegister's audit + RBAC layer, not rebuilt.
- No fleet fragmentation; Hermiq stays inside the shared abstraction — more so after the amendment, since it now
  depends only on OR's generic primitives rather than an app-specific engine living in the foundation app.
- agentskills.io + MCP compatibility keeps Hermiq inside the open ecosystem.

**Negative / risks**
- Hard dependency on **OpenRegister** for object storage, RBAC, audit, vector search, and the MCP tool registry;
  Hermiq is non-functional without it — though after the amendment this dependency is on generic infrastructure,
  not on OR owning execution itself (execution is now Hermiq's).
- **Nextcloud Talk (spreed) is not installed** on the current instance — the primary channel is an operator
  dependency (or falls back to OCS polling).
- **Ollama/Qwen function-calling fidelity** is the main quality risk; the fleet `OllamaChat.php` `think:false`/
  `keep_alive` patch must be applied (now ported into Hermiq's own LLM provider layer, per the amendment).
- NC's single `TimedJob` polls → sub-5-minute schedules need webcron/systemd; not minute-precise on default cron.
- Compliance correctness depends on two invariants: **redaction before persist**, and **single write-path**
  through `ObjectService` (enforce as a CI gate) — both now enforced in Hermiq's own code, not OR's.
- **Migration risk (new, 2026-07-05):** `CnAiCompanion` and its chat/history stores are hardcoded to OpenRegister
  across 4 nextcloud-vue call sites and are live in 17 consuming apps; mitigated by a feature-flagged parity
  harness, a `chatAppId` config point, and a ≥1-release compat proxy at OR's `/api/chat/*` before removal (see
  "Amendment").

## Alternatives considered

- **(a) Standalone engine** — Hermiq reimplements the Hermes agent/tool/LLM engine for independence.
  *Rejected:* duplicates OpenRegister, violates one-write-path, ~7/10 complexity, fragments the fleet.
- **(b) Pure surface on n8n + OpenConnector** — no OpenRegister.
  *Rejected:* the agent/tool/governance substrate lives in OpenRegister, not those two; would still need a memory/
  skills/audit engine.
- **(c/c+) Thin app over OpenRegister** — *chosen.* "+" = Hermiq also owns the richer agent-management UX
  (memory, skills catalog/marketplace, run analytics) rather than the minimal scheduling-only surface.

## Amendment — 2026-07-05

**What changed.** The Decision above is flipped from the 2026-07-03 original: Hermiq now owns agent execution,
the tool loop, the LLM provider layer, and conversations/messages/feedback; OpenRegister keeps only the generic
abstractions — object storage/RBAC/audit/multitenancy, vector embeddings + semantic/hybrid search, and the MCP
tool registry + `IMcpToolProvider` ABI + discovery/JSON-RPC server — which Hermiq's tool loop now consumes through
a small, new OR-exposed facade. Full analysis lives in the Spectr re-platforming plan
(`SPECTR-NEXTCLOUD-PLAN.md`, §6–§8); this section records the ADR-level decision and its rationale.

**Rationale.** The 2026-07-03 decision had Hermiq consume an app-specific engine (chat orchestration, LLM
providers, tool loop) that happened to live in the foundation app — the opposite of ADR-022's spirit, which
reserves OpenRegister for *generic* abstractions any app can consume, not one app's bespoke runtime. Moving the
engine into Hermiq (a relocation, not the reimplementation rejected as Alternative (a) above) makes Hermiq a true
ADR-022 leaf: everything it depends on afterward — objects, RBAC, audit, vectors, tool registry — is generic. It
also dissolves OR#269 ("Ollama tools 400") as a cross-app blocker: it becomes a bug in Hermiq's own LLPhant layer,
freeing `agent-memory`, `skills-catalog`, and `nc-native-tools` to proceed on Hermiq's own cadence instead of
waiting on OpenRegister.

**Migration (7 steps, condensed — full detail in the Spectr plan §7.4):**
1. This amendment and the paired hydra ADR-034/035 amendments land together.
2. OpenRegister grows a small, additive **tool-registry facade** (read/invoke; no behavior change to OR itself).
3. The engine is ported into Hermiq behind a feature flag — `ChatService`/`Chat/*` handlers → `lib/Service/
   Engine/*`, the LLPhant provider layer (+ Ollama patch) → `lib/Service/Llm/ProviderFactory` + `hermiq.llm`
   config, `McpProviderBridge`/tool-loop glue → `lib/Service/Engine/ToolLoop` (consuming the new facade), and OR's
   chat/agents SPA pages merge into Hermiq's existing SPA (alongside AgentCatalog/AgentDetail). Storage is OR
   objects from day one; a parity harness runs the same prompt/agent through both paths and diffs the result.
4. **Data migration:** a repair step copies the OR QBMapper tables (`openregister_{agents,conversations,messages,
   chat_history,feedback_*}` — 7 tables) into `Agent`/`Conversation`/`Message`/`Feedback` objects in the `hermiq`
   register, preserving uuids/owner/organisation and `Message.context` (and turning `Conversation.agentId` from an
   int-FK into a uuid ref). OR's tables drop one release later.
5. The nextcloud-vue choke point — 4 hardcoded call sites across 3 files (`useAiChatStream.js` ×2,
   `CnAiCompanion.vue`, `CnAiHistoryDialog.vue`) — gains a `chatAppId` config, fixing a latent chat-history 404 as
   a side effect; the default then flips `openregister` → `hermiq` on a coordinated beta bump so all 17 consuming
   apps pick it up on rebuild.
6. **Compat window:** OpenRegister keeps `/api/chat/*` and `/api/agents` as thin proxies to Hermiq for ≥1 release,
   flagged as deprecated in OR's changelog.
7. OR's runtime code/routes/tables are removed; the pre-existing `getChatStats` multi-tenant org-filter bug is
   fixed wherever the code lives by then; `ScheduleService` calls Hermiq's own engine directly instead of OR's.

**In lockstep.** hydra ADR-034 ("OpenRegister is the sole orchestrator") and ADR-035 (`IMcpToolProvider` ABI) are
amended alongside this ADR. ADR-034 flips to "Hermiq is the sole orchestrator; OR provides the RAG substrate and
the MCP tool registry" — the `CnAiCompanion` contract now points at Hermiq's routes, and the no-PHP-dependency
property for consuming apps is preserved. ADR-035's ABI, registration path, and `{appId}.{toolName}` namespacing
are **unchanged** for its 9 implementing apps; only one sentence changes — the loop consuming the registry is now
Hermiq's, not OpenRegister's. Testing: parity harness (step 3), Playwright chat E2E via `CnAiCompanion` against
Hermiq, schedule/approval/kill-switch regression (95 tests), NC34 compat (single `<background-jobs>` block,
`\OCP\Server::get`).
