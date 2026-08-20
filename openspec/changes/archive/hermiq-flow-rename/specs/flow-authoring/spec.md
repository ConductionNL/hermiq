## ADDED Requirements

### Requirement: Hermiq calls a flow a flow (REQ-FA-001)

Hermiq's flow surface SHALL use the word "flow" throughout — routes, page ids,
component names, store symbols, CSS class prefixes and user-facing strings. The
word "graph" SHALL NOT appear as a name for a flow.

A flow authored in hermiq is a row in OpenRegister's flow store with
`app = 'hermiq'`. It is not a distinct entity and SHALL NOT be given a distinct
name.

#### Scenario: The flow list is reached at /flows

- **WHEN** a user opens `/apps/hermiq/flows`
- **THEN** the list of hermiq's flows is shown, titled "Flows"

#### Scenario: An old graph URL still resolves

- **WHEN** a previously shared URL `/apps/hermiq/graphs/<uuid>` is opened
- **THEN** the browser is redirected to `/apps/hermiq/flows/<uuid>`
- **AND** the flow opens

#### Scenario: No surface still says graph

- **WHEN** the built frontend sources are searched for "graph" as a name for a flow
- **THEN** there are no matches in routes, page ids, component names, store
  symbols or translated strings

### Requirement: A flow is stored once, by OpenRegister (REQ-FA-002)

Hermiq SHALL read and write flows exclusively through OpenRegister's flow
endpoints. It SHALL NOT define a flow store, a flow schema, a flow controller or
a flow execution endpoint of its own.

#### Scenario: The flow list comes from the engine's store

- **WHEN** hermiq lists flows
- **THEN** it requests `/apps/openregister/api/flows?app=hermiq`
- **AND** no hermiq register or schema is consulted

#### Scenario: Running a flow uses the engine's endpoint

- **WHEN** a user runs a flow
- **THEN** hermiq posts to `/apps/openregister/api/flows/{id}/run`
- **AND** no hermiq-owned execution route is involved
