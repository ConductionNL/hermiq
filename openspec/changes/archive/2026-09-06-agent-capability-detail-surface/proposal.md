---
kind: code
---

# Proposal: agent-capability-detail-surface

## Why

Hermiq agents have three capability surfaces — config (name/model/prompt/tools/RAG),
skills, and memory — but only config is manageable from the agent detail page today, and
even that is edit-modal-only (no inline click-to-edit). Skills can only be attached via the
Skills catalog picking an agent as the target, and memory only has its own standalone
`AgentMemory.vue` route. A user configuring an agent has to jump across three separate
surfaces to see and change what it can do. This change consolidates all three onto the
agent detail page: a schema-driven, click-to-edit config widget, and new in-place Skills and
Memory management sections — with the Edit modal staying purely for the fields it already
owns.

**Mixed-spec rationale (see design.md for detail):** this change touches both PHP (a new
skill-detach endpoint) and Vue (the detail-page rewiring). Per ADR-032 the thin-glue
exception (≤20 LOC / ≤2 files) does not literally apply — the backend delta is one service
method, one controller method, one route, and mirrored tests. The two surfaces are however
tightly coupled (the new detach button calls only the new endpoint, nothing else), and the
backend delta mirrors an existing, already-specified write path (`installOnAgent`) rather
than introducing new domain logic. Decision: keep this as ONE change with `kind: code` — the
PHP delta is the spec-bearing surface (mirrors `skills-catalog`'s existing install
requirement) and the Vue delta consumes it plus already-shipped endpoints. Not split.

## What Changes

- **`src/views/AgentDetail.vue`** — replace the static `<dl class="agent-detail__meta">`
  config block with `<CnObjectDataWidget>` (schema-driven, click-to-edit, saves via
  `agentStore.saveObject('agent', ...)`). The schema is fetched at runtime via
  `agentStore.fetchSchema('agent')`. A `#field-tools` scoped slot renders an `NcSelect`
  (multiple, `inputLabel` set) sourced from the dynamic tool catalog
  (`GET /apps/hermiq/api/agents/tools`) since `tools` has no static enum. Tenancy/noise
  fields (`invitedUsers`, `groups`, `views`, `isPrivate`, `requestQuota`, `tokenQuota`,
  `actingUser`, `configuration`, `contextRefs`, `skillInstalls`) are excluded from the
  widget. The header "Edit agent" button keeps opening the existing config-only
  `AgentFormModal`, unchanged.
- **New "Skills" section on `AgentDetail.vue`** — lists installed skills (resolved from
  `agent.skillInstalls` against `listSkills()`), an attach picker (`NcSelect` of catalog
  skills not yet installed, calling the existing `installSkill(skillId, agentUuid)`), and a
  detach action per installed skill calling a new `uninstallSkill(skillId, agentUuid)`
  helper. Both actions reload the agent afterward.
- **New "Memory" section on `AgentDetail.vue`** — the existing `AgentMemory.vue` UI
  (char-budget bar, entry list, add-fact input, Consolidate action) is extracted into a
  shared `src/components/AgentMemoryPanel.vue` (`props: { agentId }`), consumed by both the
  standalone `AgentMemory.vue` route and the new detail-page section, so there is one
  implementation, not two.
- **Backend: skill-detach endpoint** — `SkillService::uninstallFromAgent(string $skillId,
  string $agentId): ?ObjectEntity`, mirroring the existing `installOnAgent`
  (`lib/Service/SkillService.php:196`): removes `agentId` from the skill's `installedOn` and,
  via a new private `desyncAgentSkillInstalls` (mirrors `syncAgentSkillInstalls`), removes
  `skillId` from the agent's `skillInstalls`. Idempotent. A new
  `SkillController::uninstall(string $id)` (same auth posture as `install`) and route
  `DELETE /api/skills/{id}/install/{agentId}` expose it. PHPUnit tests mirror the existing
  `installOnAgent` coverage (both-sides removal + idempotency).

## Capabilities

### Modified Capabilities
- `agent-management-ui`: the agent detail view moves from static config text + no capability
  surfaces, to a schema-driven config widget plus in-place Skills and Memory management
  sections, fulfilling a fuller "agent detail management surface" than the original MVP
  scenarios described.
- `skills-catalog`: adds the missing inverse of "install a skill onto an agent" — a detach/
  uninstall operation, so skill↔agent association is no longer append-only.

No change to `agent-memory` requirements — the memory section reuses the existing memory
API and UI behaviour unchanged; only the Vue implementation is de-duplicated into a shared
component.

## Impact

- **Frontend**: `src/views/AgentDetail.vue`, `src/views/AgentMemory.vue` (now consumes the
  extracted panel), new `src/components/AgentMemoryPanel.vue`, `src/api/skills.js` (new
  `uninstallSkill` helper). No new dependency — `CnObjectDataWidget` and `NcSelect` are
  already available via `@conduction/nextcloud-vue`/`@nextcloud/vue`.
- **Backend**: `lib/Service/SkillService.php` (new `uninstallFromAgent` +
  `desyncAgentSkillInstalls`), `lib/Controller/SkillController.php` (new `uninstall`),
  `appinfo/routes.php` (new route), `tests/` (mirrored PHPUnit coverage).
- **No schema changes** — `Agent.tools`, `Agent.skillInstalls`, and the memory schemas
  already exist (ADR-003); this change only adds a symmetrical write path and UI.

## Cross-Project Dependencies

None — self-contained within `hermiq`.

## Risks

### Risk 1: Detach endpoint auth drift from install
**Severity:** Medium — **Mitigation:** `uninstall` copies `install`'s exact auth attributes
and IDOR guard rather than re-deriving them; mirrored PHPUnit tests assert both-sides removal
and idempotency the same way the install tests do.

### Risk 2: Duplicated tool-catalog fetch on every detail-page load
**Severity:** Low — **Mitigation:** `listTools()` is a lightweight registry read already used
elsewhere in the app; no caching change is required for this scope.

## Rollback Strategy

Revert the frontend commit(s) to restore the static `<dl>` config block and standalone
`AgentMemory.vue`; revert the backend commit to remove the route/controller method/service
method (the new route is additive and idempotent, so leaving it briefly live during a partial
rollback is safe — no other code path calls it).

## Open Questions

None — the technical approach was fully specified up front (see `design.md` for the two
decisions flagged as user-made: keeping this as one `kind: code` change, and no migration
needed).
