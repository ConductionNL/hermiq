## ADDED Requirements

### Requirement: A background task acts for the task's user

Hermiq SHALL forward the user a Nextcloud TaskProcessing task belongs to as the broker's acting user
whenever it answers that task without a session: for `core:text2text`, `core:text2text:summary`,
`core:text2text:headline` and `core:contextagent:interaction`. A session user SHALL always win over
the task's user. The acting user SHALL be set only for the duration of that task's work and cleared
afterwards, also when the work fails. Hermiq SHALL NOT read the acting user from any request input.
Work entered without a session SHALL stay background work for its whole duration, also inside a
`runAs()` user switch: every broker call SHALL go through OpenRegister's PHP-internal
`requestForBackgroundUser()`, so the broker judges the task's user by its sessionless rule (owner of
a personal credential, real member of an organisation's credential, no administrator pass). Hermiq
SHALL refuse a background broker call that names no user, or that meets an OpenRegister without
that entry, rather than fall back to `request()`.

#### Scenario: An Assistant summary runs on Claude from cron

- **GIVEN** the chat provider is `anthropic` with an organisation credential of organisation O
- **AND** user bob, a member of O, scheduled a `core:text2text:summary` task
- **WHEN** cron runs the task with no session
- **THEN** hermiq asks the broker to POST `/v1/messages` with `actingUserId` bob

#### Scenario: Assistant chat runs on Claude from cron

- **GIVEN** the chat provider is `anthropic` and Assistant routes chat to `core:contextagent:interaction`
- **WHEN** cron runs bob's interaction task with no session
- **THEN** the engine turn's broker call carries `actingUserId` bob

#### Scenario: The contextagent turn reads OpenRegister as the task's user

- **GIVEN** cron runs bob's `core:contextagent:interaction` task with no session
- **WHEN** the engine reads the session, its messages and the agent's tools
- **THEN** every OpenRegister RBAC and organisation check answers for bob
- **AND** the previous (empty) user is restored afterwards

#### Scenario: A task for a disabled user is refused

- **GIVEN** cron runs a `core:contextagent:interaction` task whose user is disabled or no longer exists
- **THEN** the interaction is refused before a session is saved or the engine runs

#### Scenario: An administrator's background task outside the organisation is refused

- **GIVEN** a Nextcloud administrator A who is not a member of organisation O
- **AND** the chat provider uses O's organisation credential
- **WHEN** cron runs A's Assistant task, including a contextagent turn inside `runAs(A)`
- **THEN** the broker refuses the call
- **AND** a member's task on the same credential still reaches Anthropic

#### Scenario: A session is never replaced

- **GIVEN** alice is signed in
- **WHEN** code runs work acting for bob
- **THEN** every broker call in that work carries alice

#### Scenario: The acting user does not leak into later work

- **WHEN** work acting for bob finishes or throws
- **THEN** a later sessionless broker call carries no acting user

### Requirement: Anthropic resolves a personal then organisation credential

For the API key mode over http, hermiq SHALL resolve the Anthropic credential the same way it
resolves OpenAI and Fireworks: an agent's pinned credential first, then the acting user's personal
credential, then the organisation's credential, then the configured instance credential. The OAuth
and CLI modes SHALL keep the configured credential.

#### Scenario: A member's agent uses the organisation key

- **GIVEN** an agent of organisation O and an organisation credential of O for provider `anthropic` allowed for hermiq
- **WHEN** the agent's turn builds an Anthropic driver in API key mode
- **THEN** the driver carries that organisation credential

#### Scenario: An untouched model field uses the default model

- **GIVEN** the configured Anthropic `chatModel` is empty
- **WHEN** an Anthropic driver is built
- **THEN** it uses the default model `claude-opus-4-8`

#### Scenario: OAuth keeps the configured subscription

- **WHEN** the Anthropic auth mode is `oauth`
- **THEN** the driver carries the configured credential and no lookup runs

### Requirement: The AI provider dialog offers organisation credentials

The AI provider dialog SHALL list the caller's personal credentials and their active organisation's
credentials, marking each organisation credential "(organisation)", so an admin can choose an
organisation key without a hand-written request. A failure to load either list SHALL still show the
other.

#### Scenario: An admin picks the organisation's Claude key

- **GIVEN** the admin's active organisation holds an `anthropic` credential "Claude for the municipality"
- **WHEN** the admin opens Admin, Hermiq, AI provider and selects Anthropic
- **THEN** the credential list offers "Claude for the municipality (organisation)"

### Requirement: The organisation credential form shows and chooses its organisation

The organisation credential section in Admin, Hermiq SHALL show which organisation its list and
new credentials belong to, and SHALL let the admin choose another organisation they may manage
(from OpenRegister's `GET /api/credentials/organisations`), defaulting to their active organisation.
The list and the create call SHALL carry the chosen organisation; OpenRegister re-checks the
admin's right to it on both. The AI provider dialog SHALL list the organisation credentials of every
organisation the admin may manage, labelled with the organisation's name.

#### Scenario: The admin stores a key for another organisation

- **GIVEN** an administrator whose active organisation is D and who may manage organisation O
- **WHEN** they choose O in the Organisation picker and add an Anthropic credential
- **THEN** the credential is stored for O and listed under O
- **AND** the AI provider dialog offers it as "<name> (O)"

#### Scenario: A non-manager cannot store a key for someone else's organisation

- **GIVEN** a user who may not manage organisation D
- **WHEN** they ask to list or create an organisation credential for D
- **THEN** OpenRegister answers 403 and stores nothing
