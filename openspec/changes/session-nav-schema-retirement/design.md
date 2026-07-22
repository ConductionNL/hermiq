# Design: session-nav-schema-retirement

## Architecture Overview

Two JSON files, four edits, zero code. This design exists mainly to pin the exact edits and to
record why the deployed-slug picture does not match the register file — a discrepancy a builder
will otherwise trip over.

### `src/manifest.json`

Hermiq's own UI is manifest-driven: `pages[]` + `menu[]` in `src/manifest.json`. At HEAD:

- `pages[]` (18): `Chat`, `AgentCatalog`, `AgentDetail`, `ApprovalInbox`, `AgentMemory`,
  **`AgentSessions`**, `SkillsCatalog`, `ContextsCatalog`, `Store`, `Dashboard`,
  `FeaturesRoadmap`, `TenantOps`, `EvalDatasets`, `EvalDatasetDetail`, `GuardrailPolicy`,
  `AlgorithmRegister`, `Compliance`, `McpTools`
- `menu[]` (17), including two `icon-comment` entries for one concept:
  - `{ label: "Chat", page: "Chat", icon: "icon-comment" }` ← **keep, relabel to "Sessions"**
  - `{ label: "Sessions", page: "AgentSessions", icon: "icon-comment" }` ← **delete**

**The trap:** after the relabel, the surviving entry is *labelled* "Sessions" while the deleted
entry's *page id* is `AgentSessions`. Anyone matching on the string "Sessions" will delete the
wrong one. **Match on `page`, never on `label`.** Delete `page === "AgentSessions"`; keep and
relabel `page === "Chat"`.

Target state: 17 pages, 16 menu entries, exactly one `icon-comment` entry.

### `lib/Settings/hermiq_register.json`

The register is an OpenAPI document; schemas live under `components.schemas` (27 keys at HEAD)
with the register meta under `x-openregister` (`type: application`, `app: hermiq`,
`openregister: ^v0.2.10`, `rbac: true`, `multitenancy: true`).

Remove exactly two keys:

- `Session` (`title: "Session"`, properties `agentId`, `title`, `startedAt`, `lastActivityAt`)
- `SessionTurn` (`title: "Session turn"`, properties `sessionId`, `agentId`, `role`, `content`, `createdAt`)

Keep `Conversation` (`title`, `userId`, `agentId`, `metadata`) and `Message` (`conversationId`,
`role`, `content`, `sources`, `context`) — they are the surviving store.

## Register keys vs deployed slugs — a discrepancy the builder must know

The register file declares the **keys** `Session` and `SessionTurn` with no explicit
`x-openregister.slug`. The **deployed slugs** on the reference instance are `agentsession`
(id 4347) and `agentsessionturn` (id 4348) — prefixed, not the bare `session`/`sessionturn` the
titles would suggest. `openspec/specs/agent-memory/spec.md` line 7 corroborates this, naming
"Memory/UserProfile/agentsession/agentsessionturn schemas".

This is almost certainly the cross-app slug collision defending itself: **`session` is already
owned by `scholiq` (schema id 1286)**, so hermiq's could not claim it. It is the clearest
possible evidence for the chain's standing rule — *do not rename any schema slug to `session`*.

A **third** slug, `sessionturn` (id 4346, 0 rows), is reported on the reference instance but has
**no counterpart in the register file at HEAD** — the file declares only `Session` and
`SessionTurn`. It is therefore a stale artifact of an earlier import, not something this change
can remove by editing the register. Editing the register can only remove the two declarations it
contains. `sessionturn` is an operator cleanup item; see below. **The builder must not invent a
third key to delete.**

## Database Changes

**No migration class, and none is needed.** All three affected schemas hold **0 rows**
(`agentsession` 4347 = 0, `agentsessionturn` 4348 = 0, `sessionturn` 4346 = 0). There is no data
to move, transform, or preserve.

Removing a schema from the register JSON removes the **declaration**; the import repair step
stops asserting it. The already-provisioned OpenRegister magic tables
(`oc_openregister_table_<reg>_<schema>`) are **not** dropped by this change, deliberately:

- Dropping tables is destructive and irreversible, and this change's whole safety argument is
  "0 rows, trivially revertible". Dropping tables would forfeit that.
- The stale `sessionturn` (4346) slug is not reachable from the register file at all, so a
  register edit could never clean it up regardless.
- Empty tables cost nothing but a row in the schema list. (They do contribute to the 2116
  magic-table count that makes the unscoped search in `session-context-performance` slow — but
  three tables out of 2116 is not that change's problem to solve here.)

Reclaiming schemas 4346/4347/4348 on a deployed instance is an **operator action**, to be done
once this change has shipped and the schemas are confirmed unreferenced. Per
`feedback_never-range-delete-test-fixtures`: enumerate those exact three ids, SELECT and eyeball
before any destructive statement, never range-delete.

## Nextcloud Integration

- Controllers: none
- Services: none
- Mappers/Entities: none — persistence is OpenRegister's (ADR-022)
- Events/Hooks: none
- Register import: the existing repair step re-imports `lib/Settings/hermiq_register.json` on
  upgrade/repair (`reference_or-register-import-via-repair-step.md`). This change adds no repair
  step; it changes the file the existing one reads.

## Security Considerations

**No security impact.** No endpoint, auth posture, RBAC rule, or object-visibility rule changes.
Removing a menu entry is not an access control (the page's component is deleted by the
dependency; the route ceases to exist). The two removed schemas carry `rbac: true` inherited from
the register, hold no rows, and had no writer — there is no data whose protection could regress.

One note for completeness: the register's `x-openregister` block (`rbac: true`,
`multitenancy: true`) is untouched. A register edit that accidentally dropped or altered it would
be a serious regression across all 25 remaining schemas — which is the concrete reason the
"surgical edit + diff against merge base" discipline in the proposal's Risk 1 is not pedantry.

