# inapp-settings-section Specification

**Status**: planned
**Scope**: hermiq
**OpenSpec changes**:
- `inapp-settings-section` (this change)

## Purpose
Hermiq's in-app Settings page (`route:/settings`, `type:"settings"`) becomes the app's
settings hub: a tabbed surface consolidating Guardrail policy administration, the
Algoritmeregister publication surface, MCP tools, and Compliance — governance and utility
concerns that were previously scattered across the main nav or, in the Algoritmeregister
case, had no dedicated UI at all. Tenant ops keeps only true per-organisation operational
controls.

## ADDED Requirements

### Requirement: The Settings page MUST be a tabbed hub covering Guardrail policy, Algorithm register, MCP tools, and Compliance
The system MUST render the in-app Settings page (`route:/settings`) as a tabbed surface with
at least the following tabs: General (version information), Guardrail policy, Algorithm
register, MCP tools, and Compliance. Each tab MUST render its content within the Settings
page — none of these four areas MUST also be reachable as a separate top-level nav item.

#### Scenario: A user opens Settings and sees every governance/utility tab
- **GIVEN** a signed-in hermiq user navigates to Settings
- **WHEN** the page loads
- **THEN** the page MUST show a tab strip including General, Guardrail policy, Algorithm
  register, MCP tools, and Compliance
- **AND** the General tab MUST be active by default

#### Scenario: MCP tools and Compliance are no longer separate nav items
- **GIVEN** the main navigation menu
- **WHEN** a user inspects the available nav items
- **THEN** there MUST be no standalone "MCP tools" or "Compliance" nav item
- **AND** both surfaces MUST remain reachable via Settings

@e2e exclude tab presence covered by tests/manifest-v2.spec.js structural checks; Playwright tab-strip interaction deferred to test-plan.md TC-1.

### Requirement: Guardrail policy administration MUST exist in exactly one place
The system MUST provide the per-organisation `GuardrailPolicy` administration UI (list caller-
visible policies, inline edit, save) on the Settings page's Guardrail policy tab, and MUST NOT
also render it on the Tenant ops page. The underlying `GuardrailPolicyController` authorization
(admin/owner-gated; organisation-less instance default admitted only for an instance admin)
MUST be unchanged.

#### Scenario: Guardrail policy is administered from Settings, not Tenant ops
- **GIVEN** an organisation owner opens Tenant ops
- **WHEN** they look for guardrail policy controls
- **THEN** no guardrail policy section MUST appear on Tenant ops
- **AND** the same owner MUST be able to view and edit their organisation's `GuardrailPolicy`
  from the Settings page's Guardrail policy tab

#### Scenario: A non-owner, non-admin sees no write controls
- **GIVEN** a user who is neither an instance admin nor the owner of any organisation
- **WHEN** they open the Guardrail policy tab
- **THEN** the server MUST refuse any create/update call they attempt (unchanged
  `GuardrailPolicyController::mayAdminister()` gate)

@e2e exclude authorization already covered by tests/Unit/Service/GuardrailPolicyServiceTest.php; UI relocation is a structural/manual check, not a new authz path.

### Requirement: A dedicated Algorithm register page MUST list publish-eligible AI features
The system MUST provide a Settings tab ("Algorithm register") listing every `AiFeature` with
`riskCategory: "high"` (the only category eligible for Algoritmeregister publication), showing
its name, DPO-acknowledgement state, lifecycle state, and Algoritmeregister publication status.
For each row, when the caller is an instance admin AND the OpenCatalogi publication leaf is
installed, the page MUST offer Publish (when not yet published) or Withdraw (when published)
actions, calling the existing, unmodified `publishToAlgoritmeregister`/
`withdrawFromAlgoritmeregister` endpoints. This is the first dedicated UI for the
`algoritmeregister-publication` capability — previously reachable only via a column embedded in
the AI-feature governance register.

#### Scenario: An admin publishes a ready high-risk feature from Algorithm register
- **GIVEN** an instance admin, OpenCatalogi installed, and a `high`-risk, `enabled`,
  DPO-acknowledged `AiFeature` with every mandatory Algoritmekader field set
