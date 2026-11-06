# Run Audit Log Specification

**Status**: active (shipped to `main` v0.1.10; run entries in OR AuditTrail, owner-scoped history read, live-verified)
**Standards**: EU AI Act (Reg. 2024/1689) Art. 12 & 19, AVG/GDPR Art. 30
**Feature tier**: MVP

**OpenSpec changes:**
- `openspec/changes/run-audit-log/` — explicit `action='run'` audit write + full redact.py port + owner-scoped run-history read (kind: code)
- `openspec/changes/archive/2026-07-13-agent-tool-governance-and-disclosure/` — reads these audit entries (degraded fallback) and OR's richer MCP invocation audit log to render a per-agent art.12/14 oversight surface (kind: code) — **DONE** (see `agent-tool-governance`; the fallback triggers whenever OR has not shipped `AuditTrail.toolId`)

## Purpose

Record every agent run and every tool invocation as an immutable, tenant-scoped audit
entry, and show the user a run history. Hermiq gets this almost for free by reusing
OpenRegister's `AuditTrail` (a hash/`previousHash` tamper-evident chain with `organisation`
scoping, GDPR Art. 30 register and DSAR endpoints) — turning EU AI Act record-keeping into
an inherited platform capability rather than net-new code.

## Requirements

### Requirement: Every run and tool call is audited [MVP]
The system MUST write an `AuditTrail` entry for each agent run (start, completion, error),
scoped to the run owner's `organisation`, and MUST record each tool invocation made
during that run as a timed step within that same entry's step timeline — never as a
separate `AuditTrail` entry per tool call. For `hermiq.webSearch`/`hermiq.webFetch`
specifically, that step MUST additionally carry the call's target (the search query or
fetch URL, reduced to host+path with any query string dropped entirely) — every other
tool's step continues to carry only name/timing/outcome, unchanged.

<!-- Previous behavior: every tool-call step carried only name/timing/outcome, by design,
to avoid reintroducing a secret-leak surface into the audit trail. web-research-tool adds
an optional target field populated only for the two web-research tool ids, because for an
outbound web call the destination itself (not just the fact that a call happened) is the
fact a compliance reviewer needs — and a host+path (with the query string dropped
entirely, not selectively masked) carries materially lower leak risk than the raw
arguments/results the original design was protecting against. -->

#### Scenario: A scheduled run is fully audited
- GIVEN a scheduled agent run that calls two tools and completes
- WHEN the run finishes
- THEN the system MUST have written exactly one `AuditTrail` entry for the run, carrying
  `action`, `user`, `organisation`, timestamp, and a hash chained to the previous entry
- AND that entry's step timeline MUST include one step per tool call, each carrying the
  tool name, start/end timestamps, duration, and outcome (`ok`/`error`)

#### Scenario: A web-research tool call's trace step shows its target
- GIVEN a scheduled agent run that calls `hermiq.webFetch` with a URL
- WHEN the run completes and its trace is retrieved by the owner
- THEN the step for that call MUST show the fetched URL reduced to host+path (no query
  string), alongside its existing name/timing/outcome fields

#### Scenario: A web-research tool call's target never carries a query string
- GIVEN a scheduled agent run that calls `hermiq.webFetch` with a URL that includes a
  query string (e.g. containing a session token or search parameter)
- WHEN the step is recorded
- THEN the persisted target MUST NOT include any part of the query string, regardless of
  whether it looks sensitive — the query string is dropped entirely, not selectively
  redacted

#### Scenario: Tool-call step detail depends on the execution path
- GIVEN a scheduled agent run executed via the default OpenRegister `ChatService` path
  (in-app Engine feature flag off)
- WHEN the run finishes
- THEN the system MUST still record context-retrieval, history-build, and
  LLM-generation steps in the run's step timeline
- AND the system MAY omit individual tool-call steps for that run, because Hermiq does
  not instrument OpenRegister's internal tool loop
