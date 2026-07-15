# Tasks: inapp-settings-section

## Implementation Tasks

### Task 1: Restructure the Settings page into tabs, add its nav entry, remove the old MCP/Compliance nav
- **spec_ref**: `openspec/changes/inapp-settings-section/specs/inapp-settings-section/spec.md#requirement-the-settings-page-must-be-a-tabbed-hub-covering-guardrail-policy-algorithm-register-mcp-tools-and-compliance`
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN the Settings page's `config` WHEN it is read THEN it has a `tabs[]` array (not
    `sections[]`) with 5 tabs: General, Guardrail policy, Algorithm register, MCP tools,
    Compliance — General keeping today's `version-info` widget unchanged
  - GIVEN `pages[]` WHEN inspected THEN the `McpTools` (`/mcp-tools`) and
    `ComplianceDashboard` (`/compliance`) entries no longer exist
  - GIVEN `menu[]` WHEN inspected THEN the `McpTools`/`ComplianceDashboard` entries are
    removed and a new `Settings` entry exists (main section, reachable nav item)
  - GIVEN the `getting-started` walkthrough WHEN the "go-mcp" step is read THEN its target/
    advanceOn point at the `Settings` nav item, not the removed `McpTools` one
- [x] Implement
- [x] Test

### Task 2: Extract Guardrail policy administration out of Tenant ops
- **spec_ref**: `openspec/changes/inapp-settings-section/specs/inapp-settings-section/spec.md#requirement-guardrail-policy-administration-must-exist-in-exactly-one-place`
- **files**: `src/views/GuardrailPolicySettings.vue`, `src/utils/organisationLabel.js`, `src/views/TenantOps.vue`
- **acceptance_criteria**:
  - GIVEN `TenantOps.vue` WHEN read THEN it has no Guardrail policy section, and no
    guardrail-policy-only `data`/`computed`/`methods` remain (no dead state)
  - GIVEN `GuardrailPolicySettings.vue` WHEN mounted standalone THEN it lists caller-visible
    `GuardrailPolicy` records (via the unmodified `src/api/guardrailPolicy.js`), supports
    inline edit + save, exactly matching the removed section's prior behaviour
  - GIVEN both `GuardrailPolicySettings.vue` and `TenantOps.vue`'s Model policy section WHEN
    they resolve an organisation id to a label THEN both import `src/utils/organisationLabel.js`
    (no duplicated inline `policyOrgLabel`-style helper remains in either file)
- [x] Implement
- [x] Test

### Task 3: Build the Algorithm register page
- **spec_ref**: `openspec/changes/inapp-settings-section/specs/inapp-settings-section/spec.md#requirement-a-dedicated-algorithm-register-page-must-list-publish-eligible-ai-features`
- **files**: `src/views/AlgorithmRegister.vue`, `src/utils/algoritmeregisterReadiness.js`
- **acceptance_criteria**:
  - GIVEN `AlgorithmRegister.vue` mounted WHEN it loads THEN it lists only `AiFeature` records
    with `riskCategory === 'high'` via the unmodified `listAiFeatures()`
  - GIVEN an instance admin with OpenCatalogi installed WHEN a row is publish-ready THEN a
    Publish button calls the unmodified `publishAiFeature()`; WHEN not ready THEN the button
    is disabled with the missing conditions named (via `algoritmeregisterReadiness.js`)
  - GIVEN a caller without admin rights or without OpenCatalogi installed WHEN they view the
    page THEN no Publish/Withdraw action renders, but the read-only list still does
- [x] Implement
- [x] Test

### Task 4: Re-home McpTools and ComplianceDashboard registrations
- **spec_ref**: `openspec/changes/inapp-settings-section/specs/inapp-settings-section/spec.md#requirement-the-settings-page-must-be-a-tabbed-hub-covering-guardrail-policy-algorithm-register-mcp-tools-and-compliance`
- **files**: `src/registry.js`, `src/customComponents.js`
- **acceptance_criteria**:
  - GIVEN `registry.js` WHEN inspected THEN the `McpTools`/`ComplianceDashboard` `kind:"page"`
    entries and their imports are removed (no orphaned entries pointing at a removed page)
  - GIVEN `customComponents.js` WHEN inspected THEN it has 4 new entries —
    `GuardrailPolicySettings`, `AlgorithmRegister`, `McpTools`, `ComplianceDashboard` — each
    importing the corresponding `.vue` file
  - GIVEN the Settings page's `{type:"component", componentName:"McpTools"}` widget WHEN
    rendered THEN it resolves to the unchanged `McpTools.vue` (verified via `npm run
    check:registry` + a manual settings-page smoke check)
- [x] Implement
- [x] Test

### Task 5: Add new/moved-string translations
- **spec_ref**: `openspec/changes/inapp-settings-section/specs/inapp-settings-section/spec.md#non-functional-requirements`
- **files**: `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN every new user-facing string introduced by `GuardrailPolicySettings.vue` and
    `AlgorithmRegister.vue` WHEN `l10n/en.json`/`l10n/nl.json` are checked THEN each has an
    English-keyed entry in both files
- [x] Implement
- [x] Test

### Task 6: Bump the served-asset version
- **spec_ref**: `openspec/changes/inapp-settings-section/proposal.md#impact`
- **files**: `appinfo/info.xml`
- **acceptance_criteria**:
  - GIVEN `appinfo/info.xml` WHEN read THEN `<version>` is bumped one patch level (served-
    asset hygiene per the shared realignment brief's rule 6 — no schema change accompanies it)
- [x] Implement
- [x] Test

## Quality checklist

- All new/changed business logic (readiness helper, org-label util) covered by lightweight
  unit assertions or existing PHPUnit coverage for the untouched endpoints they call
- New/changed frontend surfaces covered by Playwright browser tests (see test-plan.md)
- `npm run check:specs` (manifest-v2, registry, register, json-strict) all green
- `npm run lint` green — no orphaned imports in `registry.js`/`customComponents.js`
- Dutch (`nl_NL`) and English (`en_US`) translation strings added for every new user-facing
  string (ADR-005)
- `openspec validate inapp-settings-section --type change --strict` passes
