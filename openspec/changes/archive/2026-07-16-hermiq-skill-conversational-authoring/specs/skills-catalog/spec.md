# Skills Catalog Specification (delta: hermiq-skill-conversational-authoring)

**Status**: in-progress
**Scope**: hermiq
**OpenSpec changes**:
- hermiq-skill-conversational-authoring

## Purpose

Adds conversational skill authoring on top of the catalog: a seeded `skill-creator`
agentskills.io Skill that teaches an agent to guide skill authoring, and a "Save as skill"
seam that turns an assistant chat message into a reviewable Skill by pre-filling the authoring
modal from `hermiq-skill-markdown-authoring`. No new engine and no new schema field. Related:
ADR-003 (skills as OR objects), ADR-016 (seed data), ADR-031 (the seed is an imperative data
seeder; the seam reuses existing services — no new declarative OR behaviour).

## ADDED Requirements

### Requirement: A seeded skill-creator skill teaches an agent to guide skill authoring

The system MUST seed, on install and upgrade, exactly one `Skill` object (schema slug
`agentskill`) named `skill-creator` via a repair step (`SeedSkillCreator` implementing
`IRepairStep`, registered in `appinfo/info.xml`). The seed MUST be idempotent — matched by
name so a re-run never duplicates it and never overwrites an admin's edit — and MUST write
through OpenRegister `ObjectService` in system context (`_rbac: false, _multitenancy: false`),
mirroring `SeedAgentTemplates`. The seeded Skill MUST carry real agentskills.io `frontmatter`
and a `body` (SKILL.md) that instructs an agent how to interview a user and emit a
well-formed agentskills.io package, with `state` `active`, `source` `local`, and `createdBy`
empty. The seed MUST NOT pass through the quarantine/scan path (it is first-party trusted
content).

#### Scenario: A fresh install exposes an installable skill-creator skill

- GIVEN a Hermiq install/upgrade runs its repair steps
- WHEN `SeedSkillCreator` runs and no Skill named `skill-creator` yet exists
- THEN exactly one `agentskill` object named `skill-creator` MUST be created with `state`
  `active`, `source` `local`, and a non-empty `frontmatter` + `body` teaching skill authoring
- AND a user MUST be able to install it onto an agent via the existing install-onto-agent path

#### Scenario: Re-running the seed never duplicates or overwrites

- GIVEN a `skill-creator` Skill already exists (possibly edited by an admin)
- WHEN the repair step runs again on a later upgrade
- THEN no second `skill-creator` object MUST be created
- AND the existing object (including any admin edits) MUST be left untouched

### Requirement: A chat assistant message can be saved as a reviewable skill

The chat surface (`src/views/Chat.vue`) MUST offer a "Save as skill" action on each assistant
message. Activating it MUST open the `SkillFormModal` (from `hermiq-skill-markdown-authoring`)
PRE-FILLED with that message's content as the SKILL.md `body`, so the user can review and
edit before saving. The SKILL.md MUST be produced by the existing chat/agent engine
(`ChatStreamController::stream()` running the installed `skill-creator` skill) — no new LLM
path is introduced. Saving from this seam MUST route the (reviewed) content onto the skills
review path so a chat-authored skill is not immediately usable by an agent (see the
skills-marketplace delta).

#### Scenario: Save as skill opens the authoring modal pre-filled

- GIVEN an agent (with `skill-creator` installed) has produced a SKILL.md in an assistant
  message
- WHEN the user activates "Save as skill" on that message
- THEN the `SkillFormModal` MUST open with the message content pre-filled as the `body`
- AND the user MUST be able to edit `name`, `description`, `frontmatter`, and `body` before saving

#### Scenario: Saving lands the skill on the review path, not immediately active

- GIVEN the pre-filled authoring modal opened from the chat seam
- WHEN the user saves
- THEN the resulting Skill MUST land `quarantined` (per the skills-marketplace delta) rather
  than immediately `active`
- AND an agent MUST NOT be able to use it until it is Approved through the existing review gate

## Non-Functional Requirements

- **Performance:** The seed writes a single object and is idempotent; it MUST add negligible
  time to the repair run and MUST short-circuit when the object already exists. The chat
  action is client-side and MUST NOT trigger an extra agent run.
- **Accessibility:** The "Save as skill" action MUST carry an `aria-label` and a translated
  label, consistent with the neighbouring feedback controls (WCAG 2.1 AA).
- **Internationalization:** Dutch and English MUST be supported (ADR-005); the action label
  goes through `t('hermiq', …)` with an English source key. The seeded SKILL.md body is
  instruction content, authored in English.

## Acceptance Criteria

- [ ] `SeedSkillCreator` seeds one active/local `skill-creator` Skill, idempotent by name, registered in `info.xml`
- [ ] The seeded Skill's `frontmatter` + `body` form a valid agentskills.io SKILL.md teaching skill authoring
- [ ] Assistant messages in Chat.vue show a "Save as skill" action that opens SkillFormModal pre-filled from the message body
- [ ] Saving from the seam lands the skill on the review path (quarantined), not immediately active
- [ ] No new schema, schema field, or LLM path is introduced

## Notes

- No schema change: `Skill` already has `frontmatter`, `body`, `state`, `source`,
  `createdBy` (verified in `lib/Settings/hermiq_register.json`).
- `SkillFormModal` and its pre-fillable `body` come from the prerequisite change
  `hermiq-skill-markdown-authoring`; this change adds an alternate open + save-target.
- `skill-creator` is a seeded agentskills.io Skill, NOT a bespoke built-in agent — the user
  installs it onto any agent of their choice.
