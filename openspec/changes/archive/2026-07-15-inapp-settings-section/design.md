# Design: inapp-settings-section

## Architecture Overview
Hermiq's app shell (`src/App.vue`) mounts `CnAppRoot` with a bundled `manifest.json`, a v2
`registry` (5-kind, `kind → {component, ...}`, consulted by `CnPageRenderer` for `type:"custom"`
page dispatch and dashboard/detail widgets), and a legacy flat `customComponents` map (consulted
by `CnSettingsPage` for `type:"settings"` page section/widget resolution — verified these are
genuinely two different consumers, see Decision 1).

Today: `Settings` (`route:/settings`, `type:"settings"`) has one flat `config.sections[]` entry
("Version", a `version-info` built-in widget). `TenantOps` (`route:/tenant-ops`, `type:"custom"`)
is one large file mixing true per-org operational sections (quota, cost guardrails, access
review, incidents, retention, audit export) with two governance-policy administration sections
that don't belong there (model policy, guardrail policy). `McpTools` (`/mcp-tools`) and
`ComplianceDashboard` (`/compliance`) are standalone top-level `type:"custom"` nav pages.
`AiFeatureRegister` (`/ai-features`) embeds the only existing Algoritmeregister publish/withdraw
UI, and is being relocated to NC admin settings by the sibling `ai-features-to-admin` change.

After this change: `Settings` becomes a 5-tab hub (`config.tabs[]`); `TenantOps` loses its
Guardrail policy section (Model policy stays — Decision 2); `McpTools`/`ComplianceDashboard`
become Settings-tab widgets instead of top-level nav pages; a new `AlgorithmRegister.vue` gives
the `algoritmeregister-publication` backend capability its first dedicated UI.

```
Settings (route:/settings, type:"settings")
├─ tab: General            → widgets[]: version-info (unchanged, built-in)
├─ tab: Guardrail policy   → widgets[]: {type:"component", componentName:"GuardrailPolicySettings"}
├─ tab: Algorithm register → widgets[]: {type:"component", componentName:"AlgorithmRegister"}
├─ tab: MCP tools          → widgets[]: {type:"component", componentName:"McpTools"}
└─ tab: Compliance         → widgets[]: {type:"component", componentName:"ComplianceDashboard"}
```

## Nextcloud Integration
- Controllers: none new/changed. Every endpoint consumed already exists and is unmodified —
  `GuardrailPolicyController` (`GET/POST/PUT /api/guardrail-policies*`), `AiFeatureController`
  (`GET /api/ai-features`, `POST /api/ai-features/{id}/publish|withdraw`), the endpoints backing
  `listTools()` (`/api/agents/tools`) and the compliance dashboard read.
- Services: none new/changed.
- Mappers/Entities: none.
- Events/Hooks: none.
- Frontend-only change: `src/manifest.json`, `src/registry.js`, `src/customComponents.js`,
  three views, two small utils, `l10n/*.json`.

## Security Considerations
No auth surface changes. Every moved/extracted component keeps its existing server-side gate
exactly as-is:
- `GuardrailPolicySettings.vue` calls the same `src/api/guardrailPolicy.js` functions
  `TenantOps.vue` already calls; `GuardrailPolicyController`'s admin/owner authorization
  (`mayAdminister()`) is untouched.
- `AlgorithmRegister.vue` calls the same `publishAiFeature`/`withdrawAiFeature` functions
  `AiFeatureRegister.vue` already calls; server-side `ActionAuthService` gating on
  `aifeature.publish`/`aifeature.withdraw` is untouched. Client-side, the page hides the
  Publish/Withdraw buttons unless `loadState('hermiq','is_admin',false)` AND
  `loadState('hermiq','opencatalogi_available',false)` — the exact same UX-hint gate
  `AiFeatureRegister.vue` uses today (server remains authoritative either way).
- `McpTools.vue`/`ComplianceDashboard.vue` are moved with zero code changes — their existing
  authorization (RBAC-scoped read; `compliance.view-dashboard`/`compliance.export-pack`
  action-auth) is untouched.
No new attack surface: no new endpoints, no new write paths, no new secrets.

## NL Design System
No new visual components. All markup continues to use `@nextcloud/vue` primitives
(`NcButton`, `NcSelect`, `NcTextArea`, `NcNoteCard`, `NcEmptyContent`, `NcLoadingIcon`) and
`@conduction/nextcloud-vue`'s `CnDataTable`, exactly as the components being moved/extracted
already do — no hardcoded colors, CSS variables only, unchanged WCAG posture.

