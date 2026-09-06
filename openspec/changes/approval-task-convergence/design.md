# Design: approval-task convergence, phase 1

## Decision 1: mirror, do not move (yet)

The Approval object stays the authoritative record in phase 1; the OpenRegister task is a mirror that adds the shared decision surface. Moving authority to the task in the same PR would mean rewriting all five source-type resume paths, the Talk reaction listener, the skill-draft applier and the compliance reads at once. The mirror gives the convergence payoff now (one inbox, one decision surface, OR notifications and VTODO for free) while every existing caller keeps working. Authority flips in phase 2.

Consequence: dual state exists by design, and both directions reconcile:

- OR decides first: `TaskTerminalListener` applies `approve()`/`deny()`; hermiq's own release call then finds the task already terminal and does nothing (OpenRegister's `terminateAsMoot()` is idempotent on terminal tasks).
- hermiq decides first: `approve()`/`deny()` release the mirror via `terminateAsMoot()`; the terminal event that raises fires with outcome `terminated`, which the listener ignores (it only acts on state `completed`), and even a completed replay is safe because `approve()`/`deny()` no-op on non-pending approvals.

## Decision 2: one creation choke point

The mirror is created inside `ApprovalService::persistApproval()`, only when the save is a CREATION of a PENDING approval (uuid null, status pending). All six ensure-pending shapes flow through that method, so no per-shape wiring exists to drift. The task uuid is written back onto the Approval in a second save; the write-back requires the `taskUuid` property to be DECLARED on the approval schema, because OpenRegister silently drops undeclared properties on save.

## Decision 3: the bridge is the only file that touches the task surface

`ApprovalTaskBridge` resolves `TaskService` from the container by FQN string at call time and reduces everything else to scalars. It never throws to its caller: a missing or older OpenRegister task surface logs a warning and returns null, and the approval write-path proceeds without a mirror. This is NOT a fail-open authorization path: no decision is ever derived from the bridge's absence; the gate itself (the pending Approval) is created before the bridge is consulted and remains decidable on hermiq's own guarded surface.

Task shape (the `UserTaskConfig` conventions):

- `state`: `active` when the reviewer is a user (assigned), `enabled` when the reviewer is a group (candidates only).
- `assignee` / `candidateGroups`: from the Approval's `reviewer`/`reviewerType`.
- `requester`: `hermiq:approval-gate`, a system seat, never the reviewer, so OpenRegister's separation-of-duties guard cannot mistake the initiator for the decider.
- `objectUuid`: the Approval's uuid (the task's anchor object).
- `metadata.hermiq`: `{approvalUuid, sourceType}`, the ownership marker the listener filters on.
- `title`/`description`: derived from sourceType, agentId and prompt.

## Decision 4: events by string literal, values by dynamic getter

`TaskTerminalEvent` is registered and routed by FQN string literal (filinq#988): during hermiq's own `register()` the `OCA\OpenRegister\` prefix may not be autoloadable yet, so neither `::class` on an import nor a `class_exists()` probe is reliable, and registering a listener for an event name that never dispatches is harmless. Inside the listener every value is read through an `is_callable` dynamic getter, so hermiq tolerates OpenRegister older, newer or absent.

The listener acts only on: committed events, state `completed`, a `metadata.hermiq.approvalUuid` it can resolve to a PENDING approval. The decider is the task's `completedBy` (falling back to `assignee`); with neither, the event is logged and skipped rather than deciding as nobody. Rejecting outcomes are OpenRegister's published vocabulary (`rejected`, `returned`, `declined`, `denied`); everything else on a completed task approves. A task terminated or cancelled in OpenRegister leaves the approval pending and decidable in hermiq.

## Decision 5: migration is a mirror pass, not a rewrite

In-flight state is pending Approval objects. The repair step (`MirrorPendingApprovalsToTasks`) walks every pending approval and ensures a mirror task exists, guarded on the stored `taskUuid` so a second run changes nothing. It runs after `CheckOpenRegisterCompatibility` in post-migration only (a fresh install has no approvals). Decided approvals are history, not work: they get no task (OpenRegister's own migration imported closed approvals because tasks BECAME the record there; here the Approval remains the record).

Rollback (`occ hermiq:approvals:rollback-task-mirror`) terminates every still-open mirror task as moot and clears the `taskUuid` backlinks, restoring the pre-convergence world without touching any decision already made. Both directions log per-object and report counts; a partial pass names what failed.

## Alternatives considered

- Full cutover in one PR (ApprovalService becomes a thin delegate): rejected for blast radius; five resume paths, the Talk binding and the skill-draft applier would all change under one review. Staged as phase 2/3.
- `TaskSequenceService::provision()` per approval: rejected; hermiq approvals are single-decider gates, not multi-step chains. A one-task sequence buys consolidation semantics nothing here. Sequences become relevant in phase 3 if hermiq grows multi-step approval.
- Hard constructor injection of `TaskService` into the bridge: rejected; it would make every hermiq boot construct the OR task surface and would fatal on an older OpenRegister that has `ObjectService` but no `Task` namespace.
