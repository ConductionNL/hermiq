---
kind: code
depends_on: [adopt-connection-registry]
---

# Proposal: observability-export-traces

## Summary

A Nextcloud administrator can send hermiq's run and tool-call traces to an outside tracing tool that speaks OpenTelemetry (OTLP), such as Langfuse, a Grafana Tempo or a Jaeger collector. By default a trace carries names, timings, outcomes, the model and token counts, and no prompt or answer text; an administrator can switch on redacted text as well. The endpoint and its credential are set in admin settings, the credential sits in OpenRegister's credential broker, and the export shows up as a connection on the Integrations page. The export runs after the fact and never slows or blocks a run, and it does not replace the audit trail.

## Why

One row of hermiq's capability matrix, decided in the OpenSpec pass of 2026-09-27.

| row | own rating | decision |
|---|---|---|
| `hermiq:ob-external-tracing` | no | build: four competitors rate yes |

Competitor cells rated yes, quoted from the matrix:

- Hermes agent: "plugins/observability/langfuse/plugin.yaml:1-3 bundled Langfuse plugin traces conversations, LLM calls and tool usage (opt-in); agent/monitoring/otlp_exporter.py:1-6 OTLP export".
- Dify: "api/controllers/console/app/ops_trace.py per-app tracing config; api/core/ops/ops_trace_manager.py sends traces to Langfuse, LangSmith, Opik, Weave, Arize, Phoenix, MLflow, Databricks, Aliyun, Tencent".
- n8n: "packages/cli/src/modules/otel (default module, packages/@n8n/backend-common/src/modules/module-registry.ts:70) exports workflow and agent spans over OTLP (otel.config.ts:9-21 endpoint, protocol, headers)", with the note "Langfuse is reached through its OTLP endpoint, not a named integration."
- Open WebUI: "backend/open_webui/env.py:1266 ENABLE_OTEL_TRACES, :1270 OTEL_EXPORTER_OTLP_ENDPOINT, utils/telemetry/setup.py and instrumentors.py send traces to any OTLP collector (Langfuse accepts OTLP)".

## What hermiq already has

- Every run records a step timeline (tool, guardrail and context steps with name, outcome and duration) in `RunTraceCollector` (`lib/Service/Engine/RunTraceCollector.php:111`, `:149`, `:221`), plus the feature, provider, model and residency that served it (`:189`).
- Runs are written to OpenRegister's audit trail as `run` (`lib/Service/ScheduleService.php:1870-1883`) and `agent-run` (`lib/Service/FlowAgentRunService.php:453-457`, `lib/Service/WebhookAgentRunService.php:533`), and read back by the run list and the trace view (`lib/Service/AnalyticsService.php:106`, `lib/Service/RunHistoryService.php:163`).
- Token usage reaches the engine on Ollama, and latency on every driver (`lib/Service/Engine/ResponseGenerationHandler.php:388`, `:451-453`, `:491-496`).
- Output that leaves the instance is redacted first: the `talk-delivery` requirement "Output crossing the instance boundary is redacted before delivery" (`openspec/specs/talk-delivery/spec.md:262`, from the archived `delivery-channels` change) with `RedactionService::redact()` (`lib/Service/RedactionService.php:221`).
- Outside calls go through OpenRegister's credential broker: `request()` for a fixed host (`lib/Service/WebResearch/WebSearchClient.php:289`), and `resolveInjectable()` once, for the CLI token (`lib/Service/Llm/ProviderFactory.php:1556`). OpenRegister's catalog offers inject-only generic providers `generic-basic`, `generic-bearer` and `generic-apikey` for hosts it cannot pin (openregister `lib/Settings/credential-providers.json`, development at c53dd0685c).
- `lib/Settings/connections.json` declares six connections for the Integrations page, and `ConnectionReporter` reports what hermiq observes (`lib/Service/Connection/ConnectionReporter.php:129`, `:160`), from the open `adopt-connection-registry` change.
- Nothing exports a trace: no OTLP, Langfuse or OpenTelemetry code in `lib/` or `src/`.
- hermiq ADR-004 rejected "observer-hook telemetry (Langfuse/OTel)" as the compliance record, because "Telemetry is exported, sanitized, and not a durable compliance record".

## What this change builds

1. Admin settings for trace export: on or off, the OTLP/HTTP endpoint, the broker credential, what to export (runs, chat turns) and how much (metadata only, or redacted text).
2. `TraceExporter`: turns a finished run or chat turn into OTLP spans, one per run with child spans per model call and per tool or guardrail step, with OpenTelemetry's generative AI attribute names where they exist.
3. Export off the request path: a queued background job sends batches, retries a failed batch with backoff, and drops it after the last try with a log line.
4. Redaction before anything leaves: with text switched on, prompts, answers and summaries pass the guardrail output filter and `RedactionService::redact()` first. Tool arguments and results are never exported.
5. A `trace-export` connection in `lib/Settings/connections.json`, reported after each batch.

## Out of scope

- Replacing or mirroring the audit trail. The export is a copy for operators; the audit trail stays the record (hermiq ADR-004).
- Metrics. The Prometheus endpoint stays as it is (`/api/metrics`).
- A Langfuse-specific client or SDK. OTLP is the one protocol; Langfuse, Tempo and Jaeger all accept it.
- Per-organisation export targets. One target per instance in this change.
