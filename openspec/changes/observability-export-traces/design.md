# Design: observability-export-traces

Kind: code. Size M. A settings controller and section, an exporter, a queued job, one call at each place a run is audited and at the end of a chat turn, and one row in `lib/Settings/connections.json`. No schema changes.

## Context at development db6b74dc

- `lib/Service/Engine/RunTraceCollector.php:111` `startStep()`, `:149` `endStep()`, `:189` `recordProviderDisclosure()`, `:221` `toArray()`.
- `lib/Service/ScheduleService.php:1870-1883` `persistRunAudit()`; `lib/Service/FlowAgentRunService.php:453-457` and `lib/Service/WebhookAgentRunService.php:533` write `agent-run`; `lib/Service/AiFeature/RunRetentionCleaner.php:56` `RUN_ACTIONS = ['run', 'agent-run']`.
- `lib/Service/Engine/Engine.php:509-532` the chat turn's return with `steps`, `usage` and `timings`.
- `lib/Service/RedactionService.php:221` `redact()`; `lib/Service/GuardrailPolicyService.php:326` `filterOutput()`.
- `lib/Service/WebResearch/WebSearchClient.php:63` the broker class name, `:289` `request()`; `lib/Service/Llm/ProviderFactory.php:1556` the one `resolveInjectable()` caller; `lib/Service/Credential/CredentialScopeResolver.php:134` `resolve()`.
- `lib/Service/WebResearch/WebResearchEgressGuard.php:112` `assertSafe()`.
- `lib/Settings/connections.json` (six connections); `lib/Service/Connection/ConnectionReporter.php:129` `report()`, `:160` `reportThrottled()`.
- `openspec/specs/talk-delivery/spec.md:262` "Output crossing the instance boundary is redacted before delivery".
- hermiq ADR-004 (governance on the audit trail; telemetry is not the record), hermiq ADR-023 rule 3 (integration configuration and credentials are admin-only), hydra ADR-064 (no secret on an object; prefer the proxy, injection for hosts that cannot be pinned; infrastructure credentials in organisation scope), hydra ADR-067 (one egress guard), hydra ADR-069 (jobs in `lib/BackgroundJob/`, `QueuedJob` for dynamic work).
- openregister development at c53dd0685c (read only): `lib/Settings/credential-providers.json` inject-only entries `generic-apikey`, `generic-bearer`, `generic-basic`, `generic-oauth2`, `generic-jwt`.

## D1. Settings, admin only, credential by reference

`GET` and `PUT /api/settings/trace-export`, `#[AuthorizedAdminSetting(Application::APP_ID)]` (hermiq ADR-023 rule 3), stored as one `IAppConfig` JSON value `trace_export`:

- `enabled` (default false);
- `endpoint`: the OTLP/HTTP traces URL, for example `https://cloud.langfuse.com/api/public/otel/v1/traces`;
- `credentialId`: a broker credential uuid, organisation scope (ADR-064 decision 4). The settings page links to OpenRegister's credential page to create one; hermiq stores only the reference;
- `sources`: `runs`, `chat` or both;
- `content`: `metadata` (default) or `redacted-text`.

The admin section shows, next to the switch: "Traces go to cloud.langfuse.com. With metadata only, no prompt or answer text leaves this server."

## D2. The credential reaches the request through the broker

Most tracing endpoints are self-hosted or regional, so their host cannot be pinned in OpenRegister's catalog. The exporter uses an inject-only generic provider (`generic-basic` for Langfuse's public and secret key pair, `generic-bearer` or `generic-apikey` for a collector), resolved with `resolveInjectable()` at send time and placed in the `Authorization` header of that one request. That is the documented exception of hydra ADR-064 decision 3; the secret still lives in the broker and never on an object or in `trace_export`. When OpenRegister adds a fixed-host provider for a tracing service, the exporter uses `request()` for it instead.

The endpoint passes the egress guard before every batch (`WebResearchEgressGuard::assertSafe()` until the shared guard of hydra ADR-067 lands), so an endpoint that resolves to a private or metadata address is refused unless the administrator allowed it for a local collector.

## D3. Spans from what hermiq already records

`TraceExporter::fromRun()` and `fromChatTurn()` build one OTLP trace:

- a root span per run or chat turn: `hermiq.run` with the agent id, trigger (`schedule`, `flow`, `webhook`, `chat`), status and duration;
- a child span per model call: `gen_ai.system` (provider), `gen_ai.request.model`, `gen_ai.usage.input_tokens` and `gen_ai.usage.output_tokens` when the driver reported them, and hermiq's residency and feature as `hermiq.*` attributes;
- a child span per trace step: `hermiq.tool` or `hermiq.guardrail` with name and outcome. No arguments and no results, as in the trace itself (hydra ADR-088, decision 4).

The resource carries `service.name=hermiq`, the instance id and the organisation. User ids are sent as a keyed hash, so a trace can group one person's runs without naming them.

## D4. Text only after the filter and redaction

With `content: redacted-text`, the root span gains `hermiq.input` and `hermiq.output`: the prompt and the answer or run summary, each passed through the organisation's guardrail output filter and then `RedactionService::redact()`, capped at 4000 characters. That is the same rule the `talk-delivery` spec applies to output that crosses the instance boundary. A turn whose output the guardrail blocked exports "[withheld by policy]".

## D5. Never in the way of a run

The four audit writers and the end of `Engine::processMessage()` call `TraceExportQueue::enqueue()` with the run's id and nothing else, only when export is on. `TraceExportJob` (a `QueuedJob` in `lib/BackgroundJob/`) builds the spans from the audit entry or the stored turn, sends batches of up to 50 traces with a 10 second timeout, retries a failed batch three times with backoff, then drops it and logs one warning. A failure never changes a run's status.

After each batch the job reports the `trace-export` connection: `ok` with the time of the last send, or `error` with the HTTP status, throttled like webhook delivery.

A test asserts that every place writing a `run` or `agent-run` entry calls `enqueue()`, so a new writer cannot silently skip export.

## D6. It is a copy, not the record

The export is for operators. The audit trail on OpenRegister stays the record of what an agent did (hermiq ADR-004), and switching export off or losing a batch changes nothing there. The settings page says so: "The audit trail stays the record. Exported traces are a copy for your own tools."

## Declarative versus imperative

No schema changes. The `trace-export` row in `lib/Settings/connections.json` is declarative. Sending spans to an outside endpoint is external integration work, which hydra ADR-031 leaves imperative.

## Seed data

None. `connections.json` gains:

`{ "key": "trace-export", "title": "Trace export", "description": "Sends run and chat traces over OpenTelemetry (OTLP) to a tool such as Langfuse.", "order": 70, "settingsUrl": "/settings/admin/hermiq#section-trace-export", "unconfiguredMessage": "Trace export is off. Switch it on in the trace export section and choose a credential." }`

## Risks

- Text leaks through a field redaction does not know. Mitigation: metadata only is the default, text is an explicit admin choice with the warning in D1, and tool arguments and results are never exported.
- A slow collector backs up the queue. Mitigation: batches, a 10 second timeout, three retries, then drop; the queue never grows without bound because a job holds at most one batch.
- An administrator points the endpoint at an internal address. Mitigation: the egress guard of D2.
