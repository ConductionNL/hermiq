## ADDED Requirements

### Requirement: Hermiq provides the text2text task-type family to the whole instance

The system MUST register `ISynchronousProvider` implementations for the
`core:text2text`, `core:text2text:summary` and `core:text2text:headline` task types,
each backed by Hermiq's configured LLM layer. A registered provider makes
`IManager::hasProviders()` report `true`, so any TaskProcessing consumer on the
instance (Assistant, Mail, decidesk) is served without changes to the consumer.

#### Scenario: A text2text provider returns the model's reply under `output`

- **GIVEN** Hermiq has a working chat provider configured (not `nextcloud`)
- **WHEN** the `core:text2text` provider's `process(userId, {input: "hi"}, report)` runs
- **THEN** it MUST return an array whose `output` is the LLM's generated reply

#### Scenario: Each provider reports its own task-type id

- **THEN** `Text2TextProvider::getTaskTypeId()` MUST be `core:text2text`
- **AND** `Text2TextSummaryProvider::getTaskTypeId()` MUST be `core:text2text:summary`
- **AND** `Text2TextHeadlineProvider::getTaskTypeId()` MUST be `core:text2text:headline`

#### Scenario: A missing input is a processing error

- **WHEN** `process()` is called with an empty or absent `input` slot
- **THEN** it MUST throw a `ProcessingException`

### Requirement: A Hermiq TaskProcessing provider never recurses through the nextcloud driver

When Hermiq's own TaskProcessing providers generate text, they MUST NOT resolve to
the `nextcloud` (TaskProcessing-backed) chat driver — doing so would call
TaskProcessing from inside a TaskProcessing provider and recurse. The shared
`ProviderFactory::generateText()` MUST reject the `nextcloud` driver when invoked
with recursion protection enabled.

#### Scenario: The nextcloud driver is refused for provider-backed generation

- **GIVEN** `hermiq.llm.chatProvider` is `nextcloud`
- **WHEN** a Hermiq text2text provider's `process()` calls
  `generateText(..., allowNextcloud: false)`
- **THEN** generation MUST fail rather than call TaskProcessing again
- **AND** the failure MUST surface to the caller as a `ProcessingException`
