# Test Plan: hermiq-flow-canvas-ports

## What could go wrong

The canvas is the surface where "renders" and "renders as nothing" are the same
to every layer below the DOM. That is not hypothetical here — it already
happened: with the store holding 17 nodes and 16 edges, the canvas drew **zero**
edges and 17 blank cards, and every API, store and unit assertion stayed green.

So the assertions are measurements of the rendered DOM and computed style, not
of component state.

## Coverage

| Risk | Test | Kind |
| --- | --- | --- |
| ports do not render | a start node has 1 port, a two-branch route has 3 (1 in, 2 out), a loop node has 4 | e2e |
| ports render but stretch | port `offsetWidth`/`offsetHeight` both 16; stacked branch ports do not overlap (bounding boxes disjoint) | e2e |
| role not readable without colour | start node has no in-port; exit node has no out-port and cannot originate a drag | e2e |
| branch not readable | each branch port exposes its branch name as its accessible name | e2e |
| connection loses its branch | dragging from branch port `idle` produces an edge recorded against `idle` | e2e |
| silent data loss on config edit | removing branch `idle` leaves its edge present and marked unassigned | e2e + unit |
| loop stores a novel structure | a loop body round-trips to ordinary cyclic edges and the document validates against the engine | integration |
| keyboard cannot branch | keyboard connection from a two-port node asks which branch and completes on the chosen one | e2e |
| dead-end warning missing | saving a half-wired flow warns naming the node, and continuing stores it | e2e |
| refused flow looks never-run | a refused flow shows error status + message in the list | e2e |
| existing behaviour regressed | edge routing, single-card chrome, pan/zoom, sidebar open/close still pass | e2e |
| nc-vue break | a node declaring NO ports renders exactly today's single right handle | unit |

## Positive controls

Two, both mandatory before a green run is believed:

1. **The port assertions must be shown able to fail.** Render a node with no
   ports and confirm the port-count assertions go red. A canvas that renders
   nothing satisfies every `toHaveCount(0)`, and counting is the whole test here.
2. **The engine must reject a deliberately broken loop.** Round-tripping a loop
   body proves nothing if the validator accepts everything; feed it a cycle with
   a dangling endpoint and confirm `POST /api/flow/validate` refuses it.

## Fixture

The live Hydra sequencer (17 nodes, 16 edges, 3 splits, 3 paths converging on
one exit) after migration. It is the flow the original defects were reported
against and it exercises splits, merges and terminal steps in one document.

## Environment note

The suite must dismiss hermiq's first-run dialogs ("Support Hermiq", "Set up
this app") before asserting. They are modal and cover the canvas, so without
that every assertion fails as "not visible" and reads as a canvas defect.
