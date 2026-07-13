# agent-template-gallery (delta)

Gives Hermiq a gallery of portable, versionable agent definitions — the agent-level analogue
of the existing `skills-catalog`/`skills-marketplace` — so a new org has something better to
start from than a blank `AgentFormModal`, and so a working `Agent` can be shared as a
secret-free template across organisations with the same quarantine/content-scan discipline
`skills-marketplace` already applies to skills.

## ADDED Requirements

### Requirement: An AgentTemplate carries no secrets and no tenant data
The system MUST persist an `AgentTemplate` object whose schema declares only:
name, description, category, systemPrompt, suggestedProvider, suggestedModel, tools,
skillRefs, suggestedSchedule, state, source, and version — and MUST NOT provide any field
capable of carrying an API key, credential, RAG source id, invited-user id, group id, or quota
value.

#### Scenario: Exporting an Agent to a template strips tenant-specific fields
- GIVEN an `Agent` with `invitedUsers`, `groups`, `requestQuota`, `views`, and `actingUser` set
- WHEN a user exports that agent to a template package via `AgentTemplateService::exportFromAgent()`
- THEN the resulting package MUST contain only the `AgentTemplate` schema's declared fields
- AND the package MUST NOT contain `invitedUsers`, `groups`, `requestQuota`, `views`, or `actingUser`

### Requirement: Importing a template from an external source lands quarantined and content-scanned
The system MUST place any `AgentTemplate` imported with `source` of `org` or `hub` into a
`quarantined` state and MUST run OpenRegister's `ContentScanService` over the template's
`systemPrompt` before the template can transition to `active`, mirroring the
`skills-marketplace` quarantine/scan contract.

#### Scenario: A user imports a template package from another organisation
- GIVEN a template package exported by organisation B
- WHEN organisation A imports that package with `source='org'`
- THEN the system MUST create the new `AgentTemplate` in a `quarantined` state
- AND the system MUST record a content-scan report (`severity`, `findings`) on the template
- AND the template MUST NOT be usable via "Use this template" until it is approved

#### Scenario: A locally authored template does not require quarantine
- GIVEN a user creates a new `AgentTemplate` directly (not via import) with `source='local'`
- WHEN the template is saved
- THEN the system MUST set the template's `state` to `active` immediately
- AND the system MUST NOT run the content scanner against a locally authored template

### Requirement: Approving a quarantined template requires action authorization
The system MUST require the caller to hold the `agenttemplate.approve-quarantined` action
(via `ActionAuthService::requireAction()`) before transitioning a `quarantined`
`AgentTemplate` towards `active`. A caller without the action MUST receive `403 Forbidden`
and the template MUST remain unchanged.

#### Scenario: A non-admin tenant member attempts to approve a quarantined template
- GIVEN a `quarantined` `AgentTemplate` and a caller whose groups are not mapped to
  `agenttemplate.approve-quarantined` in the action matrix
- WHEN the caller calls `POST /api/agent-templates/{id}/approve`
- THEN the system MUST respond `403 Forbidden`
- AND the template's `state` MUST remain `quarantined`

### Requirement: Overriding a dangerous scan verdict requires a stricter action
The system MUST block one-click approval of an `AgentTemplate` whose content-scan verdict is
`dangerous`, and MUST additionally require the `agenttemplate.override-scan-verdict` action
(beyond `agenttemplate.approve-quarantined`) before the caller may force that template to
`active`.

#### Scenario: A reviewer without override rights cannot force through a dangerous template
- GIVEN a `quarantined` `AgentTemplate` whose scan report has `severity: dangerous`
- AND the caller holds `agenttemplate.approve-quarantined` but not `agenttemplate.override-scan-verdict`
- WHEN the caller calls `POST /api/agent-templates/{id}/approve` with `force=true`
- THEN the system MUST respond `403 Forbidden`
- AND the template MUST remain `quarantined`

#### Scenario: A reviewer with override rights forces through a dangerous template
- GIVEN a `quarantined` `AgentTemplate` whose scan report has `severity: dangerous`
- AND the caller holds both `agenttemplate.approve-quarantined` and `agenttemplate.override-scan-verdict`
- WHEN the caller calls `POST /api/agent-templates/{id}/approve` with `force=true`
- THEN the system MUST transition the template's `state` to `active`
- AND the system MUST clear the template's `quarantineReason`

### Requirement: Instantiating a template never silently violates the caller's model policy
The system MUST resolve a template's `suggestedProvider`/`suggestedModel` against
`TenantModelPolicyService::effectivePolicyFor()` for the caller's organisation when
instantiating an `AgentTemplate` into an `Agent`, and MUST substitute the organisation's
policy default (or its first allowed provider) whenever the suggestion is not allowed —
never creating an `Agent` whose resolved provider/model falls outside the caller's effective
policy, and never doing so without reporting the substitution to the caller.

