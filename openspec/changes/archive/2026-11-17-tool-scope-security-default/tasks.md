# Tasks

## 1. Remove the override

- [x] Delete `HERMIQ_LEGACY_UNSCOPED_TOOLS` and its branch
- [x] Delete `applyDefaultDeny()`, orphaned once the branch goes
- [x] Keep `isWriteOrDestructive()` — wildcard expansion and the approval gate still use it

Acceptance criteria:
- No code path returns the catalog for an empty grant list.
- No unreachable method remains whose purpose is to grant tools; to the next maintainer that reads as a supported path.

Done where the resolver now lives: OpenRegister's `ToolGrantResolver::resolve()` returns `[]` for an empty
grant list with no override, and neither the flag nor `applyDefaultDeny()` remains in OpenRegister or hermiq.

## 2. Re-point the tests that used the override

- [x] Update the tests currently opting into the flag to exercise classification another way
- [x] Keep the assertion that an unconfigured agent is tool-less but NOT reported as broken

Acceptance criteria:
- `resolvesToNothing()` still returns false for empty grants. `ToolLoop` throws on it, and an unconfigured agent is a legitimate conversational agent rather than a defect.
- Configured grants that resolve to nothing still return true.

## 3. Surface the affected agents

- [x] Add a report listing agents whose `tools` is null or empty
- [x] The report MUST NOT modify them

Acceptance criteria:
- Back-filling agents with the tools they implicitly had would preserve ~101,000 tokens per turn as an explicit grant nobody chose. The report makes it a decision, not a migration.
- Measured on the development instance: 99 agents affected, 12 unaffected.

Built as the read-only repair step `lib/Repair/ReportToolessAgents.php` (runs on upgrade and on
`occ maintenance:repair`), tested in `tests/Unit/Repair/ReportToolessAgentsTest.php`.
