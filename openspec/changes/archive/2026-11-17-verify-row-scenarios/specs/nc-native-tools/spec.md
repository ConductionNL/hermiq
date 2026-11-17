## MODIFIED Requirements

### Requirement: NC-native capabilities registered as IMcpToolProvider tools
The system MUST register Files (`IRootFolder`), Contacts (addressbook `IManager`), Calendar
(`ICalendarManager`), Deck, and outbound email (`IMailer`) as tools through OpenRegister's
`IMcpToolProvider` interface, so they appear in the same tool registry as OR's other MCP tools.

#### Scenario: An agent lists available tools including NC-native ones
- GIVEN Hermiq's NC-native tool providers are registered with OR's `ToolRegistry`
- WHEN an agent run queries available tools via MCP
- THEN the Files, Contacts, Calendar, Deck, and email tools MUST appear in the returned tool list
- AND they MUST be indistinguishable in registration mechanism from OR's own MCP tools

#### Scenario: An agent looks up a contact
- **GIVEN** an agent granted `hermiq.searchContacts`, acting for a case handler whose address book holds "Jansen Bouw BV"
- **WHEN** the case handler asks "What is the email address of Jansen Bouw?"
- **THEN** the agent searches the address books the case handler can read and answers with the contact's email address

#### Scenario: An agent sends an email
- **GIVEN** an agent granted `hermiq.sendMail`, acting for a case handler
- **WHEN** the case handler asks it to mail "Uw aanvraag is compleet" to the applicant
- **THEN** the mail goes out through the instance's mail server with that text
- **AND** the sender is the case handler's own address
