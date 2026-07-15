# Proposal: inapp-settings-section

## Summary
Builds out hermiq's currently-empty in-app Settings page (`route:/settings`, `type:"settings"`,
today just a "Version" section) into the app's real settings hub, and re-homes four scattered
governance/utility surfaces into it: a new **Guardrail policy** tab (extracted from
`TenantOps.vue`, not duplicated), a new **Algorithm register** tab (a first UI for the
existing, backend-only `algoritmeregister-publication` capability), and the existing
**MCP tools** (`/mcp-tools`) and **Compliance** (`/compliance`) pages, moved in unchanged.
`TenantOps.vue` keeps only its true per-organisation operational sections (cost guardrails,
model policy, access review, incidents, retention, audit export — quota is handled by the
sibling `dashboard-org-widgets` change).

## Motivation
A UI/navigation audit of hermiq against the manifest-v2 conventions (2026-07-15) found that
almost every hermiq page is a bespoke `type:"custom"` component instead of a manifest-driven
page, and that governance/utility surfaces are scattered across the main nav (Tenant ops, MCP
tools, Compliance) instead of living together under the app's own Settings section — the
pattern reference apps (procest, pipelinq) already follow. Two of these surfaces are also
duplicated or missing entirely: the per-organisation `GuardrailPolicy` management UI lives
inline on `TenantOps.vue`, a page whose own docblock scopes it to "per-org quota + audit
export" — guardrails don't belong there, they belong in Settings alongside the app's other
governance controls. The Algoritmeregister publish/withdraw capability
(`PublicationGateway`/`AlgoritmekaderMapper`, shipped by the `algoritmeregister-publication`
change) has **no dedicated UI at all** today — its only affordance is a column + two buttons
buried inside `AiFeatureRegister.vue`'s table, a component the sibling `ai-features-to-admin`
change is relocating to NC admin settings. Consolidating these into one discoverable Settings
hub, and giving the Algoritmeregister capability its own first-class page, is this change.

## Affected Projects
- [ ] Project: `hermiq` — build out `src/manifest.json`'s Settings page into a tabbed hub;
  extract `GuardrailPolicySettings.vue` from `TenantOps.vue`; add a new `AlgorithmRegister.vue`
  page; re-home `McpTools.vue` and `ComplianceDashboard.vue` under Settings; remove their
  standalone nav/page entries; add a "Settings" nav entry (currently missing).

## Scope

