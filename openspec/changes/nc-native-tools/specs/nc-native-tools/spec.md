# nc-native-tools (delta)

Implements the Hermiq-owned surface of the `nc-native-tools` capability: a real
`IMcpToolProvider` registering Files/Contacts/Calendar/Deck/email tools with per-object
IDOR guards, routing remote calls through OpenConnector. The agent-invokes-tool path is
blocked on OR#269 (Ollama tool-calling 400) and documented, not verified end-to-end.

## ADDED Requirements

### Requirement: NC-native capabilities registered as IMcpToolProvider tools
The system MUST register Files (`IRootFolder`), Contacts (Contacts `IManager`), Calendar
(Calendar `IManager`), Deck, and outbound email (`IMailer`) as tools through OpenRegister's
`IMcpToolProvider` interface, so they appear in the same tool registry as OR's other MCP
tools, each id namespaced `hermiq.*`.

#### Scenario: An agent lists available tools including NC-native ones
- **GIVEN** Hermiq's `HermiqToolProvider` is registered under the `hermiq` MCP alias
- **WHEN** OpenRegister's `McpToolsService` enumerates providers
- **THEN** the Files, Contacts, Calendar, Deck, and email tools MUST appear in the tool list
- **AND** they MUST be registered by the same mechanism as OR's own MCP tools

### Requirement: Per-object IDOR guard on every provider
Every NC-native tool MUST enforce a per-object authorization guard — scoping to the acting
user's own resources — before acting on a Files/Contacts/Calendar/Deck object, so an agent
acting for one user cannot access another user's objects by id.

#### Scenario: A tool call targets an object outside the caller's access
- **GIVEN** an agent running on behalf of user U
- **WHEN** a tool call requests a path/object U does not have access to
- **THEN** the system MUST deny it (scope is U's own folder/addressbooks/calendars)
- **AND** MUST NOT return content from an inaccessible object

### Requirement: Remote systems route through OpenConnector
The system MUST NOT implement direct HTTP/API calls to third-party systems inside Hermiq's
tool providers; such calls MUST route through OpenConnector's `CallService`.

#### Scenario: A tool needs a non-Nextcloud system
- **WHEN** a tool would contact a third-party API
- **THEN** it MUST delegate to OpenConnector `CallService`, and Hermiq MUST NOT open a
  direct HTTP client

### Requirement: invokeTool never throws
`invokeTool()` MUST return a structured `['error' => ['code', 'message']]` for an unknown
tool, an unauthenticated caller, a missing argument, or a backend failure — never an
uncaught exception.

#### Scenario: An unknown or unauthorised tool call
- **WHEN** `invokeTool` is called with an unknown tool id or by an unauthenticated caller
- **THEN** it MUST return an error envelope, not throw
