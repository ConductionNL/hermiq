# Approval-task convergence

## ADDED Requirements

### Requirement: every pending approval is mirrored as one OpenRegister task

When hermiq creates a pending Approval, it SHALL ensure exactly one OpenRegister task exists for it, created on the trusted `TaskService::import()` path, carrying `metadata.hermiq.approvalUuid`, anchored on the Approval object, and routed to the Approval's reviewer (assignee for a user reviewer, candidate group for a group reviewer). The Approval SHALL store the mirror's uuid in its `taskUuid` property. When OpenRegister's task surface is absent or older, the approval write-path SHALL proceed without a mirror and log a warning.

#### Scenario: a gated schedule produces a task mirror

- **WHEN** the dispatcher creates a pending Approval for a gated schedule
- **THEN** one OpenRegister task exists whose metadata names the Approval's uuid, whose anchor is the Approval, and whose assignee or candidate group is the resolved reviewer
- **AND** the Approval's `taskUuid` names that task

@e2e exclude {backend-only seam; the mirror write-path is exercised by ApprovalTaskBridgeTest and ApprovalServiceTest, and the e2e surface (OpenRegister's task inbox) lives in openregister}

#### Scenario: the task surface is unavailable

- **WHEN** a pending Approval is created and `TaskService` cannot be resolved
- **THEN** the Approval is created and decidable on hermiq's own surface, with no mirror and a logged warning

@e2e exclude {degraded-install branch, unit-covered in ApprovalTaskBridgeTest}

### Requirement: a task decision is an approval decision

hermiq SHALL consume OpenRegister's committed `TaskTerminalEvent` through a listener registered by string-literal FQN. For a task completed with `metadata.hermiq.approvalUuid` resolving to a pending Approval, a rejecting outcome (`rejected`, `returned`, `declined`, `denied`) SHALL apply `ApprovalService::deny()` and any other outcome SHALL apply `ApprovalService::approve()`, attributed to the task's `completedBy` (falling back to its assignee). An event without a resolvable decider SHALL be skipped and logged, never decided as nobody. Uncommitted events, non-completed terminal states and foreign tasks SHALL be ignored.

#### Scenario: approving from the OpenRegister inbox resumes the gated run

- **WHEN** a reviewer completes a mirror task in OpenRegister with an approving outcome
- **THEN** the linked Approval transitions to `approved` with the completer as decider, and the gated run resumes exactly as if approved on hermiq's surface

@e2e exclude {cross-app event path; unit-covered in TaskTerminalListenerTest, and the inbox UI belongs to openregister}

#### Scenario: rejecting from the OpenRegister inbox denies

- **WHEN** a reviewer completes a mirror task with outcome `rejected` and a comment
- **THEN** the linked Approval transitions to `denied` with the comment as reason, and the gated run never executes

@e2e exclude {cross-app event path, unit-covered in TaskTerminalListenerTest}

### Requirement: a hermiq decision releases the mirror

When an Approval is decided on hermiq's own surface, hermiq SHALL terminate the linked mirror task as moot so it leaves every OpenRegister inbox. Releasing an already-terminal task SHALL be a no-op, and a decision applied FROM a task terminal event SHALL NOT loop (the approval's non-pending status makes the replay a no-op).

#### Scenario: deciding in hermiq clears the OpenRegister inbox

- **WHEN** a reviewer approves a mirrored Approval through hermiq's own controller
- **THEN** the mirror task is terminated as moot with a reason naming the hermiq decision

@e2e exclude {cross-app write, unit-covered in ApprovalTaskBridgeTest}

### Requirement: in-flight approvals migrate and roll back

On upgrade, a repair step SHALL mirror every already-pending Approval that has no `taskUuid`, idempotently (a second run changes nothing), reporting counts and naming failures. An occ command SHALL roll the mirror back: every still-open mirror task is terminated as moot and every `taskUuid` backlink cleared, without touching decisions already made.

#### Scenario: upgrade mirrors pending approvals once

- **WHEN** the repair step runs twice on an instance with pending approvals
- **THEN** after the first run every pending Approval carries a `taskUuid` naming an existing task, and the second run creates nothing

@e2e exclude {repair-step path, unit-covered in MirrorPendingApprovalsToTasksTest}

#### Scenario: rollback restores the pre-convergence world

- **WHEN** `occ hermiq:approvals:rollback-task-mirror` runs after the mirror pass
- **THEN** every mirror task for a still-pending Approval is terminal and every pending Approval's `taskUuid` is cleared

@e2e exclude {occ path, unit-covered in RollbackApprovalTaskMirrorTest}
