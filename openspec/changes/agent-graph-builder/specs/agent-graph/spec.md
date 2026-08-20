# agent-graph

## ADDED Requirements

### Requirement: Agent graphs are authored as OpenRegister objects

The system SHALL persist an agent graph as an `agentflow` OpenRegister object with
shape `{ name, trigger, stateSchema, nodes[], edges[], limits }`, where a node is
`{ id, type, config, position }` and an edge is `{ id, source, target, when? }`.
The definition SHALL round-trip unchanged through OpenRegister's object API
(`GET`/`PUT /apps/openregister/api/objects/...`): a node `type` the executor does
not understand MUST be preserved verbatim on save, so hand-editing and canvas
editing are interchangeable and forward-compatible.

#### Scenario: A graph round-trips through the object API

- **WHEN** an `agentflow` object with nodes and edges is saved and re-read
- **THEN** its `nodes`, `edges`, `trigger` and `limits` MUST be byte-identical
- **AND** a node whose `type` is unknown to the current executor MUST survive the save unmodified

### Requirement: A graph executor walks nodes over a typed state

The system SHALL provide `Hermiq\Service\Graph\GraphExecutor` that, given a trigger
object, builds a typed `state` from it, resolves the start node, and repeatedly
executes the current node then follows the single matching outgoing edge until a
terminal node is reached or a bound is hit. The executor MUST dispatch each node to
its backing service — `agent-step` → `Engine::processMessage`, `tool-call` →
`FacadeToolInvoker`, `orchestrator` → `DelegationService`, `approval` →
`ApprovalService`, `object-write` → `ObjectService` — and control nodes (`router`,
`condition`, `parallel`, `evaluate-refine`, `loop`) MUST evaluate against `state`.

#### Scenario: A linear chain runs to completion

- **WHEN** a graph `on-event → agent-step → condition → object-write` is executed
- **THEN** each node runs in edge order, the agent-step's output updates `state`, and the `object-write` node persists the mapped result via `ObjectService`

### Requirement: Loops and recursion are hard-bounded

The executor MUST enforce `limits.maxIterations` on every `loop` / `evaluate-refine`
node and MUST refuse to traverse an edge that would exceed it. The executor MUST
carry a cycle guard so a graph whose `object-write` re-triggers the same graph
cannot recurse without bound (mirroring the object-CRUD engine's `activeObjects`
guard). Exceeding a bound MUST stop the run and record it, never loop indefinitely.

#### Scenario: An evaluate-refine loop stops at the iteration bound

- **GIVEN** an `evaluate-refine` node with `limits.maxIterations = 3` whose evaluator never passes
- **WHEN** the graph runs
- **THEN** the node MUST execute at most 3 iterations and then stop the run with a bounded-out status

#### Scenario: A self-re-triggering graph does not loop forever

- **WHEN** an `object-write` node updates the object that triggered the graph
- **THEN** the re-entrant graph invocation MUST be suppressed by the cycle guard

### Requirement: State is checkpointed between nodes for durable resume

After each node completes, the executor MUST checkpoint the run's `state` and
position to an `agentflowrun` object. A run that pauses (for an `approval` node or
an external signal) MUST be resumable from its last checkpoint on the exact next
node — without re-running already-completed nodes — even after a long delay
(at-most-once per node).

#### Scenario: A paused run resumes without re-running prior nodes

- **GIVEN** a graph that has completed nodes A and B and is paused at an `approval` node
- **WHEN** the approval is granted later
- **THEN** the run MUST resume at the node after `approval`, and MUST NOT re-execute A or B

### Requirement: Human-in-the-loop is a first-class interrupt

An `approval` node MUST pause the run: the executor persists the checkpoint, creates
an `ApprovalService` request surfaced in the Approval inbox, and returns without
advancing. On approve, edit, or reject the run MUST resume, feeding the decision
into the next node; a rejected decision MUST halt the graph.

#### Scenario: An approval gates the rest of the graph

- **WHEN** execution reaches an `approval` node
- **THEN** the run MUST pause with a pending approval, and no downstream node runs until a human decides

### Requirement: Every node hop is governed

Before executing each node the executor MUST apply the existing governance gates —
organisation kill-switch, `BudgetService` (per-graph and per-node), and
`GuardrailPolicyService` / `ToolGrantResolver` for any tool the node uses — and MUST
write a redacted `AuditTrail` entry for the hop. A denied gate MUST stop the run at
that node, not silently continue.

#### Scenario: Budget exhaustion stops the run mid-graph

- **GIVEN** a graph run whose per-graph token budget is exhausted after node C
- **WHEN** the executor reaches node D
- **THEN** node D MUST NOT run and the run MUST stop with a budget status, with an `AuditTrail` entry recorded

### Requirement: Graphs trigger on Nextcloud events and write results back

A graph's `trigger` SHALL be an OpenRegister event-catalog id (e.g. `object.created`,
`object.updated`). A `Hermiq\Listener\GraphRunRequestedListener` MUST start the
matching graph with the event's object as the initial state, asynchronously. An
`object-write` node MUST write structured output back to an OpenRegister object
field or a new object via `ObjectService`, carrying all existing fields forward
(PUT-semantic) so unrelated data is never dropped.

#### Scenario: An object update runs a graph that writes back

- **WHEN** an object of the graph's schema is updated and matches the graph `trigger`
- **THEN** the graph runs with that object as input, and its `object-write` node persists the result back to the object without dropping other fields

### Requirement: Graphs are authored on the shared flow canvas

The system SHALL provide a `CnAgentGraphModal` built on the existing nc-vue
`CnFlowCanvas`, exposing the LLM node palette and per-node config panels, opened
from a Hermiq "Edit agent graph" surface. It MUST load and save the `agentflow`
object through OpenRegister's object API, and its trigger palette SHALL be driven by
`GET /api/flow/event-catalog` (falling back to the object-CRUD triggers when the
endpoint is absent).

#### Scenario: Authoring on the canvas persists a runnable graph

- **WHEN** an editor drags an `on-event` trigger and `agent-step` + `object-write` nodes onto the canvas and saves
- **THEN** an `agentflow` object is written that the `GraphExecutor` can run unchanged
