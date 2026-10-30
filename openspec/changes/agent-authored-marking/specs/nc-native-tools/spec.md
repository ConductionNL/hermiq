# nc-native-tools

## ADDED Requirements

### Requirement: Every object an agent writes is marked as agent-authored (REQ-AILBL-001)

hermiq SHALL mark each object an agent creates or updates as agent-authored, in the same operation
as the write, with the mechanism native to that object's subsystem: a Nextcloud system tag for
files and notes, and the `X-HERMIQ-AGENT-AUTHORED` property on the object itself for contacts
(vCard), calendar items and tasks (iCalendar). The system tag SHALL have one name in every UI
language, so one filter finds every tagged file. A write whose mark cannot be applied SHALL report
failure, never success.

Rows: `dm-ai-label`. Board: `hermiq/HmToezicht`.

#### Scenario: An agent writes a note
- **WHEN** an agent creates or updates a note
- **THEN** the note's file carries the agent-authored system tag

@e2e exclude requires the Notes app, which CI does not install; covered by NotesWriteServiceTest.

#### Scenario: An agent writes a contact or a calendar item
- **WHEN** an agent upserts a contact or creates a calendar event
- **THEN** the stored object carries `X-HERMIQ-AGENT-AUTHORED`
- **AND** the property survives export and synchronisation of the object

@e2e exclude asserting stored vCard and iCalendar bytes needs a CardDAV or CalDAV fetch, not a page; covered by ContactWriteServiceTest and CalendarWriteServiceTest.

#### Scenario: The mark cannot be applied
- **GIVEN** the object was written
- **WHEN** applying the mark fails
- **THEN** the tool call reports failure to the agent and the run trace records it

@e2e exclude a tagging failure cannot be forced from a page; covered by the write service tests.

### Requirement: A person can mark a labelled file as reviewed, and the oversight page counts the labels (REQ-AILBL-002)

A person who can read an agent-authored file SHALL be able to mark it as reviewed; the file SHALL
then carry a reviewed system tag next to the agent-authored tag, which stays. The AI oversight page
SHALL show a section, as drawn on `hermiq/HmToezicht`, with the number of agent-authored notes and
files, contacts and calendar items, the share of agent-authored files marked as reviewed, and a link
that opens Files filtered on the agent-authored tag. Counts SHALL include only what the viewer is
allowed to see.

Rows: `dm-ai-label`. Board: `hermiq/HmToezicht`.

#### Scenario: A reviewed file counts as reviewed
- **GIVEN** four agent-authored files the viewer can read, one marked as reviewed
- **WHEN** the viewer opens the AI oversight page
- **THEN** the label section shows 4 files and a reviewed share of 25%

#### Scenario: Marking a file someone cannot read is refused
- **GIVEN** an agent-authored file the caller cannot read
- **WHEN** they ask to mark it as reviewed
- **THEN** the request is refused with 404 and the file's tags are unchanged

### Requirement: Chat answers are shown as the agent's, without a separate label (REQ-AILBL-003)

On hermiq's chat page every answer SHALL be shown under the agent's name and avatar, so a person
can always tell an agent's text from a person's. hermiq SHALL NOT add a separate AI label to chat
answers; the label of REQ-AILBL-001 applies to what an agent writes outside the conversation.

Rows: `dm-ai-label`. Board: `hermiq/HmChat`.

#### Scenario: An answer shows who wrote it
- **WHEN** an agent answers in a conversation
- **THEN** the answer bubble shows the agent's name, and a person's own message shows that person's name
