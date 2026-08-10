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

### Requirement: Selecting a run replays its path on the canvas

Selecting a run in the Runs tab MUST mark, on the canvas, the connections that
run followed and the nodes it touched. Marked in green, and the marking MUST
also be carried by something other than hue — a run's path drawn only in colour
is unreadable to a reader who cannot distinguish it and disappears in print
(WCAG 2.1 AA 1.4.1).

Beside each followed connection there MUST be a control opening the JSON that
passed along it — the output of the node it left, which is the input of the node
it reaches. That payload is the thing an operator actually needs when a flow
"ran fine" and produced the wrong answer, and it is invisible in a status.

A connection the run did NOT take MUST remain visibly unmarked. The value of a
replay is the contrast: which way it went is only information if the ways it did
not go are equally legible.

A run whose record has no path MUST say so rather than drawing an empty canvas,
which would read as "it did nothing".

#### Scenario: A run's path is legible on the canvas
- **GIVEN** a completed run that took one branch of a route
- **WHEN** it is selected in the Runs tab
- **THEN** the connections it followed and the nodes it touched MUST be marked
- **AND** the branch it did not take MUST remain unmarked
- @e2e exclude needs a seeded flow with a recorded branching run

#### Scenario: The payload on a connection is inspectable
- **GIVEN** a followed connection
- **WHEN** its JSON control is used
- **THEN** the items that passed along it MUST be shown
- @e2e exclude needs a recorded run with items

### Requirement: The node palette is a card per type, and the card explains itself

Each palette entry MUST be a card carrying the node's name, the beginning of the
engine's own description, and the icon of the app that CONTRIBUTED the type in
front of the name. Cards MUST be colour-coded by role — `trigger`, `step`,
`end` — and the role MUST also be readable without colour.

The role MUST be read from the `role` OpenRegister ships on every palette entry,
which it decides from the markers a node implements. The editor MUST NOT infer
it from the node's id: a convention like `id.includes('.trigger-')` recognises
OpenRegister's own three and silently badges a start or stop node contributed by
any other app as a step.

One vocabulary, everywhere. `trigger` / `step` / `end` are the words in the node
ids, in the engine's interfaces, in the palette API and on the badge. The editor
must not introduce a fourth set — it has previously shown "starts"/"ends" over
role keys `trigger`/`terminal` over ids `trigger-*`/`stop`.

The list MUST be ordered `trigger`, then `step`, then `end`: the order a flow is
read in, so the list itself teaches the shape of one.

A node on the CANVAS MUST be drawn from its declared role too. Drawing it from
graph position — "nothing points at this node, so paint it as a start" — colours
an unconnected step as an entry point, which makes a flow that can never fire
look finished.

An editor MUST resolve a stored node's type through the `aliases` the palette
publishes. A flow saved before a node was renamed still names the old id, and
without the aliases it renders a raw type id where the node's name belongs while
the engine runs it perfectly well.

### Requirement: A flow MUST have a trigger and an end

The editor MUST show an ERROR banner when a flow carries no trigger node, no end
node, or neither. Both facts are invisible on the canvas: a flow with no trigger
sits there fully drawn and never runs, and no run record appears to say why.

An error rather than a warning, because unlike a half-wired graph there is no
version of a working flow that has no trigger. It MUST NOT block the save — the
author is mid-build, and refusing would force them to build in an order where
the document is never incomplete.

When BOTH are missing, ONE message MUST name both. An author told about the
trigger, who adds it and is then told about the end, has been made to do the
work twice.

Both MUST be decided by node TYPE, never by graph position, and an end may
finish in success or in error — both are deliberate ends.

#### Scenario: A flow with no trigger says so
- **GIVEN** a flow with steps and an end node but no trigger node
- **WHEN** its Flow tab is opened
- **THEN** an error banner MUST name the missing trigger
- **AND** saving MUST still be possible
- @e2e exclude covered by the canvas's component tests

#### Scenario: An empty flow is not nagged
- **GIVEN** a flow with no nodes at all
- **WHEN** its Flow tab is opened
- **THEN** no missing-ends banner MUST be shown, because a blank canvas is
  missing both by definition and the author can see that
- @e2e exclude covered by the canvas's component tests

The provider's icon is not decoration. The catalogue mixes types from
OpenRegister, openconnector and hermiq, and which app a node comes from decides
where its configuration is documented and who to ask when it misbehaves.

Selecting a card MUST expand it to the full description in place, rather than
navigating or opening a dialog: the author is choosing between types, and a
choice is made by comparison, which a modal over the list prevents.

#### Scenario: A palette card names its provider
- **GIVEN** a catalogue containing types from more than one app
- **WHEN** the palette renders
- **THEN** each card MUST show the contributing app's icon before the name
- @e2e exclude covered by the palette's component tests

