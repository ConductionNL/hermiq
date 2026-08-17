# Tasks

## 1. Remove the override

- [ ] Delete `HERMIQ_LEGACY_UNSCOPED_TOOLS` and its branch
- [ ] Delete `applyDefaultDeny()`, orphaned once the branch goes
- [ ] Keep `isWriteOrDestructive()` — wildcard expansion and the approval gate still use it

Acceptance criteria:
- No code path returns the catalog for an empty grant list.
- No unreachable method remains whose purpose is to grant tools; to the next maintainer that reads as a supported path.

## 2. Re-point the tests that used the override

- [ ] Update the tests currently opting into the flag to exercise classification another way
- [ ] Keep the assertion that an unconfigured agent is tool-less but NOT reported as broken

Acceptance criteria:
- `resolvesToNothing()` still returns false for empty grants. `ToolLoop` throws on it, and an unconfigured agent is a legitimate conversational agent rather than a defect.
- Configured grants that resolve to nothing still return true.

## 3. Surface the affected agents

- [ ] Add a report listing agents whose `tools` is null or empty
- [ ] The report MUST NOT modify them

Acceptance criteria:
- Back-filling agents with the tools they implicitly had would preserve ~101,000 tokens per turn as an explicit grant nobody chose. The report makes it a decision, not a migration.
- Measured on the development instance: 99 agents affected, 12 unaffected.
