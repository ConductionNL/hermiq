# nc-native-tools

Delta: the NC-native surface gains write verbs on Calendar and Contacts, and
Notes as a new subsystem. The existing IDOR requirement and the
route-remote-calls-through-OpenConnector requirement are unchanged and apply to
every tool added here.

## ADDED Requirements

### Requirement: Calendar and Contacts expose create/update verbs, scoped to the acting user
The system MUST expose a calendar-event creation tool and a contact upsert tool.
Each MUST resolve only resources the acting user owns, and MUST authorise before
any data access. A calendar MUST additionally be verified writable before it is
targeted.

#### Scenario: An agent creates an event in the user's own writable calendar
- **GIVEN** an agent is running on behalf of user U
- **WHEN** the agent creates a calendar event in a calendar U owns and can write to
- **THEN** the event MUST be created

#### Scenario: The target calendar is not writable
- **WHEN** the target is a subscription or a read-only share
- **THEN** the system MUST refuse with a structured error
- **AND** the system MUST NOT create the event elsewhere

#### Scenario: An agent selects one of its user's own address books
- **GIVEN** the acting user owns several address books
- **WHEN** a contact upsert supplies the id of one of them
- **THEN** the contact MUST be written to that address book

#### Scenario: An agent targets another user's address book or the system address book
- **WHEN** a contact upsert targets an address book the acting user does not own,
  the system address book, or an unknown id
- **THEN** the system MUST refuse
- **AND** no contact data MUST be written or returned
- **AND** the refusal MUST hold even when the agent's grant would otherwise permit
  that argument value — grant narrowing MUST NOT substitute for the ownership guard

#### Scenario: An operator wants an agent confined to one address book
- **WHEN** the contact upsert tool is granted with an argument-scoped constraint on
  the target address book
- **THEN** an invocation naming any other address book MUST be refused before the
  write is attempted

### Requirement: Calendar event creation supports attendees and is classified destructive
Because creating an event with attendees dispatches invitation messages to them, the
calendar-event creation tool MUST declare `destructiveHint: true`, and its
description MUST state that outbound effect in its first sentence. The invocation
record MUST capture the number of attendees invited and MUST NOT capture their
addresses.

#### Scenario: An agent creates an event with attendees
- **WHEN** an event creation request carries attendees
- **THEN** the event MUST be created with those attendees
- **AND** the invocation record MUST capture the number of attendees
- **AND** the invocation record MUST NOT capture their addresses

#### Scenario: An operator inspects the tool before granting it
- **WHEN** the calendar-event creation tool is shown in the tool catalogue
- **THEN** it MUST be classified as destructive
- **AND** its description MUST state, in its first sentence, that creating an event
  with attendees sends invitations to them

#### Scenario: The tool is granted
- **WHEN** an operator grants the calendar-event creation tool
- **THEN** the tool MUST NOT be reachable without an explicit exact-id grant
- **AND** an invocation MUST pass the approval gate before the event is created

#### Scenario: An event is created with no attendees
- **WHEN** an event creation request carries no attendees
- **THEN** no invitation message MUST be dispatched to any address

### Requirement: Notes is exposed as an optional NC-native subsystem
The system MUST expose list, create and update tools for Notes, resolving the
Notes service lazily and guarding for its absence. When Notes is not installed the
tools MUST return a structured error and MUST NOT throw.

#### Scenario: Notes is not installed
- **WHEN** an agent invokes a notes tool on an instance without Notes
- **THEN** the tool MUST return a structured error identifying the missing app
- **AND** the agent run MUST continue

#### Scenario: An agent targets a note it does not own
- **WHEN** an update targets a note belonging to another user
- **THEN** the system MUST refuse
- **AND** the note's content MUST NOT be returned

### Requirement: Every object an agent writes is marked as agent-authored
The system MUST mark each object it creates or updates as agent-authored, using the
marking mechanism native to that object's subsystem — a Nextcloud system tag for
files and notes, and an `X-` property on the object itself for vCard and iCalendar
objects, which system tags do not cover. Marking MUST happen in the same operation
as the write, and a write whose mark cannot be applied MUST report failure.

#### Scenario: An agent creates a calendar event or upserts a contact
- **WHEN** the object is written
- **THEN** the stored object MUST carry a property identifying it as agent-authored
- **AND** that property MUST survive export and synchronisation of the object

#### Scenario: An agent creates or updates a note
- **WHEN** the note is written
- **THEN** the underlying file MUST carry the agent-authored system tag

#### Scenario: The mark cannot be applied
- **GIVEN** the object was written
- **WHEN** applying the mark fails
- **THEN** the operation MUST report failure rather than success

#### Scenario: A capability cannot mark what it writes
- **WHEN** a proposed write capability has no available marking mechanism for its
  object type
- **THEN** that capability MUST NOT be exposed as a write tool

### Requirement: Every write is recorded with the object's identity, and without its content
The system MUST record each write with the identity of the object written (file id,
or object UID) and the acting agent, so an operator can follow the record to the
object. The record MUST NOT contain the object's field values — no contact details,
no event description, no note body.

#### Scenario: An operator reviews an agent's write activity
- **WHEN** the agent's invocation records are reviewed
- **THEN** each write MUST identify the object it wrote and the agent that wrote it
- **AND** the record MUST NOT contain the object's field values

### Requirement: No NC-native tool deletes user data
No tool in this capability may delete a calendar event, contact, note, file or
message.

#### Scenario: The tool surface is audited for delete verbs
- **WHEN** the NC-native tool surface is enumerated
- **THEN** no tool MUST offer a delete operation

### Requirement: Every write tool declares honest hints and is default-denied
Each write tool MUST declare a `scope` of `create` or `update`,
`readOnlyHint: false`, and a `destructiveHint` reflecting its real effect. Each
MUST therefore be default-denied by grant resolution and reachable only through an
explicit exact-id grant, and MUST appear in the per-agent tool catalogue with its
write classification.

#### Scenario: An agent has no explicit grant
- **GIVEN** an agent whose granted tool list is empty
- **WHEN** its resolved tool set is computed
- **THEN** no write tool from this capability MUST appear in it

#### Scenario: An operator reviews an agent's available tools
- **WHEN** the per-agent tool catalogue is requested
- **THEN** every write tool from this capability MUST be listed with its write
  classification
- **AND** the operator MUST be able to grant or withhold each tool individually

#### Scenario: A granted write tool is invoked
- **WHEN** an agent invokes a granted write tool for the first time
- **THEN** the invocation MUST pass through the approval gate before any data is written
