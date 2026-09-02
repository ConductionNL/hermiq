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

- [ ] 2.1 `ApprovalController::index()` reads pending work through OpenRegister's `TaskInboxService` (mirror tasks joined onto Approval payloads), retiring the app-local pending query as the inbox source
- [ ] 2.2 Retire `DeliveryService`'s reviewer notification fan-out for approvals in favour of OpenRegister task notifications (Talk delivery stays until 2.3)
- [ ] 2.3 Repoint `TalkApprovalReactionListener` and `Talk/TalkApprovalBinding` onto the mirror task (a Talk reaction completes the TASK, so both surfaces converge on one decision path)
- [ ] 2.4 Flip authority: the task becomes the record of the decision, the Approval keeps only hermiq-specific resume context

## Phase 3: thin delegate (staged)

- [ ] 3.1 `ApprovalService::approve()`/`deny()` become delegates over `TaskService::complete()`; the terminal listener becomes the ONLY decision applier
- [ ] 3.2 Evaluate `TaskSequenceService::provision()` for multi-step approval (skill drafts with maintainer + owner sign-off); adopt or record the rejection
- [ ] 3.3 Adopt `expiresAt`/`onTimeout` for TTL-bounded `toolcall` approvals once OpenRegister's `task-expiry-and-outcomes` change lands, replacing the app-local TTL constant
- [ ] 3.4 Retire the approval schema's decision fields that the task now owns; `occ openregister:schemas:prune-retired` for anything dropped
