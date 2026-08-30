# Skills Catalog Specification

**Status**: active (management surface live-verified; skill authoring UI live)

**Feature tier**: V1

**OpenSpec changes:** `skills-catalog` — DONE: `agentskill`/`agentskillsource` schemas; dependency-free `SkillSerializer` (byte-for-byte agentskills.io round-trip); `SkillService` (import/export/list/install-onto-agent); `SkillController` endpoints; `SkillsCatalog` UI (Playwright-verified: browse, import, install, export). Run-loop consumption of `installedOn` (skill available during a turn) is an OpenRegister seam.
`hermiq-skill-markdown-authoring` — DONE: a `SkillFormModal` (via SkillsCatalog's `#form-dialog` slot) authors a skill's `body` (SKILL.md) with `CnMarkdownEditor` + `name`/`description`/`frontmatter` + an auxiliary `files` editor, replacing the generic Add-Skill form; write/paste, no backend change. `hermiq-skill-conversational-authoring` — DONE: seeds a `skill-creator` agentskills.io Skill (repair step) and adds a chat→"Save as skill" seam that pre-fills the modal and lands the result quarantined.
`hermiq-github-store-skill-schema` — DONE: `Skill` schema gains `githubOwner`/`githubRepo`/`publishedAt` (mirroring `AgentTemplate`) so a skill published to GitHub carries its provenance; head of the `hermiq-github-store` config→code chain.

## Purpose

Ports Hermes' self-improving skills system to OpenRegister objects, keeping full compatibility with
the agentskills.io skill format. Skills are stored with their frontmatter, body, and auxiliary files
as OR objects/files so an agent's capabilities can be browsed, installed, and evolved (active → stale
→ archived) without a separate filesystem-based skill store.
## Requirements
### Requirement: Skill objects hold agentskills.io frontmatter + body + files
The system MUST persist each skill as a `Skill` OpenRegister object carrying the agentskills.io
frontmatter fields, the skill body, and any auxiliary files as OR-managed files, plus a `state`
(active/stale/archived) and a `createdBy` field.

#### Scenario: A skill is imported from an agentskills.io-formatted package
- GIVEN a skill package with valid agentskills.io frontmatter, a body, and one auxiliary file
- WHEN the system imports it into a `Skill` object
- THEN the frontmatter fields, body, and auxiliary file MUST all be persisted on the `Skill` object
- AND the skill's `state` MUST default to `active` with `createdBy` set to the importing user

### Requirement: Bidirectional SkillSerializer round-trip fidelity
The system MUST provide a `SkillSerializer` that converts a `Skill` object to an agentskills.io file
package and back, and a round trip (serialize then deserialize) MUST reproduce the original
frontmatter and body byte-for-byte.

#### Scenario: A skill is exported then re-imported
- GIVEN an existing `Skill` object with frontmatter and body content
- WHEN `SkillSerializer` exports it to an agentskills.io package and then re-imports that package
- THEN the resulting `Skill` object's frontmatter and body MUST be identical to the original

### Requirement: Browse and install skills into an agent
The system MUST let a user browse available `Skill` objects (scoped to their tenant) and install a
chosen skill onto a specific agent, making it available to that agent's next run.

#### Scenario: A user installs a skill onto their agent
- GIVEN a `Skill` object in `active` state exists within the user's organisation
- WHEN the user installs that skill onto agent X
- THEN the system MUST associate the skill with agent X
- AND agent X's next run MUST have that skill available for use

### Requirement: Detach an installed skill from an agent
The system MUST let a user remove a previously-installed skill's association with a specific
agent, symmetrically undoing the install operation: the skill's `installedOn` list MUST no
longer contain the agent's uuid, and the agent's `skillInstalls` allowlist MUST no longer
contain the skill's uuid. Detaching MUST be idempotent — detaching a skill/agent pair that is
not currently associated MUST succeed as a no-op rather than error.

