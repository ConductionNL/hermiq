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
The system MUST write an `AuditTrail` entry for each agent run (start, completion, error) and each tool invocation, scoped to the run owner's `organisation`.

#### Scenario: A scheduled run is fully audited
- GIVEN a scheduled agent run that calls two tools and completes
- WHEN the run finishes
- THEN the system MUST have written audit entries for the run and each tool call, carrying `action`, `user`, `organisation`, timestamp, and a hash chained to the previous entry

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
