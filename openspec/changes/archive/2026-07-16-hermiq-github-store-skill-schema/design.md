# Design: hermiq-github-store-skill-schema

## Architecture Overview
Hermiq is a thin client — it owns no database tables. Its schemas live declaratively in
`lib/Settings/hermiq_register.json` and are imported into OpenRegister by
`SettingsService::loadConfiguration()`, which calls
`ConfigurationService::importFromApp(appId, data, version, force)` with `version` taken from the
config's `info.version` (`lib/Service/SettingsService.php:184-194`). The import is version-gated: it
only re-applies the schema when `info.version` is newer than what OpenRegister last imported for the
app. On an app upgrade the `post-migration` repair step `OCA\Hermiq\Repair\InitializeSettings` runs
`loadConfiguration(force: false)` (`lib/Repair/InitializeSettings.php:89`), so a schema edit reaches a
running instance only when BOTH the register `info.version` and the app `<version>` are bumped.

This change adds three provenance fields to the `Skill` schema (slug `agentskill`) and performs the
two version bumps. It is the config head of the `hermiq-github-store` chain (ADR-032); the follow-up
code change reads and writes these fields.

## Goals / Non-Goals
**Goals**
- Give the `Skill` schema the same GitHub publish-provenance fields that `AgentTemplate` already
  carries, so the follow-up code can stamp them uniformly.
- Make the fields live on running instances via the version-gated re-import.

**Non-Goals**
- No service, controller, route, Vue, or manifest change (deferred to `hermiq-github-store`).
- No change to `SkillSerializer` or the agentskills.io package shape.
- No writes to the new fields — they stay empty until the code change lands.

## Decisions

### Decision 1: Mirror AgentTemplate's field definitions verbatim (only reword descriptions)
The `AgentTemplate` schema already defines `githubOwner`/`githubRepo`/`publishedAt`
(`lib/Settings/hermiq_register.json:2487-2502`). We copy the JSON keys, `type`, and `format` exactly and
only reword the human-readable `description` for the skill context (referencing `SkillSerializer`
instead of `AgentTemplateSerializer`). **Alternative considered:** inventing skill-specific field names
(e.g. `publishedRepo`). Rejected — the follow-up code treats agents and skills uniformly in a
generalised catalog/push service, so mirror-image schemas keep that code branch-free.

### Decision 2: All three fields optional; none added to `required`
The fields are pure provenance, set only after a successful publish. Making them optional means every
existing `Skill` object stays valid after re-import (no backfill, no data transformation).
**Alternative considered:** a nested `github` object mirroring `scanReport`. Rejected — `AgentTemplate`
uses three flat top-level fields, and mirroring that shape is the whole point of Decision 1.

### Decision 3: Two coordinated version bumps, not one
Bump `info.version` 0.13.0 → 0.14.0 (what `importFromApp` compares) AND `appinfo/info.xml` `<version>`
(what triggers the post-migration repair steps). **Alternative considered:** bumping only
`info.version`. Rejected — without an app-version bump the repair steps never run on upgrade, so the
re-import never fires; this is the documented "schema edit that never reaches the register" failure
mode.

### Decision 4 (ADR-031 declarative-vs-imperative): no new declarative behaviours
This change introduces **no** OpenRegister lifecycle, aggregation, calculation, or notification
behaviour. The three fields are plain optional strings with no defaults, no computed values, no
`x-openregister` hooks, and no notification dialect. The GitHub catalog/push services that will read and
write them are **external-integration imperative services** — the ADR-031 exception for talking to a
third-party HTTP API (GitHub) — and they already exist for `AgentTemplate` under the
`agent-template-github-store` spec. There is therefore nothing declarative to express here beyond the
field declarations themselves; the imperative surface is entirely deferred to `hermiq-github-store`.

## Database Changes
None in Hermiq (thin client, no owned tables). The schema change is applied to OpenRegister's storage
by the version-gated register re-import — see Migration Plan.

## Nextcloud Integration
- Controllers: none changed.
- Services: `SettingsService::loadConfiguration()` (unchanged) applies the schema via
  `ConfigurationService::importFromApp()`.
- Mappers/Entities: none (OpenRegister owns storage).
- Events/Hooks: `Repair\InitializeSettings` (post-migration, unchanged) triggers the re-import.

## Security Considerations
No security impact. The three fields are provenance-only, non-secret, and hold nothing beyond a GitHub
owner/repo name and a timestamp. No auth, CSRF, or input-validation surface changes (no new endpoint).
The follow-up code change owns the ADR-023 authorization on the publish action that will write them.

## Migration Plan
No Nextcloud migration class exists or is added — Hermiq has no `lib/Migration/` directory. The
"migration" is an OpenRegister register re-import:
1. Bump `info.version` to `0.14.0` and `appinfo/info.xml` `<version>`.
2. On app upgrade, the `post-migration` repair step `InitializeSettings` runs
   `loadConfiguration(force: false)`.
3. `importFromApp` sees the newer `info.version` and re-imports the `agentskill` schema, adding the
   three optional properties.
4. Existing `Skill` objects gain three empty optional fields — no transformation, no loss.

**Rollback:** revert the JSON + info.xml diffs and invoke the "Re-import configuration" admin action
(`SettingsController::load()` → `loadConfiguration(force: true)`), which re-imports the reverted schema.

## Seed Data
Hermiq seeds starter data via idempotent repair steps (e.g. `SeedAgentTemplates`). This change adds
**no new schema and no new seed step** — it extends an existing schema with three optional provenance
fields. Seeded `Skill` objects (if/when a skill seeder exists) would leave these fields empty, exactly
as a locally-authored, never-published skill does. The realistic values below illustrate what a
*published* skill looks like once the follow-up code stamps the fields; they are documentation of the
field semantics, not a seed step this change introduces. All repo names are safe placeholders.

### Schema: `agentskill`
| Field | Object 1 | Object 2 | Object 3 |
|-------|----------|----------|----------|
| slug | agentskill | agentskill | agentskill |
| name | WOZ bezwaar-triage | Vergunning-samenvatter | Subsidie-intake check |
| description | Triages incoming WOZ objection letters | Summarises permit applications | Validates subsidy intake forms |
| state | active | active | active |
| source | local | hub | org |
| githubOwner | gemeente-example | conduction-example | consultancy-example |
| githubRepo | hermiq-skill-woz-triage | hermiq-skill-permit-summary | hermiq-skill-subsidy-intake |
| publishedAt | 2026-07-16T09:00:00+00:00 | 2026-07-16T10:30:00+00:00 | *(empty — org-shared, never GitHub-published)* |

**Related items per object:** none introduced by this change — the fields are scalar provenance on the
existing `Skill` object. A never-published skill leaves all three fields empty (the default state for
every existing object after re-import).

## Trade-offs
Splitting a three-field schema edit into its own change adds ceremony, but ADR-032's config→code split
is what guarantees the fields are live before the code writes them, and it keeps the declarative diff
reviewable in isolation. The alternative (one combined change) risks the code landing against fields the
running register has not yet re-imported.
