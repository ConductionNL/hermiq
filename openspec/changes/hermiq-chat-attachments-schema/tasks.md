# Tasks: hermiq-chat-attachments-schema

## Implementation Tasks

### Task 1: Add the `attachments` reference field to the Message schema
- **spec_ref**: `openspec/changes/hermiq-chat-attachments-schema/specs/chat-attachments/spec.md#requirement-message-schema-declares-a-per-turn-attachments-reference-field`
- **files**: `lib/Settings/hermiq_register.json`
- **acceptance_criteria**:
  - GIVEN the `Message` schema block in `hermiq_register.json` WHEN an `attachments` property is added THEN it is `type: array` with `default: []` and `items` of `{ path, name, description }`, each sub-field carrying its own `title`/`description`, mirroring the `Context.files` field's shape.
  - GIVEN the `attachments.items.path` sub-property WHEN inspected THEN its description states the path is relative to the acting user's Nextcloud folder, matching `Context.files[].path` semantics.
  - GIVEN the `Message` schema `required` list WHEN inspected THEN `attachments` is NOT present (the field stays optional; `required` remains `["conversationId", "role"]`).
  - GIVEN the `attachments.items` sub-properties WHEN inspected THEN no `body`, `content`, `data`, `base64`, `encoding`, `mediaType`, or `mimeType` sub-property is present.
  - GIVEN the `Message` schema `version` WHEN this change is applied THEN it reads 0.1.1 (was 0.1.0), mirroring `Context.version` after ADR-024.
- [ ] Implement
- [ ] Test

### Task 2: Version-gate the re-import and verify against the imported schema
- **spec_ref**: `openspec/changes/hermiq-chat-attachments-schema/specs/chat-attachments/spec.md#requirement-the-register-re-import-is-version-gated`
- **files**: `lib/Settings/hermiq_register.json`, `appinfo/info.xml`
- **acceptance_criteria**:
  - GIVEN the register `info.version` is 0.15.0 WHEN this change is applied THEN it reads 0.16.0.
  - GIVEN `appinfo/info.xml` `<version>` is 0.1.80 WHEN this change is applied THEN it reads 0.1.81.
  - GIVEN both gates are bumped and the app is upgraded WHEN the `Message` schema is read back FROM OpenRegister THEN the `attachments` property is present on the IMPORTED schema — a field present only in the JSON file is a failure, not a pass.
  - GIVEN the diff WHEN the `Message` and `Conversation` schema blocks are inspected THEN the only added property is `Message.attachments`; `conversationId`, `role`, `content`, `sources`, `context` are unchanged; `Conversation` is untouched; no new schema is added.
- [ ] Implement
- [ ] Test

## Quality checklist

- Register JSON re-validates after the edit (`openspec validate` and a register re-import at the bumped version).
- Verify on the Postgres instance (localhost:8080) — SQLite breaks OpenRegister magic tables.
- Confirm the imported schema via the OpenRegister API or the magic table, NOT by grepping `hermiq_register.json` — the version gate is the known silent-failure mode for this change.
- No PHPUnit/Newman/Playwright work in this change — it is schema-only; behaviour tests live in the dependent `hermiq-chat-attachments` change.
- Field `title`/`description` text stays English in the register (source-of-truth convention, ADR-007); no user-facing UI strings are added here.
- No seed data in this change — a seeded attachment path would be dangling by construction (see spec Notes); fixtures belong to the dependent change.
- `openspec validate` passes.

## Verification
- [ ] All tasks checked off
- [ ] `openspec validate` passes
- [ ] Manual testing against acceptance criteria
- [ ] Code review against spec requirements

## Tests (company-wide ADR-009)
- [ ] PHPUnit unit tests — N/A: schema-only change, no business logic added; behaviour tests live in `hermiq-chat-attachments`.
- [ ] Newman/Postman tests — N/A: no API endpoint is added or changed in this change.
- [ ] Browser tests (Playwright MCP) — N/A: no UI in this change.
- [ ] Register re-import verified at the bumped version against the imported schema

## Documentation (company-wide ADR-010)
- [ ] Feature documentation — N/A: no user-facing behaviour ships in this change; docs land with `hermiq-chat-attachments`.
- [ ] Screenshot — N/A: no UI in this change.

## i18n (company-wide ADR-005)
- [ ] N/A — no new user-facing strings; register field `title`/`description` text is English by convention.
