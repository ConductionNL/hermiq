# Design: hermiq-flow-canvas-ports

## Ports are a CnGraphCanvas change, not a hermiq one

`CnGraphCanvas` renders exactly one handle per node:

```
<button v-if="connectable && !readOnly" class="cn-graph-canvas__handle" …>
```

positioned `right: -8px; top: 50%`. There is no in-port, and no way for a
consumer to declare more than one. Hermiq cannot style its way to per-branch
ports because the elements do not exist.

So the canvas gains a per-node port declaration — a list of `{id, side, label}`
— and emits `connect` with the originating port id. Hermiq supplies the list
from the node's step type: one out-port normally, one per branch for a routing
node, plus loop ports for a loop node.

This is a `@conduction/nextcloud-vue` change on the **Vue 2.7** line
(`peer vue: ^2.7.0`, 21 dependent apps — ADR-065 Decision 5). It is additive:
a node with no declared ports keeps today's single right-hand handle, so no
existing consumer changes behaviour.

**Measured defect to fix while there**: the handle is declared `16x16` with
`border-radius: 50%` and renders **16x34** — Nextcloud's global button
`min-height` overrides it, so every port is a bar rather than a dot. Any port
work that does not set an explicit height inherits this.

## Ports carry meaning, so they carry role

| Port | Side | Present when |
| --- | --- | --- |
| in | left | the node has at least one possible predecessor — i.e. always except a start node |
| out | right | the node is not an exit node |
| branch out | right, stacked | the step type declares branches; one per branch, labelled |
| loop body-out | top | the step type is a loop |
| loop body-in | top | the step type is a loop |

**Loop ports sit on the TOP edge, not the bottom** (decided 2026-08-09). The
proposal said bottom; `CnGraphCanvas` positions ports on `left`, `right` or
`top` only, and its own rationale is that the nodes a loop repeats read as a
visible sub-list above the chain rather than as a detour hanging beneath it.
Following the renderer rather than restating an intent it cannot express.


A start node having **no in-port** is what makes it visibly a start; an exit node
having **no out-port** is what makes it visibly an end. That is stronger than
colouring a port, because it removes the affordance rather than annotating it —
you cannot drag a connection out of an exit node at all.

Role still also drives colour (success on start, error on exit), which is the
redundant-encoding rule: colour alone fails for a colour-blind author, and shape
alone is subtle.

## Branches come from the step type, not from the drawn edges

A routing node's out-ports must be known BEFORE any edge is drawn — otherwise
there is nothing to drag from. So the branch list is a property of the step's
configuration (`openregister.route`'s `rules[]`, `openregister.switch`'s cases),
read from the node's config, not inferred from existing edges.

Consequence worth stating: editing the config changes the port set, and an edge
whose branch disappears is orphaned. Those edges are shown as unassigned rather
than deleted — silently dropping a user's connection because they renamed a rule
is the kind of quiet data loss ADR-065 records `CnEditFlowsModal` committing.

## Loops are ordinary cycles

The loop ports are an authoring affordance over ordinary edges: body-out is an
edge to the first body node, body-in is the edge from the last body node back to
the loop node. The stored document is a cycle, which the engine already bounds
with `limits.maxIterations`.

No new engine concept, no new stored structure. Drawing them at the bottom
separates "what happens inside the loop" from "what happens after it", which is
the distinction a single right-hand port cannot express.

## Keyboard parity

`CnGraphCanvas` has a keyboard connection path (`c` to arm, `c` on the target to
complete, `Escape` to cancel). With several out-ports, arming must also choose a
port: pressing `c` on a node with more than one out-port opens a port chooser
rather than assuming the first. WCAG 2.1 AA 2.1.1 — a port that only a mouse can
originate from is a feature keyboard authors do not have.

## Seed Data

Not applicable (ADR-001). Flows are native OpenRegister rows, not objects; this
change introduces no schema.

## Declarative-vs-imperative decision

Not applicable (ADR-031). This is canvas rendering and interaction. It defines
no lifecycle, aggregation, derived field, notification, relation or dashboard
widget — the flow's behaviour is the engine's, and this change only draws it.

## Alternatives considered

**Style the existing single handle into several.** Impossible — one element.

**Infer branch ports from drawn edges.** Circular: you cannot draw the edge
until the port exists.

**Keep one out-port and label the lines instead.** This is what the pre-inversion
canvas did, and it is why a routing node was unreadable without opening its
config: several lines left one point and only their far ends said which was
which.
