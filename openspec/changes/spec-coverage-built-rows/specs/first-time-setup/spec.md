# first-time-setup

## ADDED Requirements

### Requirement: The setup wizard guides an administrator to a first working provider and agent (REQ-SPCOV-013)

On first use, the setup wizard SHALL walk an administrator through a welcome, an optional
example data set, the model endpoint and a test call to that endpoint. After setup, a
walkthrough SHALL show a new user the dashboard, the agents and the skills on their first
visit.

Rows: `op-setup-wizard`. Board: `hermiq/HmSetup`.

#### Scenario: An administrator sets up the first provider
- **GIVEN** a fresh install
- **WHEN** an administrator opens hermiq, enters an Ollama endpoint in the wizard and runs the test step
- **THEN** the wizard reports that the model answered and the setup is complete

#### Scenario: A new user gets the walkthrough
- **GIVEN** setup is complete
- **WHEN** a user opens hermiq for the first time
- **THEN** the walkthrough starts and points at the agents page
