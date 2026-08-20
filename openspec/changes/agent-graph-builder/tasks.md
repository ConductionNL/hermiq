# Tasks: agent-graph-builder

## Phase 1 — Linear chains + write-back (MVP)

- [ ] 1.1 Add the `agentflow` schema to `lib/Settings/hermiq_register.json`: `{ name, trigger, stateSchema, nodes[], edges[], limits }`; nodes `{ id, type, config, position }`, edges `{ id, source, target, when? }`. Unknown node `type` round-trips untouched.
- [ ] 1.2 Add the `agentflowrun` schema (graph-run record): `{ agentflow, correlationId, status, steps[], stateSnapshots[], usage }`.
- [ ] 1.3 Create `Hermiq\Service\Graph\GraphExecutor` (SPDX docblock): resolve start node, walk `agent-step`/`tool-call`/`condition`/`object-write` nodes along matching edges over a typed state built from the trigger object.
- [ ] 1.4 Node dispatch: `agent-step` → `Engine::processMessage`; `tool-call` → `FacadeToolInvoker`; `object-write` → `ObjectService::saveObject` (structured output back to the object's field / a new object, PUT-semantic); `condition` → boolean guard on state.
- [ ] 1.5 Per-hop governance seam: run kill-switch (`ScheduleService::isOrganisationEngaged`), `BudgetService`, `GuardrailPolicyService`, `ToolGrantResolver` before each node; write a redacted `AuditTrail` entry per hop.
- [ ] 1.6 `Hermiq\Listener\GraphRunRequestedListener` + `GraphRunRequestedJob` (siblings of the agent-run pair): start a graph from an OR event-catalog trigger with the event's object as input; enqueue async.
- [ ] 1.7 Checkpoint state to the `agentflowrun` object after each node (foundation for resume).

## Phase 2 — Control flow + reflection

- [ ] 2.1 `router` node — classify state with one `Engine` call → select exactly one outgoing edge.
- [ ] 2.2 `parallel` node — fan-out (sectioning) to N branches, or vote (same node ×N), then aggregate; implement via enqueued sub-jobs + a join/barrier on the checkpoint.
- [ ] 2.3 `evaluate-refine` node — generator ⇄ evaluator loop to a score/criteria or `limits.maxIterations`.
- [ ] 2.4 `loop`/map node — iterate a subgraph over a list or until a condition, hard-bounded by `limits.maxIterations` + a cycle guard; the executor MUST refuse to exceed the bound.
- [ ] 2.5 Per-graph and per-node budgets/iteration limits enforced by the executor (extend `BudgetService` scope to a graph run).

## Phase 3 — Durable HITL + orchestrator + run history

- [ ] 3.1 `approval` node — persist checkpoint, create an `ApprovalService` request, return; on approve/edit/reject resume from the checkpoint feeding the decision forward. Reuse the Approval inbox UI.
- [ ] 3.2 Durable resume — a paused `agentflowrun` (awaiting approval / external signal) resumes from its last checkpoint on the exact node, days/weeks later, without re-running completed nodes (at-most-once per node).
- [ ] 3.3 `orchestrator` node — a lead agent decomposes at runtime and delegates to worker sub-agents via `DelegationService`, bounded by existing `delegation.maxDepth`/`maxFanOut`/cycle refusal.
- [ ] 3.4 Run-history surface — `RunHistoryService`-style read over `agentflowrun`: ordered node timeline, state snapshots, time-travel/replay, tokens/cost.

## Phase 4 — Authoring canvas + compose both ways

- [ ] 4.1 `CnAgentGraphModal` (nc-vue) built on the shipped `CnFlowCanvas`: LLM node palette + per-node config panels; round-trip the `agentflow` OR object through OR's object API. Opened from a hermiq "Edit agent graph" surface.
- [ ] 4.2 Trigger palette driven by OR's `GET /api/flow/event-catalog` (fall back to object-CRUD when offline), mirroring the procest flow builder.
- [ ] 4.3 Expose an `agentflow` as a callable tool/skill (sub-graph) so one graph can invoke another.
- [ ] 4.4 Expose an `agentflow` as an OR `x-openregister-flows` action / NC-Flow operation so object-CRUD flows and agent graphs invoke each other (bidirectional composition).

## Verification

- [ ] V.1 Live-verify on Postgres 8080: an `object.updated` graph (agent-step → condition → object-write) fires on an object update, runs governed, and writes the result back — no unbounded loop.
- [ ] V.2 Live-verify a paused `approval` node resumes correctly after an inbox approval, without re-running prior nodes.
- [ ] V.3 `openspec validate agent-graph-builder --strict` passes.
