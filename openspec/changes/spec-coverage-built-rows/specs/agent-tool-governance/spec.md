# agent-tool-governance

## ADDED Requirements

### Requirement: The tool catalogue lists every installed app's tools (REQ-SPCOV-007)

Any installed Nextcloud app SHALL be able to add tools by registering an `IMcpToolProvider`.
The system SHALL read those tools through OpenRegister's tool registry without a hermiq
change, and SHALL let an owner grant them to an agent like any other tool. The MCP tools page
SHALL list every available tool with its name and what it does, grouped by the app that
offers it, and SHALL show an empty state when no app offers a tool.

Rows: `tl-catalog`, `dm-app-tools`. Boards: `hermiq/HmMcpTools`, `hermiq/HmAgentBewerken`.

#### Scenario: A newly installed app's tools appear
- **GIVEN** an app "decidiq" that registers the tool `decidiq.listMeetings`
- **WHEN** an administrator opens the MCP tools page after installing it
- **THEN** the page lists `decidiq.listMeetings` with its description under "decidiq"
- **AND** the agent form offers it as a tool an owner can grant

#### Scenario: No app offers a tool
- **GIVEN** no installed app registers a tool provider
- **WHEN** a person opens the MCP tools page
- **THEN** the page shows an empty state that says no tools are installed

### Requirement: An agent reads and updates register records within its grant (REQ-SPCOV-015)

An agent granted the read tools of an OpenRegister schema SHALL be able to search that schema's
records and read one record, as the acting user and only within what that user may see. An
agent granted the write tools of that schema SHALL also be able to create and update its
records. Without a write grant, an update SHALL be refused and the record SHALL stay as it was.

Rows: `tl-register`, `dm-builtin-table`. Board: `hermiq/HmAgentBewerken`.

#### Scenario: An agent answers from the records it may read
- **GIVEN** an agent granted the read tools of the schema "permit application", acting for a case handler who may see twelve open applications
- **WHEN** the case handler asks "How many permit applications are still open?"
- **THEN** the agent searches the schema and answers "12", and its tool steps show the search

#### Scenario: An agent updates a record it may write
- **GIVEN** the same agent with a write grant on "permit application" added by its owner
- **WHEN** the case handler asks it to set application 2026-0412 to "Waiting for documents"
- **THEN** the record shows the status "Waiting for documents", and the audit trail names the agent and the case handler

#### Scenario: An update without a write grant is refused
- **GIVEN** an agent with only the read tools of "permit application"
- **WHEN** the model calls the update tool for application 2026-0412
- **THEN** the call is refused as not granted, and the record is unchanged
