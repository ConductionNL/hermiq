# agent-management-ui

## ADDED Requirements

### Requirement: The agent page shows what an agent may use (REQ-SPCOV-001)

The agent page SHALL show, on one page, the skills installed on the agent, the tools it is
granted and the model and provider it runs on. A person SHALL see all three without leaving
the page or opening a dialog.

Rows: `ag-capability-profile`. Board: `hermiq/HmAgent`.

#### Scenario: An owner checks what an agent can reach
- **GIVEN** an agent "Permit helper" with the skill "Summarise a permit", a read grant on the
  permits schema and the model `qwen2.5` on `ollama`
- **WHEN** its owner opens the agent page
- **THEN** the page lists the skill "Summarise a permit", the read grant on permits and
  "ollama, qwen2.5"
- **AND** no dialog has to be opened to see them

### Requirement: An agent answers with the temperature its owner sets (REQ-SPCOV-002)

The agent form SHALL offer a temperature from 0 to 2. The system SHALL send the saved value
to the model provider on every turn of that agent, and SHALL refuse a value outside 0 to 2.

Rows: `dm-temperature`. Board: `hermiq/HmAgentBewerken`.

#### Scenario: A low temperature reaches the provider
- **GIVEN** an agent whose owner set the temperature to 0.2 and saved
- **WHEN** a person sends that agent a message
- **THEN** the provider request carries the temperature 0.2

#### Scenario: A value out of range is refused
- **WHEN** the owner enters 3 as the temperature and saves
- **THEN** the form says the temperature must be between 0 and 2
- **AND** the stored agent keeps its old temperature
