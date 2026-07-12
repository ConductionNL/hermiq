# multi-tenant-ops (delta)

Extends the existing per-organisation operational controls (quota reporting, audit export) with a
configured, per-organisation **retention statement** for EU AI Act Art. 12 record-keeping,
surfaced in the same `TenantOps.vue` page. This is a stated policy value, not automated
enforcement — see Notes.

## ADDED Requirements

### Requirement: Per-organisation retention period configuration
The system MUST let an org admin configure a retention period (in months) for the organisation's
governance records, MUST default new organisations to a value that meets EU AI Act Art. 12's
minimum (6 months), MUST reject any configured value below 6, and MUST surface the current value
in `TenantOps.vue` alongside the existing quota and audit-export sections.

#### Scenario: A new organisation has an Art. 12-compliant default retention period
- GIVEN an organisation with no retention period explicitly configured
- WHEN an org admin views the retention setting in Tenant ops
- THEN the system MUST show a default retention period of at least 6 months

#### Scenario: An org admin configures a longer retention period
- GIVEN an organisation's current retention period is the 6-month default
- WHEN an org admin sets the retention period to 12 months
- THEN the system MUST persist 12 months as that organisation's retention period
- AND subsequent views of Tenant ops for that organisation MUST show 12 months

#### Scenario: An org admin attempts to configure a non-compliant retention period
- GIVEN an organisation's current retention period is 6 months
- WHEN an org admin attempts to set the retention period to 3 months
- THEN the system MUST reject the change
- AND the organisation's retention period MUST remain unchanged at 6 months

## Non-Functional Requirements

- **Performance:** Retention read/write MUST be a single tenant-scoped config read/write, adding
  no additional cross-tenant scan to `TenantOps.vue`'s existing load.
- **Accessibility:** The retention field MUST meet WCAG AA (reuse existing `NcNoteCard`/form
  control patterns already on the page).
- **Internationalization:** Dutch and English MUST be supported (ADR-005) for the retention
  setting's labels and validation message.

## Acceptance Criteria

- [ ] `TenantOps.vue` shows the organisation's current retention period, defaulting to at least 6
  months when unconfigured.
- [ ] An org admin can raise the retention period; the new value persists per organisation.
- [ ] A retention value below 6 months is rejected with the organisation's value left unchanged.

## Notes

- This requirement covers the **stated policy** only — surfacing and validating the configured
  retention period. Automated enforcement (purging or archiving `AuditTrail` records once the
  retention period elapses) is explicitly out of scope for this change; OpenRegister's
  `AuditTrail` continues to retain records regardless of the configured value until a future
  change implements enforcement.
- Retention period is stored as a field on the existing `TenantControl` object (the same
  per-organisation governance-config object that already holds the kill-switch), not a new schema.
- Related: **ADR-004** (governance via OR AuditTrail), the original `multi-tenant-ops` capability
  (quota + audit export, unchanged by this addition), `agent-lifecycle-governance` (the sibling
  capability this change also introduces).
