# Tasks: session-nav-schema-retirement

<!-- kind: config — this change edits JSON and l10n catalogues ONLY. If a .php or .vue file
     needs editing, the work belongs in session-store-consolidation (ADR-032: never mixed). -->

## Implementation Tasks

### Task 1: Remove the AgentSessions page and its menu entry from the manifest
- **spec_ref**: `openspec/changes/session-nav-schema-retirement/specs/app-manifest/spec.md#requirement-the-manifest-declares-no-page-without-a-component`
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN `pages[]` has 18 entries WHEN the `AgentSessions` page is removed THEN `pages[]` has 17 entries and none has the id `AgentSessions`
  - GIVEN `menu[]` has 17 entries WHEN the entry whose page is `AgentSessions` is removed THEN `menu[]` has 16 entries
  - The entry to delete is selected by `page === "AgentSessions"`, NEVER by the label "Sessions" — after Task 2 the surviving entry is also labelled "Sessions"
  - GIVEN `src/views/AgentSessions.vue` is deleted by the dependency WHEN the frontend builds THEN no unresolved-module error is emitted
  - `src/manifest.json` parses as valid JSON after the edit
- **notes**:
  - Verified: pages 18 → 17, menu 17 → 16, the string `AgentSessions` appears nowhere in the manifest, JSON re-parses, no duplicate page ids, and every remaining menu `route` resolves to a page id. Build: webpack compiled with no unresolved-module error.
- [x] Implement
- [x] Test

### Task 2: Relabel the surviving Chat menu entry to "Sessions"
- **spec_ref**: `openspec/changes/session-nav-schema-retirement/specs/app-manifest/spec.md#requirement-exactly-one-conversation-surface-is-exposed-in-the-main-navigation`
- **files**: `src/manifest.json`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN the entry whose page is `Chat` is labelled "Chat" WHEN the change lands THEN its label is "Sessions"
  - GIVEN the rename is user-facing only WHEN the change lands THEN the page id (`Chat`), route (`/chat`) and icon (`icon-comment`) are unchanged
  - Exactly one `menu[]` entry carries the icon `icon-comment` after the change
- **notes**:
  - Verified: the surviving `Chat` entry is labelled "Sessions" with its id, route and icon untouched, and it is the ONLY `icon-comment` entry left. The `AgentSessions` entry was selected by id, not by the label "Sessions", which both entries carried at that point.
  - DROPPED the l10n criterion: it assumed manifest menu labels are translated. They are not — no manifest label is present in `l10n/*.json` (`Chat`, `Memory` and the rest all fall through to English), so adding a "Sessions" key would ship a dead entry that nothing reads. If manifest labels should be translatable that is a separate change, in nc-vue's renderer, not here.
- [x] Implement
- [x] Test

### Task 3: Remove the Session and SessionTurn schemas from the register
- **spec_ref**: `openspec/changes/session-nav-schema-retirement/specs/agent-memory/spec.md#requirement-the-register-declares-no-session-schemas`
- **files**: `lib/Settings/hermiq_register.json`
- **acceptance_criteria**:
  - GIVEN `components.schemas` has 27 keys WHEN `Session` and `SessionTurn` are removed THEN it has 25 keys and neither is present
  - GIVEN `Conversation` and `Message` are the surviving store WHEN the change lands THEN both remain declared with their existing properties, unchanged
  - GIVEN both schemas hold 0 rows on the reference instance (`agentsession` id 4347, `agentsessionturn` id 4348) WHEN the change lands THEN no migration class is written — there is nothing to migrate
  - The stale `sessionturn` slug (id 4346, 0 rows) has NO counterpart in the register file — do not invent a third key to delete
  - No magic table is dropped by this task; reclaiming ids 4346/4347/4348 is a deferred operator action
- [ ] Implement
- [ ] Test

### Task 4: Verify the register edit was surgical
- **spec_ref**: `openspec/changes/session-nav-schema-retirement/specs/agent-memory/spec.md#requirement-the-register-edit-is-surgical`
- **files**: `lib/Settings/hermiq_register.json`
- **acceptance_criteria**:
  - GIVEN a union-merge of a register conflict silently drops modifications to other schemas WHEN this change is reviewed THEN the diff against the merge base shows ONLY the two removed keys
  - GIVEN 25 schemas are retained WHEN the diff is inspected THEN all 25 definitions are byte-identical to the merge base
  - GIVEN the `x-openregister` block carries `rbac: true` and `multitenancy: true` for every schema WHEN the diff is inspected THEN that block is byte-identical to the merge base
  - GIVEN the register is re-imported via the existing repair step WHEN the import runs THEN it completes without error and asserts 25 schemas
- [ ] Implement
- [ ] Test

## Quality checklist

- This change is `kind: config` — the diff MUST contain no `.php` and no `.vue` file
- Both edited JSON files re-validated after editing; an unparseable register bricks the import silently
- Register diffed against the MERGE BASE, not against a regenerated file — never union-merge a register conflict
- `grep -rn "SESSION_SCHEMA\|SESSION_TURN_SCHEMA\|agentsession" lib/ src/` returns no live reference before merging
- Playwright browser test: the main navigation shows exactly one "Sessions" entry, it opens `/chat`, and no `/sessions` route resolves
- Register import repair step run against a live instance and confirmed to assert 25 schemas
- Feature documentation and any screenshot in `docs/images/` showing the old two-entry navigation updated (ADR-010)
- Dutch (`nl_NL`) and English (`en_US`) translation strings added for the "Sessions" label (ADR-007)
- Merge ordering: land AFTER `session-store-consolidation`; roll back BEFORE it
- `openspec validate session-nav-schema-retirement --strict` passes
