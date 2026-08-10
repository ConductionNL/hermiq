# Tasks: hermiq-flow-canvas-ports

- [x] `CnGraphCanvas`: per-node port declarations `{id, side, label}`, rendering
      left / right / top ports, emitting `connect` with the originating port
      id. Additive — a node declaring no ports keeps today's single right handle.
- [x] `CnGraphCanvas`: fix the handle's measured size. Declared `16x16` round,
      renders `16x34` because Nextcloud's global button `min-height` wins.
- [ ] `CnGraphCanvas`: keyboard connection chooses the origin port when a node
      has more than one (WCAG 2.1 AA 2.1.1).
      **Implemented in nc-vue** (`feat/graph-canvas-keyboard-port`): pressing the
      connect key again on the source steps through its exits, and the armed
      port is ringed and `aria-pressed`. Left unticked HERE because hermiq
      consumes the PUBLISHED library — the behaviour does not reach this app
      until that lands in a release and the pin moves. Ticking it now would
      record a capability the app does not have.
- [x] Node cards render the step's catalogue name plus key config; a node with
      no step type is called out as a warning, not drawn as an ordinary node.
- [x] In-port on every non-start node; out-port on every non-exit node. Role is
      expressed by the ABSENCE of the port, with colour as redundant encoding.
- [x] Branch out-ports derived from the node's configuration (route `rules[]`,
      switch cases), labelled per branch.
- [x] An edge whose branch disappears after a config edit is shown as
      unassigned, never silently deleted.
- [x] Loop body-out / body-in ports on the TOP edge; the body is ordinary nodes and
      the stored result is an ordinary cycle.
- [x] Save warning modal listing dead-end nodes, allowing the author to continue.
- [x] Last run (status, message, time) on the flow list and in the editor,
      distinguishing a refused flow from one that has never run.
- [x] Keep what already works: orthogonal edge routing with trimmed endpoints,
      one card frame (canvas wrapper owns it, body adds none), pan/zoom with
      keyboard-reachable zoom controls, openable/closable sidebar.
- [x] e2e coverage for ports: start node has no in-port, exit node has no
      out-port, a two-branch route has two labelled out-ports, a loop body
      round-trips, the save warning appears and continuing stores the flow.

## Acceptance criteria

- A node card says what it does; a line says only where it goes.
- Start and exit nodes are identifiable without reading colour.
- A routing node's branches are readable without opening its configuration.
- Every connection operation is reachable from the keyboard.
- Saving a half-wired flow warns and proceeds; the list shows why a refused flow was refused.

## Quality checklist

- `npm run lint`, `npm run stylelint`, `npm run check:specs` pass.
- The nc-vue change targets the Vue 2.7 line and breaks no existing consumer.
- Depends on `or-flow-action-nodes` (nodes carry steps),
  `or-flow-connectivity-and-last-run` (exit definition, warning, last run) and
  `hermiq-flow-rename` (component names).
