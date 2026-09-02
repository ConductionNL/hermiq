# Approval-task convergence

## Why

The fleet audit flagged hermiq's app-local `ApprovalService` as a duplicate of the engine. OpenRegister now owns the fleet task entity and the approval layer: `openregister_tasks`, `TaskService` (with a trusted `import()` path), `TaskInboxService`, `TaskSequenceService`, task notifications and the CalDAV VTODO projection. Every approval hermiq keeps to itself is an approval the shared inbox, the calendar projection and the sequence machinery cannot see.

hermiq's `ApprovalService` is 1,746 lines gating five action shapes (schedule, flow, webhook, tool, toolcall) plus skill drafts, with its own reviewer routing, its own inbox (`ApprovalController::index`), its own Talk delivery and its own decision write-path. filinq already made this move for signing (filinq#988): it consumes OpenRegister's task events through string-literal FQNs and retired its step listener.

## What changes

Phase 1 (this change) delivers the adoption seam and the data migration, not the full retirement:

- Every pending hermiq Approval is mirrored as ONE OpenRegister task, created on the trusted `TaskService::import()` path (hermiq is the initiator), carrying a `metadata.hermiq.approvalUuid` backlink and anchored on the Approval object. The Approval object stores the mirror's `taskUuid`.
- A new `TaskTerminalListener` consumes OpenRegister's committed `TaskTerminalEvent` (registered by string-literal FQN, the filinq#988 pattern, so registration survives bootstrap ordering): a task completed with an approving outcome applies `ApprovalService::approve()`, a rejecting outcome applies `deny()`. A decision made in OpenRegister's inbox therefore resumes the gated run exactly as a decision made in hermiq.
- A decision made on hermiq's own surface releases the mirror: the linked task is terminated as moot, so it never dangles in anyone's inbox.
- A repair step mirrors every already-pending Approval on upgrade (idempotent, guarded on the stored `taskUuid`), and an occ command rolls the mirror back (terminates mirror tasks, clears the backlinks), following OpenRegister's `MigrateApprovalChainsToTasks` + rollback-command pattern.

Phase 2 and 3 (staged in tasks.md, not in this change's code): route hermiq's approval inbox reads through `TaskInboxService`, retire the app-local reviewer notification fan-out in favour of task notifications, and reduce `ApprovalService`'s decision surface to a thin delegate over task verbs.

## Impact

- Affected specs: `human-approval-gate` (decision surface widens to OpenRegister's task inbox; hermiq's gate semantics are unchanged).
- Affected code: `lib/Service/ApprovalService.php` (bridge seam), new `lib/Service/Approval/ApprovalTaskBridge.php`, new `lib/Listener/TaskTerminalListener.php`, `lib/AppInfo/Application.php`, new `lib/Repair/MirrorPendingApprovalsToTasks.php`, new `lib/Command/RollbackApprovalTaskMirror.php`, `appinfo/info.xml`, `lib/Settings/hermiq_register.json` + mock (new `taskUuid` property on the approval schema).
- Approval-gate behaviour is unchanged when OpenRegister's task surface is absent or older: the bridge degrades to a logged no-op and the Approval object remains authoritative.