## NL Design System

The main navigation continues to use the standard Nextcloud navigation surface with the existing
`icon-comment` icon and CSS variables. No component, token, or colour changes. Removing a
duplicate entry is a small accessibility improvement: two adjacent navigation items with the same
icon and overlapping meaning are a recognised confusion for screen-reader and low-vision users.

## File Structure

```
src/
  manifest.json                  # MODIFIED: -1 page (AgentSessions), -1 menu entry (Sessions), Chat label → "Sessions"
lib/
  Settings/
    hermiq_register.json         # MODIFIED: components.schemas -Session -SessionTurn (27 → 25)
l10n/
  en.json                        # MODIFIED: menu label
  nl.json                        # MODIFIED: menu label
```

No file is created. No file is deleted (`AgentSessions.vue` is deleted by the dependency).

## Seed Data

**Not applicable — this change introduces no schemas. It REMOVES two of them.**

ADR-001/ADR-016 require realistic seed data for every schema a change introduces or modifies, so
the app is testable on install. This change moves in the opposite direction:

- `Session` and `SessionTurn` are **removed** from `lib/Settings/hermiq_register.json`. A removed
  schema cannot be seeded.
- Both hold **0 rows** on the reference instance and never had a writer, so there is no existing
  seed data to retire, migrate, or re-home either.
- The surviving store needs no seed data from this change: `conversation` (id 701) already holds
  **180 rows** and `message` (id 700) **289 rows** of real traffic. The capability is testable on
  a live instance as-is.
- The remaining 25 schemas in the register are untouched; their seed data (wherever declared) is
  unaffected — provided the register edit is surgical (see Risk 1).

**Net seed-data delta for this change: none.**

## Declarative-vs-imperative decision

**Applicable — and the answer is "declarative, by not changing anything".**

This change touches the register JSON, which is where ADR-031's declarative dialects live
(`x-openregister-notifications`, lifecycle, aggregations, derived fields, relations). So the
question must be answered rather than waved off:

- The two removed schemas (`Session`: `agentId`, `title`, `startedAt`, `lastActivityAt`;
  `SessionTurn`: `sessionId`, `agentId`, `role`, `content`, `createdAt`) declare **no** lifecycle,
  aggregation, derived field, notification, or relation dialect. Removing them therefore removes
  no declarative behaviour.
- This change adds **no** new declarative dialect and modifies none on the surviving 25 schemas.
- It introduces **no** imperative code — it cannot; it is `kind: config`.

Explicit instruction to the builder, because this is exactly where scope creeps: the temptation
is to "improve" `Conversation`/`Message` while in the file — e.g. declare `Message.conversationId`
as an OpenRegister relation now that it is the recall join key
(`session-store-consolidation/design.md` names this join). **Do not.** It is a schema change
outside this change's scope, it would require re-validating 289 live message rows against the new
dialect, and a relation-dialect drift is silently ignored rather than loudly rejected. If that
relation is wanted, it is its own change with its own migration story.

Widgets: none touched. The manifest edit removes a page and a menu entry; it declares no widget
and modifies no `widgetKey`.

## Trade-offs

### Chosen: split config from code rather than one atomic change

ADR-032 forbids `kind: mixed`. The cost is a two-PR sequence with a brief window where the
manifest declares a page whose component is gone (if merged out of order) — hence
`depends_on: [session-store-consolidation]` and the explicit rollback ordering. The benefit is
that the register/manifest edit is reviewable as config, on its own, by someone who does not have
to read `MemoryService`.

### Rejected: drop the magic tables in this change

Covered under Database Changes. Destructive, irreversible, forfeits the cheap rollback, and
cannot reach the stale `sessionturn` (4346) slug anyway. Deferred to a scoped operator action.

### Rejected: keep the `Sessions` menu entry and point it at the `Chat` page

Superficially attractive — no label churn. Rejected: it leaves a page id (`AgentSessions`) whose
name no longer describes anything and whose component is deleted, and it does not reduce the
menu's duplicate-`icon-comment` confusion unless the `Chat` entry goes instead. Relabelling the
surviving entry is the smaller lie.

### Rejected: rename the `Chat` page id and `/chat` route to `Sessions` / `/sessions`

Out of scope by the chain's rule: "session" is a **UI/terminology word only**. Page ids and
routes are internal contracts; renaming them churns the manifest, the router and any bookmark for
zero user-visible gain. The label is what users read.

### Deviation from ADR-003

`session-store-consolidation/design.md` records the deviation in full and proposes the amendment.
This change is where it becomes physical: **ADR-003 (`Status: proposed`) mandates the schema set
`Memory, UserProfile, Session, SessionTurn, Skill, SkillSource`, and this change deletes
`Session` and `SessionTurn` from the register.** After it lands, the register no longer matches
the ADR's mandated list, and the ADR should be amended to strike those two and note that
cross-session recall reads `Conversation`/`Message`. ADR-003 is `proposed`, not `accepted`, and
its session writers were never implemented, so no accepted decision is being violated.
`Memory`/`UserProfile`/`Skill`/`SkillSource` remain in the register, exactly as ADR-003 requires.