- AND the persisted entry MUST indicate whether tool-call steps are available for that
  run, so a reader can distinguish "no tools were called" from "tool-level detail
  unavailable on this path"

### Requirement: Sensitive data is redacted before persistence [MVP]
Secrets and PII MUST be redacted from audit payloads **before** they are written to the immutable trail.

#### Scenario: An API key in tool output is masked
- GIVEN a tool result containing an API-key-shaped token
- WHEN the audit entry is created
- THEN the persisted payload MUST show the token masked, because redaction runs before the hash-chained write (which cannot be edited afterwards)

### Requirement: View run history [MVP]
A user MUST be able to see the run history for an agent/schedule they own, with status, timing, and a link to the audit detail.

#### Scenario: Owner reviews recent runs
- GIVEN an agent with several past runs
- WHEN the owner opens its run history
- THEN the system MUST list recent runs (newest first) with status and duration, scoped to what the owner may see

### Requirement: Run history surfaces retry attempts and dead-letter/circuit-breaker outcomes [MVP]

The run-history read surface MUST expose, per run record, the retry attempt
number when the run is part of a retry sequence, and MUST support `status`
values `retry_pending`, `dead_letter`, and `paused_circuit_breaker` (in
addition to the existing `ok` / `error` / `running` / `skipped_killswitch` /
`awaiting_approval` vocabulary), sourced from the audit entry's redacted
context exactly like the existing `status`/`durationMs`/`summary` fields.

#### Scenario: A dead-lettered occurrence's full retry sequence is visible

- GIVEN an occurrence that failed once, retried twice, and was ultimately
  marked `dead_letter`
- WHEN the owner opens run history for that schedule
- THEN the system MUST list each attempt (including both retries) newest-first
  with its own status, timing, and attempt number
- AND the final (most recent) entry MUST show `status='dead_letter'`

#### Scenario: A circuit-breaker auto-pause is visible in run history

- GIVEN a schedule whose third consecutive dead-letter trips the circuit
  breaker
- WHEN the owner opens run history for that schedule
- THEN the entry for that occurrence MUST show `status='paused_circuit_breaker'`
  alongside the prior `dead_letter` entries, so the owner can see why the
  schedule stopped running

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

### Requirement: The delivery trace step reflects the channel actually used [MVP]

The system MUST record the run trace's `delivery` step with a `name` that
reflects the channel `DeliveryResult` reports as actually used
(`"Talk delivery"`, `"Notification delivery"`, `"Email delivery"`,
`"Webhook delivery"`, or `"No delivery"` for `deliver=none`/silent output),
rather than a single hard-coded label. The step's `type` remains `delivery`
in every case, so nothing that filters on step `type` is affected.

#### Scenario: A webhook delivery's trace step is labelled accordingly

- GIVEN a schedule with `deliver=webhook` whose run completes and is
  delivered successfully
- WHEN the owner retrieves that run's trace
- THEN the `delivery` step's `name` MUST read `"Webhook delivery"`
- AND its `type` MUST still read `delivery`

#### Scenario: An email delivery's trace step is labelled accordingly

- GIVEN a schedule with `deliver=email` whose run completes and is
  delivered successfully
- WHEN the owner retrieves that run's trace
- THEN the `delivery` step's `name` MUST read `"Email delivery"`

#### Scenario: Existing Talk delivery labelling is unchanged

- GIVEN a schedule with `deliver=talk` whose run completes and is
  delivered successfully
- WHEN the owner retrieves that run's trace
- THEN the `delivery` step's `name` MUST still read `"Talk delivery"`,
  unchanged from existing behavior

### Requirement: Every AI run MUST carry the retention that applied when it was written

The system MUST resolve a retention period for every AI run from an instance default
an administrator sets, with an optional per-feature override. The resolved period MUST
be written onto the run entry, not referenced, so changing the default later MUST NOT
shorten or extend what an existing run was promised.

The instance MUST have a default. A retention nobody set is a retention of forever.

