# Design: run-audit-log

## Context

Hermiq is a thin scheduling + management app over OpenRegister's agent core
(ADR-001). Governance is inherited, not rebuilt (ADR-004): OpenRegister ships a
tamper-evident `AuditTrail` (`hash`/`previousHash` chain, `organisation`
scoping, GDPR Art. 30 verwerkingsregister + DSAR + `verify()`), and every
`ObjectService::saveObject()` already auto-writes an audit entry via
`AuditTrailMapper::createAuditTrail(old, new, action)`. `ScheduleService` already
saves the Schedule object twice per run (commit-before-run + finalise), so each
run *already* leaves audit traces of its Schedule mutations.

Research into `../openregister/lib` confirmed the reusable surface:

- **Auto-audit on save**: `SaveObject.php` (insert/update), `GetObject.php`
  (read), `DeleteObject.php` (delete) all call `createAuditTrail(...)`. Confirmed
  — the dispatcher's saves already audit.
- **App-writable explicit entry**:
  `AuditTrailMapper::createAuditTrailEntry(ObjectEntity $object, string $action, array $context=[]): AuditTrail`
  — public; sets `user`/`user_name` from the current session (so owner
  impersonation yields the right actor), `object_uuid`/`register`/`schema` from
  the passed object, `changed` from `$context`, and `insert()`s into the same
  chain the `verify()` endpoint recomputes. This is the net-new write seam.
- **Read a specific object's trail**:
  `ObjectService::getLogs(string $uuid, array $filters=[], bool $_rbac=true, bool $_multitenancy=true): array` → `AuditTrail[]`.
  HTTP equivalent already exposed by OpenRegister:
  `GET /api/objects/{register}/{schema}/{id}/audit-trails`
  (`AuditTrailController::objects`, `@NoAdminRequired`) and
  `GET /api/objects/{register}/{schema}/{id}/logs`.

So run-audit-log is almost entirely inherited. Net-new = (a) an explicit
per-run `action='run'` entry, (b) redaction before that write, (c) a thin
owner-scoped read surface.

This is imperative external-integration/read-surface code, not a derived value
or declarative lifecycle: **ADR-031 imperative exception applies** — no new
OpenRegister schema, no if/then lifecycle, `kind: code`.

## Goals / Non-Goals

**Goals:**
- Emit one explicit, redacted `action='run'` audit entry per run, reusing
  OpenRegister's chain (no new store).
- Provide an owner-scoped run-history read for a schedule.
- Keep the single-write-path and redaction-before-persist invariants (ADR-004)
  intact and CI-enforceable.

**Non-Goals:**
- No dedicated `AgentRun` OpenRegister object/schema (see Open Questions — that
  is a richer, mixed schema+code change, deferred).
- No per-tool-call audit granularity beyond what OpenRegister auto-audits + the
  single per-run entry (deeper tool-call telemetry is `run-analytics`, V2).
- The FULL port of Hermes' `agent/redact.py` (complete vendor-key/header/DSN/
  private-key/JWT/phone/env-assignment pattern set) IS in scope for this change,
  implemented as a dedicated `RedactionService`. (Whether it later also backs
  display-time redaction beyond the audit write is separate — Open Questions.)
- No new frontend beyond what a later `agent-management-ui`/`run-analytics`
  change consumes; this change ships the read API + backend.

## Decisions

- **Write via `createAuditTrailEntry`, not a bespoke store.** The dispatcher
  already injects OpenRegister services; add `AuditTrailMapper` and, on finalise
  (both success and failure branches of `ScheduleService::dispatch`), call
  `createAuditTrailEntry($schedule, 'run', $redactedContext)`. The entry inherits
  the impersonated owner as `user` and the Schedule's `organisation`, and joins
  the existing hash chain. *Alternative — a Hermiq-owned AgentRun store — rejected*:
  duplicates OR audit + GDPR machinery and forks the write path (ADR-004).
