# ai-feature-admin-surface Specification

## Purpose
TBD - created by archiving change ai-features-to-admin. Update Purpose after archive.

## Requirements

### Requirement: The AI-feature register renders inside Nextcloud admin settings, not the in-app nav

The system MUST render the AI-feature governance register only within Nextcloud's
Administration settings for hermiq (reachable at `/settings/admin/hermiq`), as a section
of the existing admin-settings Vue mount (`AdminRoot.vue`), and MUST NOT expose it as an
in-app `vue-router` page or main-nav menu item.

#### Scenario: Opening admin settings shows the AI-feature section

- **GIVEN** an instance admin opens Administration settings → Hermiq
- **WHEN** the panel loads
- **THEN** the system MUST show an "AI features" section listing the tenant's AiFeature
  register with the same governance actions (list, acknowledge, enable, disable) it had
  on the former in-app nav page

@e2e exclude UI relocation verified live via browser navigation to /settings/admin/hermiq during implementation; no committed Playwright suite exists for hermiq's admin-settings surfaces yet (mirrors the "AI provider"/"Web research" sections, which are also live-verified only).

#### Scenario: The former in-app nav route no longer exists

- **GIVEN** the manifest-driven SPA's `src/manifest.json`, `src/registry.js`
- **WHEN** they are inspected after this change
- **THEN** neither MUST contain an `AiFeatureRegister` menu entry, a `/ai-features` page
  entry, or a registry entry/import for it
- **AND** navigating to the app's former in-app route MUST NOT resolve to the AI-feature
  register (no matching SPA route remains)

@e2e exclude static manifest/registry absence is verified by `npm run check:specs` (manifest-v2 schema + registry cross-reference) and by grep during code review; no dynamic browser assertion needed for an absent route.

### Requirement: The admin-settings bootstrap supplies the same capability flags the component already reads

The system MUST provide the `is_admin` and `opencatalogi_available`
`IInitialState` keys from the admin-settings bootstrap (`AdminSettings::getForm()`) so
the relocated component's existing Algoritmeregister publish/withdraw visibility gating
(`loadState('hermiq', 'is_admin', …)` / `loadState('hermiq', 'opencatalogi_available',
…)`) continues to resolve correctly after the move — the component itself is not
modified to accommodate the relocation.

#### Scenario: Algoritmeregister action gating still resolves correctly after the move

- **GIVEN** OpenCatalogi is installed and an instance admin opens Administration
  settings → Hermiq
- **WHEN** the AI-feature section loads
- **THEN** `opencatalogi_available` MUST be `true` and `is_admin` MUST be `true`,
  identical to what the same caller would have seen loading the former `/ai-features`
  nav page

@e2e exclude initial-state provisioning is verified live (admin settings page loaded with OpenCatalogi installed/absent) during implementation; no committed Playwright suite for this surface yet.

### Requirement: The governance API and its authorization are unchanged by the UI relocation

The system MUST leave `AiFeatureController`'s routes, `@NoAdminRequired` /
`@NoCSRFRequired` attributes, and `ActionAuthService` action-auth gating unchanged by
this UI relocation — moving where the UI is mounted MUST NOT alter who can call the
underlying API or what response codes it returns.

#### Scenario: API callers are unaffected by the UI move

- **GIVEN** a caller whose authorization outcome for the acknowledge/enable/disable/list
  endpoints was fixed before this change (e.g. 401 unauthenticated, 403 non-admin/non-DPO,
  200 admin)
- **WHEN** this change is applied and the same caller invokes the same endpoint
- **THEN** the system MUST return the same authorization outcome as before the change

@e2e exclude covered by the existing AiFeatureController unit tests (unchanged by this PR); regression risk is a pure refactor with no controller diff, so no new test is added for this requirement.

### Requirement: The record chat offers the ready-made prompts for its record type (REQ-RCPROMPT-001)

The chat about a record MUST offer the enabled prompts the library holds for that record type and the unscoped ones, in the order the library returns them. Picking a prompt MUST put the prompt's text in the message box unchanged and MUST NOT send it. When the library has no prompt for the type, or cannot be read, the chat MUST offer none and MUST keep working.

#### Scenario: A person picks a ready-made prompt on a record

