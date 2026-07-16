# context-documents Specification

## Purpose
TBD - created by archiving change hermiq-context-documents. Update Purpose after archive.
## Requirements
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

### Requirement: Context schema declares an inline documents source kind

The `Context` schema MUST declare an inline `documents` source kind, so that
document-shaped context (a design.md, persona brief, standards doc) is a
first-class Context source distinct from `files` and `objectQueries` (ADR-024).

The `Context` schema (slug `context`, in `lib/Settings/hermiq_register.json`)
MUST declare a `documents` property of `type: array` with `default: []`, whose
items are objects with the fields `name`, `body`, `format`, and `description`.
`body` MUST hold inline Markdown authored directly on the Context object.
`format` MUST default to `"markdown"`. The property MUST NOT be added to the
schema's `required` list.

#### Scenario: The widened schema is imported

- GIVEN the `Context` schema at register `info.version` 0.14.0 with fields `name, description, files, objectQueries, viewRefs, charBudget, needsConsolidation`
- WHEN the register is re-imported at the bumped version
- THEN the `Context` schema exposes a `documents` array property whose items are `{ name, body, format, description }`
- AND `format` carries `default: "markdown"`
- AND `documents` is absent from the schema's `required` list

#### Scenario: A Context object persists a documents entry

- GIVEN the widened `Context` schema is imported
- WHEN a Context object is saved with a `documents` value of one entry `{ name: "design.md", body: "# Design…", format: "markdown", description: "…" }`
- THEN the object persists with that `documents` array intact
- AND a Context object saved WITHOUT any `documents` value still validates (the field is optional and defaults to `[]`)

### Requirement: The documents field mirrors the files field shape

The `documents` property MUST mirror the existing `files` field's structure and
house style: `type: array` + `default: []` at the field level, a human-readable
`title` and `description`, and an `items` object whose sub-properties each carry
their own `title` and `description`. Only the single `documents` property MUST be
added; no other `Context` field (`files`, `objectQueries`, `viewRefs`,
`charBudget`, `needsConsolidation`) SHALL be changed, and no new schema SHALL be
introduced.

#### Scenario: Shape parity with files

- GIVEN the widened `Context` schema
- WHEN the `documents` field is compared to the `files` field
- THEN both are `type: array` with `default: []` and array-of-typed-objects items
- AND each `documents.items` sub-property (`name`, `body`, `format`, `description`) carries its own `title` and `description`

#### Scenario: No collateral schema change

- GIVEN the change diff to `hermiq_register.json`
- WHEN the `Context` schema block is inspected
- THEN the only added property is `documents`
- AND `files`, `objectQueries`, `viewRefs`, `charBudget`, and `needsConsolidation` are unchanged

### Requirement: The register re-import is version-gated

The change MUST bump the register `info.version` from 0.14.0 to 0.15.0 AND the
Hermiq app `<version>` in `appinfo/info.xml`, so OpenRegister's version-gated
re-import applies the widened schema on upgrade. An unchanged version MUST NOT be
relied on to re-import.

#### Scenario: Versions bumped so the re-import fires

- GIVEN the register `info.version` is 0.14.0 and the app has a prior `<version>`
- WHEN this change is prepared
- THEN the register `info.version` reads 0.15.0
- AND `appinfo/info.xml` `<version>` is bumped above its prior value

