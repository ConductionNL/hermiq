# Test Plan: hermiq-context-documents

## Test Cases

### TC-1: Document renders into the agent preamble
- **spec_ref**: `openspec/changes/hermiq-context-documents/specs/context-documents/spec.md#requirement-contextassembler-renders-documents-into-the-budgeted-preamble`
- **type**: functional
- **persona**: Noor (municipal functional admin configuring an agent)
- **preconditions**: A Context with one `documents` entry (`name: "design.md"`, markdown `body`) is attached to an agent via `contextRefs`
- **steps**: Run the agent (or unit-invoke `ContextAssembler::assembleForAgent`) and inspect the assembled preamble
- **expected result**: The preamble contains a titled section for the document (its `name`) with the `body` text, under the single `Context: {name}` header, alongside any files/object-queries
- **test command**: /test-functional

### TC-2: Malformed document entry is skipped, not fatal
- **spec_ref**: `openspec/changes/hermiq-context-documents/specs/context-documents/spec.md#requirement-contextassembler-renders-documents-into-the-budgeted-preamble`
- **type**: regression
- **persona**: n/a
- **preconditions**: A Context with two `documents` entries — one valid, one missing `body`
- **steps**: Resolve the Context (PHPUnit `ContextAssemblerTest`)
- **expected result**: The valid document renders; the malformed entry is skipped-and-logged; files/object-queries sections still assemble; a no-documents Context assembles identically to pre-change
- **test command**: /test-regression

### TC-3: Documents share the existing budget contract
- **spec_ref**: `openspec/changes/hermiq-context-documents/specs/context-documents/spec.md#requirement-documents-share-the-existing-budget-contract`
- **type**: functional
- **persona**: n/a
- **preconditions**: A Context whose files + object-queries + documents body exceeds its `charBudget`
- **steps**: Resolve the Context and inspect the returned text + persisted `needsConsolidation`
- **expected result**: `needsConsolidation` is flagged; the assembled text is returned in full (never truncated); no separate budget is introduced
- **test command**: /test-functional

### TC-4: Author, edit, add/rename/remove documents in the editor
- **spec_ref**: `openspec/changes/hermiq-context-documents/specs/context-documents/spec.md#requirement-a-context-editor-authors-documents-with-a-markdown-editor-per-entry`
- **type**: functional
- **persona**: Noor (municipal functional admin)
- **preconditions**: The Context management page is available; the operator is authenticated
- **steps**: Open the Context editor in create mode, set `name`, add a `documents` entry, write its Markdown `body`, save; reopen in edit mode, add a second entry, rename it, remove the first, save
- **expected result**: A Context persists with the authored `documents`; after edit the `documents` array reflects exactly the remaining renamed entry; prior `charBudget`/`viewRefs`/`needsConsolidation` survive the edit
- **test command**: /test-functional

### TC-5: Management surface opens the editor via the form-dialog slot
- **spec_ref**: `openspec/changes/hermiq-context-documents/specs/context-documents/spec.md#requirement-context-objects-are-managed-through-a-dedicated-surface`
- **type**: functional
- **persona**: Noor (municipal functional admin)
- **preconditions**: The Context nav entry + index page are wired (`slots.form-dialog: "ContextFormModal"`)
- **steps**: Navigate to the Context page, trigger create (and edit on a row)
- **expected result**: `ContextFormModal` opens via the page's form-dialog slot; on save the list reflects the created/updated Context
- **test command**: /test-functional

### TC-6: Document body passes through guardrail input filters
- **spec_ref**: `openspec/changes/hermiq-context-documents/specs/context-documents/spec.md#requirement-contextassembler-renders-documents-into-the-budgeted-preamble`
- **type**: security
- **persona**: Noor (municipal CISO / functional admin)
- **preconditions**: An org guardrail input-filter policy is configured; a Context document `body` contains a prompt-injection-style phrase
- **steps**: Run an agent with that Context attached
- **expected result**: The assembled preamble (documents included) is filtered by the existing guardrail policy exactly as files/object-queries text is; no new trust bypass; assembly inherits the run's acting-user identity (ADR-023, ADR-024 Rule 3)
- **test command**: /test-security

## Coverage Summary

- Requirement "ContextAssembler renders documents into the budgeted preamble" — covered by TC-1, TC-2, TC-6.
- Requirement "Documents share the existing budget contract" — covered by TC-3.
- Requirement "A Context editor authors documents with a markdown editor per entry" — covered by TC-4.
- Requirement "Context objects are managed through a dedicated surface" — covered by TC-5.

All four ADDED requirements are covered.

## Out of Scope

- `viewRefs` resolution (still deferred by ADR-024; unchanged by this change).
- The `documents` schema field's shape/version gating — verified by the dependent
  `hermiq-context-documents-schema` change, not re-tested here.
