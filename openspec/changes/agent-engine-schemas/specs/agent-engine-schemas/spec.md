## ADDED Requirements

### Requirement: Declare the Agent OpenRegister schema

The system MUST declare a declarative OpenRegister schema `Agent` in the app's schema register
`lib/Settings/hermiq_register.json` under `components.schemas`, so that an autonomous agent
definition can be persisted and validated by OpenRegister's `ObjectService` inside the `hermiq`
register instead of OpenRegister's own tables. The schema MUST define `name` as a required string,
and MUST define `description`, `type`, `provider`, `model`, `prompt`, `temperature`, `maxTokens`,
`configuration` (object), `active` (boolean, default `true`), `enableRag` (boolean, default
`false`), `ragSearchMode`, `ragNumSources`, `ragIncludeFiles` (boolean), `ragIncludeObjects`
(boolean), `requestQuota` (integer), `tokenQuota` (integer), `views` (array), `searchFiles`
(boolean), `searchObjects` (boolean), `isPrivate` (boolean, default `true`), `invitedUsers` (array),
`groups` (array), `tools` (array, default empty — the ADR-035 tool-whitelist: an empty array means
every discovered tool is allowed), and `user` (string, the identity an agent runs as in
cron/background scenarios). Tenant scoping (`owner`/`organisation`) MUST come from OpenRegister
`ObjectEntity` and MUST NOT be declared as schema properties. No PHP, controller, or service is
introduced by this schema declaration.

#### Scenario: Agent schema is importable into the hermiq register

- **WHEN** the register `lib/Settings/hermiq_register.json` is imported via
  `ConfigurationService::importFromApp()` in the repair step
- **THEN** OpenRegister MUST create the `Agent` schema in the `hermiq` register without altering
  the existing schemas (union import, no regression)
- **AND** an `Agent` object with only `name` set MUST validate and persist successfully with
  `active=true`, `isPrivate=true`, and `tools=[]` as defaults

#### Scenario: Agent tool whitelist defaults to allow-all

- **WHEN** an `Agent` object is created without an explicit `tools` value
- **THEN** the persisted agent MUST have `tools` as an empty array
- **AND** the downstream tool loop (`agent-engine-port`) MUST treat an empty `tools` array as "every
  discovered tool is allowed" (no regression from OpenRegister's current all-tools-allowed default)

### Requirement: Declare the Conversation OpenRegister schema

The system MUST declare a declarative OpenRegister schema `Conversation` in
`lib/Settings/hermiq_register.json`, representing a chat thread bound to one `Agent`. The schema
MUST define `title` (string), `userId` (string), `agentId` (string, format `uuid`, required — a
reference to an `Agent` object in the `hermiq` register, NOT an integer foreign key), and
`metadata` (object). Tenant scoping and soft-delete state MUST come from OpenRegister `ObjectEntity`
and MUST NOT be declared as schema properties.

#### Scenario: Conversation schema is importable and references an Agent by uuid

- **WHEN** the register is imported via `ConfigurationService::importFromApp()`
- **THEN** OpenRegister MUST create the `Conversation` schema in the `hermiq` register without
  altering existing schemas
- **AND** a `Conversation` object created with `agentId` set to a valid uuid string MUST persist
  successfully

### Requirement: Declare the Message OpenRegister schema

The system MUST declare a declarative OpenRegister schema `Message` in
`lib/Settings/hermiq_register.json`, representing one turn in a `Conversation`. The schema MUST
define `conversationId` (string, format `uuid`, required — a reference to a `Conversation` object,
NOT an integer foreign key), `role` (required enum `system`|`user`|`assistant`|`tool`), `content`
(string), `sources` (array), and `context` (object, optional — the AI Chat Companion `CnAiContext`
snapshot per hydra ADR-034 Decision 5, captured at the moment the message was sent).

#### Scenario: Message schema is importable and preserves the companion context snapshot

- **WHEN** a `Message` object is created with `conversationId` set to a valid uuid, `role=user`,
  `content` set, and `context` set to an object containing `appId`/`pageKind`/`capturedAt`
- **THEN** the object MUST persist with `context` retained verbatim as JSON
- **AND** a `Message` with a `role` value outside the four allowed enum values MUST be rejected

### Requirement: Declare the Feedback OpenRegister schema

The system MUST declare a declarative OpenRegister schema `Feedback` in
`lib/Settings/hermiq_register.json`, representing a rating on one `Message`. The schema MUST define
`messageId`, `conversationId`, and `agentId` (all string, format `uuid`, required — references, NOT
integer foreign keys), `userId` (string, required), `type` (string, required), and `comment`
(string, optional). Tenant scoping (`organisation`) MUST come from OpenRegister `ObjectEntity` and
MUST NOT be declared as a schema property.

#### Scenario: Feedback schema is importable and references Message/Conversation/Agent by uuid

- **WHEN** the register is imported via `ConfigurationService::importFromApp()`
- **THEN** OpenRegister MUST create the `Feedback` schema in the `hermiq` register without altering
  existing schemas
- **AND** a `Feedback` object created with valid uuid references for `messageId`, `conversationId`,
  and `agentId`, plus a `userId` and `type`, MUST persist successfully

#### Scenario: Feedback requires its uuid references and type

- **WHEN** a `Feedback` object is created missing `messageId`, `agentId`, `userId`, or `type`
- **THEN** OpenRegister MUST reject the object as failing required-field validation
