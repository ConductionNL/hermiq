# Tasks: a-conversational-intake-that-files-for-the-citizen

## Implementation Tasks

### Task 1: The intake surface, separate from the case assistant
- **spec_ref**: `openspec/changes/a-conversational-intake-that-files-for-the-citizen/specs/conversational-intake/spec.md`
- **acceptance_criteria**:
  - A distinct surface, with a create-only tool grant scoped to declared record types
  - It cannot read or change an existing record
  - The case assistant surface still resolves to no tools

- [ ] Add the surface and its narrow grant
- [ ] Add a test asserting the case assistant sentinel is unchanged

### Task 2: Classification with confidence and abstention
- **acceptance_criteria**:
  - The proposal is drawn from the owning app's declared catalogue
  - A confidence travels with it
  - Below an administered threshold the system abstains and hands over
  - The threshold is readable

- [ ] Add the classification call and the catalogue read
- [ ] Add the threshold setting and the abstention path

### Task 3: No dead ends
- **acceptance_criteria**:
  - Every terminal state is a filed request or a handover
  - The transcript travels with a handover

- [ ] Enumerate terminal states and add the handover path
- [ ] Add a test over the terminal states

### Task 4: One conversation across channels
- **acceptance_criteria**:
  - A conversation is keyed by person and subject
  - A message on another channel attaches to an open conversation
  - hermiq implements no channel transport

- [ ] Add the conversation key and the attach rule
- [ ] Confirm channels arrive through their owning apps

### Task 5: Deterministic before model
- **acceptance_criteria**:
  - A deterministic signal from the owning app is used and recorded as such
  - A model score is used only in its absence and is labelled everywhere
  - A reader can tell the two apart

- [ ] Read the owning app's signal where declared
- [ ] Add the label to every surface showing a score

### Task 6: The external review tool
- **acceptance_criteria**:
  - The review is declared by the owning app
  - The verdict is carried into the conversation
  - The run records the reviewing party and the time
  - hermiq evaluates nothing itself

- [ ] Add the declared review call and its recording

### Task 7: Register intake as its own AI feature
- **acceptance_criteria**:
  - It appears in the register with its own risk category
  - The existing acknowledgement gate applies to it

- [ ] Add the feature registration

### Task 8: Verification
- [ ] Unit tests for the abstention threshold, the terminal states and the
      deterministic preference
- [ ] e2e coverage or a reason-bearing exclusion per scenario, per gate 19
