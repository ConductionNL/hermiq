# Design: hermiq-github-store

## Architecture Overview
Hermiq's GitHub store today is agent-template-only, built from two imperative external-integration
services and one manifest page:
- `GitHubTemplateCatalogService` (`lib/Service/GitHubTemplateCatalogService.php`) — broker-mediated
  GitHub search. Constants: `DISCOVERY_TOPIC = 'topic:hermiq-agent-template'` (line 70),
  `PACKAGE_FILE = 'hermiq-agent-template.json'` (line 78). `search()` (line 208) builds cards from the
  repo's package file via `buildCard()`; `fetchTemplateFile()` (line 289) returns the raw package for
  install; `validRepo()` and `isBrokerAvailable()` are the safety/availability gates.
- `GitHubTemplatePushService` (`lib/Service/GitHubTemplatePushService.php`) — broker-mediated repo
  create + topic tag + package commit. Constants: `DISCOVERY_TOPIC = 'hermiq-agent-template'` (line 74),
  `PACKAGE_FILE = 'hermiq-agent-template.json'` (line 83). `push(package, owner, repo, visibility,
  credentialId, actingUserId)` (line 138) asserts the repo is absent, creates it, sets topics, commits
  the package. The token is never held or logged (fail-closed if the broker is unavailable).
- The `AgentTemplateGallery` manifest page (`src/manifest.json:435-479`, route `/agent-templates`,
  `type: index`) with the `agent-template-github-store` store widget in `slots.below-header` and
  `agent-template-row-actions` in `slots.row-actions`; menu item at `src/manifest.json:63-69`.

Skills already have the parallel building blocks: `SkillSerializer::toPackage()/fromPackage()`
(agentskills.io, byte-for-byte), `SkillService::exportSkill()` (returns the package string), and
`SkillMarketplaceService::installFromSource(package, source, createdBy)` — the quarantine+scan gate,
the skill analogue of `AgentTemplateService::importPackage(source: 'hub')`.

This change kind-parameterises the two services and unifies the UI. Nothing about the broker, the
fail-closed posture, or the coordinate validation changes.

## Goals / Non-Goals
**Goals**
- One GitHub store serving both agents and skills, kind-tagged, with a per-kind filter.
- Skill publish to a `topic:hermiq-skill` repo in agentskills.io format, stamping provenance.
- Skill install-from-GitHub through the existing `installFromSource(source: 'hub')` quarantine gate.
- Retire `AgentTemplateGallery`; fold its discovery into the unified Store page.

**Non-Goals**
- No schema/version change (owned by the head change).
- No removal of the OpenConnector `publishToHub` skill path (kept secondary).
- No change to agentskills.io package format or `SkillSerializer` round-trip.

## API Design
New skill endpoints mirror the existing agent-template GitHub endpoints
(`appinfo/routes.php:325-350`). Exact paths are settled in tasks; the shapes mirror the template ones.

### `GET /api/skills/github/search`
Reuses the generalised catalog search. Returns 200 with kind-tagged cards (a `kind` field per card:
`agent-template` | `skill`), degrading gracefully (`outcome`, `rateLimited`) exactly as the template
search does. A single unified search endpoint MAY serve both kinds with a `kind` filter param instead
of two endpoints (decided in Task ordering).
**Response (200):**
```json
{ "outcome": "ok", "brokerUsed": false, "rateLimited": false, "cards": [ { "kind": "skill", "owner": "YOUR_OWNER_HERE", "repo": "hermiq-skill-example", "name": "Example skill", "description": "", "version": "0.1.0", "stars": 0 } ] }
```

### `POST /api/skills/github/install`
Fetches the discovered repo's skill package file and passes it to
`SkillMarketplaceService::installFromSource(source: 'hub')` — quarantine + `ContentScanService` scan,
unchanged. Body: `{ "owner": "...", "repo": "...", "ref": null, "credentialId": null }`.

### `POST /api/skills/{id}/github/publish`
Exports the skill (`SkillService::exportSkill(id)`), pushes it via the generalised `push()` under the
skill kind, then stamps `githubOwner`/`githubRepo`/`publishedAt` onto the `Skill`. Body:
`{ "owner": "...", "repo": "...", "visibility": "private", "credentialId": "00000000-0000-0000-0000-000000000000" }`.
**Errors:** `400 invalid_repo` (coordinate validation), `404` (skill not visible to caller),
`422` (missing broker credentialId), `503` (broker unavailable) — identical to
`AgentTemplateController::publishGithub()`.

## Database Changes
None. Provenance fields were added by the head change; this change writes them. Hermiq owns no tables.

## Nextcloud Integration
- Controllers: `SkillController` (search/install — catalog-flavoured) and `SkillMarketplaceController`
  (publish — marketplace-flavoured, already gates ADR-023 actions). See Decision 3.
- Services: `GitHubTemplateCatalogService`, `GitHubTemplatePushService` (generalised), `SkillSerializer`,
  `SkillService::exportSkill()`, `SkillMarketplaceService::installFromSource()` (reused).
- Mappers/Entities: none (OpenRegister owns storage).
- Events/Hooks: none new.

## Security Considerations
- The GitHub token never reaches Hermiq: publish and authenticated search route through the
  OpenRegister credential broker; the services fail closed when the broker is unavailable. This posture
  is preserved verbatim from `agent-template-github-store` — no token-bearing fallback is added.
