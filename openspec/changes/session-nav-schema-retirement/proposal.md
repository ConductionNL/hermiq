---
kind: config
depends_on: [session-store-consolidation]
chain:
  - session-store-consolidation   # hermiq, code
  - session-nav-schema-retirement # this spec (hermiq, config)
---

# Proposal: session-nav-schema-retirement

## Summary

The config half of the session consolidation chain. `session-store-consolidation` (kind: code)
repoints the session readers at the live `conversation`/`message` store and deletes
`src/views/AgentSessions.vue`. This change removes the now-dangling declarations that pointed at
it: the `AgentSessions` page and its `Sessions` menu entry in `src/manifest.json`, and the
`Session`/`SessionTurn` schemas in `lib/Settings/hermiq_register.json`. It also relabels the
surviving `Chat` menu entry to "Sessions", so the one live conversation surface carries the name.
No code changes, no data migration — both retired schemas hold 0 rows.

## Motivation

Split from `session-store-consolidation` purely to honour ADR-032: a change declares `kind: code`
or `kind: config`, never `mixed`. Manifest and register JSON are config; `MemoryService` and the
Vue views are code. Sequencing matters in one direction only — the manifest must not keep
declaring a page whose component file has been deleted, so this change lands *after* its
dependency.

Concretely it closes three loose ends:

1. **A manifest page pointing at a deleted component.** `src/manifest.json` declares an
   `AgentSessions` page; `session-store-consolidation` deletes `src/views/AgentSessions.vue`.
   Left alone, the app ships a navigation entry that cannot render.
2. **Two menu entries for one concept.** The menu today has both `Chat` (page `Chat`, icon
   `icon-comment`) and `Sessions` (page `AgentSessions`, icon `icon-comment`) — same icon, same
   concept, one of them dead. After this change there is one entry: `Chat` page, labelled
   "Sessions".
3. **Four schemas for two.** The register declares `Session` and `SessionTurn` alongside
   `Conversation` and `Message`. With no writer and no reader left, they are pure confusion.

## Affected Projects

- [ ] Project: `hermiq` — `src/manifest.json`: remove the `AgentSessions` page and the `Sessions`
  menu entry; relabel the `Chat` menu entry to "Sessions". `lib/Settings/hermiq_register.json`:
  remove the `Session` and `SessionTurn` schemas.

## Scope

### In Scope

- Remove the `AgentSessions` entry from `src/manifest.json`'s `pages[]`.
- Remove the `Sessions` menu entry (page `AgentSessions`) from `src/manifest.json`'s `menu[]`.
- Change the `Chat` menu entry's label from "Chat" to "Sessions". Its page id (`Chat`), route
  (`/chat`) and icon (`icon-comment`) are unchanged.
- Remove the `Session` and `SessionTurn` schemas from `lib/Settings/hermiq_register.json`'s
  `components.schemas`.
- Add the new/renamed menu label to `l10n/en.json` and `l10n/nl.json`.

### Out of Scope

- **Any code change.** This change is `kind: config`. It edits two JSON files and two l10n
  catalogues and nothing else. If a `.php` or `.vue` file needs editing, the work belongs in
  `session-store-consolidation`.
- **Renaming schema slugs.** The slug `session` is owned by `scholiq` (schema id 1286).
  See `session-store-consolidation/design.md`.
- **Dropping the deployed magic tables.** Removing a schema from the register JSON removes the
  *declaration*. Reclaiming the underlying OpenRegister magic tables on an already-provisioned
  instance is an operator action, deliberately deferred — see design.md.
- **Removing `Conversation`/`Message`.** They are the surviving store.

## Approach

Edit two JSON files. Re-import the register via the existing repair step (Hermiq's established
pattern — `reference_or-register-import-via-repair-step.md`). Validate both JSON files after
editing; a register-JSON edit that leaves the file unparseable bricks the import silently.

The register edit MUST be a surgical removal of exactly two keys. Do **not** regenerate or
union-merge the register file: union-merging a register conflict drops modifications to existing
schemas, a failure recorded in `reference_union-merge-register-drops-modifications.md`. Diff the
result against the merge base and confirm the only delta is the two removed keys.

## New Dependencies

None.

## Impact

- `src/manifest.json` — one page removed (`AgentSessions`), one menu entry removed (`Sessions`),
  one menu label changed (`Chat` → "Sessions"). Pages drop from 18 to 17; menu entries from 17
  to 16.
- `lib/Settings/hermiq_register.json` — `components.schemas` drops from 27 keys to 25 (`Session`,
  `SessionTurn` removed).
- `l10n/en.json`, `l10n/nl.json` — the menu label string.
- Deployed instances: the `agentsession` (id 4347) and `agentsessionturn` (id 4348) schemas —
  both **0 rows** — stop being re-asserted by the import. Their magic tables remain until an
  operator reclaims them.

## Cross-Project Dependencies

None at the code level. One cross-app *constraint* is load-bearing and must not be forgotten: the
slug `session` belongs to `scholiq`. This change removes hermiq schemas; it must not add any.

## Risks

### Risk 1: A surgical register edit is done as a regenerate/union-merge and silently reverts other schemas
**Severity:** High — **Mitigation:** `lib/Settings/hermiq_register.json` holds 27 schemas; only 2
are being removed. A union-merge of a register conflict is a known way to silently drop
modifications to the *other* 25. Edit by hand, re-validate the JSON, and diff against the merge
base asserting the delta is exactly two removed keys and nothing else.

### Risk 2: The manifest is edited before its dependency lands, or the Chat page is removed by mistake
**Severity:** Medium — **Mitigation:** `depends_on: [session-store-consolidation]` is declared.
The two menu entries share the icon `icon-comment` and, after the relabel, one of them is
*named* "Sessions" while the other's page id *is* `AgentSessions` — an easy transposition. The
entry to keep is the one whose page is `Chat`; the entry to delete is the one whose page is
`AgentSessions`. Verify by page id, never by label.

### Risk 3: A schema is removed while a reader still references it
**Severity:** Low — **Mitigation:** the dependency removes every reader first. Before merging,
confirm `grep -rn "SESSION_SCHEMA\|SESSION_TURN_SCHEMA\|agentsession" lib/ src/` returns nothing
live.

## Rollback Strategy

Revert the commit and re-run the register import repair step, which re-asserts the two schema
declarations. Because both schemas hold 0 rows and their magic tables are not dropped by this
change, rollback restores the prior state exactly — there is no data to recover. Roll back
*this* change before rolling back `session-store-consolidation`, never the reverse: the manifest
must not declare a page whose component is absent.

## Capabilities

### Modified Capabilities

- `app-manifest`: the production manifest stops declaring the `AgentSessions` page and its
  duplicate `Sessions` menu entry; the single surviving conversation page is labelled "Sessions".
- `agent-memory`: the capability's register footprint drops the `Session` and `SessionTurn`
  schema declarations, leaving `Memory`/`UserProfile` as its schemas and the
  `Conversation`/`Message` store (owned elsewhere) as its recall substrate.