## File Structure
```
src/
  manifest.json                          (modified — Settings tabs, removed pages/menu, new menu entry)
  registry.js                            (modified — McpTools/ComplianceDashboard entries removed)
  customComponents.js                    (modified — 4 new entries added)
  utils/
    organisationLabel.js                 (new — shared org-id → label lookup)
    algoritmeregisterReadiness.js         (new — publish-readiness pure logic)
  views/
    TenantOps.vue                        (modified — Guardrail policy section removed)
    GuardrailPolicySettings.vue           (new — extracted from TenantOps.vue)
    AlgorithmRegister.vue                 (new)
    McpTools.vue                         (unchanged — re-homed via manifest/registry only)
    ComplianceDashboard.vue              (unchanged — re-homed via manifest/registry only)
l10n/
  en.json, nl.json                       (modified — new strings)
appinfo/
  info.xml                               (modified — <version> bump)
```

## Decisions

### Decision 1: Use `CnSettingsPage`'s existing `config.tabs[]`, not new sub-routes
**Chosen**: one `/settings` route, `config.tabs[]` (each tab owns `sections[]`), each section a
single `{type:"component", componentName}` widget.
**Alternatives considered**:
- *New sub-routes* (`/settings/guardrails`, `/settings/mcp-tools`, …) as separate `type:"custom"`
  pages, linked from a Settings landing page. Rejected: reinvents navigation `CnSettingsPage`'s
  `tabs[]` already provides (verified live in `CnSettingsPage.vue` — tab strip, active-tab
  state, `@tab-change` event), and would need a new landing-page component with no reuse value.
- *Flat `sections[]`* (no tabs), stacking all 5 areas on one long scroll. Rejected: 5 areas
  including 2 full data tables (MCP tools catalogue, per-framework compliance tables) makes for
  an unwieldy single page; tabs give each area its own scroll context, matching how procest's
  and pipelinq's admin-settings hubs group unrelated concerns (per fleet survey).
**Trade-off accepted**: `CnSettingsPage`'s `{type:"component"}` resolution only reads the legacy
`customComponents` map (see Decision 4) — a pre-existing library constraint, not something this
tab choice introduces or could avoid by choosing sub-routes instead (sub-routes would hit the
identical `customComponents` vs `registry` split via `CnPageRenderer`'s `type:"custom"` path,
just the OTHER direction — registry-first there).

### Decision 2: Model policy is NOT moved
The brief named "Guardrail policy" explicitly as moving and described "the non-organisation
items currently on TenantOps.vue" as also moving, without naming Model policy. Structurally,
Model policy (`TenantModelPolicyController`) is nearly identical to Guardrail policy — both are
admin/owner-gated, both fall back to an organisation-less "instance default" — which could read
as "non-organisation." However, the sibling `dashboard-org-widgets` change (written from the
same brief, in parallel) explicitly lists "model policy" among the sections that "stay on
Tenant ops as true per-organisation operational items" in its Out of Scope section, while
separately and explicitly naming "Guardrail policy" as moving to `inapp-settings-section`. Two
independent readings of the same brief agree on this split, so Model policy stays on
`TenantOps.vue`, untouched by this change. If this is wrong, it is a small, isolated follow-up
(the exact same extraction pattern as Guardrail policy, into a `ModelPolicySettings.vue`).

### Decision 3: `AlgorithmRegister.vue` is a NEW page, not a re-export of `AiFeatureRegister.vue`'s table
`AiFeatureRegister.vue` is being relocated to NC admin settings by the sibling
`ai-features-to-admin` change — a file this change does not touch (explicit brief instruction:
"not your job"). Building the new Algorithm register page as an independent component, calling
the same unmodified `src/api/aiFeatures.js` functions, means it works correctly regardless of
whether `ai-features-to-admin` has merged yet, and regardless of where `AiFeatureRegister.vue`
ends up living. The new page shows only `riskCategory: "high"` features (the only ones eligible
for Algoritmeregister publication per `AlgoritmekaderMapper::RISK_PUBLISHABLE`) with columns
Name / DPO acknowledgement (read-only) / Lifecycle (read-only) / Algoritmeregister status, plus
Publish/Withdraw actions — narrower than `AiFeatureRegister.vue`'s full governance table (which
also does Acknowledge/Enable/Disable, staying admin-settings-only per the sibling change).

