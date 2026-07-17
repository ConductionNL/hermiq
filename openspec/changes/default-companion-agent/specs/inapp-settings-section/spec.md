# inapp-settings-section Specification

**Status**: in-progress
**Scope**: hermiq
**OpenSpec changes**:
- `default-companion-agent` — personal settings gain a default-agent picker above the Talk
  delivery section (kind: code)

## Purpose

Delta for `openspec/specs/inapp-settings-section/spec.md`. Hermiq's personal settings render in
`src/App.vue`'s `#user-settings` slot as `NcAppSettingsSection` children hosted by `CnAppRoot`'s
`NcAppSettingsDialog`. This delta adds the per-user default companion agent picker — the first
actionable personal setting, since it decides which agent every companion chat talks to.

## ADDED Requirements

### Requirement: Personal settings expose a default companion agent picker
The system MUST render a default companion agent picker in the personal settings dialog. The
picker MUST list only agents the calling user can access, MUST allow clearing the selection, and
MUST be available to every authenticated user — it MUST NOT be admin-only.

#### Scenario: A user picks their default agent from personal settings
- GIVEN an authenticated user opens the Hermiq personal settings dialog
- WHEN the user selects an agent in the default-agent picker
- THEN the system MUST store that agent as the user's default companion agent
- AND the next companion chat started without naming an agent MUST use it

#### Scenario: A user clears their default agent from personal settings
- GIVEN a user with a stored default agent
- WHEN the user clears the picker
- THEN the system MUST remove the stored preference
- AND companion agent resolution MUST fall through to the next precedence tier

#### Scenario: The picker offers only accessible agents
- GIVEN agents exist that the calling user cannot access
- WHEN the user opens the default-agent picker
- THEN the picker MUST NOT list those agents

#### Scenario: A non-admin user sets a default
- GIVEN an authenticated user who is not an administrator
- WHEN the user opens the personal settings dialog
- THEN the default-agent picker MUST be visible and usable

### Requirement: The default agent picker is placed above the Talk delivery section
The system MUST render the default-agent settings section between the "About Hermiq" section
(`#about`) and the "Talk delivery" section (`#talk-delivery`) in the personal settings dialog.
The existing sections and their order — About Hermiq, Talk delivery, Setup (admin-only),
Credentials — MUST otherwise be unchanged.

#### Scenario: A user opens the personal settings dialog
- GIVEN the personal settings dialog renders About Hermiq, Talk delivery, Setup and Credentials in that order
- WHEN the change lands
- THEN the default-agent section MUST appear after About Hermiq and before Talk delivery
- AND the relative order of the existing four sections MUST be unchanged

#### Scenario: An admin opens the personal settings dialog
- GIVEN the Setup section is rendered only for administrators
- WHEN an administrator opens the dialog
- THEN the default-agent section MUST still appear directly above Talk delivery
- AND the admin-only visibility of the Setup section MUST be unaffected

## Non-Functional Requirements

- **Performance:** the picker MUST load its agent list only when the settings dialog is opened —
  it MUST NOT add a request to app boot.
- **Accessibility:** the picker MUST meet WCAG 2.1 AA. If implemented with `NcSelect`, it MUST
  carry an `inputLabel` (or `ariaLabelCombobox`); a manual `<label>` element MUST NOT be used, as
  it breaks the component's internal accessibility wiring (SC 1.3.1, 4.1.2).
- **Internationalization:** Dutch and English MUST be supported (ADR-005). The section name and
  all picker strings MUST be present in `l10n/en.json` and `l10n/nl.json`, keyed by the English
  source string.

## Acceptance Criteria

- The personal settings dialog renders its sections in this order: About Hermiq, default agent picker, Talk delivery, Setup (admin-only), Credentials.
- The picker is visible and usable for a non-admin user.
- Selecting an agent stores it; the next companion chat uses it.
- Clearing the picker removes the preference and resolution falls through.
- The picker lists no agent the user cannot access.
- Any `NcSelect` used carries an `inputLabel`.

## Notes

- The sections live in `src/App.vue`'s `#user-settings` slot: `#about` ("About Hermiq") →
  `#talk-delivery` ("Talk delivery") → `#setup` ("Setup", `v-if="isAdmin"`) → `#credentials`
  ("Credentials"). The new section is inserted between the first two.
- **`#setup` is admin-only; the new section is not.** Every user sets their own default.
- The picker writes through the same endpoint as the agent detail page's "Make my default" action
  — the two surfaces MUST NOT diverge.
- The authorization contract lives in the `default-companion-agent` spec: the write endpoint
  access-checks and returns `403`; the read path access-checks and falls through. A stored UUID is
  a preference, never an authorization.
