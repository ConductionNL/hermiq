## ADDED Requirements

### Requirement: Reviewer approval inbox lists pending approvals routed to the user

The system MUST provide an "Approvals" view, reachable from the app navigation, that
lists the pending `Approval` objects for which the current user is the resolved
reviewer (the designated user, or a member of the reviewer group). Each row MUST show
the schedule name, the bound agent, the prompt, and the `requestedAt`, and MUST offer
**Approve** and **Deny** actions. An approval the current user may not decide MUST NOT
be actionable for them.

#### Scenario: A reviewer sees their pending approvals

- **GIVEN** a pending `Approval` whose resolved reviewer is the current user (directly
  or via the reviewer group)
- **WHEN** the user opens the Approvals view
- **THEN** the view MUST list that pending `Approval` with the schedule name, agent,
  prompt, and requested time, and Approve/Deny actions
- **AND** approvals routed to a different reviewer MUST NOT appear as actionable

### Requirement: Approve and deny actions call the guarded endpoints

The Approve action MUST call the enforcement approve endpoint for the `Approval` and,
on success, reflect the run outcome and remove the row from the pending list. The Deny
action MUST open an isolated deny-reason modal, and on submit MUST call the deny
endpoint with the reason and remove the row. A rejected action (e.g. the user is not
the reviewer) MUST surface an error and MUST NOT falsely mark the approval decided.

#### Scenario: Reviewer approves from the inbox

- **WHEN** the reviewer clicks Approve on a pending approval
- **THEN** the UI MUST call the approve endpoint and, on success, remove the row and
  indicate the gated run has been executed

#### Scenario: Reviewer denies with a reason

- **WHEN** the reviewer clicks Deny and submits a reason in the deny modal
- **THEN** the UI MUST call the deny endpoint with that reason and, on success, remove
  the row; the gated run MUST NOT execute

### Requirement: Deny modal is an isolated component with accessible inputs

The deny-reason modal MUST live in its own component file under `src/modals/`
(`NcModal`-based) rather than inline in the parent view (ADR-004 modal-isolation), and
any `NcSelect` in the approval UI MUST carry an `inputLabel` (or `ariaLabelCombobox`)
so screen-reader association is correct (ADR-004 nc-input-labels, WCAG 2.1 AA).

#### Scenario: Deny modal is isolated and labelled

- **WHEN** the deny modal is opened
- **THEN** it MUST be rendered from a dedicated `src/modals/` component, not inline
  markup in the inbox view
- **AND** any `NcSelect` used in the approval UI MUST have an `inputLabel` set

### Requirement: Org admin kill-switch toggle surface

The system MUST provide a kill-switch toggle control, visible only to a Nextcloud
sub-admin of the organisation's group or an instance admin, that shows the current
engaged state of the organisation's `TenantControl` and toggles it via the enforcement
endpoint. A user without the required admin rights MUST NOT see or be able to use the
toggle.

#### Scenario: Org admin engages the kill-switch from the UI

- **GIVEN** the current user is an org sub-admin or instance admin
- **WHEN** they use the kill-switch toggle to engage it
- **THEN** the UI MUST call the toggle endpoint and reflect the engaged state

#### Scenario: A non-admin does not see the toggle

- **GIVEN** the current user is neither an org sub-admin nor an instance admin
- **WHEN** they open the Approvals view
- **THEN** the kill-switch toggle MUST NOT be shown or actionable for them

### Requirement: Approvals route is wired into the manifest-driven router

The Approvals view MUST be registered as a page in `src/manifest.json` (with a `route`
and `component`) so the manifest-driven `routesFromManifest()` in `src/main.js`
exposes it as a vue-router route, and a corresponding navigation entry MUST be present
— mirroring the existing `AgentCatalog`/`AgentDetail` pages. No bespoke router file is
introduced.

#### Scenario: The Approvals route resolves via the manifest

- **WHEN** the app builds its routes from the manifest
- **THEN** the Approvals page MUST resolve to the `ApprovalInbox` component at its
  declared route, reachable from the navigation
