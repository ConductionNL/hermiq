# Tasks: memory-project-scopes

Kind: code. Size M. Row `hermiq:dm-project-memory`.

## Implementation tasks

### Task 1: The scope properties and the repair step
- **spec_ref**: `openspec/changes/memory-project-scopes/specs/agent-memory/spec.md#requirement-an-agent-has-one-shared-memory-and-a-memory-per-project-req-pmem-001`
- **files**: `lib/Settings/hermiq_register.json`, `lib/Repair/ScopeExistingMemories.php`, `appinfo/info.xml`
- **acceptance_criteria**:
  - GIVEN existing `Memory` objects without `scope` WHEN the repair step runs THEN each has `scope: shared` and a rerun changes nothing
- [ ] Implement
- [ ] Test (PHPUnit on the repair step, including the count check)

### Task 2: Scoped get and recall in MemoryService
- **spec_ref**: `openspec/changes/memory-project-scopes/specs/agent-memory/spec.md#requirement-recall-in-a-project-reads-the-project-and-the-shared-memory-only-req-pmem-002`
- **files**: `lib/Service/MemoryService.php`
- **acceptance_criteria**:
  - GIVEN a shared and two project memories WHEN recall runs for one project THEN only that project and shared are searched, each entry labelled
  - GIVEN no project WHEN `getMemory()` runs THEN it returns the shared object, never a project one
- [ ] Implement
- [ ] Test (PHPUnit with three memory objects)

### Task 3: Project key into the memory tools on both transports
- **spec_ref**: `openspec/changes/memory-project-scopes/specs/agent-memory/spec.md#requirement-writes-go-to-the-project-memory-unless-marked-shared-req-pmem-003`
- **files**: `lib/Service/Engine/FacadeToolInvoker.php`, `lib/Mcp/HermiqToolProvider.php`, `lib/Service/Llm/RunTokenService.php`, `lib/Controller/McpRunController.php`
- **acceptance_criteria**:
  - GIVEN a project session WHEN `rememberMemory` runs with scope `agent` THEN the project memory gains the entry
  - GIVEN the CLI transport WHEN the same call arrives over the governed MCP endpoint THEN the project key comes from the run token
- [ ] Implement
- [ ] Test (PHPUnit on the injection and on the token claim)

### Task 4: Project picker on the Chat page
- **spec_ref**: `openspec/changes/memory-project-scopes/specs/agent-memory/spec.md#requirement-an-agent-has-one-shared-memory-and-a-memory-per-project-req-pmem-001`
- **files**: `lib/Settings/hermiq_register.json`, `lib/Controller/SessionController.php`, `src/views/Chat.vue`, `src/api/chat.js`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN an agent with projects WHEN a person starts a session THEN they can pick a project, start a new one or choose none, and the header shows the project
- [ ] Implement
- [ ] Test (Playwright under `tests/e2e/spec-coverage/memory-project-scopes.spec.ts`)

### Task 5: Layers and moves on the Memory page
- **spec_ref**: `openspec/changes/memory-project-scopes/specs/agent-memory/spec.md#requirement-an-owner-sees-and-moves-entries-between-layers-req-pmem-004`
- **files**: `appinfo/routes.php`, `lib/Controller/MemoryController.php`, `src/views/AgentMemory.vue`, `src/api/memory.js`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN a project entry WHEN the owner moves it to shared THEN it leaves the project and appears under Shared, redacted again
- [ ] Implement
- [ ] Test (PHPUnit on the move; Playwright for the tabs and the move)

## Verification
- [ ] `openspec validate memory-project-scopes --type change --strict` passes
- [ ] PHPUnit and the Playwright file run, exit codes read
- [ ] A live check on the dev instance: two project sessions with one agent, a fact remembered in one does not come back in the other
