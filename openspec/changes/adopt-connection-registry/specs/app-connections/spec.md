# app-connections Specification Delta

## ADDED Requirements

### Requirement: Hermiq declares its outside connections in one static file (REQ-HERMIQ-CONN-001)

Hermiq SHALL declare its outside connections in `lib/Settings/connections.json`
following hydra connection-registry design D2 and D12. The file MUST validate
against integriq's `connections.schema.json`, its `app` MUST equal the id in
`appinfo/info.xml`, and every key MUST be unique. A `settingsUrl` SHALL point
only at an element id that exists in `src/` or `templates/`. A family of
per-record targets, such as webhook targets, SHALL be declared as one row. A
connection to Nextcloud on the same instance SHALL NOT be declared.

#### Scenario: the declaration lists the six connections
@e2e app-connections::the-declaration-lists-the-six-connections

- **GIVEN** hermiq and integriq are installed and integriq has synced
- **WHEN** an admin opens the Integrations page
- **THEN** the page SHALL list the six declared connections in declared order
- **AND** every row SHALL have `app` equal to `hermiq`

#### Scenario: a settings link lands on a section that exists
@e2e app-connections::a-settings-link-lands-on-a-section-that-exists

- **GIVEN** the AI provider, web search and GitHub store rows carry a `settingsUrl`
- **WHEN** the admin follows one
- **THEN** the hermiq admin settings page SHALL hold an element with that id

#### Scenario: an unset AI provider does not read Simulated
@e2e exclude The claim is a property of the declaration and the save report. tests/Unit/Settings/ConnectionsDeclarationTest.php (testNoRowCanReadSimulated) asserts no row carries an adapter block, and tests/Unit/Controller/Settings/LlmSettingsConnectionReportTest.php (testASaveWithoutAProviderReportsUnconfigured) asserts the unconfigured report.

- **GIVEN** no chat provider is chosen
- **WHEN** the admin saves the AI provider settings
- **THEN** hermiq SHALL report `llm` as `unconfigured`
- **AND** the declaration SHALL NOT mark any row as simulated

### Requirement: The Integrations page lists hermiq's rows from integriq (REQ-HERMIQ-CONN-002)

Hermiq SHALL render an Integrations page as an `index` page over
`integriq/app_connection` at `/settings/integrations`, admin only, with
`requiresApp` integriq. Its menu entry SHALL carry `query: {app: hermiq}` and
`visibleIf.appInstalled: integriq`, and SHALL sit in the settings foldout. The
page SHALL NOT offer the generic Add button. Its Add integration header action
SHALL open `/apps/integriq/connections?app=hermiq&link=1`.

#### Scenario: the page opens on hermiq's own rows
@e2e app-connections::the-page-opens-on-hermiqs-own-rows

- **GIVEN** integriq holds rows for hermiq and other apps
- **WHEN** an admin opens Integrations from the settings foldout
- **THEN** only rows with `app` equal to `hermiq` SHALL be listed
- **AND** each status SHALL render as Configured, Limited, Not configured, Simulated, Not available or Error

#### Scenario: Add integration goes to integriq
@e2e app-connections::add-integration-goes-to-integriq

- **GIVEN** the Integrations page
- **WHEN** the admin chooses Add integration
- **THEN** the browser SHALL open integriq's Connections overview with `app=hermiq` and `link=1`
- **AND** no generic Add button SHALL be offered

#### Scenario: without integriq the page says what is missing
@e2e exclude No browser flow reaches a hermiq instance without integriq once integriq is installed for this spec. tests/connections-page.spec.js asserts the requiresApp and visibleIf declarations.

- **GIVEN** integriq is not installed
- **WHEN** an admin opens `/settings/integrations` by URL
- **THEN** the missing-app screen SHALL name Integriq
- **AND** the settings foldout SHALL NOT list Integrations

### Requirement: Hermiq reports what only it can observe (REQ-HERMIQ-CONN-003)

Hermiq SHALL send `OCA\Integriq\Event\ConnectionStatusReportedEvent` for a
connection whose state it observes. The class SHALL be named by string and sent
only when it exists. A report SHALL NOT change the response or outcome of the
request or job that sent it, whether integriq is absent or its listener fails.
A report SHALL be sent on a settings save or, throttled, on an outcome hermiq
already met. It SHALL NOT be sent on a page load (ADR-076).

