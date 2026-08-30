# agent-management-ui (delta)

Extends the create/edit agent requirement: the Provider and Model fields, currently free-text
(`NcTextField`), become policy-filtered `NcSelect` dropdowns populated from the caller's
effective `ModelPolicy` (see the new `tenant-model-policy` capability), so a user cannot save
an agent configured outside their organisation's allowed providers/models.

## MODIFIED Requirements

### Requirement: Create and configure an agent [MVP]
The system MUST let a user create an agent (name, model/provider, prompt, enabled tools) via
a create form, and MUST let a user edit an existing agent's configuration fields inline from
the agent detail view using a schema-driven, click-to-edit widget, persisting via
OpenRegister. Tenancy/noise fields (invited users, groups, views, private flag, quotas,
acting user, configuration, context refs, skill installs) MUST NOT be shown in the inline
config widget. The Provider and Model fields MUST be presented as selectable options drawn
from the caller's effective `ModelPolicy` rather than free text, and the system MUST reject
saving an agent whose provider/model falls outside that effective policy.

#### Scenario: Create an agent
- **GIVEN** the create form
- **WHEN** the user fills in name, selects a model, writes a prompt, and saves
- **THEN** the system MUST create the agent as an OpenRegister object owned by the user's organisation
@e2e exclude pre-existing behaviour (config-only create modal, unchanged by this change); live-verified during this change's verification (created an agent via the form → 201). Playwright coverage owned by the agent-management-ui base change.

#### Scenario: Edit a config field inline from the detail view
- **GIVEN** an agent detail view rendering the schema-driven config widget
- **WHEN** the user clicks a config field (e.g. name, prompt, temperature) and saves a new value
- **THEN** the system MUST persist the change via OpenRegister
- **AND** the detail view MUST reflect the saved value without opening the Edit modal
@e2e exclude live-verified: the schema-driven CnObjectDataWidget click-to-edit + whole-object PUT round-trip returned 200 and the cell reflected the saved value. Dedicated Playwright coverage deferred.

#### Scenario: Edit the tool allowlist inline from the detail view
- **GIVEN** an agent detail view rendering the schema-driven config widget
- **WHEN** the user opens the tools field
- **THEN** the system MUST present the current, dynamically-fetched tool catalog as selectable options
- **AND** selecting or deselecting tools and saving MUST persist the updated tool allowlist via OpenRegister
@e2e exclude live-verified end-to-end: opening the tools field rendered the dynamic /api/agents/tools catalog, selecting a tool and confirming issued the agent PUT (200), and the Tools cell displayed the saved tool. Dedicated Playwright coverage deferred.

#### Scenario: Model and Provider choices are filtered to the organisation's policy
- **GIVEN** the create/edit agent form for a user in an organisation whose effective
  `ModelPolicy` allows only `ollama` (any model)
- **WHEN** the user opens the Provider field
- **THEN** the system MUST offer only `ollama` as a selectable provider
- **AND** the Model field MUST be populated from the models available for the chosen provider
  under the effective policy, not a free-text input

#### Scenario: Saving an out-of-policy provider/model is rejected
- **GIVEN** an agent form pre-filled (e.g. via a raw API edit or a stale form state) with a
  provider/model combination outside the caller's effective policy
- **WHEN** the user attempts to save
- **THEN** the system MUST reject the save with a clear error naming the disallowed
  provider/model
- **AND** the agent's previously-saved (in-policy or unset) configuration MUST remain
  unchanged

## Non-Functional Requirements

- **Performance:** Fetching the effective model policy for the form MUST add at most one
  additional request when the form opens, not a request per keystroke/selection.
- **Accessibility:** The Provider/Model `NcSelect`s MUST carry an `input-label` (WCAG 2.1 AA,
  matching every other `NcSelect` in this form).
- **Internationalization:** Dutch and English MUST be supported (ADR-005) for the
  policy-rejection error message.

## Acceptance Criteria

- [ ] An agent catalog lists agents scoped by NC group/RBAC with schedule + last-run status.
- [ ] Create/edit agent forms persist via OpenRegister (no bespoke store; Options API + createObjectStore).
- [ ] Agent detail lets the user add/edit a schedule and "Run now".
- [ ] Agent detail shows run history with status/timing and audit/output links.
- [ ] UI uses `@conduction/nextcloud-vue` components and meets WCAG 2.1 AA.
- [ ] The Provider and Model fields are policy-filtered `NcSelect`s, not free text, and an
      out-of-policy save is rejected client- and server-side.

## Notes

Frontend follows Conduction conventions: Vue 2.7 + Options API, `createObjectStore`, no custom
Pinia stores. Depends on the new `tenant-model-policy` capability's `GET /api/model-policy/effective`
endpoint. Server-side rejection (not just UI filtering) is the actual guarantee — see
`tenant-model-policy`'s Trade-offs — the UI change here is a usability improvement on top of
that enforcement, not a substitute for it.
