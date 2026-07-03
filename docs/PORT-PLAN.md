<!--
SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
SPDX-License-Identifier: EUPL-1.2
-->

# Hermiq — Hermes → Nextcloud Port Plan

> Source-level plan to bring **NousResearch Hermes** (a self-hosted autonomous agent with
> persistent memory, self-improving skills and a cron scheduler) into Nextcloud as **Hermiq**:
> a thin, multi-tenant, OpenRegister-governed app. See [ADR-001](../openspec/architecture/adr-001-agent-boundary.md)
> for the boundary decision, and `docs/hermiq-portplan.html` for the visual version.

## At a glance

| | |
|---|---|
| Concept verdict | **GO** |
| Build complexity | **4 / 10** |
| MVP (backend) | **~4–6 weeks** |
| Full port (backend, excl. UI) | **~59–83 dev-days (~12–17 weeks)** |
| Serviceable market (SAM) | **€30–75M/yr** |
| Primary customer | MKB/SMB + orgs on Nextcloud |
| Revenue | Open-core + hosting |

## The boundary (ADR-001, Option C+)

> **agents** = Hermiq · **workflows** = n8n · **integrations** = OpenConnector · **agent-core + governance** = OpenRegister

Hermiq owns only **scheduling/triggers** and a **richer agent-management UX** (agent catalog,
skills marketplace, run analytics). Everything else is delegated. The source study proved
OpenRegister already ships the agent engine — run loop, MCP tools, LLPhant/Ollama, and a
hash-chained audit trail — so Hermiq stays thin.

## Port matrix

### 1. Scheduling & Triggers — *8–11 dev-days*
- **New:** `Schedule` OpenRegister object (cron/interval/once); `ScheduleTask extends TimedJob`; `dragonmantank/cron-expression`; natural-language → schedule via LLPhant.
- **Reused:** OR `AgentHandler`/`ChatService` for execution; OpenConnector `JobTask` scheduler pattern; OR audit trail.
- **Dropped:** Chronos NAS webhook, JSON file-store + locks, gateway self-restart guard.

### 2. Memory & Skills — *22–30 dev-days*
- **New:** 6 schemas (Memory, UserProfile, Session, SessionTurn, Skill, SkillSource); `SkillSerializer` (agentskills.io round-trip); `SkillCatalogService`; Curator background job.
- **Reused:** OR object storage + versioning; `SearchTrail` + `VectorizationService`; `SecurityService`; group RBAC from `ObjectEntity`.
- **Dropped:** SQLite FTS5 index + triggers, `.bak` drift-backup, bundled-manifest sync + HubLockFile.

### 3. Tools, Integrations & Channels — *11–16 dev-days*
- **New:** one **Nextcloud Talk** delivery adapter; thin `IMcpToolProvider` wrappers (Files/Contacts/Calendar/Deck); IMailer outbound.
- **Reused:** OR MCP server + client + `ToolRegistry`; LLPhant `OllamaChat` (local Qwen); OR `AgentTool`; OpenConnector `CallService`.
- **Dropped:** 22-platform gateway daemon, provider-profile layer, own MCP client/server + tool engine.

### 4. Run Observability & AI Governance — *18–26 dev-days*
- **New:** durable **Approval** + tenant **kill-switch** object states; `redact.py` → PHP, run **before** persist; per-tenant AI-Act evidence-pack export.
- **Reused:** OR `AuditTrail` (hash/previousHash chain); GDPR Art.30 verwerkingsregister + DSAR endpoints; `SearchTrail`; Nextcloud groups → multi-tenant RBAC.
- **Dropped:** credential-pool, secret-scope, shadow-git checkpoints, in-memory approval queue.

## Phasing

- **MVP (~4–6 wk):** `Schedule` object + `ScheduleTask` firing OpenRegister's existing agent + Talk delivery + AuditTrail logging — a schedulable, audited agent.
- **V1:** memory + skills catalog + human-approval gate/kill-switch.
- **V2:** skills marketplace, NC-native tool pack, multi-tenant MSP features.

## Verified facts & risks

**Already there (reused):**
- OR `AuditTrail` ships a hash-chained, tamper-evident log with tenant scoping, GDPR Art.30 register, DSAR + `verify()` endpoints — AI-Act record-keeping is inherited.
- OR MCP + LLPhant + Ollama and `AgentTool` exist — tool/provider/delegation layers are deleted, not ported.
- `ObjectEntity` carries owner/organisation/groups/version/locked/deleted — multi-tenant RBAC + rollback come free.

**Risks & dependencies:**
- **Nextcloud Talk (spreed) is not installed** on the dev instance (only `opentalk` video) — the primary channel is an operator dependency; falls back to OCS polling.
- **Ollama/Qwen function-calling fidelity** is the main quality risk; the fleet `OllamaChat.php` `think:false`/`keep_alive` patch must be applied.
- **Redaction must run before AuditTrail persist**, and every write must go through `ObjectService` — enforce both as a CI gate.
- NC's single `TimedJob` polls — sub-5-minute schedules need webcron/systemd.

## Source

Hermes: [`github.com/NousResearch/hermes-agent`](https://github.com/NousResearch/hermes-agent) (MIT).
Full market + competitor evidence lives in the Specter intelligence DB under `app = hermiq`.