#### Scenario: saving the web research settings reaches the row
@e2e app-connections::saving-the-web-research-settings-reaches-the-row

- **GIVEN** the web search row reads Not configured
- **WHEN** the admin saves a SearXNG endpoint in the web research section
- **THEN** hermiq SHALL report `web-search` as `configured`, naming the endpoint host
- **AND** the web search row SHALL read Configured on the next page load

#### Scenario: a Nextcloud Assistant provider reads Limited
@e2e exclude Nextcloud Assistant needs a TaskProcessing provider app that the browser suite does not install. tests/Unit/Controller/Settings/LlmSettingsConnectionReportTest.php (testANextcloudProviderReportsLimited) asserts the report.

- **GIVEN** the admin chooses Nextcloud Assistant as the provider
- **WHEN** the AI provider settings are saved
- **THEN** hermiq SHALL report `llm` as `limited`
- **AND** the message SHALL say chat cannot run on it

#### Scenario: a GitHub rate limit reads Limited, and repeats are throttled
@e2e exclude A GitHub rate limit cannot be caused on demand from the browser suite. tests/Unit/Service/GitHubTemplateCatalogConnectionReportTest.php asserts the report, and tests/Unit/Service/Connection/ConnectionReporterTest.php asserts the throttle.

- **GIVEN** GitHub answers a store search with HTTP 403
- **WHEN** the search runs twice within an hour
- **THEN** hermiq SHALL report `github-templates` as `limited` once
- **AND** a cached search SHALL report nothing

#### Scenario: a failed webhook delivery turns the row red
@e2e exclude A failing webhook target cannot be stood up from the browser suite. tests/Unit/Service/DeliveryServiceConnectionReportTest.php asserts the report.

- **GIVEN** a schedule's webhook target answers HTTP 500 on every attempt
- **WHEN** the delivery gives up
- **THEN** hermiq SHALL report `webhook-delivery` as `error`, naming the host and the attempts
- **AND** the delivery result SHALL be the same as without the report

#### Scenario: without integriq nothing is sent
@e2e exclude The browser suite installs integriq for this spec. tests/Unit/Service/Connection/ConnectionReporterTest.php asserts nothing is dispatched, stored or logged when the event class is absent.

- **GIVEN** integriq is not installed
- **WHEN** the admin saves the AI provider settings
- **THEN** no event SHALL be sent and no warning SHALL be logged
- **AND** the save response SHALL be unchanged

#### Scenario: a failing listener never reaches the request
@e2e exclude A throwing listener cannot be installed from a browser. tests/Unit/Service/Connection/ConnectionReporterTest.php asserts the exception is caught and logged.

- **GIVEN** integriq's report listener throws
- **WHEN** a store search reports
- **THEN** the search SHALL answer as it would without the report
- **AND** the failure SHALL be logged as a warning naming the connection

### Requirement: The runner and the speech sidecar read honestly (REQ-HERMIQ-CONN-004)

The `llm-runner` row SHALL read Not configured while no chat turn routes through
the runner, and Error when turns route through it and AppAPI cannot find it
enabled. The `speech` row SHALL read Configured only when `speech_base_url` is
filled, and its unconfigured message SHALL name the local default hermiq falls
back to.

#### Scenario: the runner reads Error when cli is on and the ExApp is missing
@e2e exclude Setting executionMode cli needs the llm app config written without the admin form, and AppAPI is not installed in the browser suite. tests/Unit/Controller/Settings/LlmSettingsConnectionReportTest.php (testCliWithoutTheRunnerReportsAnError) asserts the report.

- **GIVEN** Anthropic is the chat provider with `executionMode` `cli`
- **AND** the `hermiq-llm-runner` ExApp is not installed
- **WHEN** the AI provider settings are saved
- **THEN** hermiq SHALL report `llm-runner` as `error` with the reason AppAPI gave

#### Scenario: the speech row names the fallback
@e2e app-connections::the-speech-row-names-the-fallback

- **GIVEN** `speech_base_url` is empty
- **WHEN** the admin reads the speech row
- **THEN** it SHALL read Not configured
- **AND** its message SHALL name `http://127.0.0.1:8000`
