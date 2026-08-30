# context-documents Specification

**Status**: planned
**Scope**: hermiq
**OpenSpec changes**:
- hermiq-context-documents-schema (schema — the `documents` field)
- hermiq-context-documents (this change — assembler rendering + editor)

## Purpose

Make the inline `documents` source kind on the `Context` schema work end-to-end,
per ADR-024: `ContextAssembler` renders each document into the same budgeted
preamble as `files`/`objectQueries`, and a Context editor (mirroring the skill
markdown-authoring surface) lets operators author documents and manage Context
objects. This change consumes the `documents` field added by
`hermiq-context-documents-schema`.

## ADDED Requirements

### Requirement: ContextAssembler renders documents into the budgeted preamble

`ContextAssembler` MUST render each `documents[]` entry of a resolved `Context`
into the assembled preamble as a titled section identified by the entry's `name`,
merged into the same `$sections` collection that object-queries and files feed,
under the same `Context: {name}` header. A `documents` entry that is not a valid
object, or that lacks a non-empty `name` or `body`, MUST be skipped (logged) and
MUST NOT abort assembly of the rest of the Context — mirroring the existing
per-entry tolerance of `resolveFiles()` and `resolveObjectQueries()`. A Context
with no `documents` value MUST assemble exactly as it does today.

#### Scenario: A document is rendered under its name

- GIVEN a Context object with one `documents` entry `{ name: "design.md", body: "# Design…", format: "markdown" }`
- WHEN the assembler resolves that Context for an agent
- THEN the preamble contains a section for that document identified by its `name`
- AND the document `body` text appears in the assembled preamble alongside any resolved files and object-queries under the single `Context: {name}` header

#### Scenario: A malformed document entry is skipped, not fatal

- GIVEN a Context with two `documents` entries, one valid and one missing `body`
- WHEN the assembler resolves that Context
- THEN the valid document is rendered
- AND the malformed entry is skipped and logged
- AND the files/object-queries sections still assemble

#### Scenario: No documents behaves as before

- GIVEN a Context with no `documents` value (field absent or `[]`)
- WHEN the assembler resolves that Context
- THEN the assembled preamble is identical to the pre-change output for that Context

### Requirement: Documents share the existing budget contract

The rendered `documents` text MUST be counted inside the Context's existing
`charBudget` / `needsConsolidation` accounting (the assembled body length compared
to `charBudget`), with NO new or separate budget contract. Content MUST NOT be
silently truncated to fit; exceeding the budget only flags `needsConsolidation`,
exactly as for files and object-queries today.

#### Scenario: Documents push a bundle over budget

- GIVEN a Context whose files + object-queries + documents assembled body exceeds its `charBudget`
- WHEN the assembler resolves that Context
- THEN `needsConsolidation` is flagged for that Context
- AND the assembled text is returned in full (never truncated)

### Requirement: A Context editor authors documents with a markdown editor per entry

The app MUST provide a Context editor modal that mirrors the skill markdown
authoring form: it MUST accept the `#form-dialog` slot contract
(`{ show, item, schema, close }`), expose a required `name` and a `description`,
and manage a `documents` list where each entry's `body` is authored with
`CnMarkdownEditor` (`value` in, `@input` out) and entries can be added, renamed,
edited, and removed. The editor MUST also surface the Context's existing `files`
and `objectQueries`. Saving MUST persist through the generic OpenRegister object
write path (create and edit alike), and an edit MUST preserve Context fields the
form does not surface (`viewRefs`, `charBudget`, `needsConsolidation`).

#### Scenario: Author a document and save

- GIVEN the Context editor is open in create mode
- WHEN the operator sets a `name`, adds a `documents` entry, writes its Markdown `body` in the editor, and clicks Save
- THEN a Context object persists with that `documents` entry via the OpenRegister object write path

#### Scenario: Edit preserves unsurfaced fields

- GIVEN an existing Context with a `charBudget` and a `viewRefs` value
- WHEN the operator edits its documents in the editor and saves
- THEN the saved object retains its prior `charBudget` and `viewRefs`

#### Scenario: Add, rename, and remove document entries

- GIVEN the Context editor with one document entry
- WHEN the operator adds a second entry, renames it, and removes the first
- THEN the saved `documents` array reflects exactly the remaining, renamed entry set

### Requirement: Context objects are managed through a dedicated surface

The app MUST expose a Context management surface (a manifest index page over the
`context` schema with a navigation entry) whose create/edit action is wired to the
Context editor via `slots.form-dialog`, mirroring the Skills catalog page. From
this surface an operator MUST be able to list, create, and edit Context objects.

#### Scenario: Open the editor from the management page

- GIVEN the Context management page is shown
- WHEN the operator triggers create (or edit on a row)
- THEN the Context editor modal opens via the page's `form-dialog` slot
- AND on save the list reflects the created or updated Context

## Non-Functional Requirements

- **Performance:** `resolveDocuments()` reads only the already-loaded Context
  object (no filesystem, no extra query); it adds negligible cost to assembly.
- **Accessibility:** editor controls use standard NC components with labels
  (WCAG 2.1 AA); the markdown editor carries an `aria-label`.
- **Internationalization:** Dutch and English MUST be supported (ADR-007) — all
  new user-facing strings via `t('hermiq', …)`.

## Acceptance Criteria

- `ContextAssembler::resolveDocuments()` renders each entry as a titled section in
  the same `$sections`, tolerant of malformed entries, under the existing budget.
- The Context editor authors `documents` (markdown editor per entry;
  add/rename/edit/remove) plus `files`/`objectQueries`, and preserves unsurfaced
  fields on edit.
- A Context management page + nav entry wires the editor via `slots.form-dialog`.
- No new backend endpoint, no `Context` schema field change, no new trust code.

## Notes

- Driving decision: ADR-024 (accepted) Rules 1, 2, 3, 4. `ContextAssembler` is an
  existing imperative service extended here (ADR-001/context-system), not new
  declarative logic (ADR-031 does not apply).
- Depends on `hermiq-context-documents-schema` for the `documents` field.
