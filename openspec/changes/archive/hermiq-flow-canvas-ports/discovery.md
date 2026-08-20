# Discovery: hermiq-flow-canvas-ports

Three open questions, all about `CnGraphCanvas` — a shared Vue 2.7 component
with 21 dependent apps. Each must be answered before the canvas work starts,
because each can invalidate the port design.

## Q1 — Can CnGraphCanvas express multiple ports per node at all?

**Today it cannot.** It renders exactly one handle:

```
<button v-if="connectable && !readOnly" class="cn-graph-canvas__handle" …>
```

fixed at `right: -8px; top: 50%`. There is no in-port and no per-node
declaration, so hermiq cannot reach the design by styling. The component must
gain a port API.

**To resolve:** design the additive prop (`node.ports: [{id, side, label}]`) and
confirm a node that declares none keeps today's single right-hand handle
byte-for-byte. Existing consumers — procest is the intended one, though ADR-065
records it has **not** adopted the canvas — must not change behaviour.

**Risk if wrong:** a breaking change to a component 21 apps depend on.

## Q2 — Does the port render at its declared size?

**Measured: no.** The handle is declared `16x16` with `border-radius: 50%` and
renders **16x34** on a live instance — Nextcloud's global `<button>` `min-height`
overrides it, so every port is a bar rather than a dot.

**To resolve:** confirm the fix (explicit `height`/`min-height`) holds inside
Nextcloud's cascade on NC34, in a browser, not jsdom. ADR-065's own verification
note insists on this distinction for exactly this component.

**Risk if wrong:** stacked branch ports inherit the stretch and overlap each
other, making a two-branch node unreadable — the defect this change exists to fix.

## Q3 — Where does the branch list come from?

A routing node's ports must exist **before** any edge is drawn, so they cannot be
inferred from edges. They must come from the node's config — but the config shape
differs per step type: `openregister.route` reads `rules[]`, `openregister.switch`
reads cases, and the catalogue carries **no config schema** (established in
`or-flow-preflight`: config vocabulary is declared per node via
`IFlowNodeConfigKeys`, which lists keys, not structure).

**To resolve:** decide whether branch enumeration is (a) hermiq reading known
config shapes per type — works now, breaks for any step type it does not know;
or (b) a new engine-side declaration of a step's branches — correct, but widens
scope into OpenRegister.

**Risk if wrong:** a contributed routing step from another app renders with one
port and cannot be branched at all, silently.

## Recommendation

Answer Q1 and Q2 first — they are self-contained and cheap. Q3 is the one that
can widen scope, and it should be settled explicitly rather than absorbed: if the
answer is (b), it belongs in the OpenRegister chain, not here.
