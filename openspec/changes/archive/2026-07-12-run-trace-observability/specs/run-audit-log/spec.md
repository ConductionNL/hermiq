# run-audit-log (delta)

Corrects and enriches the existing per-run `AuditTrail` entry with an ordered step timeline
(context/history/LLM/tool/delivery), replacing the unimplemented claim that each tool invocation
gets its own separate audit entry with the actual, honest model: each tool invocation becomes a
timed step *within* the single per-run entry `run-audit-log` already writes. Adds a downloadable,
redaction-applied trace read endpoint for one run.

## MODIFIED Requirements

### Requirement: Every run and tool call is audited [MVP]
The system MUST write an `AuditTrail` entry for each agent run (start, completion, error), scoped to
the run owner's `organisation`, and MUST record each tool invocation made during that run as a timed
step within that same entry's step timeline — never as a separate `AuditTrail` entry per tool call.

<!-- Previous behavior: this requirement stated each tool invocation is audited as its own AuditTrail
entry. At HEAD, no such per-tool-call entry was ever implemented — only the single per-run entry
existed, with no tool-call detail at all. This correction aligns the requirement with the actual,
shipped model (one entry per run, enriched with a step timeline) rather than the unimplemented claim. -->

#### Scenario: A scheduled run is fully audited
- GIVEN a scheduled agent run that calls two tools and completes
- WHEN the run finishes
- THEN the system MUST have written exactly one `AuditTrail` entry for the run, carrying `action`,
  `user`, `organisation`, timestamp, and a hash chained to the previous entry
- AND that entry's step timeline MUST include one step per tool call, each carrying the tool name,
  start/end timestamps, duration, and outcome (`ok`/`error`)

#### Scenario: Tool-call step detail depends on the execution path
- GIVEN a scheduled agent run executed via the default OpenRegister `ChatService` path (in-app
  Engine feature flag off)
- WHEN the run finishes
- THEN the system MUST still record context-retrieval, history-build, and LLM-generation steps in
  the run's step timeline
- AND the system MAY omit individual tool-call steps for that run, because Hermiq does not
  instrument OpenRegister's internal tool loop
- AND the persisted entry MUST indicate whether tool-call steps are available for that run, so a
  reader can distinguish "no tools were called" from "tool-level detail unavailable on this path"

## ADDED Requirements

### Requirement: Downloadable, redacted run trace [MVP]
The system MUST let the owner of a schedule retrieve the full, ordered step timeline for one of its
past runs, and MUST apply the same redaction-before-persist guarantee to that data as to every other
part of the audit entry (the trace is read verbatim from the already-redacted entry — no additional
redaction step is needed or skipped at read time). A non-owner MUST be refused exactly as the
existing run-history list already refuses a non-owner.

#### Scenario: An owner retrieves a run's step timeline
- GIVEN a schedule owner with a completed run that called one tool and delivered output
- WHEN the owner requests that run's trace
- THEN the system MUST return the run's ordered steps (context, tool, LLM, delivery — and, when the
  run was gated, a leading approval-wait step) with timestamps, durations, and outcomes
- AND the response MUST NOT contain any raw secret- or PII-shaped value that `RedactionService`
  would have masked in the run's summary

#### Scenario: A non-owner cannot retrieve another owner's run trace
- GIVEN a schedule owned by user A with at least one completed run
- WHEN a different authenticated user B requests that run's trace
- THEN the system MUST refuse with HTTP 404 (not 403), identical to the existing run-history list's
  anti-probing behavior
- AND no step data MUST be returned to user B

#### Scenario: A gated run's trace shows the approval wait
- GIVEN a schedule with `requiresApproval=true` that was skipped for one or more ticks awaiting a
  decision before an approved run finally executed
- WHEN the owner requests that run's trace
- THEN the system MUST include a leading step representing the time between the first
  `awaiting_approval` occurrence and the run's actual start
- AND the system MUST NOT include a gate-wait step when no such adjacent gate-skip entry precedes
  the run
