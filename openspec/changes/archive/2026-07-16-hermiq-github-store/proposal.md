---
kind: code
depends_on: [hermiq-github-store-skill-schema]
---

# Proposal: hermiq-github-store

## Summary
This is the **code tail** of the config→code chain (ADR-032) headed by
`hermiq-github-store-skill-schema`. With the `Skill` schema now carrying
`githubOwner`/`githubRepo`/`publishedAt` provenance fields (landed by the head change), this change
generalises Hermiq's existing GitHub store — today agent-template-only — to serve **both** agent
templates and skills through one unified surface: (a) `GitHubTemplateCatalogService` searches both
`topic:hermiq-agent-template` AND `topic:hermiq-skill` and returns a single kind-tagged result set;
(b) `GitHubTemplatePushService.push()` also publishes a `Skill` to a new tagged `topic:hermiq-skill`
repository in agentskills.io format (via `SkillSerializer`), stamping the skill's new provenance
fields; and (c) a single "Store" manifest page replaces `AgentTemplateGallery`, retires the
`/agent-templates` route and the "Agent templates" menu item, and lets a user find-on-GitHub and
install/publish both kinds behind a per-kind filter. Install, publish, and approve-quarantined stay
behind their existing guarded endpoints and gates (ADR-023). The OpenConnector `publishToHub` path for
skills (`SkillMarketplaceController::publish`, action `skill.publish-hub`) stays as the secondary
publish route.

