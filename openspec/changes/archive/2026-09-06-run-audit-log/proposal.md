---
kind: code
---

# Proposal: run-audit-log

## Why

Hermiq's scheduled agents fall under EU AI Act (Reg. 2024/1689) Art. 12 & 19
record-keeping and AVG/GDPR Art. 30 duties, but today nothing gives the owner a
first-class, immutable record of *what an agent run did* or a way to review its
history. The good news (ADR-004): OpenRegister already ships a tamper-evident
`AuditTrail` (`hash`/`previousHash` chain, `organisation` scoping, GDPR Art. 30
verwerkingsregister + DSAR + a `verify()` endpoint). The dispatcher's existing
per-tick `ObjectService::saveObject()` writes already auto-produce audit entries
for every Schedule mutation. This change closes the remaining gap thinly: an
explicit per-run audit entry that names the run (agent, status, timing, redacted
output summary), a light redaction pass **before** that write, and a read surface
so an owner can see their run history — all by reusing OR, not rebuilding it.

## What Changes

- Add an explicit per-run `AuditTrail` entry, written from `ScheduleService`
  after each run finalises, via OpenRegister's public
  `AuditTrailMapper::createAuditTrailEntry(ObjectEntity $object, string $action, array $context)`
  with `action = 'run'` and a context carrying `agentId`, `status`
  (ok/error), start/end/duration, and a **redacted** output summary — scoped to
  the run owner because the dispatcher already impersonates them (the entry's
  `user`/`organisation` are inherited from the impersonated session + the
  Schedule object).
- Add a light redaction helper (`RedactionService`) that masks secret- and
  PII-shaped tokens in the output summary **before** it is placed in the audit
  context — enforcing ADR-004's redaction-before-persist invariant (the chain is
  append-only, so a secret written once cannot be removed without breaking it).
- Add a thin **run-history read surface**: a `RunHistoryController` /
  `RunHistoryService` that returns a schedule's OR audit entries as run records
  (newest first, owner-scoped), delegating to OpenRegister's existing
  `ObjectService::getLogs(string $uuid, array $filters, bool $_rbac, bool $_multitenancy)`
  — no new logging store, no new schema.
- No new OpenRegister schema and no declarative lifecycle: every state write
  still goes through `ObjectService` (single write-path), so no run escapes the
  trail.

## Capabilities

### New Capabilities
- `run-audit-log`: every agent run and its outcome are recorded as an
  OpenRegister `AuditTrail` entry (redacted before persist), and an owner can
  read the run history for a schedule they own.

### Modified Capabilities
<!-- none: no existing capability's requirements change -->

## Impact

- **Code**: `lib/Service/ScheduleService.php` (explicit per-run audit write on
  finalise), new `lib/Service/RedactionService.php`, new
  `lib/Service/RunHistoryService.php`, new `lib/Controller/RunHistoryController.php`,
  `appinfo/routes.php` (one owner-scoped GET route).
- **Reused from OpenRegister (no change there)**:
  `AuditTrailMapper::createAuditTrailEntry()` (write),
  `ObjectService::getLogs()` (read), the `hash`/`previousHash` chain +
  `organisation` scoping + `verify()` endpoint.
- **Dependencies**: hard dependency on OpenRegister (already the case per
  ADR-001); no new external packages.
- **Compliance**: delivers the EU AI Act Art. 12/19 + GDPR Art. 30 record-keeping
  and run-history obligations as inherited platform capabilities.
