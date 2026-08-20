# Test Plan: inapp-settings-section

## Test Cases

### TC-1: Settings renders as a 5-tab hub
- **spec_ref**: `openspec/changes/inapp-settings-section/specs/inapp-settings-section/spec.md#requirement-the-settings-page-must-be-a-tabbed-hub-covering-guardrail-policy-algorithm-register-mcp-tools-and-compliance`
- **type**: functional
- **persona**: Noor (Municipal CISO / Functional Admin) — the primary audience for
  governance/compliance settings
- **preconditions**: signed in as an instance admin who also owns an organisation
- **steps**: navigate to Settings; observe the tab strip; click each of General, Guardrail
  policy, Algorithm register, MCP tools, Compliance in turn
- **expected result**: all 5 tabs render, General is active by default, each tab switch shows
  the correct content without a full page reload, and the URL stays `/settings` throughout
- **test command**: `/test-functional`

### TC-2: Guardrail policy is administered only from Settings
- **spec_ref**: `openspec/changes/inapp-settings-section/specs/inapp-settings-section/spec.md#requirement-guardrail-policy-administration-must-exist-in-exactly-one-place`
- **type**: functional
- **persona**: Noor (Municipal CISO / Functional Admin)
- **preconditions**: an organisation owner with an existing `GuardrailPolicy`
- **steps**: open Tenant ops (confirm no Guardrail policy section); open Settings → Guardrail
  policy tab; edit the input PII action and save
- **expected result**: Tenant ops shows no guardrail controls; the Settings tab lists the
  policy, the edit persists via the unchanged `PUT /api/guardrail-policies/{id}` endpoint, and
  a success toast appears
- **test command**: `/test-functional`

### TC-3: Non-privileged caller cannot write a guardrail policy
- **spec_ref**: `openspec/changes/inapp-settings-section/specs/inapp-settings-section/spec.md#requirement-guardrail-policy-administration-must-exist-in-exactly-one-place`
- **type**: security
- **preconditions**: a user who is neither an instance admin nor an organisation owner
- **steps**: attempt `PUT /api/guardrail-policies/{id}` directly against the API
- **expected result**: 403 Forbidden (unchanged `GuardrailPolicyController::mayAdminister()`)
- **test command**: `/test-security`

### TC-4: Algorithm register lists only high-risk features and publishes successfully
- **spec_ref**: `openspec/changes/inapp-settings-section/specs/inapp-settings-section/spec.md#requirement-a-dedicated-algorithm-register-page-must-list-publish-eligible-ai-features`
- **type**: functional
- **persona**: Annemarie (VNG Standards Architect) — cares about national-register compliance
- **preconditions**: instance admin, OpenCatalogi installed, a `high`-risk, `enabled`,
  DPO-acknowledged, fully-described `AiFeature`, plus a `minimal`-risk feature for contrast
- **steps**: open Settings → Algorithm register; observe the list; click Publish on the
  ready feature
- **expected result**: only the high-risk feature appears; Publish succeeds and the row's
  status flips to Published; the minimal-risk feature never appears in the list
- **test command**: `/test-functional`

### TC-5: Publish is disabled with a named reason when not ready
- **spec_ref**: `openspec/changes/inapp-settings-section/specs/inapp-settings-section/spec.md#requirement-a-dedicated-algorithm-register-page-must-list-publish-eligible-ai-features`
- **type**: functional
- **preconditions**: a `high`-risk `AiFeature` missing `wettelijkeGrondslag`
- **steps**: open Settings → Algorithm register; inspect the row's Publish button
- **expected result**: Publish is disabled; hovering/inspecting shows `wettelijkeGrondslag`
  named as a missing condition
- **test command**: `/test-functional`

### TC-6: Publish/withdraw actions hide without OpenCatalogi or admin rights
- **spec_ref**: `openspec/changes/inapp-settings-section/specs/algoritmeregister-publication/spec.md#requirement-the-algoritmeregister-publication-capability-must-be-discoverable-via-a-dedicated-settings-page`
- **type**: functional
- **preconditions**: two runs — (a) OpenCatalogi not installed, admin caller; (b) OpenCatalogi
  installed, non-admin caller
- **steps**: open Settings → Algorithm register in each condition
- **expected result**: in both, the high-risk feature list still renders but no Publish/
  Withdraw action appears for any row
- **test command**: `/test-functional`

### TC-7: MCP tools and Compliance are reachable only via Settings
- **spec_ref**: `openspec/changes/inapp-settings-section/specs/inapp-settings-section/spec.md#requirement-the-settings-page-must-be-a-tabbed-hub-covering-guardrail-policy-algorithm-register-mcp-tools-and-compliance`
- **type**: regression
- **preconditions**: any signed-in user
- **steps**: inspect the main nav for "MCP tools"/"Compliance" entries; navigate to Settings
  and open the MCP tools and Compliance tabs
- **expected result**: no standalone nav entries exist; both tabs render their unchanged prior
  content (tool catalogue table; per-framework compliance tables) with no regression in data
  or behaviour
- **test command**: `/test-regression`

### TC-8: Tenant ops keeps its remaining sections working
- **spec_ref**: `openspec/changes/inapp-settings-section/specs/inapp-settings-section/spec.md#requirement-tenant-ops-must-retain-only-true-per-organisation-operational-controls`
- **type**: regression
- **preconditions**: an organisation owner with budgets, a model policy, access-review agents,
  incidents, and a retention setting
- **steps**: open Tenant ops; exercise cost guardrails (budgets), model policy edit, access
  review "Mark reviewed", incident creation, retention save, and audit export
- **expected result**: every remaining section behaves exactly as before this change; no
  Guardrail policy section is present
- **test command**: `/test-regression`

### TC-9: Manifest/registry structural validation stays green
- **spec_ref**: `openspec/changes/inapp-settings-section/specs/inapp-settings-section/spec.md#acceptance-criteria`
- **type**: api
- **preconditions**: n/a (static checks)
- **steps**: run `npm run check:specs` and `npm run lint` in the hermiq repo
- **expected result**: both exit 0 — manifest validates against the v2 schema, `registry.js`
  has no orphaned/malformed entries, no unresolved imports
- **test command**: `/test-api` (or direct `npm run check:specs && npm run lint`)

## Coverage Summary
- **Settings page is a 5-tab hub** (inapp-settings-section req 1) — TC-1, TC-7, TC-9: covered
- **Guardrail policy exists in exactly one place** (req 2) — TC-2, TC-3: covered
- **Algorithm register lists publish-eligible features** (req 3) — TC-4, TC-5, TC-6: covered
- **Tenant ops retains only true operational controls** (req 4) — TC-8: covered
- **algoritmeregister-publication delta (dedicated UI reuses existing endpoints)** — TC-4, TC-6: covered

## Out of Scope
- Model policy behaviour — untouched by this change (Decision 2); no new test cases needed
  beyond TC-8's existing-functionality check.
- Live cross-app Algoritmeregister publication to the real national portal — deferred exactly
  as the original `algoritmeregister-publication` spec defers it (no OpenCatalogi co-install
  environment in CI today).
- The temporary overlap between this page and `AiFeatureRegister.vue`'s embedded buttons
  (proposal Risk 1) — functional duplication, not a correctness bug; not separately tested.
