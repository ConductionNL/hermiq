# Design: hermiq-flow-rename

## Scope of the rename

Eleven files under `src/` name "graph", plus the manifest and the registry:

| Surface | From | To |
| --- | --- | --- |
| route | `/graphs`, `/graphs/:id` | `/flows`, `/flows/:id` |
| page id | `GraphIndex`, `GraphDetail` | `FlowIndex`, `FlowDetail` |
| component | `GraphBuilder`, `GraphSidebar`, `GraphIndex`, `RunGraphDialog` | `FlowBuilder`, `FlowSidebar`, `FlowIndex`, `RunFlowDialog` |
| store | `useGraphEditorStore`, `graph`, `graphs` | `useFlowEditorStore`, `flow`, `flows` |
| CSS block | `.graph-builder__*`, `.graph-sidebar__*` | `.flow-builder__*`, `.flow-sidebar__*` |

`lib/Flow/HermiqAgentNode.php` and `HermiqWorkloadNode.php` are untouched: they
are step-type implementations registered with OpenRegister's node registry and
already speak the engine's vocabulary.

## The old routes redirect

`/graphs` and `/graphs/:id` redirect rather than 404. These URLs are pasted into
Hydra issues, run logs and PR bodies; the Hydra sequencer's own flow URL appears
in tooling output. One route entry is cheaper than a dead link, and a redirect
is honest about what happened where a 404 is not.

## Why the CSS classes rename too

A `.graph-builder__node` inside `FlowBuilder.vue` is exactly the kind of residue
that makes a later reader ask whether there used to be two components. The
e2e spec selects on these class names, so they are load-bearing and must move
together with their tests in one commit.

## Test co-movement

`tests/e2e/graph-builder-flow-dialect.spec.ts` selects on `.graph-builder__edge`,
`.graph-builder__node-label`, `.graph-builder__sidebar-toggle` and
`[data-testid="graph-step-pane"]`. Renaming the component without the spec turns
a passing suite into a suite that cannot find anything — which reads as "the
canvas broke", not "the selectors moved". Spec and component rename in the same
commit.

## Seed Data

Not applicable (ADR-001). A rename; no schema is introduced or modified.

## Declarative-vs-imperative decision

Not applicable (ADR-031). No behaviour changes — no lifecycle, aggregation,
derived field, notification, relation or widget is introduced. This is naming
and routing only.

## Alternatives considered

**Keep "graph" as a display label, rename only the code.** This is what the
current state already is, and it is what produced a duplicate object store, an
unregistered run endpoint and a blank canvas. The word is the problem.

**Rename without redirects.** Cheaper by one route entry, at the cost of every
already-shared flow URL. Not worth it.
