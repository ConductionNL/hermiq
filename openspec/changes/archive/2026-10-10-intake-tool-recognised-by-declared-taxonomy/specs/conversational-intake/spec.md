## ADDED Requirements

### Requirement: An intake tool is recognised by its mark and its declared create taxonomy

The intake surface MUST treat a catalogue entry as an intake tool only when all of these hold: the owning app marks it `citizenIntake: true` in the descriptor's `annotations`, the descriptor declares `scope: create` AND `action: create`, and OpenRegister's own classification (`ToolGrantResolver::isWriteOrDestructive()`) calls it a write. The tool MUST be identified by its dotted `mcpId` when the catalogue carries one, falling back to `name` and then `id`; the intake surface MUST accept both the dotted id and the LLM-safe alias of a permitted tool. The last segment of the id MUST NOT be read as the verb: a curated tool such as `dossiq.fileCase` has none, and an id that ends in `.create` without the declared taxonomy MUST NOT qualify. A mark is a claim and MUST NOT qualify a tool on its own.

#### Scenario: A curated intake tool qualifies
- **GIVEN** the catalogue entry `{name: dossiq_fileCase, mcpId: dossiq.fileCase, scope: create, action: create, annotations: {citizenIntake: true}}`
- **WHEN** the intake surface lists its tools
- **THEN** `dossiq.fileCase` MUST be an intake tool
- **AND** a call by `dossiq.fileCase` or by `dossiq_fileCase` MUST reach the owning app

#### Scenario: An id ending in create is not enough
- **GIVEN** a marked entry `dossiq.melding.create` that declares no `scope` or `action`
- **WHEN** the intake surface is asked to call it
- **THEN** it MUST be refused before the owning app is called

#### Scenario: Both declarations are required
- **GIVEN** a marked entry declaring `scope: create` and `action: reopen`
- **WHEN** the intake surface lists its tools
- **THEN** it MUST NOT be an intake tool

#### Scenario: A refusal folded into the result is still a refusal
- **GIVEN** an owning app's intake tool that refuses, which OpenRegister's provider bridge returns as a result carrying `isError: true` inside a call the facade reports as successful
- **WHEN** the intake surface reads the answer
- **THEN** it MUST treat the filing as refused, so the conversation is handed to a person
