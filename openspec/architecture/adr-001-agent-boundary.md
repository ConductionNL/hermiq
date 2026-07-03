# ADR-001 — Hermiq is a thin scheduling + management-UX app over OpenRegister's agent core (Option C+)

- **Status:** Proposed
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

**Hermiq is a thin Nextcloud app.** It owns exactly two things:

1. **Scheduling & triggers** — the missing cron/interval/webhook layer that fires OpenRegister agents.
2. **A fuller agent-management UX (the "+" in Option C+)** — memory editor, a skills **catalog/marketplace**, and
   run analytics — realized as OpenRegister objects.

Everything else is **delegated**:

| Concern | Owner |
|---|---|
| Agent execution, tools (MCP), LLM, governance/audit, memory & skill **storage** | **OpenRegister** |
| Connectors / external-system integrations | **OpenConnector** |
| Heavy visual, branching workflows | **n8n-nextcloud** |
| Identity, RBAC, groups, multi-tenancy, notifications, Talk, Files, Mail | **Nextcloud** |

**The fleet boundary (say this in docs and UI):**
> **agents** = Hermiq · **workflows** = n8n · **integrations** = OpenConnector · **agent-core + governance** = OpenRegister

### What Hermiq builds (NET-NEW)
- OpenRegister `Schedule`/`Trigger` schema object + `ScheduleTask extends TimedJob` (copying OpenConnector's
  pattern) using `dragonmantank/cron-expression`; dispatch delegates to OR's `AgentHandler`.
- Memory/skills schemas (`Memory`, `UserProfile`, `Session`, `SessionTurn`, `Skill`, `SkillSource`), a bidirectional
  **`SkillSerializer`** guaranteeing agentskills.io round-trip fidelity, a `SkillCatalogService` (hub adapters +
  quarantine + security scan), and a Curator background job.
- One **Nextcloud Talk** delivery adapter + thin `IMcpToolProvider` wrappers for NC-native tools
  (Files/Contacts/Calendar/Deck) + IMailer outbound.
- Durable **Approval** and tenant **kill-switch** OR object states, enforced synchronously in the dispatch loop.
- A PHP port of Hermes' `agent/redact.py`, applied **before** any AuditTrail persist.

### What Hermiq must NOT build (delegated / dropped)
- No second agent/tool/LLM engine (use OR).
- No 22-platform gateway, no provider-profile layer, no MCP client/server (dropped / use OR's).
- No SQLite/FTS5 store, no credential-pool, no secret-scope, no shadow-git checkpoints (use OR objects + NC RBAC).

## Consequences

**Positive**
- Complexity ~4/10; a schedulable, audited agent MVP in ~4–6 weeks; full port ~12–17 weeks (backend).
- EU AI Act (Art. 12/13/14/19) record-keeping, transparency, and human-oversight obligations are **inherited**
  from OpenRegister's audit + RBAC layer, not rebuilt.
- No fleet fragmentation; Hermiq stays inside the shared abstraction.
- agentskills.io + MCP compatibility keeps Hermiq inside the open ecosystem.

**Negative / risks**
- Hard dependency on **OpenRegister** for all execution; Hermiq is non-functional without it.
- **Nextcloud Talk (spreed) is not installed** on the current instance — the primary channel is an operator
  dependency (or falls back to OCS polling).
- **Ollama/Qwen function-calling fidelity** is the main quality risk; the fleet `OllamaChat.php` `think:false`/
  `keep_alive` patch must be applied.
- NC's single `TimedJob` polls → sub-5-minute schedules need webcron/systemd; not minute-precise on default cron.
- Compliance correctness depends on two invariants: **redaction before persist**, and **single write-path**
  through `ObjectService` (enforce as a CI gate).

## Alternatives considered

- **(a) Standalone engine** — Hermiq reimplements the Hermes agent/tool/LLM engine for independence.
  *Rejected:* duplicates OpenRegister, violates one-write-path, ~7/10 complexity, fragments the fleet.
- **(b) Pure surface on n8n + OpenConnector** — no OpenRegister.
  *Rejected:* the agent/tool/governance substrate lives in OpenRegister, not those two; would still need a memory/
  skills/audit engine.
- **(c/c+) Thin app over OpenRegister** — *chosen.* "+" = Hermiq also owns the richer agent-management UX
  (memory, skills catalog/marketplace, run analytics) rather than the minimal scheduling-only surface.
