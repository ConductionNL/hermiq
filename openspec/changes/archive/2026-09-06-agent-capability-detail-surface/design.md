# Design: agent-capability-detail-surface

## Architecture Overview

Hermiq is a thin client over OpenRegister (Option C+, ADR-001): agents, skills, and memory
already exist as OR objects with their own read/write services and Vue stores. This change
does not add a new domain concept — it adds one missing write path (skill detach) and
reorganises the frontend so all three existing capability surfaces (config, skills, memory)
are reachable from one place, the agent detail page, instead of being scattered across the
edit modal, the skills catalog, and a standalone memory route.

```
AgentDetail.vue
 ├── CnObjectDataWidget (config: schema-driven, click-to-edit)
 │     └── #field-tools slot → NcSelect sourced from listTools()
 ├── Skills section (new)
 │     ├── installed list ← agent.skillInstalls × listSkills()
 │     ├── attach picker → installSkill() [existing]
 │     └── detach action → uninstallSkill() [NEW] → SkillController::uninstall
 └── Memory section (new)
       └── <AgentMemoryPanel :agent-id="agent.id" /> (shared with AgentMemory.vue)
```

## Declarative-vs-imperative decision (ADR-031)

Skill install/detach is **imperative**, not declarative. It is a direct, application-level
write of two related objects (`Skill.installedOn` and `Agent.skillInstalls`) triggered by an
explicit user action ("attach"/"detach"), not a lifecycle transition, an aggregation, or a
calculated/derived field. `installOnAgent` already established this pattern (ADR-003: "one
write path through ObjectService" — both sides are written from the same service method, via
`objectService->saveObject`/`find`, never a direct DB write). `uninstallFromAgent` is the
exact mirror: same imperative shape, same single write path, same bidirectional-but-not-a-
second-source-of-truth relationship (`Skill.installedOn` stays authoritative for "installed
somewhere"; `Agent.skillInstalls` stays a convenience forward-ref, kept in sync — not
independently mutable). No new lifecycle state, no aggregation, no `@ref`/`@aggregate`
declarative calculation is introduced. Memory and skills already exist as OR objects
(ADR-003); this change adds no new schema.

## Mixed-spec rationale (kind: code, not split)

This change carries both a PHP delta (new service/controller method + route + tests) and a
Vue delta (detail-page rewiring + a new shared component). ADR-032's thin-glue exception
(≤20 LOC / ≤2 files) does not literally cover the backend delta size. The user made the call
to keep this as **one** change with `kind: code` rather than splitting into a backend change
and a frontend change, because:

1. The two surfaces are directly coupled at the call site — the new "detach" button in the
   Skills section calls only the new endpoint; there is no independent backend-only
   consumer and no independent frontend-only consumer to justify two separately-versioned
   deltas.
2. The backend delta is a mirror of an already-specified, already-tested write path
   (`installOnAgent`), not new domain design — reviewing it split from its one caller would
   add process overhead without added rigor.
3. The PHP delta is still the spec-bearing surface of this change (it is what makes detach
   possible at all); the Vue delta is presentation/consumption of that endpoint plus
   already-shipped ones (`getMemory`, `listSkills`, `fetchSchema`). That satisfies "the PHP
   is spec-bearing" for `kind: code` even though a UI also ships in the same change.

## Goals / Non-Goals

**Goals**
- One agent detail page surfaces config (inline edit), skills (attach/detach), and memory
  (view/add/consolidate).
- Config editing becomes schema-driven (`CnObjectDataWidget`) instead of a static `<dl>`,
  removing field-list drift between the schema and the template.
- Skill association becomes bidirectional (install AND detach), closing the gap left by
  `skills-catalog`'s install-only original scope.
- The memory UI is de-duplicated into one component consumed from two routes.

**Non-Goals**
- No new OR schema fields or registers (`Agent`, `Skill`, `Memory` schemas are unchanged).
- No run-loop behaviour change — `tools`/`skillInstalls` continue to be read the same way
  by `ToolLoop`/future skill-injection; this change only touches how they are *managed*.
- No change to the Edit modal's field set — it remains config-only, unchanged.
- No admin/tenant-scoping changes — existing RBAC on agent/skill/memory reads is reused
  as-is.

## Decisions

### Decision 1: `CnObjectDataWidget` over a hand-rolled edit-in-place grid
**Why:** `CnObjectDataWidget` (`@conduction/nextcloud-vue`) already implements schema-driven
click-to-edit + per-field slot overrides + `store.saveObject` wiring, used elsewhere in the
fleet. Hand-rolling the same behaviour in `AgentDetail.vue` would duplicate logic that
belongs in the shared lib (feedback: Vue logic lives in nc-vue).
**Alternative considered:** Keep the static `<dl>` and add per-field edit buttons manually.
Rejected — reinvents a solved widget and drifts from the schema whenever `Agent` gains/loses
a property.

### Decision 2: `tools` as a per-field slot, not a schema enum
**Why:** the tool catalog is dynamic (`GET /apps/hermiq/api/agents/tools`), so a static
`enum` in `hermiq_register.json` would go stale. `CnObjectDataWidget`'s `#field-tools` slot
lets the widget defer entirely to an `NcSelect` populated from `listTools()` at render time,
while every other field still gets the widget's built-in inline editors for free.
**Alternative considered:** Add a `oneOf`-style dynamic enum resolved server-side in the
schema response. Rejected — more backend surface for no behavioural gain over a slot the
widget already supports.

