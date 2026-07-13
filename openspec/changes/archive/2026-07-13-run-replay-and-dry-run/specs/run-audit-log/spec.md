# run-audit-log (delta)

Adds `dryRun`/`replayOf` markers and the exact `prompt` text used to the existing per-run `AuditTrail`
entry's context, and widens the trace's step outcome vocabulary with `would-have-called` — all
additive fields inside the same entry/column `run-audit-log` already writes and
`run-trace-observability` already enriched. No new entry type, no new write path.

## MODIFIED Requirements

### Requirement: Every run and tool call is audited [MVP]
The system MUST write an `AuditTrail` entry for each agent run (start, completion, error), scoped to
the run owner's `organisation`, and MUST record each tool invocation made during that run as a timed
step within that same entry's step timeline — never as a separate `AuditTrail` entry per tool call.
A dry-run or replay run MUST be written using the identical entry shape, additionally marked
`dryRun: true` (and `replayOf: <original runId>` for a replay), and MUST persist the exact prompt text
used for that run so it can later be replayed exactly.

<!-- Previous behavior: this requirement covered only real, side-effecting runs and did not mention
persisting the run's exact prompt text. run-replay-and-dry-run adds dry-run/replay as a first-class,
clearly-marked variant of the same per-run entry, and persists `prompt` so a later replay is not
dependent on the schedule's current (possibly since-edited) prompt field. -->

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

#### Scenario: A dry-run entry is written with its exact prompt and a clear marker
- GIVEN a dry-run of a scheduled agent
- WHEN the run finishes
- THEN the system MUST write one `action='run'` `AuditTrail` entry marked `dryRun: true`
- AND that entry MUST persist the exact prompt text used for the run
- AND a side-effecting tool call within it MUST appear as a step with `outcome='would-have-called'`,
  never `ok`/`error`

#### Scenario: A replay entry references its original run
- GIVEN a replay of a previously completed run
- WHEN the replay finishes
- THEN the system MUST write a new `action='run'` entry marked `dryRun: true` and
  `replayOf: <the original run's id>`

### Requirement: Downloadable, redacted run trace [MVP]
The system MUST let the owner of a schedule retrieve the full, ordered step timeline for one of its
past runs, and MUST apply the same redaction-before-persist guarantee to that data as to every other
part of the audit entry (the trace is read verbatim from the already-redacted entry — no additional
redaction step is needed or skipped at read time). A non-owner MUST be refused exactly as the
existing run-history list already refuses a non-owner. A step recorded with
`outcome='would-have-called'` MUST additionally carry the tool's arguments, redacted before
persistence exactly like every other free-text field in the entry — this is the only step outcome
that carries arguments; `ok`/`error` steps remain name/timing/outcome-only.

<!-- Previous behavior: this requirement covered only name/timing/outcome per step, with tool
arguments/results explicitly never persisted (run-trace-observability Risk 4). run-replay-and-dry-run
adds one narrow, deliberate exception: a `would-have-called` step (which never actually invoked the
tool) additionally carries redacted arguments, since they are the only user-visible content of that
preview step. -->

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

#### Scenario: A would-have-called step's arguments are redacted
- GIVEN a dry-run whose neutralised tool call included a value matching a known secret/PII pattern
- WHEN the owner retrieves that run's trace
- THEN the `would-have-called` step's arguments MUST show that value masked, never in the clear