#### Scenario: A card expands in place
- **GIVEN** a palette card with a long description
- **WHEN** it is selected
- **THEN** the full description MUST be shown without leaving the list
- @e2e exclude covered by the palette's component tests

### Requirement: The canvas offers per-element actions, reachable two ways

Right-clicking a node MUST offer edit, delete and copy; right-clicking a
connection MUST offer edit and delete. Every one of those actions MUST also be
reachable without a pointer, because a context menu is a pointer gesture and
cannot be the only route to an action (WCAG 2.1 AA 2.1.1).

Right-clicking the EMPTY canvas MUST offer paste and "add note". Both need a
POINT — where the node lands, where the note is pinned — and the background is
the only place that carries one.

Copying a node MUST copy its type and configuration and MUST NOT copy its
connections. A copy that arrived pre-wired would silently add paths to a flow
the author did not draw. Copy MUST place the node on a clipboard rather than on
the canvas, and paste MUST place it where the menu was raised: a copy that
appears at a fixed offset is one the author then has to drag.

The keyboard route is on the CANVAS, where the selection is: Delete or Backspace
MUST remove the selected node or connection, and Enter MUST open its editor.
Both MUST be ignored while the author is typing — a Backspace that deleted the
selected node mid-correction is the worst possible reading of the key.

#### Scenario: A node's context menu offers the three actions
- **GIVEN** a node on the canvas
- **WHEN** it is right-clicked
- **THEN** edit, delete and copy MUST be offered
- @e2e exclude covered by the canvas's component tests

#### Scenario: Every context action has a keyboard route
- **GIVEN** a selected node
- **WHEN** the keyboard is used
- **THEN** edit, delete and copy MUST all be reachable
- @e2e exclude covered by the canvas's component tests

#### Scenario: The empty canvas offers paste and add-note
- **GIVEN** a canvas with nothing under the pointer
- **WHEN** it is right-clicked
- **THEN** paste and "add note" MUST be offered, and paste MUST be disabled
  when nothing has been copied
- @e2e exclude covered by the canvas's component tests

#### Scenario: Delete removes the selection, and never while typing
- **GIVEN** a selected node and an author typing in a note
- **WHEN** Backspace is pressed inside the note
- **THEN** the selected node MUST NOT be deleted
- @e2e exclude covered by the canvas's component tests

### Requirement: A note reads as paper, not as a node

An annotation MUST be drawn as a sticky note — a warm sheet with its own ink,
square-ish corners and a shadow — and MUST NOT be drawn as a node card. It
belongs to no node and no edge, the engine never sees it, and a note that looks
like a step invites an author to wire it up.

The canvas draws its own card around everything it positions, so a note MUST
suppress that card rather than render inside it, and the node body MUST NOT
render for an annotation at all.

#### Scenario: An annotation draws one thing, not two
- **GIVEN** a note pinned to the canvas
- **WHEN** it is drawn
- **THEN** exactly one element MUST appear — the sheet — with no node card
  around it and no second card beneath it reading "No step type"
- @e2e exclude covered by the canvas's component tests

### Requirement: Auto-sort arranges the drawing and never the flow

The Flow tab MUST offer an auto-sort that lays the graph out so it reads in one
direction, following the connections from the entry points onward.

It MUST change only coordinates. Not one node, connection, type, configuration
or branch target may differ before and after — a layout button that could alter
behaviour is one nobody dares press on a flow that works.

It MUST be undoable in the ordinary way: it marks the flow dirty and is
discarded like any other unsaved edit.

Nodes the layout cannot place — an unreachable island, a cycle with no entry —
MUST still be placed somewhere visible, never dropped or stacked out of view.

#### Scenario: Auto-sort moves nodes and changes nothing else
- **GIVEN** a flow with nodes at arbitrary positions
- **WHEN** auto-sort runs
- **THEN** every node's `x`/`y` may differ
- **AND** the node list, connection list, types, configurations and branch
  targets MUST be identical
- @e2e exclude a document invariant — covered by the layout function's tests

#### Scenario: An unreachable node is still placed
- **GIVEN** a flow containing a node no connection reaches
- **WHEN** auto-sort runs
- **THEN** that node MUST be placed somewhere visible
- @e2e exclude covered by the layout function's tests

### Requirement: A link into another app opens in a new tab

Following a run-log action into another app — a contract, a session, a source —
MUST open in a new browser tab.

The editor holds unsaved state. Navigating away in place discards an author's
in-progress flow to show them a record they wanted to glance at, and the browser
back button returns to an editor that has forgotten everything. A new tab keeps
the flow where it was.

#### Scenario: A contract link keeps the flow open
- **GIVEN** an unsaved flow and a run-log entry linking to a contract
- **WHEN** the link is followed
- **THEN** it MUST open in a new tab and the editor MUST retain its unsaved state
- @e2e exclude covered by the run-log component tests
