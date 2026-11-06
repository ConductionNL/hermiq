# knowledge-graph (delta)

This change adds a knowledge-graph layer to Hermiq's knowledge system (Context /
Memory / Skills per ADR-024): typed entities and relations extracted from and linked
to OpenRegister objects, files, mail, and conversation history, traversable at
context-assembly time (`ContextRetrievalHandler` graph mode) and as agent tools.

## ADDED Requirements

### Requirement: Graph nodes reference records and never copy them
The system MUST persist each `GraphEntity` as a typed reference (`sourceType` +
`sourceRef`) to exactly one underlying record — an OpenRegister object, a Nextcloud
file, a mail message, or a Hermiq conversation — and MUST NOT store the underlying
record's content on the node or edge. When record content is needed (context
hydration, tool follow-up), the system MUST read it live from the underlying record
as the acting user.

#### Scenario: A node is created from an OpenRegister object
- **GIVEN** an extraction that identifies an entity in an OpenRegister object
- **WHEN** the entity is persisted
- **THEN** the `GraphEntity` MUST carry `sourceType: object` and a `sourceRef` of
  `{register, schema, uuid}`
- **AND** the node MUST NOT contain the object's data beyond its label, type,
  aliases, and provenance fields

#### Scenario: The underlying record changes after extraction
- **GIVEN** a `GraphEntity` referencing an object that is later edited
- **WHEN** the graph context mode hydrates that entity into a turn
- **THEN** the hydrated text MUST reflect the record's current content, not the
  content at extraction time

#### Scenario: The underlying record is deleted
- **GIVEN** a `GraphEntity` whose `sourceRef` no longer resolves
- **WHEN** any read path encounters that node
- **THEN** the system MUST treat the node as not visible (fail closed)
- **AND** MUST NOT raise an error to the caller

### Requirement: An edge is visible only when both endpoints are
The system MUST derive graph visibility from the underlying records: a node is
visible to the acting user only if that user can currently read the record its
`sourceRef` points at, an edge (`GraphRelation`) is returned only when BOTH its
endpoint nodes are visible to the acting user, and a path is returned only when every
edge on it is visible. Visibility checks MUST run as the acting user via the
underlying record's own authorization (OpenRegister object RBAC for objects, the
user's own folder for files, the mail account scoping for mail, and the
owner-or-listed-participant rule for conversations). An unresolvable check MUST count
as not visible.

#### Scenario: Both endpoints readable
- **GIVEN** an edge whose two endpoint records the acting user can read
- **WHEN** the user's agent traverses the graph
- **THEN** the edge and both endpoints MUST be returned

#### Scenario: One protected endpoint hides the edge
- **GIVEN** an edge between record A (readable by the acting user) and record B (not
  readable by the acting user)
- **WHEN** the user's agent asks for A's neighbors
- **THEN** the system MUST NOT return the edge
- **AND** MUST NOT return B's node, label, or `sourceRef`

#### Scenario: A path with a hidden link is not returned
- **GIVEN** a path A→B→C where the acting user cannot see the B→C edge
- **WHEN** `hermiq.graphPath` is asked for a path from A to C
- **THEN** the system MUST NOT return the A→B→C path
- **AND** MAY return a different path consisting only of visible edges

### Requirement: Extraction runs as the acting user in audited background jobs
The system MUST run graph extraction as background jobs that impersonate the user who
enqueued them (restoring the prior identity afterwards, whatever the outcome), so an
extraction can only read records that user can read. Every graph write MUST go
through OpenRegister's `ObjectService` (single write-path) so it is recorded in the
AuditTrail, and every persisted entity/relation MUST record its extractor identity
and version (`extractedBy`) and a `confidence` value.

#### Scenario: Extraction cannot read beyond its user
- **GIVEN** an extraction job enqueued by a user who cannot read a given object
- **WHEN** the job runs
- **THEN** the system MUST NOT create nodes or edges derived from that object

#### Scenario: A graph write is audit-trailed
- **WHEN** an extraction job persists a `GraphEntity` or `GraphRelation`
- **THEN** the write MUST be performed via `ObjectService`
- **AND** the persisted object MUST carry `extractedBy` and `confidence`

#### Scenario: Conversation extraction respects the session roster
- **GIVEN** a conversation whose owner and participants roster exclude the enqueueing
  user
- **WHEN** an extraction job for that conversation would run for that user
- **THEN** the system MUST refuse to extract from that conversation's history

### Requirement: Graph traversal is available to context assembly
The system MUST support a `graph` value for the agent's `ragSearchMode`: at retrieval
time it MUST match seed entities from the query against `GraphEntity` labels and
aliases, traverse a bounded neighborhood (configurable depth and node/edge caps),
hydrate the visible underlying records into the existing retrieval result shape, and
include the visible relations as a compact structured block in the context text.
Retrieval MUST keep `retrieveContext()`'s never-throws contract: when the graph is
empty, unavailable, or matches no seed, the system MUST degrade to the existing
keyword retrieval path and log the degradation.

#### Scenario: A graph-mode turn assembles a neighborhood
- **GIVEN** an agent with `graphEnabled` and `ragSearchMode: graph`, and a graph
  containing entities matching the user's query
- **WHEN** context is retrieved for the turn
- **THEN** the retrieved context MUST contain excerpts hydrated live from the visible
  underlying records of the seed entities and their bounded neighborhood
- **AND** MUST contain the visible relations between them

#### Scenario: An empty graph degrades to keyword retrieval
- **GIVEN** an agent with `ragSearchMode: graph` and no matching graph entities
- **WHEN** context is retrieved
- **THEN** the system MUST fall back to the keyword retrieval path
- **AND** MUST NOT fail the turn

### Requirement: Graph traversal is exposed as governed agent tools
The system MUST expose `hermiq.graphNeighbors` and `hermiq.graphPath` as read-only
agent tools registered through the same grant-governed tool path as other `hermiq.*`
tools, subject to the resolved, default-denied tool set. Tool results MUST contain
only RBAC-surviving nodes, edges, and their `sourceRef` pointers, and MUST NOT inline
underlying record content.

#### Scenario: graphNeighbors returns an RBAC-filtered neighborhood
- **GIVEN** an agent granted `hermiq.graphNeighbors`
- **WHEN** the model calls it for an entity
- **THEN** the result MUST contain only nodes and edges visible to the acting user
- **AND** each node MUST carry its `sourceRef` so the record can be followed up with
  the existing governed record tools

#### Scenario: An ungranted graph tool is not invocable
- **GIVEN** an agent whose resolved tool set does not include the graph tools
- **WHEN** a turn for that agent is assembled
- **THEN** the graph tools MUST NOT be exposed to or invocable by the model