- Coordinate validation (`^[A-Za-z0-9._-]{1,100}$` for owner/repo, safe ref pattern) runs before any
  outbound call, for both kinds.
- Skill install-from-GitHub lands quarantined and content-scanned (`installFromSource(source: 'hub')`);
  a `dangerous` verdict blocks one-click approval, requiring `skill.override-scan-verdict` (ADR-023).
- Skill publish is scoped to skills the caller can already see: `exportSkill()` returns `null` for a
  skill outside the caller's tenant visibility, yielding a 404 (never a 403 that confirms existence) —
  the same scoping `publishGithub()` uses for templates.
- No new secret is stored on any schema (ADR-003); provenance fields are non-secret.

## NL Design System
The unified Store page reuses existing NC/nldesign components already used by the
`agent-template-github-store` store widget and the row-action widgets (`NcButton`, `NcSelect` with
`inputLabel`, `NcModal`-based dialogs in `src/modals/`). The per-kind filter uses an `NcSelect` or
segmented control with an accessible `inputLabel`. No hardcoded colours; CSS variables only. Dutch +
English strings for any new labels ("Store", the kind filter, "Publish skill to GitHub").

## File Structure
```
lib/
  Service/
    GitHubTemplateCatalogService.php   # generalised: index both topics, kind-tagged cards
    GitHubTemplatePushService.php      # generalised: push skills in agentskills.io format
  Controller/
    SkillController.php                # githubSearch/githubInstall (catalog-flavoured)
    SkillMarketplaceController.php     # publishGithub (marketplace-flavoured, ADR-023)
appinfo/
  routes.php                           # new skill GitHub routes
src/
  manifest.json                        # unified Store page replaces AgentTemplateGallery; menu + route retired
  registry.js                          # store widget + row-action widgets reused with a kind prop
  widgets/                             # AgentTemplateGithubStore generalised (or a thin Store wrapper)
```

## ADR-031 Declarative-vs-Imperative
This change introduces **no new OpenRegister declarative behaviour** — no lifecycle transition,
aggregation, calculation, or notification dialect is added. The provenance fields are plain optional
strings (added by the head change). The GitHub catalog and push services are
**external-integration imperative services** — the ADR-031 exception for talking to a third-party HTTP
API (GitHub) — already established for agent templates by the `agent-template-github-store` spec; this
change merely widens their reach to a second object kind. Install still routes through the existing
imperative quarantine/scan gate (`installFromSource`); publish still stamps provenance via an ordinary
object update. There is nothing to express in the `x-openregister-*` declarative dialects here.

## Trade-offs
- **Generalise the shared services vs. build a parallel skill store.** Chosen: generalise. The
  broker/fail-closed/validate/degrade invariants are hard-won and already tested; duplicating them for
  skills invites drift. Cost: the shared services grow a `kind` seam and must retain the template path
  as a regression-guarded default.
- **Unify pages vs. keep two galleries.** Chosen: one Store page (per user decision). Cost: retiring
  `AgentTemplateGallery` means repointing the agent-detail action that links to it
  (`src/manifest.json:195`) and dropping a menu item; mitigated by keeping the row-action + store
  widgets intact and only changing the page host + filter.

## Decisions

### Decision 1: Kind-parameterise the two services rather than fork them
Add a `kind` seam (per-kind `DISCOVERY_TOPIC` + `PACKAGE_FILE`, and for push the serializer source) so
one code path serves both. **Alternative:** new `GitHubSkillCatalogService`/`GitHubSkillPushService`.
Rejected — near-total duplication of broker/validation/degradation logic, guaranteed to drift.

### Decision 2: Skill install reuses `installFromSource(source: 'hub')` unchanged
The fetched GitHub package is handed verbatim to the existing quarantine+scan gate, exactly as template
install hands its package to `importPackage(source: 'hub')`. **Alternative:** a new GitHub-specific
install path. Rejected — it would re-implement quarantine/scan and violate the "no new quarantine
logic" invariant the template store already holds.

### Decision 3: Skill publish on `SkillMarketplaceController`; search/install on `SkillController`
Publish is a marketplace lifecycle action (the controller already gates ADR-023 actions and owns the
secondary `publish-hub` path), so the GitHub publish action lives beside it. Search/install are
catalog/discovery operations, so they live on `SkillController` alongside `index`/`import`. **Alternative:**
all three on one controller. Rejected — it splits the ADR-023 gating story across unrelated methods.

### Decision 4: The unified Store page replaces AgentTemplateGallery; `/skills` stays separate
The Store page (new route, e.g. `/store`) hosts find-on-GitHub for both kinds behind a per-kind filter
and reuses the existing widgets. The tenant `SkillsCatalog` `/skills` list stays as-is (Open Question
in the proposal). **Alternative:** collapse `/skills` into Store too. Deferred — larger UI surface,
out of this change's scope.

## Open Questions
- Final route id for the Store page and whether the retired `/agent-templates` route should 301-redirect
  or be dropped outright (depends on whether the agent-detail action reference can be repointed cleanly).
- One unified `/api/*/github/search` endpoint with a `kind` filter vs. a per-kind endpoint — settled
  during Task 2 against the widget's data needs.