### Decision 3: `uninstallFromAgent` mirrors `installOnAgent` exactly (route shape, auth, tests)
**Why:** minimizes review surface and risk — the reviewer can diff against a known-good,
already-tested pattern instead of evaluating new authorization logic from scratch.
**Alternative considered:** A single `PATCH /api/skills/{id}/install` toggling install state
via a body flag. Rejected — install and uninstall are different intents (idempotent add vs.
idempotent remove) and Conduction's REST convention (company ADR-002) favors separate
verbs/routes over an overloaded body-flag toggle.

### Decision 4: Extract `AgentMemoryPanel.vue` rather than duplicate the memory UI
**Why:** the detail-page Memory section and the standalone `AgentMemory.vue` route need
identical behaviour (char-budget bar, entry list, add-fact, consolidate). Extracting a
`props: { agentId }` component keeps one implementation (feedback: DRY / no duplicated
logic) and both call sites get any future memory-UI fix automatically.
**Alternative considered:** Leave `AgentMemory.vue` as-is and copy its template into the new
section. Rejected — direct violation of the "no duplicated logic" working-style rule and the
existing standalone route would immediately drift from the new section.

## API Design

### `DELETE /api/skills/{id}/install/{agentId}`
**Request:** no body — `id` (skill uuid) and `agentId` (agent uuid) are route params, both
constrained to `[^/]+`.
**Response (200):**
```json
{
  "id": "00000000-0000-0000-0000-000000000000",
  "installedOn": []
}
```
(shape identical to `install`'s response — same `shape()` helper.)
**Response (404):** `{"error": "Not found"}` — skill uuid does not resolve.
**Response (401):** `{"error": "Unauthenticated"}` — no NC session.
**Response (500):** `{"error": "Uninstall failed"}` — unexpected failure, logged
server-side, no internal detail leaked to the client (mirrors `install`).

Auth posture is copied verbatim from `install`: `@NoAdminRequired` + `@NoCSRFRequired`
(any authenticated user; per-object scoping happens inside `ObjectService`'s own
RBAC/organisation checks — ADR-023 Rule 1, data RBAC is OpenRegister's job, not the
controller's).

## Nextcloud Integration

- **Controllers:** `SkillController::uninstall(string $id)` (new method, existing class).
- **Services:** `SkillService::uninstallFromAgent()` + private `desyncAgentSkillInstalls()`
  (new methods, existing class) — both route exclusively through
  `objectService->saveObject()`/`find()` (ADR-003 one write path; no direct DB access).
- **Mappers/Entities:** none — OR's `ObjectEntity` is reused as-is.
- **Events/Hooks:** none.
- **Frontend stores:** existing `agentStore` (`createObjectStore('agent', ...)`) gains no
  new methods beyond the standard `fetchSchema`/`getSchema`/`saveObject` it already exposes;
  `src/api/skills.js` gains `uninstallSkill(skillId, agentUuid)`.

## Security Considerations

- The new `uninstall` route follows the exact same auth attributes and IDOR posture as
  `install` (per-object authorization is delegated to `ObjectService`, not re-implemented in
  the controller) — see Decision 3. No new attack surface shape is introduced.
- The route is idempotent (detaching a not-installed skill/agent pair is a no-op, not an
  error), matching `install`'s idempotent-add behaviour — prevents a repeated-click race from
  producing inconsistent state.
- No new user-supplied data is persisted beyond the two existing uuid route params, both of
  which are used only as keys into `ObjectService::find`/`saveObject` (server-validated
  reads), never interpolated into a query string or file path.

## NL Design System

- `CnObjectDataWidget` and `NcSelect` are both `@conduction/nextcloud-vue`/`@nextcloud/vue`
  components already used across the fleet — CSS variables, no hardcoded colors, WCAG 2.1 AA
  by construction.
- Every `NcSelect` instance added (`#field-tools`, the skill attach picker) carries
  `inputLabel` (accessibility gate — hydra-gate-nc-input-labels).
- The Skills/Memory sections use standard NC section/heading markup, matching the rest of
  `AgentDetail.vue`.

## File Structure

```
lib/
  Controller/
    SkillController.php        (+ uninstall())
  Service/
    SkillService.php            (+ uninstallFromAgent(), + desyncAgentSkillInstalls())
appinfo/
  routes.php                    (+ skill#uninstall route)
src/
  api/
    skills.js                   (+ uninstallSkill())
  components/
    AgentMemoryPanel.vue        (NEW — extracted from AgentMemory.vue)
  views/
    AgentDetail.vue             (config widget + Skills + Memory sections)
    AgentMemory.vue             (now consumes AgentMemoryPanel)
tests/
  Unit/Service/SkillServiceTest.php     (+ uninstallFromAgent coverage)
  Unit/Controller/SkillControllerTest.php (+ uninstall coverage, if such a test file exists)
```

## Seed Data

Not applicable — no new schema, register, or entity is introduced. `Agent`, `Skill`, and
`Memory` seed data already exists from their originating changes (`agent-engine-schemas`,
`skills-catalog`, `agent-memory`); this change adds no new schema field for seed data to
cover.

## Trade-offs

- **One combined change vs. two.** Discussed above (Mixed-spec rationale) — accepted the
  larger single-PR review surface in exchange for reviewing the coupled feature as one unit
  rather than introducing a temporarily-dead backend endpoint or a temporarily-broken
  frontend button across two PRs.
- **Slot-based dynamic field vs. schema enum for `tools`.** Accepted a small amount of
  bespoke per-field Vue (the `#field-tools` slot) in exchange for not having a schema field
  that silently drifts from the live tool registry.
- **Extraction now vs. later.** Extracting `AgentMemoryPanel.vue` in this change (rather than
  filing a follow-up) was chosen because the duplication would otherwise ship on day one of
  the new section, and the rule against duplicated logic applies at merge time, not later.
