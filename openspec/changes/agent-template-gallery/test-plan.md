# Test Plan: agent-template-gallery

## Test Cases

### TC-1: Exporting an Agent strips tenant-specific fields
- **spec_ref**: `openspec/changes/agent-template-gallery/specs/agent-template-gallery/spec.md#requirement-an-agenttemplate-carries-no-secrets-and-no-tenant-data`
- **type**: api
- **persona**: n/a
- **preconditions**: An `Agent` exists with `invitedUsers`, `groups`, `requestQuota`, `views`, `actingUser` set
- **steps**: Call `AgentTemplateService::exportFromAgent($agentId)` (or `GET /api/agent-templates/from-agent/{agentId}/export` if exposed as a route)
- **expected result**: The returned package contains only `AgentTemplate`-declared fields; none of `invitedUsers`/`groups`/`requestQuota`/`views`/`actingUser` appear
- **test command**: `/test-api`

### TC-2: Importing from an external source lands quarantined + scanned
- **spec_ref**: `openspec/changes/agent-template-gallery/specs/agent-template-gallery/spec.md#requirement-importing-a-template-from-an-external-source-lands-quarantined-and-content-scanned`
- **type**: api
- **persona**: n/a
- **preconditions**: A valid template package string
- **steps**: `POST /api/agent-templates/import` with `source='org'`
- **expected result**: Response `state='quarantined'`, a `scanReport` object is present, `quarantineReason` is set
- **test command**: `/test-api`

### TC-3: A locally authored template skips quarantine
- **spec_ref**: `openspec/changes/agent-template-gallery/specs/agent-template-gallery/spec.md#requirement-importing-a-template-from-an-external-source-lands-quarantined-and-content-scanned`
- **type**: api
- **preconditions**: A user creates a template directly via `POST /api/agent-templates` (no `source` param, defaults `local`)
- **steps**: Inspect the created object
- **expected result**: `state='active'`, no `scanReport` written
- **test command**: `/test-api`

### TC-4: Approving a quarantined template without the action is refused
- **spec_ref**: `openspec/changes/agent-template-gallery/specs/agent-template-gallery/spec.md#requirement-approving-a-quarantined-template-requires-action-authorization`
- **type**: api
- **persona**: n/a
- **preconditions**: A `quarantined` `AgentTemplate`; caller's groups not mapped to `agenttemplate.approve-quarantined`
- **steps**: `POST /api/agent-templates/{id}/approve`
- **expected result**: `403 Forbidden`; template `state` remains `quarantined`
- **test command**: `/test-api`

### TC-5: Forcing through a dangerous verdict requires the stricter action
- **spec_ref**: `openspec/changes/agent-template-gallery/specs/agent-template-gallery/spec.md#requirement-overriding-a-dangerous-scan-verdict-requires-a-stricter-action`
- **type**: api
- **preconditions**: A `quarantined` template with `scanReport.severity='dangerous'`; caller holds `agenttemplate.approve-quarantined` only
- **steps**: `POST /api/agent-templates/{id}/approve` with `force=true`
- **expected result**: `403 Forbidden`; template stays `quarantined`. Repeating with both actions granted transitions the template to `active`
- **test command**: `/test-api`

### TC-6: Instantiate coerces an out-of-policy suggested model
- **spec_ref**: `openspec/changes/agent-template-gallery/specs/agent-template-gallery/spec.md#requirement-instantiating-a-template-never-silently-violates-the-callers-model-policy`
- **type**: api
- **preconditions**: A template suggests `provider='openai'`; caller's org `ModelPolicy` only allows `ollama`
- **steps**: `POST /api/agent-templates/{id}/instantiate`
- **expected result**: The created `Agent`'s `provider='ollama'` (policy default/first-allowed); response includes `modelCoerced: true` with both requested and resolved values
- **test command**: `/test-api`

### TC-7: Instantiate honours an in-policy suggested model
- **spec_ref**: `openspec/changes/agent-template-gallery/specs/agent-template-gallery/spec.md#requirement-instantiating-a-template-never-silently-violates-the-callers-model-policy`
- **type**: api
- **preconditions**: A template suggests `provider='ollama'`, `model='qwen2.5'`; caller's org policy allows `ollama` with an empty (any-model) list
- **steps**: `POST /api/agent-templates/{id}/instantiate`
- **expected result**: Created `Agent` has `provider='ollama'`, `model='qwen2.5'`; response `modelCoerced: false`
- **test command**: `/test-api`

