## ADDED Requirements

### Requirement: A background task acts for the task's user

Hermiq SHALL forward the user a Nextcloud TaskProcessing task belongs to as the broker's acting user
whenever it answers that task without a session: for `core:text2text`, `core:text2text:summary`,
`core:text2text:headline` and `core:contextagent:interaction`. A session user SHALL always win over
the task's user. The acting user SHALL be set only for the duration of that task's work and cleared
afterwards, also when the work fails. Hermiq SHALL NOT read the acting user from any request input.

#### Scenario: An Assistant summary runs on Claude from cron

- **GIVEN** the chat provider is `anthropic` with an organisation credential of organisation O
- **AND** user bob, a member of O, scheduled a `core:text2text:summary` task
- **WHEN** cron runs the task with no session
- **THEN** hermiq asks the broker to POST `/v1/messages` with `actingUserId` bob

#### Scenario: Assistant chat runs on Claude from cron

- **GIVEN** the chat provider is `anthropic` and Assistant routes chat to `core:contextagent:interaction`
- **WHEN** cron runs bob's interaction task with no session
- **THEN** the engine turn's broker call carries `actingUserId` bob

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
