# Proposal: agent-template-github-store

## Summary
Hermiq's agent-template-gallery (shipped) already lets a user export an `AgentTemplate` to a
portable JSON package and import one back in, quarantined + content-scanned when the source is
external. This change gives that package a public distribution channel: publish an
`AgentTemplate` to a GitHub repository tagged `topic:hermiq-agent-template`, and discover +
install templates other people have published, from inside the Agent templates gallery. It
mirrors OpenBuild's `TemplateGallery`/`ShopController`/`GitHubCatalogService` app-store pattern
exactly — Hermiq is the second Conduction app to grow a GitHub-backed store, and the fixed-host,
broker-mediated, fail-closed shape that pattern already proved in production is reused verbatim
rather than re-invented.

## Motivation
Today an `AgentTemplate` package only moves between organisations by copy-pasting a JSON blob
(`export()` returns a string; `import()` accepts one). That works for a single instance or a
Slack-pasted snippet, but there is no way to browse what other Hermiq installs have published,
and no way to make a locally-authored template discoverable without an out-of-band channel. NC's
app store solved exactly this problem for apps; OpenBuild solved it for citizen-built
applications with `topic:openbuild-app` + a fixed-host GitHub search + a credential-broker push.
Agent templates are the same shape of artifact (a portable, versioned JSON definition) and
deserve the same distribution mechanism, without hermiq ever holding a GitHub token.

## Affected Projects
- [x] Project: `hermiq` — new `GitHubTemplateCatalogService` + `GitHubTemplatePushService`
  (broker-mediated, mirroring OpenBuild's `GitHubCatalogService`/`GitHubPushService`), two new
  `AgentTemplateController` actions (`githubSearch`, `githubInstall`) plus one on the existing
  template resource (`publishGithub`), and a "GitHub store" tab added to the existing
  `AgentTemplateGallery.vue` page. `AgentTemplate` gains three schema fields
  (`githubOwner`/`githubRepo`/`publishedAt`) recording the last publish target.

## Scope

### In Scope
- A server-backed GitHub search for repositories tagged `topic:hermiq-agent-template`
  (`GET /api/agent-templates/github/search`), anonymous by default, upgraded through
  OpenRegister's credential broker when the caller supplies a `github`-provider credential UUID
  (raises the anonymous rate limit; reaches private repos), fixed-host (`api.github.com`, no
  admin-configurable URL — the same SSRF-closed shape as `GitHubCatalogService`).
- Installing a discovered repo: fetch its template package file, run it through the EXISTING
  `AgentTemplateService::importPackage()` path with `source='hub'` — landing `quarantined` +
  content-scanned exactly like a hub import today. No new import/quarantine logic.
- Publishing an existing `AgentTemplate` to a new GitHub repository tagged
  `topic:hermiq-agent-template`, through a broker credential (create-repo → single-commit blob/
  tree/commit → push), reusing `AgentTemplateSerializer::toPackage()` for the committed file —
  the same package shape export/import already produce and consume.
- A "GitHub store" tab in `AgentTemplateGallery.vue`: search box, result cards, an "Add a GitHub
  credential to raise limits / browse private repos" hint (mirrors
  `hasGithubCredential`/`githubRateLimited`/`githubUnavailable` in OpenBuild's
  `TemplateGallery.vue`), and a "Publish" action per local template.
- Three new `AgentTemplate` schema fields: `githubOwner`, `githubRepo`, `publishedAt` (read-only
  provenance of the last publish; never round-tripped through the portable package).

### Out of Scope
- A hosted/curated Hermiq hub (deferred — this is GitHub-as-the-hub, identical to OpenBuild's
  scope boundary; `SkillMarketplaceService::publishToHub()`'s OpenConnector-hub seam is untouched
  and unrelated).
