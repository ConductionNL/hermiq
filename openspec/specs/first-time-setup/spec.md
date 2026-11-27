# first-time-setup Specification

## Purpose
The first-time setup wizard lets an administrator pick an example dataset and load it in the same step, from a card that runs the load itself. The server accepts only known datasets, records the pick only after a successful load, and reports every step of the wizard manifest from the setup status endpoint, so the wizard and the server agree on what is done.

## Requirements

### Requirement: Each example data card loads itself

The `demo-data` setup step MUST be a cards choice step with `loadAction: load-demo-data`. The setup wizard MUST NOT carry a separate run-action step that loads the picked dataset.

#### Scenario: The operator loads a dataset from its card

- GIVEN the setup wizard shows the example data cards
- WHEN the operator presses Load on a card
- THEN the wizard posts `{ "dataset": <card value> }` to `/api/setup/action/load-demo-data`
- AND the server loads that dataset
- AND the server records the dataset as the pick only after the load succeeds
- @e2e exclude the card and its spinner are CnSetupWizard UI, tested in nextcloud-vue; the posted body is covered by tests/Unit/Controller/SetupControllerDemoDataTest.php

#### Scenario: An unknown dataset is refused

- GIVEN a dataset id that no card offers
- WHEN it is posted to `/api/setup/action/load-demo-data`
- THEN the server answers 400 with `success: false`
- AND nothing is loaded or stored
- @e2e exclude API contract without a browser path of its own; covered by tests/Unit/Controller/SetupControllerDemoDataTest.php

#### Scenario: A call without a body keeps working

- GIVEN a dataset was stored through `/api/setup/config`
- WHEN `/api/setup/action/load-demo-data` is called without a body
- THEN the stored dataset is loaded
- @e2e exclude API contract without a browser path of its own; covered by tests/Unit/Controller/SetupControllerDemoDataTest.php

#### Scenario: A failed load leaves the step open

- GIVEN the load of the posted dataset fails
- WHEN the server answers
- THEN the answer carries `success: false`
- AND no pick or decision is stored
- @e2e exclude needs a load that fails on a live instance; covered by tests/Unit/Controller/SetupControllerDemoDataTest.php

### Requirement: Setup status reports every manifest step

`GET /api/setup/status` MUST report a `done` state for every step id in `manifest.setup.steps`.

#### Scenario: The status ids match the manifest

- GIVEN the Hermiq manifest
- WHEN an administrator reads `/api/setup/status`
- THEN `steps` holds an entry for every manifest step id
- AND the retired load step is not reported
- @e2e exclude API contract without a browser path of its own; covered by tests/Unit/Controller/SetupControllerDemoDataTest.php

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
@e2e exclude Needs a fresh install with no provider and an answering Ollama endpoint, which the shared e2e instance is not; the wizard's presence is checked by dismissTour() in tests/e2e/spec-coverage/_fixtures.ts on every run.

#### Scenario: A new user gets the walkthrough
- **GIVEN** setup is complete
- **WHEN** a user opens hermiq for the first time
- **THEN** the walkthrough starts and points at the agents page