Candidate C-access-and-privacy-53 (`access-and-privacy.tsv:56`), relevance **`must`**,
driven passer openproject: `AI::TextTransformRun` with status, input and events, and
`ai_text_transform_run_retention_seconds` enforced by
`app/workers/ai/text_transform_runs/cleanup_job.rb`.

#### Scenario: A run knows its own expiry

- **GIVEN** an instance default of ninety days
- **WHEN** a run is recorded
- **THEN** its entry MUST carry ninety days as its retention

#### Scenario: Changing the default does not move an old promise

- **GIVEN** runs recorded under a ninety-day default
- **WHEN** the default is changed to thirty days
- **THEN** those runs MUST still carry ninety days, and new runs MUST carry thirty

#### Scenario: A feature may keep less

- **GIVEN** a feature overriding the retention to seven days
- **WHEN** it runs
- **THEN** the run entry MUST carry seven days

### Requirement: A scheduled job MUST enforce retention, and MUST report that it did

The system MUST run a scheduled job that removes the payload of every run entry past
its retention. The instance MUST report when the job last ran and how many entries it
acted on.

A retention setting without an enforcing job is worse than neither: the screen says
ninety days and the data is still there in year three. The report is what makes the
enforcement checkable rather than assumed.

#### Scenario: Expired runs lose their payload

- **GIVEN** run entries past their recorded retention
- **WHEN** the job runs
- **THEN** their payloads MUST be removed

#### Scenario: The last cleanup is an answerable question

- **WHEN** an administrator asks when retention last ran
- **THEN** the instance MUST answer with a time and a count

#### Scenario: A job that has never run is visible as such

- **GIVEN** an instance where the job has not yet run
- **WHEN** the report is read
- **THEN** it MUST say so, rather than reading as a successful run of zero

### Requirement: Retention MUST remove the payload and MUST NOT break the chain

The system MUST NOT delete an audit chain entry to satisfy retention. It MUST remove
the entry's payload and leave a tombstone carrying that a run happened, when, for
which feature, under which provider, and that the payload was deleted under retention
on a stated date.

Deleting an entry from a hash and `previousHash` chain invalidates every hash after
it, destroying the property the chain exists for. The tombstone keeps the article 30
record of the processing while the personal data is gone, which is what storage
limitation asks for.

#### Scenario: The chain still verifies after a cleanup

- **GIVEN** an audit chain containing entries whose payloads have been removed
- **WHEN** the chain is verified
- **THEN** it MUST verify

#### Scenario: The processing is still recorded after the data is gone

- **GIVEN** a run whose payload has been removed under retention
- **WHEN** its entry is read
- **THEN** it MUST report that the run happened, when, for which feature and provider,
  and that the payload was deleted under retention on a stated date

#### Scenario: No personal data survives the tombstone

- **GIVEN** the same entry
- **WHEN** it is read
- **THEN** the input text and the model output MUST be absent

## User Stories

- As a compliance officer, I want an immutable log of what every agent did so that I can meet EU AI Act record-keeping duties.
- As a user, I want to see whether my scheduled agent ran and what it did so that I trust it.
- As a DPO, I want secrets kept out of the audit trail so that logging does not create a new data-leak.

## Acceptance Criteria

- [ ] Each run (start/complete/error) and each tool call writes an OpenRegister `AuditTrail` entry.
- [ ] Entries carry `organisation`/`user` and chain via `hash`/`previousHash`.
- [ ] Redaction runs **before** any audit write; the persisted payload never contains raw secrets/PII.
- [ ] A run-history view lists an owner's runs with status/timing and links to audit detail.
- [ ] All state writes go through OpenRegister's `ObjectService` (single write-path) so no run escapes the trail.

## Notes

- Reuses OR `AuditTrail`/`SearchTrail` + `verify()` hash-chain endpoint; no new logging store.
- **Single-write-path** and **redaction-before-persist** are compliance-critical invariants —
  enforce as CI gates (ADR-004).
- Related: **ADR-004** (governance via OR AuditTrail), `human-approval-gate` (V1), `run-analytics` (V2).
