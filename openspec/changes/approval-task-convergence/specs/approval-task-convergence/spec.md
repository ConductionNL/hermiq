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

hermiq SHALL consume OpenRegister's committed `TaskTerminalEvent` through a listener registered by string-literal FQN. For a task completed with `metadata.hermiq.approvalUuid` resolving to a pending Approval, a rejecting outcome (`rejected`, `returned`, `declined`, `denied`) SHALL apply `ApprovalService::applyDenial()` and any other outcome SHALL apply `ApprovalService::applyApproval()`, attributed to the task's `completedBy` (falling back to its assignee). An event without a resolvable decider SHALL be skipped and logged, never decided as nobody. Uncommitted events, non-completed terminal states and foreign tasks SHALL be ignored.

#### Scenario: approving from the OpenRegister inbox resumes the gated run

- **WHEN** a reviewer completes a mirror task in OpenRegister with an approving outcome
- **THEN** the linked Approval transitions to `approved` with the completer as decider, and the gated run resumes exactly as if approved on hermiq's surface

@e2e exclude {cross-app event path; unit-covered in TaskTerminalListenerTest, and the inbox UI belongs to openregister}

#### Scenario: rejecting from the OpenRegister inbox denies

- **WHEN** a reviewer completes a mirror task with outcome `rejected` and a comment
- **THEN** the linked Approval transitions to `denied` with the comment as reason, and the gated run never executes

@e2e exclude {cross-app event path, unit-covered in TaskTerminalListenerTest}

### Requirement: a hermiq decision releases the mirror

When an Approval is decided on hermiq's own path (it has no mirror task, or OpenRegister refused to complete it), hermiq SHALL terminate the linked mirror task as moot so it leaves every OpenRegister inbox. Releasing an already-terminal task SHALL be a no-op, and a decision applied FROM a task terminal event SHALL NOT loop (the approval's non-pending status makes the replay a no-op).

#### Scenario: deciding in hermiq clears the OpenRegister inbox

- **WHEN** a reviewer approves a mirrored Approval through hermiq's own controller and OpenRegister refuses to complete its mirror task
- **THEN** the mirror task is terminated as moot with a reason naming the hermiq decision

@e2e exclude {cross-app write, unit-covered in ApprovalTaskBridgeTest}

### Requirement: a hermiq decision completes the mirror task

`ApprovalService::approve()` and `ApprovalService::deny()` SHALL decide a mirrored Approval by completing its mirror task in OpenRegister as the deciding reviewer, with outcome `approved`, or `rejected` with the denial reason (trimmed, omitted when empty) as the task comment. OpenRegister's terminal event then reaches the task terminal listener, which SHALL be the only caller that applies the decision (`applyApproval()`/`applyDenial()`). After the completion hermiq SHALL re-read the Approval and apply the decision itself only when it is still pending; it SHALL NOT apply a decision to an Approval it can no longer read, so a gated run is never released twice. When the decision was applied in the same request, `approve()` SHALL report that applied result. A non-pending Approval, a question, a held draft and a skill draft awaiting re-qualification SHALL never reach the task. An Approval without a mirror, an install without the task surface, or a refused completion SHALL be decided on hermiq's own path as before.

#### Scenario: approving in hermiq completes the task and the listener applies it

- **WHEN** a reviewer approves a mirrored, pending Approval through hermiq's own controller
- **THEN** its mirror task is completed by that reviewer with outcome `approved`, the terminal listener approves the Approval and resumes the gated run once, and the controller reports that the run fired

@e2e exclude {cross-app task completion and event, unit-covered in ApprovalServiceTaskDelegateTest}

#### Scenario: denying in hermiq rejects the task with the reason

- **WHEN** a reviewer denies a mirrored, pending Approval with a reason
- **THEN** its mirror task is completed with outcome `rejected` and that reason as the comment, and the Approval is denied once

@e2e exclude {cross-app task completion, unit-covered in ApprovalServiceTaskDelegateTest}

#### Scenario: a refused completion decides in hermiq

- **WHEN** the Approval has no mirror task, or OpenRegister refuses the completion and the claim
- **THEN** hermiq approves or denies the Approval itself and releases the mirror as moot

@e2e exclude {degraded branch, unit-covered in ApprovalServiceTaskDelegateTest}

#### Scenario: an Approval the task already decided is not decided again

- **WHEN** the mirror task completed and the re-read Approval is no longer pending, or cannot be read
- **THEN** hermiq applies no second decision and the gated run is not released again

@e2e exclude {defensive branch, unit-covered in ApprovalServiceTaskDelegateTest}

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

### Requirement: the reviewer inbox reads pending work from the OpenRegister task inbox

hermiq's approval inbox (`GET /api/approvals`) SHALL take its routing from OpenRegister's `TaskInboxService`: a mirrored pending Approval SHALL be listed for a user when its open mirror task, requested by hermiq's approval gate, is assigned to that user or offered to a group they are in, in the task inbox's order and carrying the mirror's `taskUuid`. A mirrored Approval whose task the inbox does not route to the user SHALL NOT be listed for them. A pending Approval without a mirror SHALL still be listed by hermiq's own reviewer routing, after the routed ones. When the task inbox cannot be resolved or read, the whole inbox SHALL fall back to hermiq's own reviewer routing and log why. A task row SHALL never widen hermiq's reviewer check: an Approval that is no longer pending, or whose reviewer is not the user, is not listed whatever its task says.

