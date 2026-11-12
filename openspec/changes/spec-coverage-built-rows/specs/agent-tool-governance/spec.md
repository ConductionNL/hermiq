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
