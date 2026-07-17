# Agent Memory Specification

**Status**: in-progress
**Scope**: hermiq
**OpenSpec changes**:
- `session-nav-schema-retirement` — the `Session` and `SessionTurn` schema declarations are
  removed from `lib/Settings/hermiq_register.json` (kind: config)

## Purpose

Delta for `openspec/specs/agent-memory/spec.md`. The config half of the retirement:
`session-store-consolidation` removes every reader and writer of the `Session`/`SessionTurn`
objects; this delta removes their declarations from the register so the import repair step stops
asserting schemas that nothing uses. Both schemas hold 0 rows, so no migration exists.

## ADDED Requirements

### Requirement: The register declares no session schemas
The system's `lib/Settings/hermiq_register.json` MUST NOT declare the `Session` or `SessionTurn`
schemas under `components.schemas`. The agent-memory capability's register footprint MUST be
`Memory` and `UserProfile` only; cross-session recall MUST read the `Conversation`/`Message`
store instead, per `session-store-consolidation`.

#### Scenario: The register is imported on upgrade
- GIVEN the register import repair step runs against `lib/Settings/hermiq_register.json`
- WHEN the import completes
- THEN the system MUST NOT assert a `Session` or `SessionTurn` schema
- AND `components.schemas` MUST contain 25 keys
- AND the `Conversation` and `Message` schemas MUST remain declared and unchanged

#### Scenario: No reader references a removed schema
- GIVEN the dependency `session-store-consolidation` has removed every session reader and writer
- WHEN the schemas are removed from the register
- THEN no code in `lib/` or `src/` MUST reference the `Session` or `SessionTurn` schema

### Requirement: The register edit is surgical
The system's register edit MUST remove exactly the `Session` and `SessionTurn` keys and MUST NOT
alter any other schema, nor the `x-openregister` register metadata block (`type`, `app`,
`openregister`, `description`, `rbac`, `multitenancy`). The file MUST remain valid JSON.

#### Scenario: The register diff is reviewed
- GIVEN the register declares 27 schemas before the change
- WHEN the change is diffed against its merge base
- THEN the only delta MUST be the removal of the `Session` and `SessionTurn` keys
- AND the remaining 25 schema definitions MUST be byte-identical to the merge base
- AND the `x-openregister` block MUST be byte-identical to the merge base

#### Scenario: The edited register is validated
- GIVEN `lib/Settings/hermiq_register.json` has been edited
- WHEN the file is parsed
- THEN it MUST parse as valid JSON before the change is committed

## Non-Functional Requirements

- **Performance:** no runtime impact. Two fewer schema declarations to import.
- **Accessibility:** not applicable — no UI surface is declared by the register edit.
- **Internationalization:** not applicable — the register carries no user-facing strings changed
  by this delta. (Dutch and English remain supported per ADR-005; the menu label change is
  specified in this change's `app-manifest` delta.)

## Acceptance Criteria

- `lib/Settings/hermiq_register.json` parses as valid JSON.
- `components.schemas` contains 25 keys; neither `Session` nor `SessionTurn` is among them.
- `Conversation` and `Message` remain declared with their existing properties.
- The `x-openregister` block is unchanged.
- `grep -rn "SESSION_SCHEMA\|SESSION_TURN_SCHEMA\|agentsession" lib/ src/` returns no live reference.

## Notes

- **Register keys vs deployed slugs.** The register declares the keys `Session`/`SessionTurn`; the
  deployed slugs are `agentsession` (id 4347, 0 rows) and `agentsessionturn` (id 4348, 0 rows) —
  prefixed because the slug `session` is already owned by `scholiq` (schema id 1286). A third slug
  `sessionturn` (id 4346, 0 rows) exists on the reference instance with **no counterpart in the
  register file at HEAD**; it is a stale artifact of an earlier import and cannot be removed by
  editing the register. Do not invent a third key to delete.
- **Magic tables are not dropped.** Removing a declaration stops the import asserting it; the
  provisioned `oc_openregister_table_*` tables remain. Reclaiming ids 4346/4347/4348 is a scoped
  operator action, deferred deliberately (see design.md) — enumerate those exact ids, never
  range-delete.
- **ADR-003 deviation.** ADR-003 (`Status: proposed`) mandates the schema set `Memory,
  UserProfile, Session, SessionTurn, Skill, SkillSource`. This change removes two of them. The
  deviation and the proposed amendment are recorded in `session-store-consolidation/design.md`
  and this change's `design.md`.
- **Do not union-merge this file.** A union-merge of a register conflict silently drops
  modifications to the other 25 schemas.