- **WHEN** they open the Algorithm register tab and click Publish on that feature's row
- **THEN** the system MUST call the existing publish endpoint and, on success, show the
  feature's status as published

#### Scenario: Publish is disabled with an explained reason when not ready
- **GIVEN** a `high`-risk `AiFeature` missing its `wettelijkeGrondslag` field
- **WHEN** an instance admin views its row on the Algorithm register tab
- **THEN** the Publish button MUST be disabled
- **AND** a tooltip/reason MUST name `wettelijkeGrondslag` as a missing condition

#### Scenario: Non-high-risk features do not appear
- **GIVEN** `AiFeature` records with `riskCategory` of `minimal`, `limited`, and `high`
- **WHEN** the Algorithm register tab loads
- **THEN** only the `high`-risk record MUST appear in the list

#### Scenario: Publish/withdraw actions are hidden without OpenCatalogi or admin rights
- **GIVEN** either OpenCatalogi is not installed, or the caller is not an instance admin
- **WHEN** they view the Algorithm register tab
- **THEN** no Publish or Withdraw action MUST be shown for any row
- **AND** the list of high-risk features MUST still render (read-only)

@e2e exclude publish-readiness gate is unit-tested server-side (AlgoritmekaderMapperTest); this page is a thin UI reusing that unchanged gate — covered by test-plan.md TC-2/TC-3, live cross-app verification deferred until OpenCatalogi co-install exists (mirrors algoritmeregister-publication's own e2e posture).

### Requirement: Tenant ops MUST retain only true per-organisation operational controls
Following this change, the Tenant ops page MUST continue to show cost guardrails (budgets),
model policy, access review, incidents, retention, and audit export — and MUST NOT show a
Guardrail policy section. (Schedules/agents-in-use quota is out of scope for this requirement —
owned by the sibling `dashboard-org-widgets` change.)

#### Scenario: Tenant ops no longer shows Guardrail policy
- **GIVEN** the Tenant ops page after this change
- **WHEN** an organisation owner views it
- **THEN** cost guardrails, model policy, access review, incidents, retention, and audit
  export sections MUST all still be present and functioning exactly as before
- **AND** no Guardrail policy section MUST be present

@e2e exclude regression-covered by existing Tenant ops functional checks; the removed section has no remaining assertions to carry forward.

## Non-Functional Requirements

- **Performance:** Switching Settings tabs MUST NOT trigger a full page reload — `CnSettingsPage`'s
  client-side tab switch (`activeTabId`) governs the transition.
- **Accessibility:** The tab strip MUST use `role="tablist"`/`role="tab"`/`aria-selected` (as
  already implemented by `CnSettingsPage`) — no additional hermiq-side ARIA wiring required.
- **Internationalization:** Dutch and English MUST be supported (ADR-005) — all new strings
  added to both `l10n/en.json` and `l10n/nl.json` with English keys.

## Acceptance Criteria

- [ ] Settings renders 5 tabs: General, Guardrail policy, Algorithm register, MCP tools, Compliance
- [ ] `TenantOps.vue` no longer contains a Guardrail policy section
- [ ] `/mcp-tools` and `/compliance` no longer exist as standalone `pages[]`/`menu[]` entries
- [ ] Algorithm register lists only `riskCategory: "high"` features with working Publish/Withdraw
- [ ] `npm run check:specs` and `npm run lint` both pass with zero orphaned imports

## Notes

Related capabilities (unchanged by this spec, cross-referenced only): `agent-guardrails`
(`GuardrailPolicyController`/`GuardrailPolicyService`), `algoritmeregister-publication`
(`PublicationGateway`/`AlgoritmekaderMapper`, see the sibling delta spec in this same change),
`multi-tenant-ops` (Tenant ops' remaining sections), `compliance-control-packs`
(`ComplianceDashboard.vue`, unchanged). Coordinates with sibling changes `dashboard-org-widgets`
(Dashboard quota widget) and `ai-features-to-admin` (AI-feature register relocation to NC admin
settings) — see proposal.md Cross-Project Dependencies.
