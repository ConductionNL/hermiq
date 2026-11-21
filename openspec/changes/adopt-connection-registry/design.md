# Design: adopt-connection-registry

The contract is hydra `openspec/changes/connection-registry/design.md`. This file
records how hermiq meets it and where it does not fit.

## D1. The declaration

Six connections, each checked against the code on `development` (2026-09-14).

| Key | What it is | How its status is known |
|---|---|---|
| `llm` | The chat provider in the `llm` JSON blob: OpenAI, Ollama, Fireworks AI, Anthropic or Nextcloud Assistant | Report on AI provider save |
| `llm-runner` | The `hermiq-llm-runner` ExApp, reached through AppAPI, that runs Anthropic turns with `executionMode: cli` | Report on AI provider save |
| `speech` | The Whisper and Kokoro sidecar at `speech_base_url` | `requiredConfig` |
| `web-search` | SearXNG or a generic JSON search API in the `webResearch` blob | Report on web research save |
| `webhook-delivery` | Signed outbound webhooks. Each schedule sets its own target, so this is one row for the family | Throttled report on a delivery outcome |
| `github-templates` | `api.github.com` for the template and skill store, through the credential broker or anonymous | Throttled report on a store search |

- `settingsUrl` points only at an element that exists. `#section-ai-provider`,
  `#section-web-research` and `#section-organisation-credentials` are added to
  the `NcSettingsSection` roots in `src/views/AdminRoot.vue` by this change.
  `NcSettingsSection` has one root element, so the `id` falls through to it.
- `llm-runner`, `speech` and `webhook-delivery` carry no link. `executionMode`
  and `speech_base_url` have no admin field, and a webhook target belongs to one
  schedule.
- Not declared: the Talk chat bridge and Talk delivery (Nextcloud Talk on the same
  instance), email delivery (the instance mail server), `web.fetch` (one record
  per call, contract D12) and the setup wizard's `llmendpoint` (tested by the
  wizard, read by no chat path).

## D2. The LLM rows do not use `adapter`

The audit suggested `adapter.configKey: llm` with `jsonPath: chatProvider`. That
would be false. With no chat provider, `ProviderFactory::createChatDriver` throws
a 503. No mock answers, so the row must never read Simulated. The contract has
no declaration that turns an empty JSON path into Not configured, so both rows
carry no adapter block, and `LlmSettingsController::update` reports what it saved.

The report asks the same factory the engine uses, so the page cannot disagree
with what a chat turn meets. Building a driver makes no network call.

`llm`:

- no chat provider: `unconfigured`, saying chat answers 503;
- the factory refuses the saved provider, for example no credential:
  `unconfigured` with the factory's own reason;
- `nextcloud`: `limited`. Background work runs through TaskProcessing, and
  `ResponseGenerationHandler` refuses it for chat;
- any other provider that builds: `configured`, naming it and saying it is not
  tested.

`llm-runner`:

- Anthropic is not the chat provider, or its `executionMode` is not `cli`:
  `unconfigured`, saying nothing routes turns through the runner;
- `cli` is on and `ProviderFactory::assertCliRunnerAvailable` passes:
  `configured`, saying AppAPI reports the ExApp enabled and it is not tested;
- `cli` is on and the check fails: `error` with the check's reason.

`assertCliRunnerAvailable` becomes public for this. It reads AppAPI's own ExApp
table and makes no call to the runner.

## D3. Web search reports on save

`web.search` answers `search_unavailable` when the provider or the endpoint is
empty. That is off, not mocked, so the row follows the LLM route:

- provider and endpoint set: `configured`, naming the provider and the endpoint
  host, not tested;
- otherwise: `unconfigured`, saying `web.search` answers `search_unavailable`.

The message carries the host, never the full endpoint, which may hold a query
string with a key.

## D4. Throttled reports

A store search and a webhook delivery are the only moments hermiq meets GitHub or
a webhook target. Reporting every one would write integriq's row on each search
(ADR-076). `ConnectionReporter::reportThrottled` keeps one memory per connection
in a lazy app-config key, `connection_report_<key>`, holding `status|time`:

- the same status is sent again after an hour;
- a different status is sent after five minutes, so two schedules that disagree
  cannot report on every run;
- the memory is written only when the event was sent.

`github-templates`, from `GitHubTemplateCatalogService::search`, on a live call
only (a cached answer met nothing):

- answered: `configured`, saying whether a credential was used;
- rate limited: `limited`, pointing at Organisation credentials;
- unreachable: `error`.

`webhook-delivery`, from `DeliveryService::deliverWebhook`, after a POST was
attempted:

- delivered: `configured`, naming the target host;
- retries exhausted: `error`, naming the host and the number of attempts.

A schedule with no target or no signing secret sends nothing: that is a gap in
one schedule, not a fact about the connection.

## D5. Reports and refreshes

`OCA\Hermiq\Service\Connection\ConnectionReporter` mirrors openregister#3730 and
zaakafhandelapp#693:

- `STATUS_EVENT` is a string constant, resolved with `class_exists` (ADR-041).
  Without integriq nothing is sent, stored or logged.
- `report()` refuses a key outside `KEYS` or a status outside `STATUSES` with a
  warning. `STATUSES` includes `limited`.
- A listener that throws is caught and logged. It never reaches the request.

**No refresh event.** Contract D6 and hydra#674 ask for
`ConnectionRefreshRequestedEvent` on a save that writes a declared config key,
meaning a `requiredConfig` or `adapter.configKey`. The only such key here is
`speech_base_url`, and no hermiq screen or endpoint writes it. `occ` does, and the
contract's hourly resolver covers that (D7). The two saves that do exist, AI
provider and web research, write keys no row declares, and each sends a report
that is newer than any earlier observation. So hermiq has no caller for the
refresh event and does not ship one.

Callers:

| Caller | Sends |
|---|---|
| `LlmSettingsController::update` | report `llm` and `llm-runner` |
| `WebResearchSettingsController::update` | report `web-search` |
| `GitHubTemplateCatalogService::search` | throttled report `github-templates` |
| `DeliveryService::deliverWebhook` | throttled report `webhook-delivery` |

A failed save reports nothing. Nothing reports on a page load.

## D6. The page

- Page id `Integrations`, route `/settings/integrations`, title Integrations,
  `type: index` over `integriq/app_connection`, `requiresApp` integriq,
  `showAdd: false`, the columns of contract D8 and a folder sidebar on status.
- Menu entry `Integrations` in the settings foldout with
  `query: {app: hermiq}` and `visibleIf.appInstalled: integriq`.
- `src/customComponents.js` gains `openIntegriqConnections`. Hermiq already passes
  `customComponents` to `CnAppRoot`. `App.vue` now passes `formatters` too, built
  by `src/services/connectionRegistry.js`.

## Risks

- **An `occ` change to `llm` or `webResearch` leaves the row stale** until the next
  save through the admin panel. These rows have no declared keys, so the hourly
  resolver cannot see the change.
- **Speech reads Configured from a filled key.** Nothing probes the sidecar for
  the row. The capabilities endpoint does probe it, but it runs for every user
  and hermiq keeps it off the report path.
- **A family row shows the last outcome.** One broken webhook target turns the
  row red while other schedules deliver. The message names the host, so the
  admin can tell which.
