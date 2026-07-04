# ADR-004: Governance and AI-Act compliance via OpenRegister's AuditTrail

**Status**: proposed

**Date**: 2026-07-03

## Context

Autonomous, scheduled agents fall under EU AI Act obligations: record-keeping and
auto-generated logs (Art. 12 & 19), transparency (Art. 13), and human oversight with a stop
mechanism (Art. 14). Hermes has display-time secret/PII redaction and cooperative in-memory
approvals, but no durable, tamper-evident, tenant-scoped record of what an agent did — its own
`SECURITY.md` calls these in-process heuristics, not a boundary. OpenRegister, by contrast,
already ships `AuditTrail` with a `hash`/`previousHash` tamper-evident chain, `organisation`
scoping, a GDPR Art. 30 `verwerkingsregister`, DSAR endpoints, and a hash-chain `verify()`
endpoint — plus `ObjectEntity` state (`locked`, `deleted`, `version`) for lifecycle.

## Decision

Base all Hermiq governance on OpenRegister's audit layer. Each run, LLM call, tool invocation,
and approval decision is written as an `AuditTrail` entry (with an `action` taxonomy) via
`ObjectService`. The **human-approval gate** and tenant **kill-switch** are modeled as durable
OpenRegister object **states** (not in-memory queues), enforced **synchronously** in the
dispatch loop, with the human notified via Nextcloud Talk/Notifications. Hermes' `redact.py`
pipeline is ported to PHP and applied **before** any audit write. Multi-tenant RBAC comes from
Nextcloud groups + `ObjectEntity`. Hermes' credential-pool, secret-scope, and shadow-git
checkpoints are **dropped**.

Two invariants are treated as compliance-critical and enforced as CI gates:
1. **Redaction-before-persist** — the hash-chained trail is append-only, so a secret written
   once cannot be removed without breaking the chain.
2. **Single write-path** — every state change goes through `ObjectService`; any bypass creates
   a gap in the immutable log.

## Consequences

**Positive:**
- EU AI Act record-keeping, transparency, and DSAR are inherited from OpenRegister, not rebuilt.
- Tamper-evident, per-tenant, exportable audit out of the box; a genuine differentiator vs
  single-tenant Hermes.
- Human oversight and stop-button are durable and survive restarts.

**Negative / trade-offs:**
- Redaction must be provably applied before persistence (GDPR erasure vs. immutability tension);
  the `expires`/retention field must back a documented retention policy.
- "Human oversight" is only real if the run loop **hard-blocks** on the approval/kill-switch
  state — advisory-only would fail Art. 14.
- Trail integrity depends on nothing writing state outside `ObjectService`.

## Alternatives Considered

| Option | Reason not chosen |
|--------|------------------|
| Port Hermes' observer-hook telemetry (Langfuse/OTel) | Telemetry is exported, sanitized, and not a durable compliance record — fails Art. 12/19. |
| Build a Hermiq-specific audit store | Duplicates OpenRegister's audit + GDPR machinery and forks the write path. |
| Keep approvals in memory (Hermes model) | Lost on restart, not auditable, and auto-approves in non-interactive/cron contexts — fails Art. 14. |
