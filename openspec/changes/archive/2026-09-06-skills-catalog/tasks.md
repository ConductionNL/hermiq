# Tasks: skills-catalog

## 1. Schemas (register patch)

- [x] 1.1 Add a `Skill` schema (slug **`agentskill`** — a bare `skill` slug collides with another register's schema id 21 via OR's lower(slug) resolution): required `name`; `description`; `frontmatter` (string — the raw agentskills.io YAML block, byte-preserved); `body` (string); `files` (array of `{name, content}`); `state` (enum `active|stale|archived`, default `active`); `createdBy` (string); `installedOn` (array of agent uuids). Flat, no `if`/`then`.
- [x] 1.2 Add a `SkillSource` schema (slug **`agentskillsource`**): required `name`; `url` (string); `type` (enum `package|hub|local`, default `package`).
- [x] 1.3 Bump `info.version`; re-validate JSON; import via the repair step; confirm both schemas create cleanly (union import, no regression). Use unique slugs (avoid cross-app slug collision).

## 2. SkillSerializer (lossless round-trip)

- [x] 2.1 Create `lib/Service/SkillSerializer.php`: `toPackage(array skill): string` → `---\n{frontmatter}\n---\n{body}`; `fromPackage(string): array` → `{frontmatter, body, name, description}` (split on the leading `---`…`---` fence; extract `name:`/`description:` from the frontmatter for display). Dependency-free (no Symfony Yaml — must run in the CI stub env).
- [x] 2.2 Guarantee byte-for-byte round-trip: `fromPackage(toPackage(skill))` reproduces the original `frontmatter` block and `body` exactly (the raw frontmatter is preserved, never re-dumped).

## 3. SkillService

- [x] 3.1 Create `lib/Service/SkillService.php`: `importSkill(package, createdBy)` (parse via SkillSerializer → save a `Skill`, `state=active`, `createdBy`), `exportSkill(id)` (load → `toPackage`), `listSkills()` (tenant-scoped `findAll`), `getSkill(id)`.
- [x] 3.2 `installOnAgent(skillId, agentId)`: append the agent uuid to the skill's `installedOn` (idempotent — no duplicate), save via `ObjectService`.

## 4. Controller + routes

- [x] 4.1 Create `lib/Controller/SkillController.php` (`@NoAdminRequired`, `@NoCSRFRequired`): `index()`, `import()`, `export(id)`, `install(id)` — RBAC-scoped, cross-tenant denied.
- [x] 4.2 Register routes in `appinfo/routes.php` (`/api/skills` GET+POST import, `/api/skills/{id}/export` GET, `/api/skills/{id}/install` POST).

## 5. UI

- [x] 5.1 Add `src/api/skills.js` wrapping list/import/export/install.
- [x] 5.2 Add `src/views/SkillsCatalog.vue` (mirror `AgentMemory.vue`): a skills list (name, description, state, installed count, Export + Install), an import panel (textarea for the agentskills.io package + Import), and per-skill install (agent picker + Install); `NcEmptyContent`/loading states; `inputLabel` on every `NcSelect`.
- [x] 5.3 Register the Skills page in `src/manifest.json` (`route: /skills`, nav) + `src/registry.js` + `src/customComponents.js`.

## 6. Verify

- [x] 6.1 Unit-test `SkillSerializer` the CI way: `fromPackage(toPackage(x))` reproduces frontmatter + body byte-for-byte; `name`/`description` are extracted.
- [x] 6.2 Verify live on NC + OR: import an agentskills.io package → a `Skill` (`state=active`, `createdBy` set); export it back → identical package; install onto an agent → the agent uuid appears in `installedOn`. Then Playwright-test the Skills view (browse, import, install) with 0 console errors.

## Acceptance criteria

- `Skill` and `SkillSource` schemas exist; skill frontmatter, body, and files are persisted.
- `SkillSerializer` round-trips agentskills.io packages without loss (frontmatter + body byte-for-byte).
- Skills have `state` (`active|stale|archived`) and `createdBy`.
- Users browse tenant-scoped skills and install one onto a specific agent (`installedOn`).
- The Skills view is reachable from the nav — verified live in the browser.

## Quality reminders

- SPDX in each PHP docblock; pass `composer phpcs` (lib scope) + PHPStan; run PHPUnit the CI way.
- Single write-path via OR `ObjectService`; flat declarative schemas (no `if`/`then`).
- No sed/awk/scripts on code — Edit tool only; `@spec` docblock tags; i18n keys in English.
- The run-loop consumption of `installedOn` (skill available during a turn) is an OR seam — do NOT stub an agent core here.