#### Scenario: A user detaches a skill from their agent
- GIVEN a `Skill` object installed on agent X (agent X's uuid is in the skill's `installedOn`)
- WHEN the user detaches that skill from agent X
- THEN the system MUST remove agent X's uuid from the skill's `installedOn`
- AND the system MUST remove the skill's uuid from agent X's `skillInstalls`
- AND agent X's next run MUST NOT have that skill available
@e2e exclude covered by SkillServiceTest::testUninstallFromAgentDesyncsBothDirections (both-sides removal via ObjectService); the DELETE /api/skills/{id}/install/{agentId} route + controller mirror the install endpoint. Newman/Playwright coverage deferred.

#### Scenario: Detaching an already-detached skill/agent pair is a no-op
- GIVEN a `Skill` object that is NOT installed on agent Y
- WHEN the user (or a repeated client request) detaches that skill from agent Y
- THEN the system MUST return success
- AND the skill's `installedOn` and agent Y's `skillInstalls` MUST remain unchanged
@e2e exclude idempotency is covered by SkillServiceTest::testUninstallFromAgentIsIdempotent (agent-side write skipped when already absent). Newman/Playwright coverage deferred.

### Requirement: The Skill schema records GitHub publish provenance
The system MUST declare, on the `Skill` schema (slug `agentskill`) in
`lib/Settings/hermiq_register.json`, three optional provenance properties mirroring `AgentTemplate`:
`githubOwner` (string), `githubRepo` (string), and `publishedAt` (string, `format: date-time`). None of
the three MUST be listed in the schema's `required` array, so every existing `Skill` object remains
valid after the fields are added. The fields are provenance only: they record the GitHub owner,
repository name, and timestamp of a skill's last publish, and MUST NOT be part of the agentskills.io
package that `SkillSerializer` round-trips (mirroring how `AgentTemplateSerializer::toPackage()` never
emits `githubOwner`) — the enforcement of that non-emission is owned by the `hermiq-github-store` code
change, not this schema change.

#### Scenario: The Skill schema exposes the three optional provenance fields after re-import
- GIVEN the register `info.version` has been bumped to `0.14.0` and the app `<version>` bumped
- WHEN the app is upgraded and the `InitializeSettings` repair step runs `loadConfiguration(force: false)`
- THEN `ConfigurationService::importFromApp()` MUST re-import the `agentskill` schema
- AND the imported `Skill` schema MUST expose `githubOwner`, `githubRepo`, and `publishedAt` as optional
  string properties (with `publishedAt` typed `date-time`)

#### Scenario: Existing skills stay valid and unchanged when the fields are added
- GIVEN a `Skill` object created before this change, with no `githubOwner`/`githubRepo`/`publishedAt`
- WHEN the schema is re-imported with the three new optional fields
- THEN the existing `Skill` object MUST remain valid without modification
- AND its three provenance fields MUST be absent/empty (no backfill, no data transformation)

### Requirement: A dedicated markdown authoring form replaces the generic Skill create/edit dialog

The SkillsCatalog page (`type: "index"`, route `/skills`) MUST mount a purpose-built
`SkillFormModal` in place of the built-in generic schema-driven create/edit dialog, for both
create AND edit, wired via the page's TOP-LEVEL `slots` map entry
`form-dialog: "SkillFormModal"` (the `CnIndexPage` `#form-dialog` scoped slot, scope
`{ show, item, schema, close }`) and registered in `src/customComponents.js`. The modal MUST
author the `body` (SKILL.md) with the lib's `CnMarkdownEditor` (`value`-in / `@input`-out) and
MUST surface plain fields for `name` (required), `description`, and `frontmatter`. The modal
MUST also surface an editor for the Skill's auxiliary `files` array (each an
`{ name, content }` object per the `agentskill` schema) supporting add, rename, edit-content,
and remove, so multi-file agentskills.io skills can be hand-authored; the `files` array MUST
round-trip through the existing persistence path with no schema or backend change.

#### Scenario: Opening "Add Skill" mounts the markdown form, not the generic dialog

- GIVEN the SkillsCatalog page declares `slots.form-dialog: "SkillFormModal"` and
  `SkillFormModal` is registered in `customComponents.js`
