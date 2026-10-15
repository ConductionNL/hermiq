# Design: memory-project-scopes

Kind: code. Size M. `MemoryService`, the three memory tools and their argument injection, a repair step, two optional properties on `Memory` and one on `Session`, and the Memory page. The schema properties are incidental to the code, so the change is `kind: code` under hydra ADR-032.

## Context at development db6b74dc

- `lib/Service/MemoryService.php:145-162` `getMemory()` (find by `agentId`, else create); `:214` `appendMemoryEntry()`; `:249` `consolidateMemory()`; `:418-440` `recallEntries()` searching every `Memory` with the agent's id; `:464` `forgetEntry()`; `:495-537` `appendEntry()` with redaction; `:736-758` `findMany()`.
- `lib/Settings/hermiq_register.json:1426-1497` `Memory`; `:1575-1658` `Session`.
- `lib/Mcp/HermiqToolProvider.php:389-415` `hermiq.rememberMemory` with `scope` enum `agent`, `user`; `:417-440` `hermiq.recallMemory`; `:441-460` `hermiq.forgetMemory`; `:720-725` dispatch.
- `lib/Service/Engine/FacadeToolInvoker.php:185-197` `MEMORY_TOOL_IDS` and why the agent id is injected; `:1170-1186` `withAgentId()`.
- `lib/Controller/MemoryController.php:98`, `:139`, `:345` memory reads and writes for the Memory page; routes `appinfo/routes.php:200-211`.
- `openspec/specs/agent-memory/spec.md`: "Memory objects with char-budget consolidation", "Agent self-service memory write tool", "Memory writes are redacted before persist", "Memory tool governance is fully inherited, not reimplemented".

## D1. A scope on the Memory object, with an explicit shared value

`Memory` gains `scope` (`shared` or `project`, default `shared`), `projectKey` (a short slug) and `projectName`. A repair step sets `scope: shared` on every existing `Memory` object, so the current memory becomes the shared layer without a data move.

`getMemory(agentId)` becomes `getMemory(agentId, projectKey = null)`: with no key it finds the object with `scope: shared`, with a key the one with that `projectKey`, and creates it when missing. Filtering on an explicit `shared` value, rather than on "no project key", avoids a filter on an absent property, whose behaviour in OpenRegister's search would have to be proven first.

Rejected: one `Memory` object with a `project` field on each entry. Budgets and consolidation work per object; a busy project would push the shared memory over its budget and trigger consolidation of facts that have nothing to do with it.

## D2. The session names its project

`Session` gains `projectKey`. The Chat page's start surface shows, for an agent that has project memories, a picker "Project" with the existing projects, "New project" and "No project". The key is fixed for the life of the session; a session without a key works on the shared memory only, as today.

## D3. Recall reads both, and says which

In a project session, `recallEntries()` searches the project `Memory` and the shared `Memory` of the agent, and nothing else. Each entry in the tool result carries `layer: project` or `layer: shared`. Outside a project it searches the shared memory only. Other projects' memories are never read.

This closes a trap in the current code: `recallEntries()` searches every `Memory` with the agent's id, and `getMemory()` returns the first match. With more than one `Memory` per agent, a session would read all projects and write to whichever object came first. Both methods must take the scope before the first project memory is created.

## D4. Writes go to the project unless told otherwise

`hermiq.rememberMemory`'s `scope` enum grows from `agent`, `user` to `agent`, `user`, `shared`. In a project session, `agent` writes to the project memory and `shared` writes to the shared memory; outside a project both write to the shared memory. The tool description tells the model: "Use shared only for a fact that holds for every project, such as a naming convention of the organisation."

`hermiq.forgetMemory` finds the entry by id in the project memory first, then in the shared memory.

The project key reaches the tools the way the agent id already does: `FacadeToolInvoker` injects `projectKey` from the session into the three memory tool calls (`withAgentId()` becomes `withRunIdentity()`). On the CLI transport, where the model calls hermiq's MCP endpoint in a separate request, the project key travels in the run token next to the agent id.

## D5. The Memory page shows the layers

`src/views/AgentMemory.vue` gets tabs "Shared" and one per project, each with its entries, budget and consolidation state. An entry's menu has "Move to shared" or "Move to project…". A move is a remove plus an append, so the append passes redaction again and both objects keep their budgets right. `GET /api/agents/{agentId}/memory` takes an optional `project` parameter, and `GET /api/agents/{agentId}/memory/projects` lists the projects.

## Declarative versus imperative

`Memory.scope`, `Memory.projectKey`, `Memory.projectName` and `Session.projectKey` are declared in `lib/Settings/hermiq_register.json` with a register version bump. Choosing the layer is request-time logic in `MemoryService`, which hydra ADR-031 leaves imperative.

## Seed data

For the demo agent "Beleidsondersteuner" of Gemeente Utrecht: the shared memory holds "Beleidsstukken gebruiken de huisstijl van de gemeente, met B1-taalniveau." A project memory `omgevingsvisie-2040` ("Omgevingsvisie 2040") holds "De raad behandelt het ontwerp op 14 november 2026." A project memory `aanbesteding-wagenpark` holds "Het perceel elektrische bestelbussen is gegund aan de laagste inschrijver." Demo data only, behind the dev-mode flag (hydra ADR-069, decision 5).

## Risks

- The repair step misses an object and a second shared memory appears. Mitigation: the step counts `Memory` objects without `scope` before and after and fails loudly when any remain.
- The model files a general fact under the project. Mitigation: "Move to shared" on the Memory page, and recall in other projects never shows it, so the cost of the mistake is limited to that project.
