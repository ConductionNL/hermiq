# Deferred Decisions: inapp-settings-section

Judgment calls made while writing this change's artifacts that the shared realignment brief
did not explicitly specify. Flagged for the user/apply-agent to confirm or override.

## 1. Model policy stays on Tenant ops, not moved alongside Guardrail policy
The brief named "Guardrail policy" explicitly and said "the non-organisation items currently
on TenantOps.vue move here too" without naming Model policy. Structurally, Model policy
(`TenantModelPolicyController`) is nearly a twin of Guardrail policy — same admin/owner gating,
same organisation-less "instance default" fallback, same "list every caller-visible policy, no
org picker" shape — which could argue for moving it too. **Decision made:** leave it on
`TenantOps.vue`. This is resolved by cross-checking the sibling `dashboard-org-widgets`
proposal (written independently from the same brief), whose Out of Scope section explicitly
lists "model policy" among the sections that "stay on Tenant ops as true per-organisation
operational items," while separately and explicitly naming "Guardrail policy" as moving to
this change. Two independent readings of the same brief converged on this split. If the
intent was actually for Model policy to move too, it's a small, isolated follow-up — the exact
same extraction pattern already applied to Guardrail policy in this change, producing a
`ModelPolicySettings.vue`.

## 2. Settings gets a main-nav entry, not a gear-icon "settings" foldout entry
`src/manifest.json`'s `menu[]` has no entry today pointing at the `Settings` page at all — it's
reachable only by direct URL navigation. The brief didn't specify how it should become
reachable. Two options exist per the schema: `section:"main"` (default, a normal clickable nav
item) or `section:"settings"` (renders inside `NcAppNavigationSettings`, the gear-icon
foldout — conventionally reserved for "Personal settings"/"Admin settings" shortcuts per the
fleet survey, not app content). **Decision made:** main section, at the order slot vacated by
the removed `McpTools` entry, because Settings now aggregates governance/utility functionality
(Guardrail policy, Algorithm register, MCP catalogue, Compliance) that users need direct,
one-click access to — the same prominence the individual pages had before this change, not
buried behind a secondary affordance.

## 3. The "go-mcp" walkthrough step loses tab-level precision
The `getting-started` walkthrough's "go-mcp" step targeted the (now-removed) `McpTools` nav
item with `advanceOn: {type:"route-match", route:"McpTools"}`. The manifest schema's
`advanceOn`/target vocabulary has no tab-aware kind (`nav-item|widget|action|page|element|
selector` / `manual|click-target|route-match|element-appears|object-created|delay`), and
Settings' 5 tabs all live at the single route `/settings` — there is no route to match a
specific tab. **Decision made:** retarget the step to the `Settings` nav item generally
(`route-match` on `Settings`), with updated body copy describing what Settings now contains,
rather than leaving a broken step or inventing an unsupported tab-targeting mechanism. A user
following the tour lands on the hub, one click from MCP tools, instead of directly on it.

## 4. `customComponents.js` (not `registry.js`) is where the 4 new/moved Settings-tab entries live
Verified directly in `nextcloud-vue/src/components/CnSettingsPage/CnSettingsPage.vue`:
`{type:"component", componentName}` widget resolution only ever consults the injected
`cnCustomComponents` (the legacy flat map) — the component never injects or reads `cnRegistry`
(the v2 5-kind registry `CnPageRenderer` prefers for `type:"custom"` page dispatch). This is an
existing `nextcloud-vue` library gap (not something this change introduces or could design
around by choosing a different manifest structure — a sub-route design would hit the identical
split, just via `CnPageRenderer`'s registry-first path instead). Worked around by registering
`GuardrailPolicySettings`, `AlgorithmRegister`, `McpTools`, and `ComplianceDashboard` in
`customComponents.js`, and removing `McpTools`/`ComplianceDashboard`'s now-dead `kind:"page"`
entries from `registry.js` (since no `pages[]` entry references them anymore, leaving them
would be orphaned dead weight, not merely unused). Filing an nc-vue enhancement (teach
`CnSettingsPage` to also consult `cnRegistry`) is a reasonable fast-follow but out of scope for
this hermiq-only change.

## 5. Algorithm register duplicates ~15 lines of publish-readiness logic rather than sharing with `AiFeatureRegister.vue`
Ideal end state is one shared util both components import. Not done here because
`AiFeatureRegister.vue` is owned by the concurrently in-flight `ai-features-to-admin` change —
editing it risks a merge conflict or a false inter-change dependency (this change should not
require `ai-features-to-admin` to land first, or vice versa). A fresh copy
(`src/utils/algoritmeregisterReadiness.js`) is created instead; consolidating both call sites
onto one shared util is a natural, low-risk follow-up once `ai-features-to-admin` has merged.

## 6. Artifacts skipped
`contract.md`, `discovery.md`, and `migration.md` were not created:
- **contract.md** — no new or modified API endpoint; every endpoint this change's UI calls
  (`GuardrailPolicyController`, `AiFeatureController`, the MCP-tools/compliance reads) already
  exists, unmodified, and no other apps-extra project consumes any of them.
- **discovery.md** — no Nextcloud API/framework uncertainty; `CnSettingsPage`'s `tabs[]`
  mechanism and the `customComponents` vs `registry` resolution split were both verified
  directly against the live `nextcloud-vue` source at HEAD (see design.md Decision 1 and 4),
  not inferred or assumed.
- **migration.md** — no database/schema change; no OpenRegister register/schema version bump
  needed (nav/UI relocation only, per the shared brief's rule 6).

`openspec validate inapp-settings-section --type change --strict` passes with these three
omitted.