- WHEN the user activates the page's built-in Add CTA (create mode, `item` is null)
- THEN the `SkillFormModal` MUST be shown with a markdown editor for the `body` and text
  fields for `name`, `description`, and `frontmatter`
- AND the generic schema-driven textarea dialog MUST NOT be shown

#### Scenario: Editing an existing skill opens the same form pre-filled

- GIVEN a `Skill` object exists in the catalog
- WHEN the user triggers edit for that row (the `#form-dialog` slot supplies `item` = the
  Skill being edited)
- THEN the `SkillFormModal` MUST open pre-filled from that Skill's `name`, `description`,
  `frontmatter`, `body`, and its `files` array (each `{ name, content }` shown in the files editor)

### Requirement: Authored skills persist through the existing catalog write path without a new backend

Saving from the authoring form MUST reuse the existing skill persistence path
(`SkillController`/`SkillService` via `src/api/skills.js`) — no new endpoint, route, service,
or schema field is introduced. A newly authored skill MUST persist with `state` `active` and
`createdBy` set to the authoring user (the existing import default). On edit, the existing
Skill payload MUST be merged so schema fields the form does not surface (e.g. `state`,
`source`, `installedOn`, `githubOwner`/`githubRepo`/`publishedAt`) survive the write.

#### Scenario: Writing a new skill by hand persists it via the existing path

- GIVEN the user opens the authoring form in create mode
- WHEN they type a `name`, a YAML `frontmatter` block, and a markdown `body`, then save
- THEN the skill MUST be persisted through the existing `SkillController`/`SkillService`
  create path with the typed `frontmatter` and `body`
- AND its `state` MUST be `active` and `createdBy` MUST be the authoring user

#### Scenario: Editing preserves fields the form does not surface

- GIVEN a `Skill` already installed on an agent (its `installedOn` contains an agent uuid)
- WHEN the user edits only its `body` in the authoring form and saves
- THEN the updated skill MUST retain its `installedOn` association and any provenance
  fields it already had — only the surfaced fields (`name`/`description`/`frontmatter`/
  `body`) are replaced

### Requirement: A pasted agentskills.io package is split into frontmatter and body

The authoring form MUST let a user paste a full agentskills.io package (a leading `---`
fenced frontmatter block followed by the body) and MUST ingest it via the existing import
path (`SkillController::import` → `SkillSerializer::fromPackage`) so it is stored as
structured `frontmatter` + `body`, never as one opaque blob and never double-fenced.

#### Scenario: Pasting a fenced package populates the two fields

- GIVEN the user has a full agentskills.io package string beginning with a `---` fence
- WHEN they paste it into the authoring form's package/paste affordance
- THEN the leading fenced YAML MUST populate `frontmatter` and the remainder MUST populate
  `body`
- AND a subsequent export via `SkillSerializer::toPackage` MUST reproduce the original
  package byte-for-byte (the serializer's existing round-trip guarantee)

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

## User Stories

- As an agent builder, I want to browse a catalog of skills so that I can extend my agent's capabilities without writing code.
- As an agent builder, I want to install an existing skill onto my agent so that it gains that capability immediately.
- As a skill author, I want my agentskills.io-formatted skill to import and export without losing fidelity so that I can share it outside Hermiq.
- As a tenant admin, I want skills scoped to my organisation so that I only see skills relevant to my tenant.

## Acceptance Criteria

- [ ] `Skill` and `SkillSource` schemas exist as OpenRegister objects
- [ ] Skill frontmatter, body, and auxiliary files are all persisted (files via OR file storage)
- [ ] `SkillSerializer` round-trips agentskills.io packages without loss
- [ ] Skills have a `state` field (active/stale/archived) and a `createdBy` field
- [ ] Users can browse tenant-scoped skills and install one onto a specific agent

## Notes

Depends on OpenRegister object/file storage and the `agent-memory` spec's per-tenant scoping model.
Related: ADR-003 (memory & skills as OR objects), ADR-001 (Option C+). The `skills-marketplace` (V2)
spec builds on this catalog for cross-org sharing and external hub publishing.