- **Read via `ObjectService::getLogs(uuid)`.** `RunHistoryService` calls
  `getLogs()` for the schedule UUID, filtered to `action='run'` (newest first),
  and maps each `AuditTrail` to a run record (status/timing/agentId/link).
  `RunHistoryController` exposes it as one owner-scoped `#[NoAdminRequired]` GET
  route that first loads the schedule via `ObjectService` with RBAC ON and
  verifies the requester is the owner before returning logs — closing the IDOR
  gap (a raw pass-through to `getLogs` would leak other tenants' trails).
  *Alternative — tell the frontend to hit OR's `/audit-trails` endpoint directly —
  rejected*: it would not apply Hermiq's owner check nor shape run records.
- **Redaction runs in a dedicated `RedactionService` called before the write.**
  Mask secret/PII-shaped tokens (API keys, bearer tokens, emails) in the output
  summary string, returning a masked copy; the dispatcher only ever puts the
  masked value into the audit context. Isolating it makes the
  redaction-before-persist invariant a single, testable, CI-checkable seam.
- **Never let auditing fail a run.** The per-run audit write is wrapped so an
  audit/redaction error is logged (and itself surfaced) but does not abort the
  tick — mirroring the existing non-fatal delivery seam.

## Risks / Trade-offs

- **Light redaction can miss an exotic secret shape** → keep redaction in one
  service with unit tests per pattern; the fuller `redact.py` port is tracked as
  a follow-up (Open Questions), and the output summary is a truncated summary,
  not the full transcript, limiting exposure.
- **Owner-scoping bug would leak another tenant's trail (IDOR)** → the controller
  loads the schedule with RBAC ON and asserts ownership before any audit read;
  covered by a non-owner-refused scenario/test and gate-7 (no-admin-idor). Because
  the audit read (see next risk) is by `object_uuid` and is NOT itself
  tenant-filtered, this owner check is the sole security boundary and must never be
  dropped.
- **Upstream OR bug: app-written audit rows have `object = NULL`** → OpenRegister's
  `AuditTrailMapper::createAuditTrailEntry()` sets `object_uuid`/`register`/`schema`
  but never sets the integer `object` column, while `ObjectService::getLogs()`
  (`GetObject::findLogs()`) filters audit rows by that integer id — so our per-run
  entries are invisible to `getLogs()` (confirmed live: auto create/update rows have
  `object=<id>`, our `run` row has `object=NULL`). Mitigation (hermiq-side, OR left
  untouched): `RunHistoryService` reads by the string `object_uuid` via
  `AuditTrailMapper::findAll(filters: ['object_uuid' => …, 'action' => 'run'])`,
  which findAll() special-cases as a valid filter column. **Follow-up for OR:**
  `createAuditTrailEntry()` should also `setObject($object->getId())` so app-written
  entries are reachable through the standard `getLogs()` read path; once fixed,
  hermiq can revert to `getLogs()`.
- **Auto-audit noise**: the dispatcher's two Schedule saves per run already emit
  `update` entries; the explicit `run` entry is the canonical run record. The
  read surface filters to `action='run'` so run history is not polluted by raw
  Schedule-mutation rows.
- **Audit write failure mid-run** → wrapped non-fatal; logged. A missed explicit
  entry still leaves the auto-audited Schedule saves, so the run is never wholly
  invisible.

## Migration Plan

Additive only — no schema, no OpenRegister change, no data migration. Deploy is a
version bump of Hermiq (`info.xml`) plus the new route. Rollback = revert the
app version; OpenRegister's audit data written in the meantime remains valid and
readable.

## Open Questions

- **Rich `AgentRun` object vs thin reuse-OR-AuditTrail (code-only)?** A dedicated
  OpenRegister `AgentRun` object per run (feeding richer run-analytics and a
  fuller EU AI Act record) would be a **schema + code MIXED** change. Provisional
  decision for MVP: **thin reuse of OR AuditTrail, code-only** (this change).
  Raised as a deferred question, not built here.
- **Full `redact.py` → PHP port scope.** ADR-001/ADR-004 name a full port of
  Hermes' `agent/redact.py` as net-new. This change ships only a light masking
  pass for the audited output summary. Whether the full port is its own change
  (and whether it also backs display-time redaction) is deferred.