- Updating a template already pushed to GitHub in place (re-publish always creates a new repo,
  same as OpenBuild's `assertRepoAbsent()` — no diff/PR-update flow in this change).
- Any change to the existing paste-a-package export/import UI — it stays exactly as shipped.
- The `agent-credentials` sibling change (scaffolded, not yet specified) — this change consumes
  the same generic OpenRegister `github`-provider credential resource OpenBuild already consumes
  via `/apps/openregister/api/credentials`; it does not depend on that sibling change landing
  first, and will be revisited if that change narrows credential scoping further.

## Approach
Add a `GitHubTemplateCatalogService` (search + fetch, feature-detect broker upgrade, anonymous
fallback, short-TTL cache) and a `GitHubTemplatePushService` (create-repo → blob/tree/commit →
push, broker-only, fails closed with no broker) to `lib/Service/`, both closely modeled on
OpenBuild's `GitHubCatalogService`/`GitHubPushService` line-for-line where the shape transfers
(fixed host, pattern-validated owner/repo/ref, `class_exists`-gated lazy broker resolution,
never-log-the-secret discipline). Wire two new routes onto `AgentTemplateController`
(`githubSearch`, `githubInstall`) plus a `publishGithub` action, all `#[NoAdminRequired]` with the
same session-identity guard the existing template routes already use — tenancy is enforced by
`ObjectService`'s native RBAC, not a new authorization primitive. `githubInstall` builds the
package string from the fetched repo file and calls the existing
`AgentTemplateService::importPackage(package: ..., source: 'hub', ...)` — zero new
quarantine/scan code. Extend `AgentTemplateGallery.vue` with a "GitHub store" tab that is a
close port of `TemplateGallery.vue`'s GitHub section (search box, cards, credential hint), reusing
`src/api/agentTemplates.js`'s existing axios/CSRF conventions for the two new calls plus publish.

## New Dependencies
None. Uses Nextcloud's `IClientService` (already used by `WebSearchClient`/`SetupController`) for
the anonymous GitHub call and OpenRegister's `CredentialBrokerService` (already consumed by
`BrokerHttpClient`/`WebSearchClient`) for the broker-upgraded call — no new package, no new
external service dependency beyond the fixed `api.github.com` host OpenBuild already talks to.

## Impact
- **Backend**: 2 new services (`GitHubTemplateCatalogService`, `GitHubTemplatePushService`), 3 new
  `AgentTemplateController` methods, 3 new routes, 3 new `AgentTemplate` schema fields (register
  version bump).
- **Frontend**: `AgentTemplateGallery.vue` gains a tab; `src/api/agentTemplates.js` gains 3
  functions (`searchGithubTemplates`, `installGithubTemplate`, `publishAgentTemplateToGithub`).
  No new Vue route, no new manifest page — same `/agent-templates` custom page.
- **No NC database migration** — `AgentTemplate` is an OpenRegister-managed JSON object; the new
  fields are schema properties, not SQL columns.

## Cross-Project Dependencies
Reads (does not modify) OpenBuild's `GitHubCatalogService`/`GitHubPushService`/`ShopController`/
`RunExportJob` as the reference pattern (verified at HEAD; no shared code, no runtime coupling —
each app owns its own copy, exactly as OpenBuild owns its). Consumes OpenRegister's
`CredentialBrokerService` (already a hermiq dependency via `BrokerHttpClient`) and the generic
`github`-provider credential resource surfaced at `/apps/openregister/api/credentials` (already
consumed the same way by `LlmProviderModal.vue`/`WebResearchSettingsModal.vue`). No dependency on
the scaffolded-but-unwritten `agent-credentials` sibling change (see Out of Scope).

## Risks

### Risk 1: An installed GitHub template is an unvetted prompt-injection vector
**Severity:** High — **Mitigation:** `githubInstall` never bypasses the existing quarantine gate:
every install calls `AgentTemplateService::importPackage(source: 'hub', ...)` unchanged, so the
template lands `quarantined` and is content-scanned by `ContentScanService` exactly like today's
paste-a-package hub import, and a `dangerous` verdict still blocks one-click approval
(`agenttemplate.approve-quarantined`/`agenttemplate.override-scan-verdict` action gates
untouched).

### Risk 2: Publishing exposes an organisation's system prompt on a public host
**Severity:** Medium — **Mitigation:** `AgentTemplateSerializer::toPackage()` already emits only
the portable fields (no secrets, no tenant/org identifiers by schema construction — agent-
template-gallery Decision 1); the publish UI defaults new repos to `private` visibility (mirrors
`ExportsController`'s `githubVisibility` default) and the user chooses the target org/repo
explicitly before any network call is made.

### Risk 3: Anonymous GitHub search rate limit degrades the store for installs with no credential
**Severity:** Low — **Mitigation:** identical to OpenBuild's accepted trade-off — a short-TTL
server-side cache absorbs repeat searches, and the UI surfaces
`hasGithubCredential`/`githubRateLimited`/`githubUnavailable` with an explicit "add a GitHub
credential" hint rather than failing silently.

## Rollback Strategy
Pure additive change: revert the two new services, the three new controller methods + routes, the
gallery tab, and the three new schema fields. No existing endpoint, schema field, or UI element is
modified. Templates already published to GitHub remain valid, importable packages regardless of
whether this change is rolled back (the package format is unchanged); the `githubOwner`/
`githubRepo`/`publishedAt` provenance fields simply stop being written.

## Open Questions
None — the reference pattern (OpenBuild) and the reused import path (agent-template-gallery) are
both verified at HEAD; open design choices (repo visibility default, publish repo-naming) are
resolved in design.md.
