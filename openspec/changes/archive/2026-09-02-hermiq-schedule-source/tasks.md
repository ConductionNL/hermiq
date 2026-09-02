# Tasks: hermiq-schedule-source

## 1. Answer the scheduler

- [x] 1.1 `HermiqFlowResolver` implements `IScheduledFlowSource`.
- [x] 1.2 Report agentflows whose trigger is `schedule`, with cron, `enabled`
      and owner (entity owner, falling back to the `owner` field).
- [x] 1.3 Report disabled schedules honestly — OpenRegister decides what runs.
- [x] 1.4 An unreadable store logs and yields nothing.

## 2. The missing property

- [x] 2.1 Add `cron` to the `agentflow` schema (optional, no conditionals);
      schema v0.1.3, register descriptor v0.22.0.

## 3. Tests

- [x] 3.1 The resolver declares the capability (`instanceof`), so the registry
      will actually ask it.
- [x] 3.2 A scheduled agentflow is reported with cron, trigger and owner.
- [x] 3.3 Event-triggered and manual agentflows are not reported.
- [x] 3.4 A disabled schedule is reported with `enabled` false.
- [x] 3.5 The `owner` field is the fallback when the entity has no owner.
- [x] 3.6 Stub `IScheduledFlowSource` for standalone unit runs.

## 4. Verify

- [x] 4.1 Live: an agentflow with a short cron fires through OpenRegister's
      schedule worker; a disabled sibling does not.
