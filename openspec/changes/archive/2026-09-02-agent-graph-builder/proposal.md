---
kind: code
---

# Proposal: agent-graph-builder

> Archived 2026-09-02 (finish-agentflow-retirement), superseded. The graph builder
> this change described was built on the `agentflow` object store, and that store
> is retired: its runner, resolver and frontend are gone. Flow authoring lives in
> OpenRegister's native flow store and canvas instead (REQ-FA-001/002).

## Why

Hermiq can run a single governed agent turn triggered by a Nextcloud event and
write the result back to an OpenRegister object (`AgentRunRequestedListener` →
`FlowAgentRunService` → `ObjectService::saveObject(resultField)`). What it cannot
do is let a user **compose** several such steps — a multi-node graph with
branching, loops, evaluation and human checkpoints — the way the OpenRegister /
procest visual flow builder now composes object-CRUD flows.

The industry converged on two primitives for this: an **agentic loop** (an
augmented LLM that reasons → acts via a tool → observes → repeats under a bound)
as the unit of work, and a **graph** of those units with a typed shared state,
durable checkpoints between nodes, and interrupts for human-in-the-loop (the
LangGraph model; Anthropic's five composable workflow patterns — prompt-chaining,
routing, parallelization, orchestrator-workers, evaluator-optimizer). Hermiq
already owns almost every hard part of this as runtime services — it just has no
authored, user-composable definition on top of them. This change adds that layer,
reusing the nc-vue `CnFlowCanvas` shipped for object-CRUD flows so the authoring
experience is identical.

The mapping is near one-to-one, which is why this is an orchestration feature and
not an engine rewrite:

| Graph primitive (research) | Existing Hermiq service |
| --- | --- |
| Node = augmented-LLM loop (ReAct) | `Engine::processMessage` + `ToolLoop` |
| Tool / MCP call | `FacadeToolInvoker`, OR `ToolRegistryFacade` |
| Orchestrator → workers | `DelegationService` (`hermiq.delegateAgent`) |
| Human-in-the-loop interrupt | `ApprovalService` + Approval inbox |
| Stopping conditions / budget | `BudgetService`, `GuardrailPolicyService`, kill-switch, `ToolGrantResolver` |
| Trigger on a Nextcloud event | `AgentRunRequestedListener` ← OR event catalog / `x-openregister-flows` |
| Write result back to Nextcloud | `FlowAgentRunService` → `ObjectService::saveObject` + `AuditTrail` |

## What Changes

- **Graph definition as an OpenRegister object.** Add an `agentflow` schema to
  `lib/Settings/hermiq_register.json`: `{ name, trigger, stateSchema, nodes[],
  edges[], limits }`. A node = `{ id, type, config, position }`; an edge =
  `{ id, source, target, when? }`. Node `type` ∈ `agent-step`, `tool-call`,
  `router`, `condition`, `parallel`, `orchestrator`, `evaluate-refine`,
  `loop`, `approval`, `object-write` (and unknown types round-trip untouched,
  mirroring `x-openregister-flows`). The definition is hand- or canvas-editable and
  persists unchanged through OR's object API.

- **A graph executor** — `Hermiq\Service\Graph\GraphExecutor` — that generalises
  `FlowAgentRunService` from "run one agent" to "walk a graph." Given a trigger
  object it: builds a typed **state** from the object; resolves the start node;
  for each node dispatches to the backing service (`agent-step` → `Engine`,
  `tool-call` → `FacadeToolInvoker`, `orchestrator` → `DelegationService`,
  `approval` → `ApprovalService`, `object-write` → `ObjectService`, control nodes
  evaluate state); follows the matching outgoing edge; and **checkpoints the state
  between nodes** to an OR `agentflowrun` object so a run can pause (for an
  approval) and resume exactly where it left off. Every hop is wrapped by the
  existing gates — kill-switch, `BudgetService`, `GuardrailPolicyService`,
  per-graph/-node iteration and cycle bounds — and writes a redacted `AuditTrail`
  entry.

- **Bounded control flow.** `router` (classify state → one edge; Engine 1-call),
  `condition` (boolean guard → branch/stop), `parallel` (fan-out sections or vote
  N× then aggregate via jobs + join), `evaluate-refine` (generator ⇄ evaluator to
  a score/criteria or max-iters), and `loop`/map (iterate a subgraph over a list
  or until a condition). All loop/iterate nodes are hard-bounded by
  `limits.maxIterations` and a cycle guard; the executor refuses to exceed them.

- **Human-in-the-loop as a first-class interrupt.** An `approval` node pauses the
  run: the executor persists the checkpoint, creates an `ApprovalService` request,
  and returns. On approve/edit/reject the run resumes from the checkpoint, feeding
  the decision into the next node. This reuses the existing Approval inbox UI.

- **Triggers and write-back reuse what exists.** The `trigger` is an OR event
  catalog id (`object.created`, `object.updated`, …); a thin
  `GraphRunRequestedListener` (sibling of `AgentRunRequestedListener`) starts the
  graph with the event's object as input. `object-write` nodes write structured
  output back to OR object fields / new objects via `ObjectService`.

- **Authoring canvas — reuse `CnFlowCanvas`.** Add a `CnAgentGraphModal` (nc-vue,
  built on the shipped `CnFlowCanvas`) with the LLM node palette + per-node config
  panels, opened from a hermiq "Edit agent graph" surface; it round-trips the
  `agentflow` object through OR's object API. No new canvas engine.

- **Run history.** Add an `agentflowrun` schema recording the graph-run: ordered
  node steps, state snapshots (time-travel/replay), status, tokens/cost, and the
  correlation id — the graph-level analogue of today's `agent-run` `AuditTrail`.

- **Compose both ways (later).** Expose an `agentflow` as a callable tool/skill
  (sub-graphs) and as an OR flow action, so procest object-CRUD flows and hermiq
  agent graphs can invoke each other — the same bidirectional composition the flow
  builder now has with native Nextcloud Flow.

Out of scope: replacing the per-node execution engine (unchanged — OR `ChatService`
/ hermiq `Engine`), and any new LLM provider. Guiding principle (Anthropic): ship
the linear chain first; every node type must earn its added latency, cost and
failure surface over a single well-tooled agent call.
