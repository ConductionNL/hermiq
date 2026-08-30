---
kind: code
depends_on:
  - or-flow-action-nodes
  - or-flow-connectivity-and-last-run
  - hermiq-flow-rename
---

# Proposal: hermiq-flow-canvas-ports

## Summary

Rebuild the flow canvas around action nodes with real connection ports: an
**in-port** on the left of every non-start node, an **out-port** on the right,
**one out-port per branch** on a routing node, and **loop ports** on the TOP
edge of a loop node whose body is a chain of ordinary nodes.

Plus the two surfaces the engine now exposes: a warning when saving a flow with
a dead end, and the flow's last run.

## Why

A node card that shows what it does, with ports you drag between, is what every
flow tool looks like — and after `or-flow-action-nodes` it is finally also what
the engine reads. Until now the canvas could not present that model without
lying about the stored document.

The connection ports matter beyond aesthetics. The current canvas has a single
handle on the right of every node with no left-hand counterpart, so an edge's
direction is only discoverable by reading the arrowhead after the fact, and a
branch is drawn as several lines leaving one indistinguishable point. Naming the
branch at the port is what makes a routing node readable at a glance instead of
requiring its config to be opened.

## What Changes

- **Node cards are actions.** The card shows the step's catalogue name and its
  key configuration; the line between nodes shows only its title.
- **In-port** on the left of every node that is not a start node. A start node
  has none, which is what makes it visibly a start.
- **Out-port** on the right, drag or keyboard to connect.
- **One out-port per branch** on a routing/switch node, each labelled with its
  branch, so a two-way route has two distinct, named origins.
- **Loop ports** on a loop node: a body-out on the TOP edge starting the loop body,
  and a body-in receiving the chained body back. The body is ordinary nodes, and
  the structure is an ordinary cycle — no new engine concept.
- **Dead-end warning modal on save**, listing the offending nodes, with the save
  proceeding when the author continues.
- **Last run** shown on the flow list and in the editor.

## What this replaces

The canvas built earlier in this line of work drew the step on the EDGE, because
that is what the engine then read. Node cards showed a place's name and role,
and the step type rode on a chip on the line. That inverts with the engine.

The parts that survive unchanged: orthogonal edge routing with trimmed
endpoints, the single-card chrome rule (the canvas wrapper owns the frame, the
body adds none), pan/zoom with keyboard-reachable zoom controls, and the
openable/closable sidebar.

## Accessibility

A port is a mouse affordance, so every connection operation needs a keyboard
path. `CnGraphCanvas` already provides one (`c` to start a connection, `c` to
complete, `Escape` to cancel). Per-branch ports extend that: with several
out-ports, the keyboard path must let the author choose which branch, not just
which target. This is WCAG 2.1 AA 2.1.1 and is a requirement, not a nicety.

## Impact

- **Affected specs**: `flow-authoring`
- **Affected code**: `FlowBuilder.vue`, `FlowSidebar.vue`, `flowEditor.js`,
  `RunFlowDialog.vue`, `FlowIndex.vue`
- **Affected library**: `CnGraphCanvas` needs per-node port declarations —
  today it renders exactly one handle per node, positioned right, with no in-port
  and no way for a consumer to declare several. This is a `@conduction/nextcloud-vue`
  change (Vue 2.7 line), not something a consumer can style its way out of.

## Capabilities

### Modified Capabilities
- `flow-authoring` — the canvas model and its connection affordances
