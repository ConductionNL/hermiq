---
kind: config
depends_on: []
chain:
  - hermiq-github-store-skill-schema  # this spec (hermiq, config) — schema fields land + version bump, re-import gate opens
  - hermiq-github-store               # hermiq, code — generalises the catalog/push services + merges the Store UI; depends_on this spec
---

# Proposal: hermiq-github-store-skill-schema

## Summary
Hermiq already ships a GitHub-backed store for `AgentTemplate` objects (`agent-template-github-store`):
publish a template to a repository tagged `topic:hermiq-agent-template`, and discover + install
templates other installs have published — broker-mediated, fail-closed, the GitHub token never
reaching Hermiq. Skills (`Skill` objects, agentskills.io format) have an import/export path and a
secondary OpenConnector hub-publish path, but no GitHub store and no place on the `Skill` schema to
record where a skill was published. This change is the **head of a two-change config→code chain**
(ADR-032): it adds the three provenance fields — `githubOwner`, `githubRepo`, `publishedAt` — to the
`Skill` schema, mirroring the exact field shape already present on `AgentTemplate`, and bumps both the
register `info.version` and the app version so the version-gated re-import applies them. It writes
**no service code and no UI**; the follow-up code change (`hermiq-github-store`) generalises the
catalog + push services and unifies the two galleries into one "Store" page.

## Motivation
Skill publish today is asymmetric with agent-template publish. An `AgentTemplate` can be pushed to a
tagged GitHub repo and carries `githubOwner`/`githubRepo`/`publishedAt` provenance so the gallery can
show "last published to github.com/owner/repo at time". A `Skill` cannot: `GitHubTemplatePushService`
only knows how to push templates, and the `Skill` schema has no field to stamp a publish target onto.
The user has decided skill publish should work **like agent publish** (GitHub, broker-mediated), with
the OpenConnector `publishToHub` path kept as secondary. The code that stamps provenance
(`hermiq-github-store`) cannot land until the fields exist and the register has re-imported them —
OpenRegister's `ConfigurationService::importFromApp()` is version-gated on `info.version`
(`lib/Service/SettingsService.php:184-194`), and the post-migration repair step `InitializeSettings`
only re-imports on an app upgrade. Splitting the schema/version bump into its own head-of-chain change
keeps the declarative surface reviewable on its own and guarantees the fields are live before any code
writes to them.

## Affected Projects
- [x] Project: `hermiq` — add `githubOwner`, `githubRepo`, `publishedAt` (all optional strings) to the
  `Skill` schema in `lib/Settings/hermiq_register.json`; bump register `info.version` 0.13.0 → 0.14.0
  and app version in `appinfo/info.xml` so the version-gated re-import applies the new fields.

## Scope

### In Scope
- Add three properties to the `Skill` schema (slug `agentskill`) in `lib/Settings/hermiq_register.json`,
  copying the shape/titles/descriptions from `AgentTemplate`'s `githubOwner`/`githubRepo`/`publishedAt`
  (with the description reworded for skills and the SkillSerializer), each optional (not in `required`).
- Bump the register `info.version` from `0.13.0` to `0.14.0`.
- Bump the app `<version>` in `appinfo/info.xml` (currently `0.1.71`) so the post-migration
  `InitializeSettings` repair step fires `loadConfiguration(force: false)` on upgrade and the
  version-gated `importFromApp` re-imports the schema.

### Out of Scope
- Any PHP service, controller, or route change — deferred to `hermiq-github-store`.
- Any Vue/manifest/UI change — deferred to `hermiq-github-store`.
- Generalising `GitHubTemplateCatalogService`/`GitHubTemplatePushService`, retiring the
  `AgentTemplateGallery` page, or writing to the new fields — all deferred to `hermiq-github-store`.
- Changing `SkillSerializer` round-trip behaviour — the provenance fields are deliberately NOT part of
  the agentskills.io package (mirroring how `AgentTemplateSerializer::toPackage()` never emits
  `githubOwner`); that non-emission is enforced by code in the follow-up change, not here.

## Approach
Purely declarative. Insert the three provenance properties into the `Skill` schema's `properties`
block, after the existing `scanReport` property, matching `AgentTemplate`'s definitions:
`githubOwner` (string), `githubRepo` (string), `publishedAt` (string, `format: date-time`). Reword each
`description` for the skill context. Bump `info.version` to `0.14.0` and the `appinfo/info.xml`
`<version>`. No new `required` entries — every field is optional provenance, so existing `Skill`
objects remain valid after re-import.

## New Dependencies
None.

## Impact
- `lib/Settings/hermiq_register.json` — three new optional properties on the `Skill` schema;
  `info.version` bump.
- `appinfo/info.xml` — `<version>` bump.
- On the next app upgrade, OpenRegister re-imports the `agentskill` schema; existing `Skill` objects
  gain three empty optional fields. No data transformation, no data loss.

## Cross-Project Dependencies
None at the repository level (hermiq-only). Runtime dependency on OpenRegister's
`ConfigurationService::importFromApp()` version-gating and the credential broker is unchanged and
already established by the `agent-template-github-store` spec.

## Risks

### Risk 1: Register re-import does not fire because a version was not bumped
**Severity:** Medium — **Mitigation:** Bump BOTH `info.version` (which `importFromApp` compares) and the
`appinfo/info.xml` app version (which triggers the post-migration repair steps). Bumping only one is the
known failure mode (a schema edit that never reaches the running register). The tasks make both bumps a
single atomic task with a re-import verification step.

### Risk 2: Field-shape drift from AgentTemplate
**Severity:** Low — **Mitigation:** Copy the three property definitions verbatim from the
`AgentTemplate` schema (same JSON keys, `type`, `format`) and only reword the human-readable
`description`, so the two schemas stay mirror-images for the follow-up code that treats them uniformly.

## Rollback Strategy
Revert the `lib/Settings/hermiq_register.json` diff (remove the three properties, restore
`info.version` to `0.13.0`) and the `appinfo/info.xml` `<version>`, then run the "Re-import
configuration" action (`SettingsController::load()` forces a re-import). Because the fields are optional
and unwritten in this change, no `Skill` object holds data in them, so removal is loss-free.
