# Tasks: agent-memory-tools

## Implementation Tasks

### Task 1: Add entry `id`/`deletedAt` to the Memory and UserProfile schemas
- **spec_ref**: `openspec/changes/agent-memory-tools/specs/agent-memory/spec.md#requirement-agent-self-service-memory-forget-tool-soft-delete-only`
- **files**: `lib/Settings/hermiq_register.json`, `appinfo/info.xml`
- **acceptance_criteria**:
  - GIVEN the `Memory` and `UserProfile` schemas' `entries.items.properties` WHEN read after this task THEN each carries an `id` (string) and `deletedAt` (string, format date-time, nullable) property alongside the existing `text`/`createdAt`
  - GIVEN the schema change WHEN `info.xml` is inspected THEN its `<version>` is bumped by exactly one patch and both `Memory`/`UserProfile` schema `version` fields are bumped to match the register re-import gate
- [ ] Implement
- [ ] Test

### Task 2: Redact memory entry text before persist and generate entry ids on append
- **spec_ref**: `openspec/changes/agent-memory-tools/specs/agent-memory/spec.md#requirement-memory-writes-are-redacted-before-persist`
- **files**: `lib/Service/MemoryService.php`
- **acceptance_criteria**:
  - GIVEN `RedactionService` is injected into `MemoryService` WHEN `appendEntry()` runs THEN the entry `text` passed to `ObjectService::saveObject()` has passed through `RedactionService::redact()`
  - GIVEN a new entry is appended via `appendMemoryEntry()` or `appendUserProfileEntry()` WHEN the entry is persisted THEN it carries a freshly-generated unique `id`
  - GIVEN `MemoryController::addMemory()` (the existing operator endpoint) appends an entry WHEN the entry is persisted THEN it is redacted identically (same `appendEntry()` call path — no separate redaction call site)
- [ ] Implement
- [ ] Test

### Task 3: Add `MemoryService::forgetEntry()` (soft delete, IDOR-scoped)
- **spec_ref**: `openspec/changes/agent-memory-tools/specs/agent-memory/spec.md#requirement-agent-self-service-memory-forget-tool-soft-delete-only`
- **files**: `lib/Service/MemoryService.php`
- **acceptance_criteria**:
  - GIVEN an agent's `Memory` object contains an entry with a known `id` WHEN `forgetEntry(agentId, subjectUid, entryId)` is called THEN that entry's `deletedAt` is set and the entry remains present in the stored `entries` array
  - GIVEN `subjectUid` is supplied and the agent's own `Memory` object has no matching entry WHEN `forgetEntry()` runs THEN it also checks the acting user's own `UserProfile` object for that agent before returning not-found
  - GIVEN no entry with the given `id` exists in either object WHEN `forgetEntry()` runs THEN it returns a not-found result and does not throw
  - GIVEN soft-deleted entries exist WHEN `countCharacters()`/`needsConsolidation` are recomputed THEN soft-deleted entries are excluded from the character count
- [ ] Implement
- [ ] Test

### Task 4: Add `MemoryService::recallEntries()` and merge it into recall
- **spec_ref**: `openspec/changes/agent-memory-tools/specs/agent-memory/spec.md#requirement-agent-self-service-memory-recall-tool`
- **files**: `lib/Service/MemoryService.php`
- **acceptance_criteria**:
  - GIVEN an agent's `Memory`/`UserProfile` objects contain entries matching a search term WHEN `recallEntries(agentId, subjectUid, query)` runs THEN it returns the matching non-soft-deleted entries via the same `findMany()`/`ObjectService::findAll()` search substrate `recallSessions()` already uses (no new search index)
  - GIVEN a caller combines `recallEntries()` and the existing `recallSessions()` WHEN both are called for the same query THEN results are tenant-scoped identically (caller-context RBAC, unchanged)
- [ ] Implement
- [ ] Test

### Task 5: Wire the three memory tools into HermiqToolProvider
- **spec_ref**: `openspec/changes/agent-memory-tools/specs/agent-memory/spec.md#requirement-agent-self-service-memory-write-tool`
- **files**: `lib/Mcp/HermiqToolProvider.php`
- **acceptance_criteria**:
  - GIVEN `HermiqToolProvider::TOOL_DESCRIPTORS` WHEN read after this task THEN it includes `hermiq.rememberMemory` (`content`, `scope`), `hermiq.recallMemory` (`query`), and `hermiq.forgetMemory` (`id`), each with a JSON-schema `inputSchema`
  - GIVEN `invokeTool()` is called with one of the three new tool ids WHEN the acting user is authenticated THEN it delegates to the corresponding `MemoryService` method scoped to that acting user's uid (never accepting a caller-supplied `subjectUid`), mirroring the IDOR posture of every existing tool in this provider
  - GIVEN any of the three tools fails internally WHEN `invokeTool()` handles it THEN it returns the class's standard structured error envelope and never throws
- [ ] Implement
- [ ] Test

### Task 6: Show soft-deleted ("forgotten") entries distinctly in the memory panel
- **spec_ref**: `openspec/changes/agent-memory-tools/specs/agent-memory/spec.md#requirement-agent-self-service-memory-forget-tool-soft-delete-only`
- **files**: `src/components/AgentMemoryPanel.vue`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN a `Memory`/`UserProfile` entry has `deletedAt` set WHEN the memory panel renders its entry list THEN that entry is shown with a distinct "Forgotten" visual treatment rather than appearing indistinguishable from an active entry or disappearing silently
  - GIVEN new operator-facing strings are added WHEN `l10n/en.json`/`l10n/nl.json` are inspected THEN both contain matching English-keyed entries
- [ ] Implement
- [ ] Test

### Task 7: PHPUnit coverage for MemoryService's new methods
- **spec_ref**: `openspec/changes/agent-memory-tools/specs/agent-memory/spec.md#requirement-memory-tool-governance-is-fully-inherited-not-reimplemented`
- **files**: `tests/Unit/Service/MemoryServiceTest.php`
- **acceptance_criteria**:
  - GIVEN a fake/mock `ObjectService` and `RedactionService` WHEN `appendEntry()`, `forgetEntry()`, and `recallEntries()` are unit-tested THEN redaction-before-persist, soft-delete-not-hard-delete, and search-substrate-reuse are each asserted directly
- [ ] Implement
- [ ] Test

### Task 8: PHPUnit coverage for the three HermiqToolProvider memory tools
- **spec_ref**: `openspec/changes/agent-memory-tools/specs/agent-memory/spec.md#requirement-memory-tool-governance-is-fully-inherited-not-reimplemented`
- **files**: `tests/Unit/Mcp/HermiqToolProviderTest.php`
- **acceptance_criteria**:
  - GIVEN the three new tool ids WHEN `invokeTool()` is unit-tested for each THEN IDOR scoping to the acting user, the not-found path, and the never-throws contract are each asserted
- [ ] Implement
- [ ] Test

## Quality checklist

- All new/changed business logic covered by PHPUnit unit tests (`tests/Unit/`)
- New/changed API endpoints covered by Newman/Postman tests — N/A: this change adds no
  HTTP endpoints, only MCP tool calls dispatched through OR's existing tool registry
- UI changes covered by Playwright browser tests (the forgotten-entry treatment in
  `AgentMemoryPanel.vue`)
- All tests pass (`composer test`, `newman run`)
- Feature documentation updated in `docs/` if user-facing (ADR-010)
- Dutch (`nl_NL`) and English (`en_US`) translation strings added for any new
  user-facing strings (ADR-007)
- `openspec validate` passes
