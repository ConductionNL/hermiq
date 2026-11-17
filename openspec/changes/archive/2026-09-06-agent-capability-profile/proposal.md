---
kind: code
depends_on: [agent-engine-schemas, agent-engine-port]
---

# Proposal: agent-capability-profile

## Why

`SPECTR-NEXTCLOUD-PLAN.md` §6.3 asks for a per-agent capability profile that prevents
overflow and pins acting identity: an explicit skill allowlist (no skill overflow), an
explicit tool allowlist (no MCP/tool-prompt overflow), and an explicit acting-user
account (so a scheduled/impersonated run's writes are attributable to a real, chosen
identity rather than always defaulting to the schedule owner).

**Ground-truth check against HEAD (2026-07-06, `development` @ f5c7535, post agent-engine-port
#13):** two of the three profile fields are ALREADY SHIPPED, verified against the current
code rather than the plan's vocabulary:

- **Tool allowlist.** `Agent.tools` (declared in `agent-engine-schemas`, consumed by
  `ToolLoop::listAgentFunctions()`) already IS the plan's "toolAllowlist": an array of
  `{appId}.{toolName}` registry ids, empty = allow all, enforced at turn assembly against
  `ToolRegistryFacade::listTools()`. `ToolLoopTest` already covers whitelist-pass-through,
  legacy-id expansion, `selectedTools` intersection, and the empty-intersection guard (7
  tests, all green). This change does **not** add a second, differently-named field for the
  same concept — that would violate ADR-032's "no unions" spirit at the schema level and
  duplicate a fully-tested mechanism. `tools`'s description is tightened to say "the
  fleet-MCP-registry tool allowlist" so the plan vocabulary and the shipped property are
  legible as the same thing.
- **Acting user (partial).** `Agent.user` ("Run-as user") was declared in
  `agent-engine-schemas` and even carried through `MigrateAgentData`'s column map, but is
  **dead code**: grep at HEAD finds zero reads outside the migration's own write. This
  change RENAMES it to `actingUser` (clearer name, matches the plan) and — the actual
  content of this change — **wires it**: `ScheduleService::runAgentAsOwner()` now resolves
  and impersonates `actingUser` (when set and a valid, active NC user) instead of the
  schedule owner, for the duration of the agent turn only.
- **Skill allowlist.** Net new. `skillInstalls` (agent→skill uuid refs) is declared on
  `Agent` and kept in sync from `SkillService::installOnAgent()` (which already writes the
  reverse ref, `Skill.installedOn`) — a genuine bidirectional join, not a second source of
  truth. The loop consuming `skillInstalls` to filter which skills are exposed as
  `hermiq.skill.{slug}` tools does not exist yet (no skill-as-tool injection exists at HEAD
  at all) — that consumption is an explicit, undelivered seam here, same pattern as
  `agent-memory`'s "OR-owned run-loop seam" note.

## What Changes

- `lib/Settings/hermiq_register.json` (`Agent` schema, register `info.version` 0.7.0 →
  0.8.0 to force re-import):
  - RENAME `user` → **`actingUser`** (string): "the NC user id the agent impersonates for
    this run's `ObjectService` writes/Files/deliveries; defaults to the schedule owner when
    unset."
  - ADD **`skillInstalls`** (array of uuid, default `[]`): "Skill uuids explicitly installed
    on this agent — the allowlist a future run loop uses to decide which skills are exposed
    as tools. Kept in sync by `SkillService::installOnAgent()`."
  - `tools`'s description is clarified (no schema shape change) to name it as the tool
    allowlist explicitly.
- `lib/Repair/MigrateAgentData.php`: retarget the `user` DB column onto the renamed
  `actingUser` schema property (a one-line override; the generic snake→camel mapper cannot
  itself rename a column, so an explicit override map is added for this one field).
- `lib/Service/ScheduleService.php`: `runAgentAsOwner()` resolves the effective run
  identity — `Agent.actingUser` when set AND it resolves to an existing, enabled NC user,
  else the schedule owner (unchanged default) — and impersonates that identity for the
  Engine call only (conversation `userId`, `ObjectService` writes during the turn). The
  resolved identity is recorded on the run's audit entry (`runAsUser`). The OpenRegister
  `ChatService` (feature-flag-off) path is untouched — `actingUser` is a hermiq-register
  concept the legacy OR `Agent` entity does not have.
- `lib/Service/SkillService.php`: `installOnAgent()` additionally appends the skill uuid to
  the target agent's `skillInstalls` (best-effort: a missing/unreadable agent does not fail
  the skill-side install, which remains the source of truth for "this skill is installed
  somewhere").

## Impact

- Affected specs: NEW `agent-capability-profile` capability (config: schema deltas + rename;
  thin code: `ScheduleService` impersonation guard, `SkillService` sync, `MigrateAgentData`
  column retarget).
- Affected code: `lib/Settings/hermiq_register.json`, `lib/Repair/MigrateAgentData.php`,
  `lib/Service/ScheduleService.php`, `lib/Service/SkillService.php`, plus their existing test
  files (extended, not replaced).
- NOT delivered (explicit deferred seam): the run-loop skill-injection path that would
  actually filter `hermiq.skill.{slug}` tool exposure by `skillInstalls` — no skill-as-tool
  injection exists at HEAD to filter. UI affordances (an actingUser picker with an
  admin-account warning) are also out of scope — backend-only per the brief ("thin code").
