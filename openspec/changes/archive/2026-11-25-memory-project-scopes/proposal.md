---
kind: code
---

# Proposal: memory-project-scopes

## Summary

An agent can keep a separate memory per project next to the memory it shares across all its work. When you start a session you can put it in a project, for example "Omgevingsvisie 2040" or "Aanbesteding wagenpark". In that session the agent recalls from the project's memory and from the shared memory together, and what it learns goes into the project's memory unless it is told the fact is general. On the Memory page you see the shared memory and each project's memory apart, and you can move an entry between them.

## Why

One row of hermiq's capability matrix, decided in the OpenSpec pass of 2026-09-27.

| row | own rating | decision |
|---|---|---|
| `hermiq:dm-project-memory` | partial, built | build: the featureRequest demand row (hermes-agent#16833, project-scoped memory pools, global plus per project) asks for exactly the missing half |

Demand row:

- `dm-project-memory`: featureRequest https://github.com/NousResearch/hermes-agent/issues/16833

No competitor rates this row yes. The closest, n8n, is partial: "Agents-module memory is kept per agent and resource: per user, per chat thread, per task (packages/cli/src/modules/agents/utils/agent-memory-scope.ts:1-60) ... No built-in pairing of a project memory with a shared one."

## What hermiq already has

- One `Memory` object per agent, found by `agentId` alone and created on first use (`lib/Service/MemoryService.php:145-162`), plus one `UserProfile` per agent and person (`:174`). The `Memory` schema has `agentId`, `entries`, `charBudget` and `needsConsolidation`, and no scope or project (`lib/Settings/hermiq_register.json:1426-1497`).
- The agent writes and reads memory through `hermiq.rememberMemory` (scope `agent` or `user`), `hermiq.recallMemory` and `hermiq.forgetMemory` (`lib/Mcp/HermiqToolProvider.php:389-460`). `FacadeToolInvoker::withAgentId()` injects the running agent's id into those three calls, because the tool ABI carries no agent identity (`lib/Service/Engine/FacadeToolInvoker.php:185-197`, `:1170-1186`).
- `recallEntries()` searches every `Memory` object with the agent's id (`lib/Service/MemoryService.php:418-440`). A second memory object per agent would today be read by every session and written at random, since `getMemory()` returns the first match.
- Every write is redacted before it is stored (`MemoryService::appendEntry()`, `:495-537`), and the agent-memory spec keeps tool governance inherited, not rebuilt.
- A session knows its agent and its trigger origin, and nothing about a project (`lib/Settings/hermiq_register.json:1575-1658`).

## What this change builds

1. A `scope` on `Memory`: `shared` (the existing object, set by a repair step) or `project` with a `projectKey` and a `projectName`.
2. `projectKey` on `Session`, chosen when the session starts, from the agent's projects or as a new one.
3. Recall in a project session reads the project memory and the shared memory, and labels each entry with where it came from.
4. Writes in a project session go to the project memory by default; `hermiq.rememberMemory` gains the scope `shared` for a fact that holds across projects. Outside a project, writes go to the shared memory as today.
5. The Memory page shows the shared memory and each project memory as tabs, with "Move to shared" and "Move to project" on an entry.

## Out of scope

- A project as an object of its own, with members and a page. A project here is a memory scope and a label on sessions. Linking a session to a zaak, a Deck board or a file is `session-entity-links`.
- Per-person memory. `UserProfile` already holds what an agent knows about one person, and is unchanged.
- Memory shared across different agents. Each agent keeps its own memory, as in hermiq ADR-003.
