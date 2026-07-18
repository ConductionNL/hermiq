# Tasks: hermiq-agent-files-schema

## Implementation Tasks

### Task 1: Add the `uploadFolder` field to the Agent schema
- **spec_ref**: `openspec/changes/hermiq-agent-files-schema/specs/agent-files/spec.md#requirement-the-agent-schema-declares-a-per-agent-upload-folder`
- **files**: `lib/Settings/hermiq_register.json`
- **acceptance_criteria**:
  - GIVEN the `Agent` schema block in `hermiq_register.json` WHEN an `uploadFolder` property is added THEN it is `type: string` with `default: "Hermiq/Attachments"` and carries its own English `title`/`description`.
  - GIVEN the `uploadFolder` description WHEN inspected THEN it states the path is relative to the acting user's Nextcloud folder, matching `Context.files[].path` and `ChatAttachmentController::ATTACHMENTS_FOLDER` semantics.
  - GIVEN the `Agent` schema `required` list WHEN inspected THEN `uploadFolder` is NOT present (`required` remains `["name"]`).
  - GIVEN the diff WHEN the `Agent` schema properties are compared to 0.16.0 THEN the ONLY added property is `uploadFolder`; no `relatedFiles`/`files` array is added; `contextRefs` is unchanged.
  - GIVEN the `Agent` schema `version` WHEN this change is applied THEN it reads 0.3.1 (was 0.3.0), mirroring the sibling per-schema version bump convention.
- [ ] Implement
- [ ] Test

### Task 2: Version-gate the re-import and verify against the imported schema
- **spec_ref**: `openspec/changes/hermiq-agent-files-schema/specs/agent-files/spec.md#requirement-the-agent-schema-declares-a-per-agent-upload-folder`
- **files**: `lib/Settings/hermiq_register.json`, `appinfo/info.xml`
- **acceptance_criteria**:
  - GIVEN the register `info.version` is 0.16.0 WHEN this change is applied THEN it reads 0.17.0.
  - GIVEN `appinfo/info.xml` `<version>` is 0.1.82 WHEN this change is applied THEN it reads 0.1.83.
  - GIVEN both gates are bumped and the app is upgraded WHEN the `Agent` schema is read back FROM OpenRegister (magic table / API, on Postgres 8080) THEN `uploadFolder` is present on the IMPORTED schema — a field present only in the JSON file is a failure, not a pass.
  - GIVEN an existing Agent object with no `uploadFolder` WHEN read after re-import THEN it validates and its attachment destination is unchanged (default `Hermiq/Attachments`).
- [ ] Implement
- [ ] Test

## Quality checklist

- Register JSON re-validates after the edit (`openspec validate` and a register re-import at the bumped version).
- Verify on the Postgres instance (localhost:8080) — SQLite breaks OpenRegister magic tables.
- Confirm the imported schema via the OpenRegister API or the magic table, NOT by grepping `hermiq_register.json` — the version gate is the known silent-failure mode.
- No PHPUnit/Newman/Playwright work in this change — it is schema-only; behaviour tests live in the dependent `hermiq-agent-files` change.
- Field `title`/`description` text stays English in the register (source-of-truth convention, ADR-007); no user-facing UI strings are added here.
- No seed data in this change — the field is a per-agent scalar with a behaviour-preserving default; fixtures belong to the dependent change.
- `openspec validate` passes.

## Verification

- [ ] All tasks checked off
- [ ] `openspec validate --change hermiq-agent-files-schema` passes
- [ ] Imported `Agent` schema exposes `uploadFolder` (read back from OpenRegister, not the JSON file)
