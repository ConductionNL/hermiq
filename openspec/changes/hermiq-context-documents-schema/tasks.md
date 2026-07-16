# Tasks: hermiq-context-documents-schema

## Implementation Tasks

### Task 1: Add the `documents` source kind to the Context schema and version-gate the re-import
- **spec_ref**: `openspec/changes/hermiq-context-documents-schema/specs/context-documents/spec.md#requirement-context-schema-declares-an-inline-documents-source-kind`
- **files**: `lib/Settings/hermiq_register.json`, `appinfo/info.xml`
- **acceptance_criteria**:
  - GIVEN the `Context` schema block in `hermiq_register.json` WHEN a `documents` property is added THEN it is `type: array` with `default: []` and `items` of `{ name, body, format, description }`, each sub-field carrying its own `title`/`description`, mirroring the `files` field's shape.
  - GIVEN the `documents.items.format` sub-property WHEN inspected THEN it declares `default: "markdown"`.
  - GIVEN the `Context` schema `required` list WHEN inspected THEN `documents` is NOT present (the field is optional).
  - GIVEN the other `Context` fields (`files`, `objectQueries`, `viewRefs`, `charBudget`, `needsConsolidation`) WHEN the diff is inspected THEN they are unchanged and no new schema is added.
  - GIVEN the register `info.version` (0.14.0) and the app `<version>` WHEN this change is applied THEN `info.version` reads 0.15.0 and `appinfo/info.xml` `<version>` is bumped above its prior value.
- [ ] Implement
- [ ] Test

### Task 2: Provide a seed Context object carrying a `documents` entry
- **spec_ref**: `openspec/changes/hermiq-context-documents-schema/specs/context-documents/spec.md#requirement-context-schema-declares-an-inline-documents-source-kind`
- **files**: `lib/Settings/hermiq_register.json` (or the register's seed `_registers.json` entry per ADR-001)
- **acceptance_criteria**:
  - GIVEN a fresh install/re-import WHEN the seed data loads THEN a `Context` object `permit-team-project-context` exists with one `documents` entry (`name: "design.md"`, inline markdown `body`, `format: "markdown"`, a `description`), per design.md Seed Data.
  - GIVEN the seed object WHEN validated against the widened schema THEN it passes (safe placeholder content only, no secrets).
- [ ] Implement
- [ ] Test

## Quality checklist

- Register JSON re-validates after the edit (`openspec validate` and a register re-import at the bumped version).
- No PHPUnit/Newman/Playwright work in this change — it is schema-only; behaviour tests live in the dependent `hermiq-context-documents` change.
- Field `title`/`description` text stays English in the register (source-of-truth convention, ADR-007); no user-facing UI strings are added here.
- The re-import version gate is verified: unchanged versions must not be relied on to apply the widened schema.
- `openspec validate` passes.
