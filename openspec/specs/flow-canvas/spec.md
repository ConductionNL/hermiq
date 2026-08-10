---
status: done
---

# flow-canvas Specification

## Purpose
Defines how Hermiq's flow editor DRAWS a flow: what a node card says, what a connection says, which ports a node exposes and why, and how the engine's connectivity verdict and run history reach the author. The engine itself is OpenRegister's (`openspec/specs/flow-engine/spec.md` there, ADR-065); this spec is only about the surface.

## Requirements

### Requirement: A node card names the STEP it runs

`or-flow-action-nodes` inverted which half of the Petri net carries behaviour: a NODE holds the step type and config and is the thing that runs, an EDGE is sequence. The card MUST therefore lead with the step's catalogue name, and MUST NOT lead with the node id or a place role.

The card MUST also show one line of the configuration the step actually reads, so two nodes of the same type are told apart without opening either. Authoring annotations (keys beginning with `$`) MUST be skipped — they are documentation the engine never reads.

A node with no `type` MUST be called out as a warning rather than drawn as an ordinary card, using more than colour to say so (WCAG 1.4.1). The engine refuses such a document, and drawing it normally is how it stayed invisible.

#### Scenario: A migrated flow shows what each node does
- **GIVEN** a flow whose 16 nodes each carry a `type` and whose edges carry none
- **WHEN** the canvas renders it
- **THEN** every card MUST show that node's step name
- **AND** the phrase "No step type" MUST appear nowhere on the canvas

### Requirement: A connection shows only its own title

An edge MUST render its `title` and nothing else. It MUST NOT render a step name: the step moved to the node, so reading `type` off the edge yields nothing on every correctly migrated document.

An edge with no title MUST draw no chip at all, rather than an empty one.

#### Scenario: The place labels survive as line labels
- **GIVEN** a migrated flow in which the old place names became edge titles
- **WHEN** the canvas renders it
- **THEN** those words MUST appear on the lines
- **AND** no line MUST read "No step type"

### Requirement: Role is expressed by the ABSENCE of a port

Every node that is not a start MUST expose an in-port on its left. Every node that does not end the flow MUST expose an out-port on its right. A start therefore has NO in-port and an exit has NO out-port, and that absence — not colour — is what identifies them, so the distinction survives greyscale and does not depend on telling two hues apart (WCAG 1.4.1). Colour MAY be used as redundant encoding.

A node ends the flow if EITHER it declares `exit: true` OR its step type is terminal — the same two answers, OR-ed, that the engine accepts.

#### Scenario: The ends of the flow are identifiable without colour
- **GIVEN** a flow with one start and three sinks, one marked `exit: true` and two carrying a terminal step type
- **WHEN** the canvas renders it
- **THEN** the start MUST have no in-port
- **AND** each of the three sinks MUST have no out-port, and MUST still have an in-port

### Requirement: A routing node exposes one NAMED out-port per branch

A routing node MUST derive its branches from the keys the ENGINE reads — `config.rules[].output` plus `config.default` — and MUST render one out-port per distinct branch, each labelled with that branch name. This is what makes a two-way route readable without opening its configuration.

`config.routes` MUST NOT be honoured. It is the most common way to author the node wrong, and drawing ports for it would make a broken flow look correct.

A loop node's body ports MUST sit on the TOP edge, so the repeated nodes read as a group rather than as a detour in the left-to-right run of the main chain.

#### Scenario: A gate shows both of its branches by name
- **GIVEN** a routing node with one rule whose `output` is `work` and a `default` of `idle`
- **WHEN** the canvas renders it
- **THEN** it MUST expose exactly two out-ports
- **AND** they MUST be named `work` and `idle`

### Requirement: Connecting from a branch port records the branch

Dragging or keyboard-connecting from a named out-port MUST record which branch
the connection leaves, as `edge.fromExit`. That is the field
`FlowTokenRouter::placesForExit()` matches on when it decides which outgoing
edges a token reaches, so an edge without it is not routed by branch at all.

The branch MUST be part of an edge's identity for duplicate detection: two
branches of one routing node may legitimately lead to the same node, and keying
the check on `from`/`to` alone silently refuses the second.

