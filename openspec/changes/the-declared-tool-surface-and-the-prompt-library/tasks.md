# Tasks: the-declared-tool-surface-and-the-prompt-library

## Implementation Tasks

### Task 1: Publish an outbound tool surface an owning app declares
- **spec_ref**: `openspec/changes/the-declared-tool-surface-and-the-prompt-library/specs/agent-tool-governance/spec.md`
- **acceptance_criteria**:
  - The tools offered are declared by the owning app
  - hermiq declares no tool of its own in either direction
  - The surface is discoverable by an outside client

- [x] Add the publication surface
- [x] Add the owning app's declaration path

### Task 2: Registration, with default-deny on writes
- **acceptance_criteria**:
  - A registration names the tools it may call
  - Write tools are denied unless granted, reusing the existing rule rather than a
    second one

- [x] Add the registration object and its admin surface
- [x] Reuse the default-deny grant check

### Task 3: Authorise as the calling principal
- **acceptance_criteria**:
  - Every call is authorised by the owning app for the principal
  - A registration carries no rights of its own
  - Revoking a principal's access refuses the next call with no hermiq edit

- [x] Wire the principal through the call path
- [x] Add a test proving a registration cannot exceed its principal

### Task 4: Two gates
- **acceptance_criteria**:
  - The grant check and the owning app's authorisation both run, in that order
  - Neither passes a call alone

- [x] Add the ordered check and its refusal messages

### Task 5: The output filter
- **acceptance_criteria**:
  - A registration may allowlist response fields
  - Nothing is renamed, reshaped or computed

- [x] Add the allowlist and apply it on the way out

### Task 6: The prompt library
- **spec_ref**: `openspec/changes/the-declared-tool-surface-and-the-prompt-library/specs/ai-feature-admin-surface/spec.md`
- **acceptance_criteria**:
  - `AssistantPrompt` carries label, text, `usageScope`, order and `enabled`
  - The text sent is the text the object carries
  - Order is not re-sorted for display

- [x] Add the schema and the admin pages
- [x] Point the case assistant surface at the library

### Task 7: The kill switch
- **acceptance_criteria**:
  - Disable-all is one act, wholesale or per scope
  - It is recorded with actor and time
  - No bulk re-enable exists

- [x] Add the act and its audit entry

### Task 8: Verification
- [x] Unit tests for the two gates, the narrowing-only filter and the shipped-library
      precedence
- [x] e2e coverage or a reason-bearing exclusion per scenario, per gate 19
