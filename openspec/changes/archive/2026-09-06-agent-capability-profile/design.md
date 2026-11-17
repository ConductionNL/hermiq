# Design: agent-capability-profile

## Context

`SPECTR-NEXTCLOUD-PLAN.md` §6.3. Hermiq now owns the agent engine (`agent-engine-port`
#13) and the `Agent`/`Conversation`/`Message`/`Feedback` schemas (`agent-engine-schemas`
#12). This change is the first of two Phase-5 config+thin-code changes tightening the
per-agent capability surface; its sibling, `agent-context-system`, adds the Context entity.

## Decisions

**Do not duplicate `tools`.** `ToolLoop` already reads `Agent.tools` as a
`{appId}.{toolName}` allowlist (empty = allow all), fully tested. Adding a second field
`toolAllowlist` would be a union of two names for one concept — exactly what ADR-032
"no unions" guards against at the config layer. `tools`'s description is reworded to make
the "this is the fleet MCP tool allowlist" framing explicit; no code or schema-shape change.

**Rename `user` → `actingUser`, don't leave two identity fields.** `user` ("Run-as user")
was declared but never read anywhere in `lib/` — a dead field. Two options were considered:
(a) add a new `actingUser` field alongside the unused `user`, or (b) rename `user` in place
and wire it. (a) leaves a confusing, semantically-identical dead field sitting next to the
live one — a worse state than today. (b) is safe here specifically because `user` has zero
production consumers (grep-verified) and this is a pre-release schema (shipped last release
cycle, no real Agent data depends on the exact property name in a running fleet). The
migration's column→property map (`MigrateAgentData::AGENT_FIELDS`) is the one place that
needed a matching update: the source DB column is still literally `user` (OR's legacy
table), so an explicit override (`user` DB column → `actingUser` schema property) replaces
the generic snake→camel derivation for this one field only.

**Impersonation is scoped to the agent turn, not the whole dispatch.** `ScheduleService`
already impersonates the schedule owner for the duration of `runAgentAsOwner()` only (a
`try`/`finally` around the Engine/ChatService call); delivery and audit-write run AFTER the
identity is restored. `actingUser` slots into the exact same seam: resolved once, used for
the `IUserSession::setUser()` call and the conversation's `userId`/Engine's `userId` param,
restored in the same `finally`. This means `actingUser` affects `ObjectService`
writes/Files during the turn (conversation + messages + any tool calls that write), exactly
the brief's "ObjectService writes/Files/deliveries" scope for the *agent-authored* content —
delivery (Talk/notification) and the schedule's own run-state bookkeeping are unaffected,
matching "default remains schedule owner" for everything outside the turn.

**Valid-active-user guard, fail open to the owner.** `actingUser` is resolved via
`IUserManager::get()` + `IUser::isEnabled()`. An unset, non-existent, or disabled
`actingUser` silently falls back to the schedule owner (logged at `warning`) rather than
failing the run — a misconfigured profile field must not brick a schedule. This mirrors the
existing `resolveTimezone()` fail-safe pattern in the same class. The brief's "UI warns on
admin accounts" is a frontend affordance, out of scope for this backend-only change (noted
as deferred).

**`actingUser` only applies on the engine-enabled path.** The legacy
`OpenRegister\ChatService` path operates on OR's own (pre-hermiq-register) `Agent` QBMapper
entity, which has no `actingUser` concept and is frozen pending
`or-chat-proxy-deprecation`. `resolveActingUser()` is only invoked inside the
`isEngineEnabled() === true` branch — flag-off installs see zero behavior change, continuing
the pattern already documented on `ScheduleService`.

**`skillInstalls` is a real bidirectional join, not a second denormalisation.**
`SkillService::installOnAgent()` already writes the reverse ref (`Skill.installedOn`
appends the agent uuid). This change adds the forward ref: after a successful
`installedOn` append, the target `Agent.skillInstalls` array also gets the skill uuid
appended (idempotent, best-effort — a missing/unreadable agent does not fail the skill-side
install, which remains authoritative for "this skill is installed somewhere"). Declaring
`skillInstalls` as config only — with no consuming loop — was considered, but a completely
inert field with no writer is worse than a maintained one with a documented, not-yet-built
reader; keeping `installOnAgent` as the single write path for both directions keeps the
join consistent without inventing a second service.

## Integration seam (NOT implemented here)

No skill is exposed as a `hermiq.skill.{slug}` MCP tool at HEAD — `HermiqToolProvider`
serves only the 6 native `hermiq.*` tools (`nc-native-tools`), and no code converts a
`Skill` object into a tool descriptor. `skillInstalls` is therefore write-complete but
read-inert: the eventual "expose only installed skills to the loop" behavior needs a skill→
tool-descriptor bridge that does not exist yet. This is called out explicitly, not stubbed,
following the same convention as `agent-memory`'s recall/append run-loop seam.

## Risks / Trade-offs

- **Renaming a just-shipped field.** [`user` → `actingUser` changes a property name added
  one PR ago] → Zero grep hits outside its own migration write; safe pre-release rename.
  Flagged here for reviewer visibility rather than silently folded into "add actingUser."
- **Extra `ObjectService::find()` per scheduled run.** [`resolveActingUser()` re-fetches the
  agent before `runAgentViaEngine()` fetches it again] → Accepted: one extra small object
  read per scheduled tick is not a hot path; avoids threading a pre-fetched entity through
  the impersonation decision point, keeping the change surgical.
- **`skillInstalls` has no reader yet.** [inert until skill-as-tool injection exists] →
  Documented as a deferred seam (see above), matching repo convention.

## Open Questions

- **Open — UI.** An `actingUser` picker (with an admin-account warning) and a
  `skillInstalls` multi-select on the Agent form are natural follow-ups; out of scope here
  per the brief's "thin code" framing.
- **RESOLVED — no `toolAllowlist` field.** `Agent.tools` already is it; see Decisions.
