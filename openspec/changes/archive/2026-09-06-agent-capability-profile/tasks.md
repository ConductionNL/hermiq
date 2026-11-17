# Tasks: agent-capability-profile

## 1. Schema (register patch)

- [x] 1.1 Rename `Agent.user` → `actingUser` in `lib/Settings/hermiq_register.json`; update
      title/description to describe run-impersonation semantics (default = schedule owner;
      audited when set).
- [x] 1.2 Add `Agent.skillInstalls` (array of uuid, default `[]`): the explicit
      agent→skill allowlist, English title+description (gate-28).
- [x] 1.3 Tighten `Agent.tools`'s description to name it as the fleet MCP tool allowlist
      (no shape change — ground-truth: already wired, see design.md).
- [x] 1.4 Bump register `info.version` 0.7.0 → 0.8.0 to force OR re-import on next boot.

## 2. Migration retarget

- [x] 2.1 `lib/Repair/MigrateAgentData.php`: add an explicit column→property override so the
      legacy `user` DB column maps onto the renamed `actingUser` schema property (the
      generic snake→camel derivation would otherwise still write `user`).

## 3. ScheduleService — actingUser impersonation

- [x] 3.1 `resolveActingUser()`: on the engine-enabled path only, read the hermiq `agent`
      object's `actingUser`; when set AND it resolves to an existing, enabled NC user,
      return it; otherwise fall back to the schedule owner (logged at `warning` on an
      invalid/disabled override).
- [x] 3.2 `runAgentAsOwner()`: impersonate the resolved identity (not always the owner) for
      the Engine call only; restore the prior session identity in the same `finally` as
      today. The legacy `ChatService` path is untouched (byte-for-byte, flag off).
- [x] 3.3 Thread the resolved identity into `runAgentViaEngine()`'s conversation `userId`
      and the Engine `processMessage()` `userId` argument, and record it on the run's audit
      context (`runAsUser`).

## 4. SkillService — bidirectional install join

- [x] 4.1 `installOnAgent()`: after appending the agent uuid to `Skill.installedOn`, also
      append the skill uuid to the target `Agent.skillInstalls` (idempotent, best-effort —
      an unreadable/missing agent does not fail the skill-side install).

## 5. Verify

- [x] 5.1 Unit tests (php:8.3-cli, the CI way): `ScheduleServiceTest` — actingUser overrides
      owner impersonation when valid; falls back to owner when unset/nonexistent/disabled;
      is never consulted on the flag-off path; is recorded on the audit entry.
      `SkillServiceTest` (new) — `installOnAgent` syncs `Agent.skillInstalls`; a
      missing/unreadable agent does not fail the skill-side install.
- [x] 5.2 Fresh containerized PHPUnit run vs. the pre-change baseline — report both counts.
- [x] 5.3 `openspec validate agent-capability-profile --strict`; phpcs/psalm/phpstan clean;
      `scripts/hydra gates diff-scoped vs origin/development` (28 gates) — report results.

## Acceptance criteria

- `Agent.actingUser` (renamed from `user`) is wired: a scheduled run impersonates it (when
  valid+active) instead of the schedule owner, for the duration of the agent turn, and the
  audit trail records which identity actually ran.
- `Agent.skillInstalls` exists and is kept in sync by `SkillService::installOnAgent()`.
- `Agent.tools` (unchanged shape) is documented as the fleet tool allowlist; no duplicate
  field introduced.
- No regression on the flag-off (legacy `ChatService`) dispatch path.

## Quality reminders

- SPDX tags in each PHP docblock; `@spec` tags referencing this change.
- No sed/awk/scripts on code — Edit tool only.
- Config-then-code: the schema rename/add is declarative; the impersonation guard and the
  skill↔agent sync are the thin code paths.
- Run-loop skill-injection (filtering `hermiq.skill.{slug}` tool exposure by
  `skillInstalls`) is an explicit, undelivered seam — do not stub it.