## Motivation
Skill publish is asymmetric with agent-template publish (see the head change's motivation). The head
change added the provenance fields; this change wires the behaviour. Rather than build a second,
parallel GitHub store for skills, the user's decision is to **generalise the proven one**: Hermiq
already ships `GitHubTemplateCatalogService` (broker-mediated search, fail-closed, graceful rate-limit
degradation) and `GitHubTemplatePushService` (broker-mediated repo create + topic tag + package
commit, token never held or logged), exercised by the `agent-template-github-store` spec. Both are
one `DISCOVERY_TOPIC`/`PACKAGE_FILE` constant away from being kind-parameterised. Users also currently
navigate two separate pages ("Agent templates" gallery + "Skills" catalog) with two different
discovery stories; a single "Store" surface with a kind filter is the simpler mental model and removes
a redundant menu item.

## Affected Projects
- [x] Project: `hermiq` — generalise `GitHubTemplateCatalogService` (index both topics, kind-tagged
  results) and `GitHubTemplatePushService` (push skills in agentskills.io format, stamp Skill
  provenance); add skill GitHub search/install/publish endpoints + routes; replace the
  `AgentTemplateGallery` manifest page with a unified "Store" page, retire the `/agent-templates` route
  and the "Agent templates" menu item; reuse the `agent-template-github-store` and `skill-row-actions`
  widget patterns.

## Scope

### In Scope
- Generalise `GitHubTemplateCatalogService::search()` to query both `topic:hermiq-agent-template` and
  `topic:hermiq-skill`, tagging each returned card with its `kind` (`agent-template` | `skill`) and
  fetching the correct per-kind package file when building cards.
- Generalise `GitHubTemplatePushService::push()` to accept a kind so it commits the agentskills.io
  package (from `SkillSerializer::toPackage()` via `SkillService::exportSkill()`) under a skill package
  filename and tags the new repo `topic:hermiq-skill`; stamp `githubOwner`/`githubRepo`/`publishedAt`
  onto the published `Skill` (mirroring `AgentTemplateController::publishGithub()`'s stamping at
  `lib/Controller/AgentTemplateController.php:673-680`).
- Add skill GitHub endpoints (search-from-store handles both kinds; skill install-from-GitHub routes
  the fetched package through `SkillMarketplaceService::installFromSource(source: 'hub')`; skill
  publish-to-GitHub) with routes in `appinfo/routes.php`, each behind the existing guarded/scoped
  pattern (ADR-023; tenant-scoped visibility, as template publish already is).
- A unified "Store" manifest page (`src/manifest.json`) that replaces `AgentTemplateGallery`, serving
  both kinds with a per-kind filter, reusing the `agent-template-github-store` store widget and the
  `skill-row-actions`/`agent-template-row-actions` widgets.
- Retire the `/agent-templates` route and the "Agent templates" menu item; fold discovery into the
  Store page.

### Out of Scope
- Any schema or version change — the `Skill` provenance fields and version bumps are owned by
  `hermiq-github-store-skill-schema` (this change depends on them being live).
- Removing the OpenConnector `publishToHub` skill path — it stays as the secondary publish route.
- Changing the agentskills.io package format or `SkillSerializer` round-trip semantics — the provenance
  fields remain out of the package (they are stamped on the object, never emitted into the committed
  file).
- The `SkillsCatalog` `/skills` index page — it stays as the tenant skill list; only the GitHub-store
  discovery + the retired `AgentTemplateGallery` fold into the unified Store page. (Whether the
  `/skills` catalog also collapses into Store is deferred — see Open Questions.)

## Approach
Kind-parameterise the two GitHub services (a `DISCOVERY_TOPIC` + `PACKAGE_FILE` pair per kind, or a
`kind` argument selecting them), keeping the broker/fail-closed/validate-coordinates invariants
untouched. Add a skill publish controller action that mirrors `AgentTemplateController::publishGithub()`
(export package → `push()` → stamp provenance) and a skill install action that mirrors
`githubInstall()` (fetch package → `installFromSource(source: 'hub')` → quarantine + scan). Replace the
`AgentTemplateGallery` page node in `src/manifest.json` with a `Store` page carrying a per-kind filter
and both widget slots; delete the `/agent-templates` route + "Agent templates" menu entry.

## New Dependencies
None. Reuses the OpenRegister credential broker and `SkillSerializer`, both already present.

## Impact
- `lib/Service/GitHubTemplateCatalogService.php`, `lib/Service/GitHubTemplatePushService.php` —
  generalised to two kinds.
- `lib/Controller/SkillController.php` and/or `lib/Controller/SkillMarketplaceController.php`,
  `appinfo/routes.php` — new skill GitHub search/install/publish endpoints + routes.
- `src/manifest.json`, `src/registry.js`, `src/widgets/*` — unified Store page; retired
  `AgentTemplateGallery` page/route/menu item; widgets reused with a `kind` prop.
- Runtime: a skill can now be published to and installed from GitHub; templates behave as before.

## Cross-Project Dependencies
Depends on `hermiq-github-store-skill-schema` (same repo) being merged and re-imported first — the
publish stamping writes fields that only exist after the head change. Runtime dependency on the
OpenRegister credential broker is unchanged.

## Risks

### Risk 1: Publish stamping writes fields the running register has not re-imported
**Severity:** High — **Mitigation:** This change `depends_on` the head config change; the chain order
(ADR-032) guarantees the schema fields are live before this code merges. The tasks include a
pre-flight check that the `agentskill` schema exposes the three fields before wiring the stamp.

### Risk 2: Generalising the shared services regresses agent-template publish/search
**Severity:** Medium — **Mitigation:** Keep the template `DISCOVERY_TOPIC`/`PACKAGE_FILE` path as the
default; add the skill path alongside it; retain the existing `agent-template-github-store` scenarios as
regression tests so template behaviour is proven unchanged.

### Risk 3: Retiring AgentTemplateGallery breaks deep links / the onboarding tour
**Severity:** Medium — **Mitigation:** The onboarding tour references `SkillsCatalog` and
`AgentCatalog`, not `AgentTemplateGallery` (verified in `src/manifest.json`), but the
`AgentTemplateGallery` route is referenced by an agent-detail action (`src/manifest.json:195`). Repoint
that reference to the new Store route as part of the retirement task; add a redirect or keep the route
id stable if a repoint is infeasible (flagged for the reviewer).

## Rollback Strategy
Revert the code + manifest diff. The head change's schema fields remain (harmless, optional, and
unwritten once this change is reverted). Restore the `AgentTemplateGallery` page/route/menu item from
the reverted `src/manifest.json`. No data migration is involved.

## Open Questions
- Should the `SkillsCatalog` `/skills` index page also collapse into the unified Store page, or remain a
  separate tenant-skill list with Store handling only cross-instance discovery? This proposal keeps
  `/skills` separate and folds only the `AgentTemplateGallery` + GitHub-store discovery into Store.
- Should skill GitHub endpoints live on `SkillController` or `SkillMarketplaceController`? The publish
  action is marketplace-flavoured; search/install are catalog-flavoured. This is resolved in design.md.