@e2e exclude The record chat is mounted by the agent leaf inside a consuming app's record page, which the hermiq e2e instance does not seed; asserted in tests/record-chat-prompts.spec.js "a person picks a ready-made prompt on a record: the scoped read, order kept, text unchanged" and "CnAgentChatTab offers the prompts for its record type and picking fills the draft only".
- GIVEN an administrator added the prompt "Summarise this case" scoped to the record type "case"
- WHEN a user opens the agent chat on a case and picks "Summarise this case"
- THEN the message box holds the prompt's text and no message has been sent

#### Scenario: A prompt for another record type is not offered

@e2e exclude Scope filtering is the server's (AssistantPromptLibrary::forScope, unchanged); the client side is asserted in tests/record-chat-prompts.spec.js "a prompt for another record type is not offered: the scope goes to the server, which filters".
- GIVEN a prompt scoped to the record type "permit"
- WHEN a user opens the agent chat on a case
- THEN that prompt is not offered

### Requirement: The prompts the assistant offers MUST be administered objects

The system MUST provide an `AssistantPrompt` carrying a label, the exact prompt text
that will be sent, a `usageScope` naming where it is offered, an order, and an
`enabled` flag. An administrator MUST be able to read the exact text, edit it,
reorder the library and scope a prompt without a release.

A prompt built in code MUST NOT be offered on a case surface unless it exists as an
`AssistantPrompt`, so what an administrator reads is what the model is told.

The lane's clause: a gemeente that cannot read the prompt cannot defend the output.
When a citizen asks why the assistant summarised their bezwaar as it did, the answer
is the prompt, and it must be retrievable by somebody who does not read code.

Candidate C-configuration-44 (`configuration.tsv:43`), relevance `should`, driven
passers opencase and openproject. OpenProject's evidence: Administration, Text
transform actions, `resources :text_transform_actions` with `toggle`, `enable_all` and
`disable_all`, and `AI::TextTransformAction` carrying a `usage_scope` and a prompt.

#### Scenario: The text that will be sent is the text on screen

- **GIVEN** an administrator reading a prompt object
- **WHEN** the assistant runs that prompt
- **THEN** the text sent MUST be the text the object carries

#### Scenario: Scope decides where a prompt appears

- **GIVEN** a prompt scoped to one record type
- **WHEN** the assistant surface opens on a record of another type
- **THEN** the prompt MUST NOT be offered

#### Scenario: Order is the administrator's

- **GIVEN** a library reordered by an administrator
- **WHEN** the surface renders
- **THEN** the prompts MUST appear in that order, unsorted

### Requirement: Disabling every prompt MUST be one recorded act

The system MUST let an administrator disable every prompt, wholesale or within one
scope, in a single action. The act MUST be recorded with the actor and the time.

Re-enabling MUST be per prompt. Disabling in bulk is an incident response; re-enabling
in bulk would restore a prompt that had been switched off weeks earlier for a
different reason.

An incident response that requires editing twelve rows is not a response, and the
first question after an incident is when the assistant was switched off. That answer
must not be somebody's memory.

#### Scenario: Everything stops in one act

- **GIVEN** a library of twelve enabled prompts
- **WHEN** an administrator disables all
- **THEN** none MUST be offered on any surface, after one action

#### Scenario: The switch-off is on the record

- **WHEN** the audit is read after a disable-all
- **THEN** it MUST name the administrator and the time

#### Scenario: Coming back is deliberate

- **GIVEN** a library that was disabled wholesale
- **WHEN** an administrator re-enables
- **THEN** they MUST do it per prompt, and no bulk re-enable MUST exist

### Requirement: A consuming app MAY ship an initial library and MUST NOT hold the edited state

A consuming app MAY ship prompts as an initial library. Once an administrator has
edited, reordered, scoped or disabled one, that state MUST live in hermiq, and the
consuming app MUST NOT hold or restore it.

#### Scenario: An edit survives the shipping app

- **GIVEN** a prompt shipped by a consuming app and then edited by an administrator
- **WHEN** the consuming app is updated
- **THEN** the edited text MUST stand

#### Scenario: A disabled prompt stays disabled

- **GIVEN** a shipped prompt an administrator disabled
- **WHEN** the consuming app is updated
- **THEN** it MUST stay disabled
