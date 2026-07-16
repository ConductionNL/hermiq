# Tasks: hermiq-skill-conversational-authoring

## Implementation Tasks

### Task 1: SeedSkillCreator repair step + info.xml registration
- **spec_ref**: `openspec/changes/hermiq-skill-conversational-authoring/specs/skills-catalog/spec.md#requirement-a-seeded-skill-creator-skill-teaches-an-agent-to-guide-skill-authoring`
- **files**: `lib/Repair/SeedSkillCreator.php`, `appinfo/info.xml`
- **acceptance_criteria**:
  - GIVEN a fresh install WHEN repair steps run THEN one `agentskill` object named `skill-creator` is created with `state` `active`, `source` `local`, `createdBy` empty, and the design.md SKILL.md `frontmatter` + `body`
  - GIVEN the object already exists (or was admin-edited) WHEN the step re-runs THEN it is not duplicated or overwritten (idempotent by name, system context, mirrors SeedAgentTemplates)
  - GIVEN OpenRegister is not yet installed WHEN the step runs THEN it warns-and-returns (container-lazy ObjectService), and the step is registered under both `<pre-migration>` and `<post-migration>` in info.xml
- [ ] Implement
- [ ] Test

### Task 2: Accept source "local" on the quarantine install path
- **spec_ref**: `openspec/changes/hermiq-skill-conversational-authoring/specs/skills-marketplace/spec.md#requirement-a-locally-authored-skill-can-be-installed-through-the-quarantine-gate`
- **files**: `lib/Controller/SkillMarketplaceController.php`
- **acceptance_criteria**:
  - GIVEN `installFromSource` is called with `source: "local"` THEN the skill lands `quarantined` with `source` `local` and a recorded `scanReport` (ContentScanService ran)
  - GIVEN an unknown source value THEN it still defaults to `hub` and the skill still lands `quarantined`
  - GIVEN this is a whitelist relaxation THEN no schema change is made (`local` is already in the `source` enum)
- [ ] Implement
- [ ] Test

### Task 3: "Save as skill" chat seam opening SkillFormModal pre-filled
- **spec_ref**: `openspec/changes/hermiq-skill-conversational-authoring/specs/skills-catalog/spec.md#requirement-a-chat-assistant-message-can-be-saved-as-a-reviewable-skill`
- **files**: `src/views/Chat.vue`, `src/modals/SkillFormModal.vue`, `src/api/skills.js`
- **acceptance_criteria**:
  - GIVEN an assistant message WHEN the user activates "Save as skill" THEN SkillFormModal opens pre-filled with the message content as `body` (name/description/frontmatter editable)
  - GIVEN the modal's save-target is the review path WHEN the user saves THEN the skill is installed via `installFromSource` (source `local`) and lands `quarantined`, not immediately active
  - GIVEN the catalog authoring entry point (prerequisite change) WHEN opened normally THEN it keeps its existing active-save default (no regression)
  - GIVEN accessibility THEN the action carries an `aria-label` + translated label matching the feedback controls
- [ ] Implement
- [ ] Test

## Quality checklist

- New/changed backend logic covered by PHPUnit (`tests/Unit/` — SeedSkillCreator idempotency; installFromSource source:local)
- UI seam covered by a Playwright test (Save as skill opens the pre-filled modal; save lands quarantined)
- All tests pass (`composer test`, frontend `npm test`)
- Feature documentation updated in `docs/` if user-facing (ADR-010)
- Dutch (`nl_NL`) and English (`en_US`) translation strings added for any new user-facing strings (ADR-007)
- `openspec validate` passes; verify against HEAD that `SkillFormModal` (prerequisite change) exists before wiring the seam