#### Scenario: a routed mirror task lists its approval

- **WHEN** a reviewer opens the approval inbox and their task inbox holds an open mirror task for a pending Approval routed to them
- **THEN** that Approval is listed with the mirror's `taskUuid`, in the task inbox's order

@e2e exclude {cross-app read; unit-covered in ApprovalInboxTest and ApprovalTaskInboxTest, and the task inbox itself lives in openregister}

#### Scenario: a mirror the task inbox does not route is not listed

- **WHEN** a pending Approval carries a `taskUuid` and the reviewer's task inbox does not hold that task
- **THEN** the Approval is not listed for that reviewer

@e2e exclude {cross-app read, unit-covered in ApprovalInboxTest}

#### Scenario: an unmirrored approval stays visible

- **WHEN** a pending Approval has no `taskUuid` because the bridge could not mirror it
- **THEN** it is listed for its reviewer by hermiq's own routing, after the routed approvals

@e2e exclude {degraded branch, unit-covered in ApprovalInboxTest}

#### Scenario: no task inbox

- **WHEN** OpenRegister does not ship `TaskInboxService`, or reading it throws
- **THEN** the inbox lists every pending Approval routed to the user by hermiq's own routing, and the failure is logged

@e2e exclude {degraded-install branch, unit-covered in ApprovalTaskInboxTest}

### Requirement: the reviewer a mirror task is assigned to is notified once, by OpenRegister

When a pending Approval carries a mirror `taskUuid`, is routed to one named user, and OpenRegister's task notifications are installed, hermiq SHALL NOT raise its own Nextcloud notification to that user, because OpenRegister notifies the assignee when the mirror task is created. hermiq SHALL count that user as reached. Every other recipient of the round SHALL still get hermiq's notification. hermiq's Talk room post and Note-to-self message SHALL still go out. A group-routed mirror, an Approval without a mirror, and an instance without OpenRegister's task notifications SHALL keep hermiq's notification for every reviewer.

#### Scenario: an assigned mirror task carries the notice

- **WHEN** a pending Approval routed to user `bob` is mirrored as a task assigned to `bob`, on an instance with OpenRegister's task notifications
- **THEN** hermiq raises no notification to `bob`, the delivery reports `bob` reached, and the Talk message to `bob` is still posted

@e2e exclude {cross-app notification; unit-covered in DeliveryServiceApprovalTaskNoticeTest and ApprovalTaskNoticeTest, and the task notice itself is raised by openregister}

#### Scenario: a group-routed mirror keeps hermiq's notice

- **WHEN** a pending Approval routed to a group is mirrored as an unassigned pool task
- **THEN** hermiq notifies every member of the group itself

@e2e exclude {cross-app routing branch, unit-covered in DeliveryServiceApprovalTaskNoticeTest}

#### Scenario: no task notifications

- **WHEN** the Approval has no `taskUuid`, or OpenRegister does not ship its task notification listener
- **THEN** hermiq notifies every reviewer as before

@e2e exclude {degraded-install branch, unit-covered in ApprovalTaskNoticeTest}

### Requirement: a Talk reaction completes the mirror task

When a reviewer decides a mirrored Approval by reacting in Talk, hermiq SHALL complete the Approval's mirror task in OpenRegister as that reviewer, with outcome `approved` for 👍 and `rejected` for 👎 (with the reaction's reason as the task comment). A task offered to a group SHALL be claimed for the reviewer first. The Approval SHALL then follow from the task: hermiq's task terminal listener applies the decision. hermiq SHALL re-read the Approval after the completion and apply the decision on its own path only when the Approval is still pending; it SHALL NOT decide an Approval it can no longer read, so a gated run is never released twice. When the Approval has no mirror, OpenRegister has no task surface, or OpenRegister refuses the completion and the claim, hermiq SHALL decide the Approval on its own path as before. The reviewer check, the un-react rule and the confirmation in the room are unchanged.

#### Scenario: a 👍 on a mirrored approval completes its task

- **WHEN** the assigned reviewer reacts 👍 to the approval request of a pending Approval that has a mirror task
- **THEN** the mirror task is completed by that reviewer with outcome `approved`, the Approval is approved by the terminal listener, and the room confirms the approval

@e2e exclude {cross-app Talk reaction and task completion; unit-covered in MirrorTaskCompletionTest and TalkReactionDeciderTest}

#### Scenario: a group reviewer's reaction claims the offered task first

- **WHEN** a member of the reviewing group reacts 👎 to a mirrored Approval whose task is offered to the group
- **THEN** the task is claimed for that member and completed with outcome `rejected` and the reaction's reason as the comment

@e2e exclude {cross-app pool claim, unit-covered in MirrorTaskCompletionTest}

#### Scenario: a refused completion falls back to hermiq's decision

- **WHEN** OpenRegister refuses both the completion and the claim, or the Approval has no mirror
- **THEN** hermiq approves or denies the Approval itself, which releases the mirror as moot

@e2e exclude {degraded branch, unit-covered in TalkReactionDeciderTest and MirrorTaskCompletionTest}

#### Scenario: an unreadable approval is not decided twice

- **WHEN** the mirror task completed and the Approval can no longer be read
- **THEN** hermiq applies no second decision

@e2e exclude {defensive branch, unit-covered in TalkReactionDeciderTest}
