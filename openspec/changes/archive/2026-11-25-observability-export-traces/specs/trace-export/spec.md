# trace-export Specification

**Status**: proposed
**Scope**: hermiq
**OpenSpec changes**:
- observability-export-traces

## Purpose

An administrator sends hermiq's run and tool-call traces to an outside OpenTelemetry tool such as Langfuse, redacted and off the request path. Row `hermiq:ob-external-tracing`.

## ADDED Requirements

### Requirement: An administrator configures trace export with a brokered credential (REQ-TREX-001)

Hermiq MUST let only a Nextcloud administrator switch trace export on, set the OTLP endpoint, choose the sources and the content level, and name a credential by its broker reference. Hermiq MUST NOT store the secret in its own configuration. Trace export MUST be off by default.

#### Scenario: An administrator connects Langfuse
- GIVEN a Nextcloud administrator with a Langfuse project and an organisation-scoped `generic-basic` credential in OpenRegister
- WHEN they open the trace export section, enter `https://cloud.langfuse.com/api/public/otel/v1/traces`, choose the credential and switch export on
- THEN the section shows "Traces go to cloud.langfuse.com. With metadata only, no prompt or answer text leaves this server.", and the Integrations page lists "Trace export"
- e2e: `tests/e2e/spec-coverage/trace-export.spec.ts`

#### Scenario: A non-admin cannot change it
- GIVEN a user who is not an administrator
- WHEN they send `PUT /api/settings/trace-export`
- THEN the answer is 403 and the setting is unchanged
- @e2e exclude a crafted request; covered by PHPUnit and Newman

### Requirement: Traces carry metadata by default and never tool arguments or results (REQ-TREX-002)

Hermiq MUST export for each run or chat turn a root span, a span per model call and a span per tool and guardrail step, with names, timings, outcomes, the model and token counts when known. Hermiq MUST NOT export tool arguments or tool results. Prompt and answer text MUST be exported only when an administrator chose redacted text, and then only after the guardrail output filter and redaction.

#### Scenario: A run with a tool call arrives in the tracing tool
- GIVEN export on with metadata only
- WHEN the scheduled agent "Nachtelijke zaakcontrole" runs and calls `hermiq.readFile`
- THEN the collector receives a trace with a `hermiq.run` span, a model span with `gen_ai.request.model`, and a `hermiq.tool` span named `hermiq.readFile` with outcome `ok`, and no span holds the file's text or the prompt
- @e2e exclude inspects what a collector receives; covered by PHPUnit against a fake OTLP endpoint

#### Scenario: Redacted text hides a secret
- GIVEN export with redacted text on
- WHEN a run's answer contains an API key pattern
- THEN the exported `hermiq.output` shows it masked the way `RedactionService` masks it
- @e2e exclude covered by PHPUnit on the exporter

### Requirement: Export never slows or changes a run (REQ-TREX-003)

Hermiq MUST send traces from a queued background job, never in the request or run that produced them. A failed send MUST be retried a limited number of times and then dropped with a log entry, and MUST NOT change the run's status or its audit entry. Every place that writes a `run` or `agent-run` audit entry MUST hand the run to the export queue when export is on.

#### Scenario: The collector is down
- GIVEN export on and the collector answering 503
- WHEN ten runs complete
- THEN all ten runs complete with their normal status, the job retries each batch three times, then drops it, and the Integrations page shows "Trace export" with the error
- @e2e exclude timing and retries; covered by PHPUnit on the job with a failing endpoint

### Requirement: The export is a copy, and the audit trail stays the record (REQ-TREX-004)

Hermiq MUST keep writing the audit trail exactly as without export, and MUST say on the settings page that exported traces are a copy.

#### Scenario: Switching export off loses no record
- GIVEN a week of runs exported to Langfuse
- WHEN the administrator switches export off
- THEN the run list and every run trace in hermiq still show the same week, and the settings page reads "The audit trail stays the record. Exported traces are a copy for your own tools."
- e2e: `tests/e2e/spec-coverage/trace-export.spec.ts`