### In Scope
- Restructure the Settings page (`pages[].id: "Settings"`) to use `CnSettingsPage`'s existing
  `config.tabs[]` orchestration (each tab owns its own `sections[]`) instead of a single flat
  `sections[]` array — verified live in `nextcloud-vue/src/components/CnSettingsPage/CnSettingsPage.vue`.
  Five tabs: **General** (today's version-info, unchanged), **Guardrail policy** (new),
  **Algorithm register** (new), **MCP tools** (moved), **Compliance** (moved).
- Extract the "Guardrail policy" `<section>` (template + its dedicated `data`/`computed`/
  `methods`) out of `TenantOps.vue` into a new standalone component,
  `src/views/GuardrailPolicySettings.vue`, reusing the existing, unmodified
  `src/api/guardrailPolicy.js`. `TenantOps.vue` loses this section entirely — no duplication.
- Add `src/views/AlgorithmRegister.vue`: a new page listing `riskCategory: "high"` `AiFeature`
  records with their Algoritmeregister publish status and Publish/Withdraw actions, consuming
  the existing, unmodified `src/api/aiFeatures.js` (`listAiFeatures`/`publishAiFeature`/
  `withdrawAiFeature`) and a new small pure-logic util,
  `src/utils/algoritmeregisterReadiness.js`, mirroring `AlgoritmekaderMapper::MANDATORY_FIELDS`
  (extracted fresh, not shared with `AiFeatureRegister.vue` — see design.md Decision 5).
- Re-home `McpTools.vue` and `ComplianceDashboard.vue` (both unchanged, zero lines edited)
  as Settings-page tab widgets via `{type:"component", componentName:"..."}`; remove their
  `/mcp-tools` and `/compliance` `pages[]` entries, their `menu[]` entries, and their
  `kind:"page"` `registry.js` entries; add both to `src/customComponents.js` (the legacy map
  `CnSettingsPage` actually resolves `{type:"component"}` against — verified in
  `CnSettingsPage.vue`, which injects only `cnCustomComponents`, never the v2 `registry`).
- Add a `menu[]` entry for the Settings page itself (there is currently none — `/settings` is
  unreachable from the nav today) and update the `getting-started` walkthrough's "go-mcp" step,
  which targets the now-removed `McpTools` nav item.
- `l10n/en.json` / `l10n/nl.json` additions for new strings; `appinfo/info.xml` `<version>`
  bump (served-asset hygiene, per the shared brief's rule 6 — no schema change).

### Out of Scope
- **Model policy** stays on `TenantOps.vue`. It is structurally similar to Guardrail policy
  (admin/owner-gated, instance-default fallback) but neither the shared realignment brief nor
  the sibling `dashboard-org-widgets` proposal (which explicitly lists "model policy" among the
  sections that "stay on Tenant ops as true per-organisation operational items") names it as
  moving — only Guardrail policy is named. Not moved here; see design.md Decision 2.
- The Dashboard's Schedules/Agents-in-use quota cards — owned by the sibling
  `dashboard-org-widgets` change; not touched here.
- Moving `AiFeatureRegister.vue` to NC admin settings, or removing its embedded
  Algoritmeregister publish/withdraw buttons — owned by the sibling `ai-features-to-admin`
  change. Until that change (or a follow-up) removes those buttons, they temporarily overlap
  with this change's new Algorithm register page — see Risks.
- Any backend/API change. Every endpoint this change's UI calls
  (`GuardrailPolicyController`, `AiFeatureController`, the MCP-tools/compliance read
  endpoints) already exists, unmodified, and is already consumed by the components being
  moved or extracted.
- Converting hermiq's other bespoke `type:"custom"` pages (Agents, Chat, Skills, …) to
  manifest-driven `type:"detail"`/`type:"index"` pages — a much larger, separate effort.

## Approach
Use `CnSettingsPage`'s existing tab-orchestration feature (already shipped in `nextcloud-vue`,
schema-legal via `config.tabs[]`, `"description": "Settings tabs (for type='settings')"` in
`app-manifest-v2.schema.json`) rather than inventing new sub-routes under `/settings`. Each tab
mounts exactly one `{type:"component", componentName:"<X>"}` widget; the mounted component
brings its own chrome (heading, empty/error states), the same contract `CnVersionInfoCard`
already satisfies for the existing "General" tab. `GuardrailPolicySettings.vue` and
`AlgorithmRegister.vue` are new files; `McpTools.vue`/`ComplianceDashboard.vue` are re-homed
unchanged. Registry wiring moves from `registry.js` (`kind:"page"`, consulted by
`CnPageRenderer` for `type:"custom"` page dispatch) to `customComponents.js` (the legacy flat
map `CnSettingsPage` actually reads) for the two moved components — both files stay internally
consistent (no dead/orphaned entries in either). Details in design.md.

## New Dependencies
None.

## Impact
- `src/manifest.json` — Settings page restructured to `config.tabs[]`; `/mcp-tools` and
  `/compliance` `pages[]` entries removed; `McpTools`/`ComplianceDashboard` `menu[]` entries
  removed; a `Settings` `menu[]` entry added; walkthrough "go-mcp" step retargeted.
- `src/registry.js` — `McpTools`/`ComplianceDashboard` `kind:"page"` entries + imports removed.
- `src/customComponents.js` — `GuardrailPolicySettings`, `AlgorithmRegister`, `McpTools`,
  `ComplianceDashboard` entries + imports added.
- `src/views/TenantOps.vue` — "Guardrail policy" section + its dedicated state/methods removed.
- `src/views/GuardrailPolicySettings.vue`, `src/views/AlgorithmRegister.vue` — new files.
- `src/utils/organisationLabel.js` — new tiny shared util (org-id → label lookup), replacing
  `TenantOps.vue`'s inline `policyOrgLabel()` and used fresh by `GuardrailPolicySettings.vue`.
- `src/utils/algoritmeregisterReadiness.js` — new file (readiness-gate helper for the new page).
- `l10n/en.json`, `l10n/nl.json` — new strings.
- `appinfo/info.xml` — `<version>` bump.
- No PHP/backend files change; no OpenRegister schema/register version bump.

## Cross-Project Dependencies
None outside hermiq. Coordinates in prose (not in code) with two sibling hermiq changes from
the same realignment brief: `dashboard-org-widgets` (moves the Dashboard-bound quota cards off
`TenantOps.vue` — disjoint section of the same file, no code dependency either direction) and
`ai-features-to-admin` (relocates `AiFeatureRegister.vue`, including its embedded
Algoritmeregister buttons, to NC admin settings — see Risk 2 below for the resulting temporary
overlap). Neither sibling is implemented or modified by this change.

## Risks

### Risk 1: Two Algoritmeregister publish/withdraw surfaces temporarily overlap
**Severity:** Low — **Mitigation:** `ai-features-to-admin`'s own proposal documents this
exact overlap as its Risk 2 and defers the cleanup to this change's author. Both surfaces call
the same, unmodified `publishAiFeature`/`withdrawAiFeature` endpoints — there is no data-
integrity risk, only a duplicated affordance until a follow-up removes the Algoritmeregister
column/buttons from `AiFeatureRegister.vue` (in whichever settings surface it ends up hosted
by). Filed as a follow-up issue rather than blocking either change on the other's merge order.

### Risk 2: Removing the top-level MCP tools / Compliance nav items reduces one-click reachability
**Severity:** Low — **Mitigation:** both remain one click away (Settings → tab) instead of
zero; the getting-started walkthrough's "go-mcp" step is retargeted to the new Settings nav
item so first-time users still discover MCP tools during onboarding (see design.md).

### Risk 3: `CnSettingsPage`'s `{type:"component"}` resolution only reads the legacy
`customComponents` map, not the v2 `registry`
**Severity:** Low — **Mitigation:** verified directly in `CnSettingsPage.vue` (`inject:
{ cnCustomComponents: ... }`, no `cnRegistry` injection at all) — this is an existing
`nextcloud-vue` library gap, not something this change can or should fix (out of scope: a
shared library change, not a hermiq-only one). Worked around by registering the four
Settings-tab components in `customComponents.js` instead, exactly like the pre-existing
`version-info` built-in already does. Documented so a future nc-vue enhancement (teaching
`CnSettingsPage` to also consult `cnRegistry`) is a known, fileable follow-up.

## Rollback Strategy
Revert the five touched hermiq files (`manifest.json`, `registry.js`, `customComponents.js`,
`TenantOps.vue`) and delete the three new files (`GuardrailPolicySettings.vue`,
`AlgorithmRegister.vue`, the two new util files). No data migration, no schema change, no API
change — a plain frontend/manifest revert. The extracted Guardrail policy section can be
pasted back into `TenantOps.vue` verbatim if needed (git history preserves the pre-extraction
version).

## Open Questions
- Should the Algoritmeregister buttons be removed from `AiFeatureRegister.vue` as part of
  *this* change (reaching into the sibling's file) or left for a dedicated follow-up once
  `ai-features-to-admin` has landed? Left as a follow-up (Risk 1) to avoid coupling this
  change's merge to another change's file, per the shared brief's explicit "not your job"
  framing for `ai-features-to-admin`.
