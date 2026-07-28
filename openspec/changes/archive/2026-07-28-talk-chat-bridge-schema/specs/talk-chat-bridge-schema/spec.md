## ADDED Requirements

### Requirement: Conversation carries a filterable Talk room binding

The `Conversation` schema in `lib/Settings/hermiq_register.json` MUST declare an OPTIONAL
`talkRoomToken` property (`type: string`) holding the Talk room token this conversation is
bound to. It MUST be declared as a **top-level property** of
`components.schemas.Conversation.properties` — NOT nested inside the free-form `metadata`
object — because the bridge resolves an inbound room token to a conversation by filter
query, and OpenRegister's dot-path filters on nested JSON match nothing. It MUST NOT be
added to the schema's `required` list, and it MUST NOT be bound to any other property by a
conditional (`if`/`then`/`allOf`) block — the OpenRegister importer rejects conditional
blocks. Its `description` MUST state that an empty or unset value means the conversation
has no Talk binding.

#### Scenario: A conversation stores its bound room token

- **WHEN** a `Conversation` object is created with `talkRoomToken` set to a room token
- **THEN** the register MUST accept and persist the `talkRoomToken` value
- **AND** the schema MUST validate the object without requiring any conditional block

#### Scenario: The binding is filterable at the top level

- **WHEN** conversations are queried with a filter on `talkRoomToken` equal to a stored
  token
- **THEN** the conversation bound to that room MUST be returned
- **AND** the property MUST NOT be declared inside `metadata`

#### Scenario: talkRoomToken is optional

- **WHEN** a `Conversation` object is created with no `talkRoomToken`
- **THEN** the object MUST validate (the field is optional) and the conversation is treated
  as having no Talk binding

### Requirement: Conversation carries a participant roster

The `Conversation` schema MUST declare an OPTIONAL `participants` property
(`type: array`, `items.type: string`, `default: []`) listing the uids permitted to take a
turn in the conversation in addition to the owner. Its `description` MUST state that the
`userId` owner is **implicitly** a participant and need not appear in the list, so that an
empty or unset roster means owner-only — the behaviour of every conversation that exists
today. It MUST NOT be added to `required`.

#### Scenario: A shared conversation lists its participants

- **WHEN** a `Conversation` object is created with `participants` set to two uids
- **THEN** the register MUST accept and persist both uids in order

#### Scenario: An unset roster means owner-only

- **WHEN** a `Conversation` object is created without `participants`
- **THEN** the object MUST validate with the field unset
- **AND** the owner named in `userId` MUST still be understood as a participant

### Requirement: Message records its human author

The `Message` schema MUST declare two OPTIONAL properties: `authorId` (`type: string`), the
uid of the human who produced the turn, and `authorDisplayName` (`type: string`), that
human's display name captured at send time so history stays legible after a rename or a
deleted account. Their `description`s MUST state that both are set on `role = user`
messages and left unset for `system`, `assistant` and `tool` turns, which have no human
author. Neither MUST be added to `required`, and neither MUST be bound to `role` by a
conditional block.

#### Scenario: A user turn records who sent it

- **WHEN** a `Message` object is created with `role = user`, `authorId` set to a uid and
  `authorDisplayName` set to that user's display name
- **THEN** the register MUST accept and persist both values

#### Scenario: An assistant turn has no author

- **WHEN** a `Message` object is created with `role = assistant` and neither author field
  set
- **THEN** the object MUST validate with both fields unset

#### Scenario: Display name survives a rename

- **WHEN** a `Message` was stored with `authorDisplayName` and the user later changes their
  display name
- **THEN** the stored `authorDisplayName` MUST remain as captured at send time

### Requirement: Agent carries the Hermiq half of the Talk opt-in

The `Agent` schema MUST declare an OPTIONAL `talkEnabled` property (`type: boolean`,
`default: false`) recording whether an agent may be conversed with from a Talk room. It MUST
default to false so that every agent predating this change stays unreachable from Talk, and its
`description` MUST state that this is only HALF of the requirement — a Talk moderator must also
enable the bot in the room — so neither switch alone activates the bridge. It MUST NOT be added
to `required`.

#### Scenario: An agent opts in to Talk

- **WHEN** an `Agent` object is saved with `talkEnabled` set to true
- **THEN** the register MUST accept and persist the value

#### Scenario: Existing agents default to unreachable

- **WHEN** an `Agent` object created before this change is read
- **THEN** `talkEnabled` MUST be absent or false, leaving the agent unreachable from Talk

### Requirement: Existing conversations and messages are unaffected

All four added properties MUST be optional, so that every `Conversation` and `Message`
object that exists before this change continues to validate unchanged, with no backfill and
no migration of object payloads. A conversation with neither `talkRoomToken` nor
`participants` MUST behave exactly as it does today: owner-only and web-UI only.

#### Scenario: A pre-existing conversation still validates

- **WHEN** a `Conversation` object created before this change — carrying only `title`,
  `userId`, `agentId` and `metadata` — is read and re-saved
- **THEN** it MUST validate against the updated schema with all four new fields unset

#### Scenario: The shape is inert without the code change

- **WHEN** the register is imported with these properties but the `talk-chat-bridge` code
  change has not shipped
- **THEN** no existing behaviour MUST change — the fields are written and read by nothing

### Requirement: Additions are union-import-safe and actually applied

The four new properties MUST be added to `components.schemas.Conversation.properties` and
`components.schemas.Message.properties` without modifying any existing property, either
schema's `required` list, or any other schema in the register, so that OpenRegister's
union-based register import remains idempotent and non-destructive. Every added property
MUST carry a `title`, per the fleet's `schema-property-titles` gate. The register's
`info.version` MUST be bumped, because an import that does not raise the version advances
state without applying the change to the already-existing schemas.

#### Scenario: Re-importing the register does not corrupt the schemas

- **WHEN** the Hermiq register is imported (or re-imported) after this change
- **THEN** the existing `Conversation` and `Message` properties and `required` lists MUST be
  unchanged
- **AND** the four new optional properties MUST be present on their schemas

#### Scenario: The version bump makes the import land

- **WHEN** the register is imported after the `info.version` bump
- **THEN** the already-existing `Conversation` and `Message` schemas MUST be updated in
  place to expose the new properties
- **AND** a query filtering on `talkRoomToken` MUST be accepted by the register

#### Scenario: Every added property is titled

- **WHEN** the four added properties are inspected in the register JSON
- **THEN** each MUST declare a non-empty `title`
