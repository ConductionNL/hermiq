# Tasks: approval-task convergence

## Phase 1: adoption seam and migration (this change)

- [x] 1.1 Declare `taskUuid` on the approval schema in `lib/Settings/hermiq_register.json` and the mock register (OpenRegister drops undeclared properties on save), bump the schema version
- [x] 1.2 `ApprovalTaskBridge`: `ensureTaskFor()` creates the mirror via `TaskService::import()` (lazy FQN-string resolution, scalar-only data, never throws to the caller), `releaseFor()` terminates the mirror as moot
- [x] 1.3 Seam in `ApprovalService`: mirror on pending-approval creation inside `persistApproval()` (one choke point), release in `approve()` and `deny()`
- [x] 1.4 `TaskTerminalListener` consuming committed `TaskTerminalEvent` by string-literal FQN; register it in `Application::register()`
- [x] 1.5 `MirrorPendingApprovalsToTasks` repair step (idempotent, post-migration only) registered in `appinfo/info.xml`
- [x] 1.6 `occ hermiq:approvals:rollback-task-mirror` command registered in `appinfo/info.xml`
- [x] 1.7 Test stubs matching the REAL OpenRegister development signatures: `OCA\OpenRegister\Db\Task`, `OCA\OpenRegister\Service\Task\TaskService`, `OCA\OpenRegister\Event\TaskTerminalEvent`
- [x] 1.8 Unit tests: bridge, listener, seam, repair step, rollback command

## Phase 2: one inbox (staged, next change)

- [x] 2.1 `ApprovalController::index()` reads pending work through OpenRegister's `TaskInboxService` (mirror tasks joined onto Approval payloads), retiring the app-local pending query as the inbox source
      - `ApprovalTaskInbox` reads the reviewer's assigned and pooled open tasks requested by `hermiq:approval-gate` (FQN-resolved, null without the surface); `ApprovalInbox::listPendingForReviewer()` lists those approvals in task order with `taskUuid`, drops a mirror the task inbox does not route, and keeps hermiq's own routing only for un-mirrored approvals and for an OpenRegister without the task inbox. Tests: `ApprovalTaskInboxTest`, `ApprovalInboxTest::testTheTaskInboxRoutesMirroredApprovals`, `testATaskRowNeverWidensTheReviewerCheck`, `testAnUnreadableTaskInboxFallsBackToHermiqRouting`.
- [x] 2.2 Retire `DeliveryService`'s reviewer notification fan-out for approvals in favour of OpenRegister task notifications (Talk delivery stays until 2.3)
      - 2026-11-26: `ApprovalTaskNotice::notifiedAssignee()` names the reviewer OpenRegister already notifies through the mirror task: the approval carries `taskUuid`, is routed to one named user (the mirror is created assigned, and OpenRegister's `taskAssignedToYou` rule fires on `create`), and `OCA\OpenRegister\Listener\TaskNotificationListener` exists. `DeliveryService` raises no hermiq notification for exactly that user on every approval flavour (schedule, flow, webhook, skill draft, tool) and counts them as reached; other recipients of the round are still notified, and the Talk room post and Note-to-self stay until 2.3. A group-routed mirror is created as an unassigned pool task, which OpenRegister announces only on `offer`, not on `create`, so group reviewers keep hermiq's notice until OpenRegister's `taskOfferedToPool` rule also fires on `create`. Tests: `ApprovalTaskNoticeTest`, `DeliveryServiceApprovalTaskNoticeTest` (each fails with the guard removed).
- [ ] 2.3 Repoint `TalkApprovalReactionListener` and `Talk/TalkApprovalBinding` onto the mirror task (a Talk reaction completes the TASK, so both surfaces converge on one decision path)
- [ ] 2.4 Flip authority: the task becomes the record of the decision, the Approval keeps only hermiq-specific resume context

## Phase 3: thin delegate (staged)

- [ ] 3.1 `ApprovalService::approve()`/`deny()` become delegates over `TaskService::complete()`; the terminal listener becomes the ONLY decision applier
- [ ] 3.2 Evaluate `TaskSequenceService::provision()` for multi-step approval (skill drafts with maintainer + owner sign-off); adopt or record the rejection
- [ ] 3.3 Adopt `expiresAt`/`onTimeout` for TTL-bounded `toolcall` approvals once OpenRegister's `task-expiry-and-outcomes` change lands, replacing the app-local TTL constant
- [ ] 3.4 Retire the approval schema's decision fields that the task now owns; `occ openregister:schemas:prune-retired` for anything dropped