#### Scenario: A template's suggested model is outside the caller's org policy
- GIVEN an `AgentTemplate` with `suggestedProvider='openai'`, `suggestedModel='gpt-4o'`
- AND the caller's organisation's effective `ModelPolicy` only allows `provider='ollama'`
- WHEN the caller instantiates the template via `POST /api/agent-templates/{id}/instantiate`
- THEN the system MUST create the new `Agent` with `provider='ollama'` (the policy's default or
  first allowed provider), not `openai`
- AND the response MUST include `modelCoerced: true` with both the requested and resolved
  provider/model

#### Scenario: A template's suggested model is within the caller's org policy
- GIVEN an `AgentTemplate` with `suggestedProvider='ollama'`, `suggestedModel='qwen2.5'`
- AND the caller's organisation's effective `ModelPolicy` allows `provider='ollama'` with an
  empty (any-model) allowlist
- WHEN the caller instantiates the template
- THEN the system MUST create the new `Agent` with `provider='ollama'`, `model='qwen2.5'`
- AND the response MUST include `modelCoerced: false`

### Requirement: Instantiating a template resolves skill references best-effort
The system MUST attempt to install each of a template's `skillRefs` onto the newly created
`Agent` via `SkillService::installOnAgent()` when the referenced skill exists, is visible to
the caller's organisation, and is `active`; a reference that does not resolve MUST NOT fail
the instantiate call and MUST be reported in the response's `unresolvedSkillRefs` list.

#### Scenario: A template references a skill that does not exist in the caller's organisation
- GIVEN an `AgentTemplate` whose `skillRefs` includes a `skillId` with no matching `Skill`
  object visible to the caller's organisation
- WHEN the caller instantiates the template
- THEN the system MUST still create the new `Agent`
- AND the unresolved `skillId` MUST appear in the response's `unresolvedSkillRefs` list
- AND the system MUST NOT raise an error or abort the instantiate call

### Requirement: Instantiating a template never auto-creates a live Schedule
The system MUST return a template's `suggestedSchedule` hint verbatim in the instantiate
response for the frontend to prefill schedule creation, and MUST NOT create a `Schedule`
object as a side effect of instantiating a template.

#### Scenario: A template with a suggested schedule is instantiated
- GIVEN an `AgentTemplate` with `suggestedSchedule={kind: 'cron', cronExpr: '0 8 * * *', deliver: 'talk'}`
- WHEN the caller instantiates the template
- THEN the system MUST create the new `Agent` only
- AND the system MUST NOT create any `Schedule` object
- AND the response MUST include the `suggestedSchedule` hint unchanged

### Requirement: A fresh install ships seeded starter templates
The system MUST idempotently seed a set of starter `AgentTemplate` objects on install/upgrade
via a repair step, and MUST NOT overwrite or duplicate a seeded template that already exists
(matched by its seeded name).

#### Scenario: The repair step runs on a fresh install
- GIVEN no `AgentTemplate` objects exist yet
- WHEN the `SeedAgentTemplates` repair step runs
- THEN the system MUST create the seeded starter templates (Morning briefing, Inbox triage,
  Website/monitor watcher, Weekly report, Meeting-notes summariser)
- AND each seeded template's `state` MUST be `active` and `source` MUST be `local`

#### Scenario: The repair step runs again after an admin has edited a seeded template
- GIVEN a seeded template ("Morning briefing") already exists and an admin has edited its
  `systemPrompt`
- WHEN the `SeedAgentTemplates` repair step runs again (e.g. on the next upgrade)
- THEN the system MUST NOT overwrite the admin's edited `systemPrompt`
- AND the system MUST NOT create a duplicate "Morning briefing" template

## Non-Functional Requirements

- **Performance:** Listing templates MUST return within the same latency envelope as
  `SkillService::listSkills()` (a single paginated `ObjectService::findAll()` call, no N+1
  lookups per template).
- **Accessibility:** Every `NcSelect` in `AgentTemplateGallery.vue` and `TemplateImportModal.vue`
  MUST carry an `inputLabel` (WCAG 2.1 AA, ADR-004).
- **Internationalization:** Dutch and English MUST be supported for every new user-facing
  string (ADR-005), with English source keys.

## Acceptance Criteria

- [ ] An `AgentTemplate` can be created, exported from an existing `Agent`, and imported from
  a package.
- [ ] An externally-sourced import lands quarantined and content-scanned; approval is
  action-gated with the two-tier `approve-quarantined`/`override-scan-verdict` split.
- [ ] Instantiate always resolves the suggested model against the caller's effective
  `ModelPolicy` and reports any substitution.
- [ ] Instantiate resolves skill refs best-effort and never fails on an unresolved ref.
- [ ] Instantiate never creates a `Schedule`; the suggested-schedule hint round-trips to the
  frontend unchanged.
- [ ] A fresh install seeds 4-6 starter templates idempotently; a re-run never overwrites an
  admin's edits.

## Notes

- Reuses `ContentScanService` (OpenRegister), `TenantModelPolicyService`, `SkillService`, and
  `ActionAuthService` (ADR-023) as-is — no new write path, no new RBAC primitive.
- Natural-language agent creation and a cross-instance public hub are explicitly out of scope
  (see proposal.md).
