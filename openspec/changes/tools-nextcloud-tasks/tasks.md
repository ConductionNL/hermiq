# Tasks: tools-nextcloud-tasks

Kind: code. Size M. Row `hermiq:tl-tasks`.

## Implementation tasks

### Task 1: Verify the OCP 34 calendar surface, then resolve task lists
- **spec_ref**: `openspec/changes/tools-nextcloud-tasks/specs/nc-native-tools/spec.md#requirement-an-agent-can-create-a-task-in-the-users-own-list-marked-as-agent-authored-req-nctask-002`
- **files**: `lib/Service/NcNative/TaskWriteService.php`, `tests/Unit/Service/NcNative/TaskWriteServiceTest.php`
- **acceptance_criteria**:
  - GIVEN OCP 34 WHEN checked THEN the PR states which interface tells a shared-in calendar apart and whether createFromString replaces an existing URI
  - GIVEN calendars with and without VTODO, own and shared-in WHEN resolved THEN only own VTODO lists are writable
- [ ] Implement
- [ ] Test (PHPUnit with calendar doubles built from the real OCP interfaces)

### Task 2: listTasks
- **spec_ref**: `openspec/changes/tools-nextcloud-tasks/specs/nc-native-tools/spec.md#requirement-an-agent-can-list-the-acting-users-tasks-req-nctask-001`
- **files**: `lib/Service/NcNative/TaskWriteService.php`, `lib/Mcp/NcTaskToolDescriptors.php`, `lib/Mcp/HermiqToolProvider.php`
- **acceptance_criteria**:
  - GIVEN tasks in several states WHEN listed with status open and dueBefore THEN only matching tasks come back, at most 50
- [ ] Implement
- [ ] Test (PHPUnit)

### Task 3: createTask with the mark
- **spec_ref**: `openspec/changes/tools-nextcloud-tasks/specs/nc-native-tools/spec.md#requirement-an-agent-can-create-a-task-in-the-users-own-list-marked-as-agent-authored-req-nctask-002`
- **files**: `lib/Service/NcNative/TaskWriteService.php`, `lib/Service/NcNative/NcNativeWriteService.php`, `lib/Mcp/NcTaskToolDescriptors.php`, `lib/Service/Engine/FacadeToolInvoker.php` (ARTEFACT_WRITE_TOOL_IDS)
- **acceptance_criteria**:
  - GIVEN a valid task WHEN created THEN the object handed to the store holds the VTODO and the agent property with the agent id
  - GIVEN a shared-in list WHEN targeted THEN nothing is written and the stated error is returned
- [ ] Implement
- [ ] Test (PHPUnit asserting the stored payload; Playwright under tests/e2e/spec-coverage/ that the task shows in the Tasks app)

### Task 4: completeTask that keeps the task intact
- **spec_ref**: `openspec/changes/tools-nextcloud-tasks/specs/nc-native-tools/spec.md#requirement-an-agent-can-complete-a-task-without-losing-what-the-user-wrote-req-nctask-003`
- **files**: `lib/Service/NcNative/TaskWriteService.php`, `lib/Mcp/NcTaskToolDescriptors.php`
- **acceptance_criteria**:
  - GIVEN a task with a description, a category and an alarm WHEN completed THEN only status, completed time, percent and the mark change
  - GIVEN an instance where no replace path exists WHEN the catalogue is built THEN completeTask is absent
- [ ] Implement
- [ ] Test (PHPUnit on the rewrite, property by property)

### Task 5: Governance, the grant editor and the run record
- **spec_ref**: `openspec/changes/tools-nextcloud-tasks/specs/nc-native-tools/spec.md#requirement-task-tools-are-default-denied-never-delete-and-record-identity-without-content-req-nctask-004`
- **files**: `lib/Mcp/NcTaskToolDescriptors.php`, `lib/Service/Engine/FacadeToolInvoker.php` (trace extra), `tests/e2e/spec-coverage/nc-native-write-tools.spec.ts`
- **acceptance_criteria**:
  - GIVEN a new agent WHEN the grant editor opens THEN the three tools show with their classes and reach, ungranted
  - GIVEN a task write WHEN traced THEN list and uid are kept and the summary is not
- [ ] Implement
- [ ] Test (PHPUnit on classification and trace; Playwright on the grant editor)

## Verification
- [ ] `openspec validate tools-nextcloud-tasks --type change --strict` passes
- [ ] PHPUnit and Playwright run once before push, exit codes read
- [ ] One live chat turn that creates a task, lists it and completes it, checked in the Tasks app