### TC-8: Instantiate resolves skill refs best-effort
- **spec_ref**: `openspec/changes/agent-template-gallery/specs/agent-template-gallery/spec.md#requirement-instantiating-a-template-resolves-skill-references-best-effort`
- **type**: api
- **preconditions**: A template's `skillRefs` includes one resolvable `skillId` (active, visible) and one that does not exist in the caller's org
- **steps**: `POST /api/agent-templates/{id}/instantiate`
- **expected result**: Created `Agent` is returned (no error); the resolvable skill is installed (appears in the new agent's `skillInstalls`); the unresolved ref appears in `unresolvedSkillRefs`
- **test command**: `/test-api`

### TC-9: Instantiate never auto-creates a Schedule
- **spec_ref**: `openspec/changes/agent-template-gallery/specs/agent-template-gallery/spec.md#requirement-instantiating-a-template-never-auto-creates-a-live-schedule`
- **type**: api
- **preconditions**: A template with a `suggestedSchedule` set
- **steps**: `POST /api/agent-templates/{id}/instantiate`, then `GET /api/schedules` filtered by the new agent's uuid
- **expected result**: No `Schedule` object references the new agent; the response's `suggestedSchedule` matches the template's hint verbatim
- **test command**: `/test-api`

### TC-10: Fresh install seeds starter templates idempotently
- **spec_ref**: `openspec/changes/agent-template-gallery/specs/agent-template-gallery/spec.md#requirement-a-fresh-install-ships-seeded-starter-templates`
- **type**: functional
- **persona**: n/a
- **preconditions**: A clean Hermiq install/upgrade with no `AgentTemplate` objects
- **steps**: Run `occ upgrade` (or trigger the repair step); edit one seeded template's `systemPrompt`; re-run the repair step
- **expected result**: 5 starter templates exist after the first run (Morning briefing, Inbox triage, Website/monitor watcher, Weekly report, Meeting-notes summariser), each `active`/`local`; the edited template's `systemPrompt` survives the second run with no duplicate created
- **test command**: `/test-functional`

### TC-11: Gallery browse, import, and instantiate flow (end-to-end)
- **spec_ref**: `openspec/changes/agent-template-gallery/specs/agent-template-gallery/spec.md#requirement-instantiating-a-template-never-silently-violates-the-callers-model-policy`
- **type**: functional
- **persona**: Priya (ZZP Developer / Integrator) — evaluates a new Hermiq install and expects something to click besides a blank form
- **preconditions**: A fresh org with seeded starter templates
- **steps**: Open "Agents" → "Browse templates" → pick "Morning briefing" → "Use this template" → observe the created agent
- **expected result**: A new `Agent` is created and the user lands on its detail page; if the seeded suggestion needed coercion, a note-card explains the substitution
- **test command**: `/test-persona-priya`

### TC-12: Accessibility of the gallery and import modal
- **spec_ref**: `openspec/changes/agent-template-gallery/specs/agent-template-gallery/spec.md#non-functional-requirements`
- **type**: accessibility
- **preconditions**: `AgentTemplateGallery.vue` and `TemplateImportModal.vue` deployed
- **steps**: Run the accessibility audit against both surfaces
- **expected result**: Every `NcSelect` carries a resolvable `inputLabel`; no WCAG 2.1 AA violations introduced
- **test command**: `/test-accessibility`

### TC-13: Regression — existing skills-catalog / skills-marketplace flows unaffected
- **spec_ref**: n/a (regression guard)
- **type**: regression
- **preconditions**: Existing `Skill` objects and the Skills page
- **steps**: Re-run the existing skills-catalog/skills-marketplace test scenarios (import, install, quarantine, approve, publish)
- **expected result**: No behavior change — `AgentTemplate` is additive and shares no write path with `Skill`
- **test command**: `/test-regression`

## Coverage Summary
- An AgentTemplate carries no secrets and no tenant data — covered (TC-1)
- Importing lands quarantined and content-scanned — covered (TC-2, TC-3)
- Approving a quarantined template requires action authorization — covered (TC-4)
- Overriding a dangerous scan verdict requires a stricter action — covered (TC-5)
- Instantiating never silently violates the model policy — covered (TC-6, TC-7)
- Instantiating resolves skill refs best-effort — covered (TC-8)
- Instantiating never auto-creates a live Schedule — covered (TC-9)
- A fresh install ships seeded starter templates — covered (TC-10)
- End-to-end gallery flow + accessibility — covered (TC-11, TC-12)
- Regression safety for skills-catalog/skills-marketplace — covered (TC-13)

## Out of Scope
- Natural-language agent creation is not tested here — it is out of scope for this change
  (see proposal.md).
- Publishing a template to a cross-instance public hub is not tested here — that surface
  belongs to `skills-marketplace`, not this change.
