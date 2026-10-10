# Tasks: what-the-model-reads-and-what-is-kept

## Implementation Tasks

### Task 1: Declare a redaction requirement on a feature
- **spec_ref**: `openspec/changes/what-the-model-reads-and-what-is-kept/specs/woo-llm-anonymisation/spec.md`
- **acceptance_criteria**:
  - `AiFeature` carries `requiresRedaction`
  - The requirement attaches to a document reference, not to the whole run
  - A run with no document reference still proceeds

- [x] Add the field to the `AiFeature` register fragment

### Task 2: Refuse an unredacted document before the call
- **acceptance_criteria**:
  - The check sits in the ordered pre-call path, after residency
  - No request reaches the provider when it refuses
  - The refusal names the feature and the document reference

- [x] Read filinq's redaction outcome for the reference
- [x] Add the refusal to the pre-call path

### Task 3: Fail closed, and never call detection redaction
- **acceptance_criteria**:
  - A `detect-pii` result never satisfies `requiresRedaction`, in any path
  - An unresolvable filinq refuses every redaction-requiring feature
  - hermiq ships no redactor of its own

- [x] Add the closed-fail path and its message
- [x] Confirm by inspection that no path removes personal data inside hermiq

### Task 4: A retention on every run
- **spec_ref**: `openspec/changes/what-the-model-reads-and-what-is-kept/specs/run-audit-log/spec.md`
- **acceptance_criteria**:
  - An instance default exists and is administered
  - A per-feature override is possible
  - The resolved period is copied onto the run entry

- [x] Add the setting and the override
- [x] Write the resolved retention onto each run entry

### Task 5: The job that enforces it
- **acceptance_criteria**:
  - A scheduled job removes payloads past their retention
  - The instance reports the last run and the count
  - A job that has never run reads as never run, not as zero

- [x] Add the job
- [x] Add the report to the admin surface

### Task 6: Deletion that keeps the chain whole
- **acceptance_criteria**:
  - No chain entry is deleted
  - A tombstone carries run, time, feature, provider and the deletion date
  - The input and the output are absent afterwards
  - The chain verifies after a cleanup

- [x] Implement payload removal with a tombstone
- [x] Add a chain verification test over cleaned entries

### Task 7: Verification
- [x] Unit tests for the closed fail, the copied retention and the tombstone
- [x] e2e coverage or a reason-bearing exclusion per scenario, per gate 19
