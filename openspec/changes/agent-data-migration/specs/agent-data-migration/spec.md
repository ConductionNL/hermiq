## ADDED Requirements

### Requirement: A repair step migrates existing OR agent-engine data into hermiq-register objects

The system MUST provide an idempotent repair step that copies every row in OpenRegister's
`openregister_agents`, `openregister_conversations`, `openregister_messages`, and
`openregister_feedback` tables into equivalent `Agent`/`Conversation`/`Message`/`Feedback` objects
in the `hermiq` OpenRegister register, preserving each record's original uuid and its `owner`/
`organisation` tenancy. `openregister_chat_history` MUST NOT be migrated (confirmed dead code with
no live callers at the time this change was authored). OpenRegister's source tables MUST NOT be
dropped or modified by this repair step.

#### Scenario: An Agent row is migrated with its uuid preserved

- **WHEN** the repair step runs against an `openregister_agents` row with uuid `U` and no
  corresponding `Agent` object yet exists in the `hermiq` register
- **THEN** an `Agent` object with uuid `U` MUST be created in the `hermiq` register, with `owner`/
  `organisation` matching the source row

#### Scenario: Re-running the repair step is a no-op on migrated records

- **WHEN** the repair step runs a second time against the same OR data
- **THEN** no additional `Agent`/`Conversation`/`Message`/`Feedback` objects MUST be created or
  modified for records already migrated in a prior run

### Requirement: Integer foreign keys are resolved to uuid references during migration

The repair step MUST resolve every integer foreign key in the source OR tables — `Conversation
.agentId`, `Message.conversationId`, and `Feedback.{messageId, conversationId, agentId}` — to the
referenced row's uuid, and MUST write that uuid, not the source integer, into the corresponding
field of the migrated `hermiq`-register object.

#### Scenario: Conversation.agentId is resolved from an integer FK to the Agent's uuid

- **WHEN** an `openregister_conversations` row has `agentId=7` and the `openregister_agents` row
  with `id=7` has uuid `U` and has already been migrated
- **THEN** the migrated `Conversation` object's `agentId` field MUST be set to `U`, not `7`

#### Scenario: An unresolvable foreign key is skipped, not fatal

- **WHEN** a source row's foreign key does not resolve to an already-migrated object (e.g. a
  dangling reference)
- **THEN** the repair step MUST log the skipped record and continue processing remaining records
- **AND** MUST NOT abort the repair step or fail the app upgrade as a whole

### Requirement: Message.context is preserved exactly

The `context` JSON field on every migrated `Message` object MUST be byte-for-byte identical to the
source `openregister_messages` row's `context` value.

#### Scenario: A message with an AI Chat Companion context snapshot round-trips unchanged

- **WHEN** a source `Message` row has a `context` value containing `appId`, `pageKind`, and
  `capturedAt`
- **THEN** the migrated `Message` object's `context` MUST deep-equal the source value exactly

### Requirement: A pre-existing Schedule reference to a migrated Agent continues to resolve

Any `Schedule.agentId` value already pointing at an `Agent`'s uuid (from OpenRegister) MUST continue
to resolve correctly after migration, with no changes required to existing `Schedule` objects.

#### Scenario: An existing Schedule's agentId resolves to the migrated Agent

- **WHEN** a `Schedule` object has `agentId` set to uuid `U`, and an `Agent` with uuid `U` has been
  migrated into the `hermiq` register by this repair step
- **THEN** the `Schedule` MUST successfully resolve `agentId` to the migrated `Agent` object without
  any modification to the `Schedule` object itself
