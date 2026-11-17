---
kind: code
---

# Proposal: skills-catalog

# Why

Hermes has a self-improving skills system compatible with the agentskills.io format. The
`skills-catalog` capability spec (V1, status: idea) ports it to OpenRegister objects so an
agent's capabilities can be browsed, imported/exported, installed onto an agent, and
evolved (active → stale → archived) without a filesystem-based skill store. This change
builds the Hermiq-owned surface: the schemas, a lossless agentskills.io serializer, a
tenant-scoped catalog, install-onto-agent, and a browse/import/install UI.

Per ADR-001 Option C+, Hermiq owns the catalog + management UX and the Skill objects; the
agent run loop that makes an installed skill *available during a run* is an OpenRegister
seam, called out and not implemented here. Depends on the `agent-memory` per-tenant
scoping model.

# What Changes

- Add two declarative OpenRegister schemas to `lib/Settings/hermiq_register.json`:
  **`Skill`** (`name`, `description`, `frontmatter` (the raw agentskills.io YAML block,
  byte-preserved), `body`, `files[]`, `state` (`active|stale|archived`, default `active`),
  `createdBy`, `installedOn[]` (agent uuids)) and **`SkillSource`** (`name`, `url`,
  `type`).
- Add `lib/Service/SkillSerializer.php`: `toPackage(skill)` → an agentskills.io package
  string (`---\n{frontmatter}\n---\n{body}`) and `fromPackage(text)` → the parsed
  `{frontmatter, body, name, description}`; a round trip reproduces the frontmatter and
  body **byte-for-byte** (the raw frontmatter block is preserved, not re-dumped).
- Add `lib/Service/SkillService.php`: `importSkill(package, createdBy)` (parse → save a
  `Skill`, `state=active`), `exportSkill(id)` (→ package), `listSkills()` (tenant-scoped),
  and `installOnAgent(skillId, agentId)` (append the agent to `installedOn`). All via OR
  `ObjectService` (single write-path, native tenant scoping).
- Add `lib/Controller/SkillController.php` (`@NoAdminRequired`, `@NoCSRFRequired`): list /
  import / export / install, RBAC-scoped.
- Register routes; add a **Skills** nav page (`src/views/SkillsCatalog.vue`,
  `src/api/skills.js`) that browses tenant-scoped skills, imports an agentskills.io
  package, and installs a skill onto an agent (agent picker, `inputLabel` on every
  `NcSelect`).

# Impact

- Affected specs: `skills-catalog` (idea → active).
- Affected code: `lib/Settings/hermiq_register.json`, `lib/Service/SkillSerializer.php`,
  `lib/Service/SkillService.php`, `lib/Controller/SkillController.php`,
  `appinfo/routes.php`, `src/manifest.json`, `src/registry.js`,
  `src/customComponents.js`, `src/views/SkillsCatalog.vue`, `src/api/skills.js`,
  `tests/Unit/Service/SkillSerializerTest.php`.
- Integration seam (NOT implemented — OR-owned): the agent run loop reading `installedOn`
  to make a skill available during a turn.