An unbranched exit MUST NOT write the key at all, rather than writing an empty
one the engine has to read and ignore on every edge of every flow.

#### Scenario: Two branches of one gate reach the same node

@e2e exclude authoring round-trip — asserting the stored document needs a save against a live engine; the routing half is covered by OpenRegister's FlowLogicTest

- **GIVEN** a routing node whose branches are `passed` and `failed`
- **WHEN** the author connects BOTH to the same downstream node
- **THEN** two edges MUST exist, carrying `fromExit: "passed"` and `fromExit: "failed"`
- **AND** neither MUST be refused as a duplicate of the other

### Requirement: An edge whose branch disappears is shown, never deleted

Editing a routing node's rules can remove a branch that edges already leave
from. Those edges MUST NOT be deleted: silently removing a connection the author
drew, because a value changed in a different panel, loses work with no trace and
leaves them unable to tell an edge they forgot from one the editor took away.

Such an edge MUST be drawn as unassigned and MUST say so in words, not by colour
alone (WCAG 1.4.1). It MUST draw its label even when it has no title, since a
blank line is exactly where the state would otherwise hide.

The branch list used to detect this MUST be the same one the ports are drawn
from, so an edge can never be marked unassigned while the port it points at is
still on screen.

#### Scenario: Removing a rule leaves its edge visible and named

@e2e exclude requires editing a routing node's config and re-rendering, which is a save round-trip; the derivation is unit-level

- **GIVEN** an edge leaving the `work` branch of a routing node
- **WHEN** the author removes the rule that produced `work`
- **THEN** the edge MUST still exist
- **AND** it MUST render as unassigned, naming the branch that no longer exists

### Requirement: The keyboard can choose which branch to connect from @e2e exclude keyboard interaction against a live canvas; the port arithmetic is asserted in the port tests

Where a node exposes several out-ports, pressing the connect key again on the
source MUST step through them, and the armed port MUST be identifiable — ringed
and exposed as `aria-pressed` — rather than remembered. Dragging picks a branch
by pointing at it; without this the keyboard reaches only the first, leaving
every other branch mouse-only (WCAG 2.1.1).

On a node with a single exit the behaviour MUST be unchanged: the repeat runs
off the end and cancels.

#### Scenario: The connect key walks a gate's branches

@e2e exclude keyboard interaction against a live canvas

- **GIVEN** a focused routing node with branches `work` and `idle`
- **WHEN** the connect key is pressed twice
- **THEN** the armed port MUST be `idle`, ringed and `aria-pressed`
- **AND** completing on another node MUST record `fromExit: "idle"`

### Requirement: Saving warns about a dead end; it never refuses

A save MUST succeed and then report the engine's connectivity verdict, taken from the save response's `warnings` (reason `node-dead-end`). The editor MUST NOT recompute that verdict itself — recomputing lets the dialog and the engine disagree.

The dialog MUST state that the save SUCCEEDED before describing the problem: an author who reads "cannot finish" and assumes rejection will redo the work.

#### Scenario: A half-wired flow is stored and explained

@e2e exclude pure-backend API contract — the warning is produced by OpenRegister's save response, covered by FlowDeadEndTest and by Newman; the browser only renders what that response already decided

- **GIVEN** a flow with a node that has no outgoing edge and does not end the flow
- **WHEN** the author saves
- **THEN** the flow MUST be stored
- **AND** a dialog MUST name the offending nodes and say the changes are saved

### Requirement: The flow list shows last run and status

The list MUST show when each flow last finished and its own status. A flow that has never run MUST read "Never" rather than a dash — a dash reads as "unknown", and never-run is a different fact. A flow with no status MUST show nothing, because a null status means "no verdict", not "ok".

#### Scenario: A refused flow is distinguishable from one nobody triggered

@e2e exclude pure-backend API contract — the distinguishing fields (status, lastRunAt) are written by the engine on a refused dispatch and covered by OpenRegister's unit tests and Newman

- **GIVEN** a flow refused for a dead end, which therefore has no run at all
- **WHEN** the list renders
- **THEN** its status MUST read that it will not run
- **AND** its last run MUST read "Never"
