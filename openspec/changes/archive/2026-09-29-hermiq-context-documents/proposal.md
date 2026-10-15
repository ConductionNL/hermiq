---
kind: code
depends_on:
  - hermiq-context-documents-schema
---

# Proposal: hermiq-context-documents

## Summary

Make the `documents` source kind on the `Context` schema (added by the dependent
schema change) actually work end-to-end, per ADR-024: (a) `ContextAssembler`
gains a `resolveDocuments()` that renders each `documents[]` entry into the same
budgeted preamble as `files`/`objectQueries` — one assembly seam, no new budget
contract; and (b) a Context editor modal (mirroring `SkillFormModal`) plus a
Context management surface let operators create and edit Context objects, authoring
each document's inline Markdown with `CnMarkdownEditor`. This is the code link of
the two-change chain; it depends on `hermiq-context-documents-schema` because the
`documents` field must exist before code can read or author it.

## Motivation

ADR-024 (accepted) makes `documents` a first-class inline source on `Context` so a
`design.md`, standards doc, or persona brief is curated, versioned context that is
part of the agent's definition — not a bare pointer to a user's Nextcloud file.
The schema change alone is inert: at HEAD `ContextAssembler` reads only `files` and
`objectQueries`, and Hermiq has **no** Context management UI at all (there is no
Context view/modal in `src/`, and `AgentFormModal` does not manage `contextRefs`).
So a `documents` value can be stored but is never rendered into an agent's preamble,
and there is no authoring surface. This change closes both gaps: the assembler
renders documents, and a Context editor (reusing the exact markdown-authoring
pattern the skill work ships) lets operators write them.

## Affected Projects

- [ ] Project: `hermiq` — add `ContextAssembler::resolveDocuments()`; add
  `ContextFormModal.vue` (mirrors `SkillFormModal`); add a `useContextStore`;
  register the modal in `customComponents.js`; add a Context management page +
  nav entry to `src/manifest.json` wired via `slots.form-dialog`.

## Scope

### In Scope

- `ContextAssembler::resolveDocuments()` — a new private method rendering each
  `documents[]` entry as a titled section (its `name`) into the same `$sections`
  array that `resolveObjectQueries()` and `resolveFiles()` feed, merged in
  `assemble()`. The existing `charBudget`/`needsConsolidation` accounting
  (`mb_strlen($body) > $budget`) covers the added text unchanged.
- `ContextFormModal.vue` — a Context editor mirroring `SkillFormModal`'s
  `#form-dialog` slot contract (`{ show, item, schema, close }`): `name`
  (required) + `description`, a `documents` list (a `CnMarkdownEditor` per entry,
  with add / rename / edit / remove), and the existing `files` + `objectQueries`
  entries. Create and edit both persist through the generic OpenRegister object
  write path (`createObjectStore.saveObject`) — Context has no bespoke import path.
- A `useContextStore` (`createObjectStore('context', …)`) and registration of
  `ContextFormModal` in `customComponents.js`.
- A Context management surface in `src/manifest.json`: an index page over schema
  `context` with `slots.form-dialog: "ContextFormModal"` and a nav entry,
  mirroring the `SkillsCatalog` page.

### Out of Scope

- Any `Context` schema field change — the `documents` field ships in the dependent
  schema change; this change adds no schema fields.
- `viewRefs` resolution (still deferred — ADR-024 Neutral; unchanged).
- New guardrail/trust code — the org's existing configurable guardrail input
  filters already apply to the assembled preamble (ADR-024 Rule 3); documents add
  no new trust surface.
- The GitHub-shareable-context bundle and a conversational context-creator (parked
  follow-ons — ADR-024 Rule 4).

## Approach

Backend: add `resolveDocuments(mixed $documents)` to `ContextAssembler`, modelled
on `resolveFiles()` (defensive `is_array` guards, per-entry skip-and-log on bad
shape, one formatted block per entry). Merge its result into `$sections` in
`assemble()` alongside the two existing resolvers. No change to the budget contract
or the `Context: {name}` header.

Frontend: create `ContextFormModal.vue` by mirroring `SkillFormModal` — the same
`show/item/schema/close` props, the same auxiliary-list editor pattern used for
skill `files` (`{name, content}`) applied to `documents` (`{name, body, format,
description}`), each `body` authored with `CnMarkdownEditor` (`value` in,
`@input` out). Add `useContextStore` to `store.js`, register the modal in
`customComponents.js`, and add a manifest index page + nav entry over schema
`context` with `slots.form-dialog: "ContextFormModal"`, copying the `SkillsCatalog`
page shape. Editing a Context merges the existing payload first so unsurfaced
fields (`viewRefs`, `charBudget`, `needsConsolidation`) survive the PUT.

## New Dependencies

None. `CnMarkdownEditor` and `createObjectStore` are already provided by
`@conduction/nextcloud-vue` and already used by `SkillFormModal`/the existing
stores.

## Impact

- `lib/Service/Engine/ContextAssembler.php` — one new private method + one merge
  line in `assemble()`.
- `src/modals/ContextFormModal.vue` (new), `src/store/store.js` (+`useContextStore`),
  `src/customComponents.js` (+registration), `src/manifest.json` (+page +nav).
- Existing agents that reference a Context with `documents` immediately get those
  documents in their preamble after this lands (no per-agent rewiring — the
  assembler already runs for every `contextRefs`).

## Cross-Project Dependencies

Depends on `hermiq-context-documents-schema` (the `documents` field must exist in
the imported `Context` schema). No other apps-extra project is affected;
OpenRegister stores and validates the object but reads no Hermiq field.

## Risks

### Risk 1: A malformed or oversized document unbalances the preamble

**Severity:** Medium — **Mitigation:** `resolveDocuments()` guards each entry
(`is_array`, non-empty `name`/`body`) and skips-and-logs bad entries exactly like
`resolveFiles()`; the shared `charBudget`/`needsConsolidation` nudge already flags
an over-budget bundle without silently truncating.

### Risk 2: Prompt-injection via document body

**Severity:** Medium — **Mitigation:** No new trust code needed (ADR-024 Rule 3):
document text is data prepended to the prompt and passes through the org's existing
configurable guardrail input filters exactly as `files`/`objectQueries` text does.
It inherits the run's acting-user identity (ADR-023) and grants no authorization.

### Risk 3: Create/edit divergence loses unsurfaced Context fields

**Severity:** Low — **Mitigation:** the edit path spreads the existing Context
payload before applying form fields (the pattern `SkillFormModal.buildEditPayload()`
uses), so `viewRefs`, `charBudget`, and `needsConsolidation` survive a PUT.

## Rollback Strategy

Revert the four frontend edits and the one backend method. The `documents` schema
field (owned by the dependent change) stays; with the assembler reverted, a stored
`documents` value simply goes unrendered again (its state before this change),
which is non-fatal.

## Open Questions

None.