### Decision 4: Moved/new Settings-tab components register in `customComponents.js`, not `registry.js`
Verified in `nextcloud-vue/src/components/CnSettingsPage/CnSettingsPage.vue`: its `inject`
block only pulls `cnCustomComponents` (defaulting to `{}`); it never injects or reads
`cnRegistry`. `resolveWidgetComponent()`/`resolveSectionComponent()` both call
`this.effectiveCustomComponents[name]` exclusively. By contrast, `CnPageRenderer`'s
`resolveCustomComponent()` (used for `type:"custom"` page dispatch) checks the v2 `registry`
FIRST, falling back to legacy `customComponents` (verified at
`CnPageRenderer.vue:1089-1109`). So: while `McpTools`/`ComplianceDashboard` were top-level
`type:"custom"` pages, their `registry.js` `kind:"page"` entries were the ones actually
resolved; once they become Settings-tab widgets, those `registry.js` entries are dead (no
`pages[]` entry references them anymore) and must move to `customComponents.js` for
`CnSettingsPage` to find them. Confirmed this is not a new pattern: the existing "Version" tab's
`version-info` widget is itself a **built-in** resolved before either registry
(`BUILTIN_SETTINGS_WIDGETS` in `CnSettingsPage.vue`), so there was no prior precedent to follow
either way — this is the first hermiq settings-tab custom component, and `customComponents.js`
is the only registry the renderer will actually consult.
**Trade-off accepted**: this splits hermiq's "where do I register a component" answer by
consumer (settings tab → `customComponents.js`; everything else → `registry.js`) rather than one
uniform rule. Flagged in the proposal (Risk 3) as an nc-vue library gap worth a future fix
(teach `CnSettingsPage` to also consult `cnRegistry`), not something to work around by e.g.
duplicating the entry into both files (which would silently drift once one is edited and not
the other).

### Decision 5: `algoritmeregisterReadiness.js` duplicates ~15 lines from `AiFeatureRegister.vue`, not shared
`AiFeatureRegister.vue`'s `MANDATORY_ALGORITMEKADER_FIELDS` constant and
`missingConditions()`/`publishReady()`/`publishBlockedReason()` methods are pure, ~15 lines of
logic mirroring `AlgoritmekaderMapper::MANDATORY_FIELDS` server-side. Extracting them into a
shared util that BOTH `AiFeatureRegister.vue` and the new `AlgorithmRegister.vue` import would
be the ideal end state, but doing so requires editing `AiFeatureRegister.vue` — a file owned by
the concurrently-in-flight `ai-features-to-admin` change (which may not be merged, or may have
already moved/renamed the file, by the time this change lands). To avoid a merge conflict or a
false dependency between two sibling changes, this change creates its own copy
(`src/utils/algoritmeregisterReadiness.js`) and does not touch `AiFeatureRegister.vue`. Filed as
a natural follow-up once `ai-features-to-admin` has landed: both call sites import the one util.

### Decision 6: Settings gets a nav entry; walkthrough's "go-mcp" step is retargeted
`src/manifest.json`'s `menu[]` has no entry routing to the `Settings` page today — it is
reachable only by direct URL. Since Settings now aggregates real, frequently-needed
functionality (governance policy, MCP catalogue, compliance), it gets a main-section `menu[]`
entry (not the gear-icon `section:"settings"` foldout, which the fleet survey found is
conventionally reserved for Personal/Admin-settings shortcuts, not app content) at the order
slot vacated by the removed `McpTools` entry. The `getting-started` walkthrough's "go-mcp" step
(`target: {kind:"nav-item", ref:"McpTools"}`, `advanceOn: {type:"route-match", route:"McpTools"}`)
breaks once that nav item is removed — the manifest schema's `advanceOn` types
(`manual|click-target|route-match|element-appears|object-created|delay`) have no tab-level
granularity, so the step is retargeted to the Settings nav item generally (arrives at the hub,
not a specific tab), with updated body copy mentioning what Settings now contains.

## Risks / Trade-offs
- [Risk] Two Algoritmeregister publish/withdraw UIs temporarily coexist until a follow-up
  removes the buttons from `AiFeatureRegister.vue` → [Mitigation] both call the same unmodified,
  idempotent endpoints; no data-integrity risk, tracked as a follow-up issue (proposal Risk 1).
- [Risk] `customComponents.js`/`registry.js` split by consumer is not self-evident to a future
  contributor → [Mitigation] Decision 4 is documented in both this design and inline comments
  added to both files at implementation time (task-level detail).
- [Risk] Walkthrough tour loses tab-level precision for the MCP-tools step →
  [Mitigation] still lands the user on Settings, one click from MCP tools; documented as
  acceptable in Decision 6.

## Migration Plan
No data/schema migration. Deploy as a single frontend PR: manifest/registry/customComponents
changes, three new/modified views, two new utils, l10n strings, `<version>` bump. Rollback is a
plain revert (see proposal.md).

## Open Questions
- Whether to also extract Model policy in a fast-follow change once this one is confirmed
  correct (Decision 2) — deferred, not blocking.
