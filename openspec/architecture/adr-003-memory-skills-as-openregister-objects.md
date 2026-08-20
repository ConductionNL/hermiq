# ADR-003: Agent memory and skills are OpenRegister objects, not a local store

**Status**: proposed

**Date**: 2026-07-03

## Context

Hermes stores agent memory as `MEMORY.md`/`USER.md` files plus a per-profile SQLite database
with FTS5 full-text session search, and stores skills as on-disk `SKILL.md` directories
(the agentskills.io open standard) with a sidecar usage file and a hub lockfile. This is a
single-user, single-machine design. Hermiq is multi-tenant (org-grade) and every other
Conduction app models its data as OpenRegister objects with schema-driven RBAC and audit.

## Decision

Model memory and skills as **OpenRegister objects** in the `hermiq` register, with schemas
`Memory`, `UserProfile`, `Session`, `SessionTurn`, `Skill`, and `SkillSource`. Cross-session
recall uses OpenRegister's existing search + `VectorizationService` (semantic recall) and is
logged to `SearchTrail`; the Hermes SQLite/FTS5 index, trigram table, `.bak` drift-backup,
and bundled-manifest sync are **dropped**. Multi-tenant scoping comes from `ObjectEntity`'s
`owner`/`organisation`/`groups`/`authorization` — no bespoke auth code.

Skills remain **agentskills.io-compatible** via a bidirectional `SkillSerializer`
(`SKILL.md` dir ⇄ `Skill` object), with a raw-frontmatter passthrough field so unknown keys
round-trip losslessly. Skill lifecycle (active → stale → archived, never hard-delete) runs as
an OpenRegister background job (the "Curator"), gated by a `createdBy`/`pinned` provenance
model so shared/marketplace skills are never auto-mutated.

## Consequences

**Positive:**
- Memory and skills become searchable, versioned, RBAC-scoped, auditable, and shareable across
  an org — a governed capability, not a personal-agent feature.
- No duplicate persistence layer; one write path through `ObjectService`.
- BM25 → vector recall is an upgrade over Hermes' FTS5.

**Negative / trade-offs:**
- `SkillSerializer` round-trip fidelity is now a hard requirement (agentskills.io / marketplace
  promise); it must be a gated test on a sample corpus.
- Vector recall must be validated for parity with FTS5 on cross-session recall.
- The Curator running over a shared org catalog must strictly honor `createdBy`/`owner`/`pinned`
  guards and route all state changes through `AuditTrail` (recoverable), never `deleteObject`.

## Alternatives Considered

| Option | Reason not chosen |
|--------|------------------|
| Keep a Hermes-style SQLite/FTS5 sidecar for "fast recall" | Forks persistence, breaks org-scoping and audit, duplicates OpenRegister — violates "one write path". |
| Store skills as plain files in the app | Loses RBAC/versioning/audit and multi-tenant sharing; can't back a marketplace. |
| Invent a Hermiq-specific skill format | Breaks agentskills.io ecosystem compatibility and import/export. |
