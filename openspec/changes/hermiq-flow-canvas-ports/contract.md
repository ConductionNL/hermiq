# Contract: hermiq-flow-canvas-ports

The cross-project interface here is not an HTTP API — it is the `CnGraphCanvas`
component contract in `@conduction/nextcloud-vue`, which 21 apps depend on. This
is the authoritative definition of the additive change.

## Added prop shape

A node MAY declare ports. A node that declares none behaves exactly as today.

```
node.ports?: Array<{
  id:     string   // unique within the node; echoed back on connect
  side:   'left' | 'right' | 'bottom'
  label?: string   // rendered beside the port and used as its accessible name
  kind?:  'in' | 'out'   // default: 'in' for left, 'out' for right
}>
```

Rules:

- `ports` absent or empty → the current single right-hand out handle. **No
  existing consumer changes behaviour.**
- `ports` present → exactly the declared ports are rendered; the default handle
  is not.
- A port with `kind: 'in'` cannot originate a connection.
- Port ids are opaque to the canvas. It stores nothing and interprets nothing.

## Modified event

`connect` gains the originating port. The existing payload keys are unchanged, so
a consumer ignoring `sourcePort` keeps working.

```
@connect  { source: string, target: string, sourcePort?: string }
```

`sourcePort` is present only when the connection originated from a declared port.

## Added prop

```
readOnly, connectable          // unchanged
portsConnectable?: boolean     // default true; false renders ports as indicators only
```

## Accessibility contract

- Every port is focusable and has an accessible name — its `label`, or a
  generated one naming the node and side.
- The keyboard connection path (`c` arm, `c` complete, `Escape` cancel) MUST let
  the author choose the origin port when a node declares more than one out-port.
  A port reachable only by mouse fails WCAG 2.1 AA 2.1.1.

## Sizing contract

A port renders at its declared size. Nextcloud's global `<button>` `min-height`
currently overrides it — measured `16x34` against a declared `16x16` — and the
component MUST defend against that, because stacked branch ports otherwise
overlap.

## Compatibility

- **Vue line**: 2.7 (`peer vue: ^2.7.0`). ADR-065 Decision 5 — no Vue 3 branch of
  this library exists.
- **Additive only**: no prop is removed, no event payload key is removed, no
  default changes.
- **Consumers**: hermiq is the first. ADR-065 records procest has **not** adopted
  `CnGraphCanvas`, so there is currently no second consumer to regress — which is
  a reason for care, not comfort: nothing else would catch a break.
