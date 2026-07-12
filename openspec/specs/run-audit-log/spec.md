# Run Audit Log Specification

**Status**: active (shipped to `main` v0.1.10; run entries in OR AuditTrail, owner-scoped history read, live-verified)
**Standards**: EU AI Act (Reg. 2024/1689) Art. 12 & 19, AVG/GDPR Art. 30
**Feature tier**: MVP

**OpenSpec changes:**
- `openspec/changes/run-audit-log/` — explicit `action='run'` audit write + full redact.py port + owner-scoped run-history read (kind: code)
- `openspec/changes/agent-tool-governance-and-disclosure/` — reads these audit entries (degraded fallback) and OR's richer MCP invocation audit log to render a per-agent art.12/14 oversight surface (kind: code) — **proposed**

## Purpose

Record every agent run and every tool invocation as an immutable, tenant-scoped audit
entry, and show the user a run history. Hermiq gets this almost for free by reusing
OpenRegister's `AuditTrail` (a hash/`previousHash` tamper-evident chain with `organisation`
scoping, GDPR Art. 30 register and DSAR endpoints) — turning EU AI Act record-keeping into
an inherited platform capability rather than net-new code.
## Requirements
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
