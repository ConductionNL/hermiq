## ADDED Requirements

### Requirement: A node card shows the action it performs (REQ-FA-003)

A node on the canvas SHALL show the step it performs — its catalogue display
name and its key configuration. An edge SHALL show only its title, if it has one.

A node SHALL NOT render as an unlabelled box. When a node has no name, its id
SHALL be shown, because that is what the engine calls it and what every edge
references.

#### Scenario: A node names its action

- **GIVEN** a node of type `openconnector.source-call`
- **WHEN** the flow is opened
- **THEN** the card shows "Call a source"

#### Scenario: A node with no step type is called out

- **GIVEN** a node with no type
- **WHEN** the flow is opened
- **THEN** the card shows that no step is set, styled as a warning
- **AND** it is not shown as an ordinary node

### Requirement: Connections are made between ports (REQ-FA-004)

Every node that is not a start node SHALL have an **in-port** on its left. Every
node that is not an exit node SHALL have an **out-port** on its right. A
connection SHALL be creatable by dragging from an out-port to a node or its
in-port.

A start node SHALL have no in-port and an exit node SHALL have no out-port, so
the role is expressed by the absence of the affordance and not only by colour.

#### Scenario: A start node has no in-port

- **GIVEN** a node no edge points at
- **WHEN** the flow is opened
- **THEN** it has an out-port and no in-port

#### Scenario: An exit node cannot originate a connection

- **GIVEN** a node whose step type is registered terminal
- **WHEN** the flow is opened
- **THEN** it has an in-port and no out-port
- **AND** no connection can be dragged out of it

### Requirement: A routing node has one out-port per branch (REQ-FA-005)

A node whose step type declares branches SHALL render one out-port per branch,
each labelled with that branch. The port set SHALL be derived from the node's
configuration, not from the edges already drawn.

An edge whose branch no longer exists after a configuration change SHALL be
shown as unassigned. It SHALL NOT be silently deleted.

#### Scenario: A two-way route shows two labelled ports

- **GIVEN** a routing node configured with branches `work` and `idle`
- **WHEN** the flow is opened
- **THEN** it has two out-ports, labelled `work` and `idle`

#### Scenario: Removing a branch does not delete the connection

- **GIVEN** an edge connected to branch `idle`
- **WHEN** `idle` is removed from the node's configuration
- **THEN** the edge is shown as unassigned
- **AND** it is not deleted

### Requirement: A loop node has body ports (REQ-FA-006)

A node whose step type is a loop SHALL render a body-out and a body-in port at
its bottom edge. Nodes chained between them form the loop body.

The stored result SHALL be ordinary edges forming a cycle. No new stored
structure is introduced.

#### Scenario: A loop body is an ordinary chain

- **GIVEN** a loop node with two nodes chained from its body-out back to its body-in
- **WHEN** the flow is saved
- **THEN** the stored edges form a cycle through the loop node
- **AND** the flow builds without a loop-specific engine concept

### Requirement: Connecting is possible without a mouse (REQ-FA-007)

Every connection operation SHALL be performable from the keyboard, including
choosing which out-port a connection originates from when a node has more than
one.

#### Scenario: A branch is chosen from the keyboard

- **GIVEN** a focused routing node with two out-ports
- **WHEN** the author starts a connection from the keyboard
- **THEN** they are asked which branch it leaves from
- **AND** the connection completes on the chosen branch

### Requirement: A dead end warns on save and shows its last run (REQ-FA-008)

Saving a flow containing a non-exit node with no outgoing connection SHALL show
a warning naming those nodes, and SHALL allow the author to continue. The flow
list and the editor SHALL show each flow's last run.

#### Scenario: Saving a half-wired flow warns but proceeds

- **GIVEN** a flow with a non-exit node that has no outgoing connection
- **WHEN** the author saves
- **THEN** a warning names that node
- **AND** continuing stores the flow

#### Scenario: A refused flow shows why

- **GIVEN** a flow whose last run was refused for a dead end
- **WHEN** the list is opened
- **THEN** the flow shows an error status with its message
- **AND** it is not shown as simply never having run
