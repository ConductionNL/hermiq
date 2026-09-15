# Tasks: identical-reports-collapse-into-one

## Implementation Tasks

### Task 1: The similarity question
- **spec_ref**: `openspec/changes/identical-reports-collapse-into-one/specs/report-similarity/spec.md`
- **acceptance_criteria**:
  - Given a report and an open window, the answer is a group or a new group, with a
    score
  - No record is created, merged, closed or deleted by hermiq

- [ ] Add the similarity endpoint and its group answer

### Task 2: Three bands
- **acceptance_criteria**:
  - Above the upper threshold: joins, counted
  - Between: joins, flagged uncertain, listed beside the group
  - Below the lower threshold: stands alone
  - Both thresholds are administered and readable

- [ ] Add the bands and the thresholds
- [ ] Add the near-duplicate listing

### Task 3: Additive and reversible
- **acceptance_criteria**:
  - A group is a set of references plus scores
  - Every report stays whole, with its own reporter
  - Removing a membership restores a standalone report and moves the count

- [ ] Add the group object and the membership removal

### Task 4: Acknowledgements are untouched
- **acceptance_criteria**:
  - The grouping answer carries no acknowledgement instruction
  - Two hundred reports owe two hundred confirmations

- [ ] Add a test asserting grouping changes no acknowledgement count

### Task 5: Deterministic key first
- **acceptance_criteria**:
  - The owning app's key is used where supplied, and recorded as the decider
  - The model decides only what the key misses, and is recorded as the decider

- [ ] Read the declared key
- [ ] Record the deciding method per membership

### Task 6: The bounded window
- **acceptance_criteria**:
  - The window is administered per report type
  - The window in force is recorded on the group

- [ ] Add the window setting and the recording

### Task 7: Readable reasons
- **acceptance_criteria**:
  - Terms, window, per-member score and deciding method are readable by a handler
  - Each judgement is recorded as a run on the audit trail

- [ ] Add the reasons record and its surface
- [ ] Write each judgement to the audit trail

### Task 8: Verification
- [ ] Unit tests for the three bands, the reversal and the untouched acknowledgement
      count
- [ ] e2e coverage or a reason-bearing exclusion per scenario, per gate 19
