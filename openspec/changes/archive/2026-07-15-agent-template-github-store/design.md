# Design: agent-template-github-store

## Architecture Overview
This change adds a GitHub-backed distribution channel alongside the existing paste-a-package
export/import flow, without touching either. Two new backend services own the two directions of
travel; the existing `AgentTemplateService`/`AgentTemplateSerializer` own the package shape and
the quarantine/scan gate in both directions:

```
                    ┌──────────────────────────┐
   search/install   │  AgentTemplateController │   publish
   ┌────────────────┤   (3 new methods)        ├────────────────┐
   │                └──────────────────────────┘                │
   ▼                                                             ▼
GitHubTemplateCatalogService                          GitHubTemplatePushService
  - search(topic:hermiq-agent-template)                  - createRepo
  - fetchTemplateFile(owner, repo, ref)                   - pushTree (blob/tree/commit/ref)
  - fixed host api.github.com, anon or broker             - broker-only, fails closed
   │                                                             │
   ▼                                                             ▲
importPackage(source:'hub')  ───────────►  AgentTemplateService  │
  (EXISTING — quarantine + ContentScanService, unchanged)        │
                                                                   │
                                          exportTemplate() ────────┘
                                          (EXISTING — AgentTemplateSerializer::toPackage())
```

Both new services are modeled directly on OpenBuild's `GitHubCatalogService`/`GitHubPushService`
(verified at HEAD in `openbuild/lib/Service/`), which already solved: fixed-host SSRF-safety,
lazy broker resolution with anonymous fallback, owner/repo/ref pattern validation before path
interpolation, and never-log-the-secret discipline. This design ports that shape rather than
re-deriving it.

## API Design

### `GET /api/agent-templates/github/search`
Mirrors `ShopController::githubSearch()`. `#[NoAdminRequired]`, in-body 401 guard.

**Request:** query params `q` (optional free-text), `credentialId` (optional broker credential
UUID).

**Response (200):**
```json
{
  "outcome": "ok",
  "cards": [
    {
      "owner": "acme-council",
      "repo": "morning-briefing-template",
      "stars": 3,
      "installable": true,
      "unparseable": false,
      "name": "Morning briefing",
      "description": "Summarises overnight tickets.",
      "category": "productivity",
      "version": "0.1.0"
    }
  ],
  "brokerCredentialAvailable": true,
  "brokerUsed": false,
  "rateLimited": false
}
```
A degraded call (rate-limited/unreachable) returns 200 with `cards: []` and `outcome` set
accordingly (never a 5xx for a third-party outage — mirrors `githubSearch()`'s catch block).

### `POST /api/agent-templates/github/install`
Mirrors `ShopController::githubInstall()`, but the install target is `AgentTemplateService::
importPackage()`, not an app clone.

**Request:**
```json
{ "owner": "acme-council", "repo": "morning-briefing-template", "ref": null, "credentialId": null }
```

**Response (201):** the imported template (same shape as `POST /api/agent-templates/import`),
always `state: "quarantined"` — `owner`/`repo` are pattern-validated
(`/^[A-Za-z0-9._-]{1,100}$/`) before path interpolation; a missing/unparseable template file
returns 404/422 rather than creating a partial template.

### `POST /api/agent-templates/{id}/publish-github`
**Request:**
```json
{ "owner": "acme-council", "repo": "morning-briefing-template", "visibility": "private", "credentialId": "…" }
```

**Response (201):**
```json
{ "repoUrl": "https://github.com/acme-council/morning-briefing-template", "commitSha": "…" }
```
On success, the template's `githubOwner`/`githubRepo`/`publishedAt` fields are updated in place
(provenance only — never round-tripped through the package itself). `credentialId` is required;
absent broker/credential fails closed with 422/503 (mirrors `GitHubPushService::push()`'s
`RuntimeException` on a missing broker).

## Database Changes
None. `AgentTemplate` is an OpenRegister-managed JSON object (`lib/Settings/hermiq_register.json`).
Three properties are added to the existing schema (`githubOwner`, `githubRepo`, `publishedAt`) —
an OpenRegister schema-version bump, not a Nextcloud `lib/Migration/` class. Both
`hermiq_register.json`'s `info.version` (0.10.9 → 0.11.0) and the `agenttemplate` schema's own
`version` (0.1.0 → 0.2.0) are bumped, per the hard rule that a register schema change bumps both
the register-level and schema-level version fields; `appinfo/info.xml`'s `<version>` is bumped
(0.1.59 → 0.1.60) alongside it since served assets (the new gallery tab) change too.

## Nextcloud Integration
- **Controllers**: `AgentTemplateController` gains `githubSearch()`, `githubInstall()`,
  `publishGithub(string $id)` — same class as the existing gallery routes, not a new controller,
  because they operate on the same resource (`AgentTemplate`) and the same session-identity guard.
- **Services**: `GitHubTemplateCatalogService` (new, `lib/Service/`), `GitHubTemplatePushService`
  (new, `lib/Service/`). Both are constructor-injected into `AgentTemplateController` alongside
  the existing `AgentTemplateService`.
- **Broker**: `OCA\OpenRegister\Service\Credential\CredentialBrokerService`, resolved lazily via
  `class_exists()` + `Server::get()` — the exact pattern `BrokerHttpClient`/`WebSearchClient`
  already use in this codebase, and `GitHubCatalogService`/`GitHubPushService` use in OpenBuild.
- **HTTP client**: `OCP\Http\Client\IClientService` for the anonymous GitHub path (mirrors
  `WebSearchClient::requestDirect()`).
- **Cache**: `OCP\ICacheFactory` short-TTL distributed cache for search results (mirrors
  `GitHubCatalogService`'s `SEARCH_TTL`/`DESCRIPTOR_TTL`) — anonymous GitHub search is
  rate-limited at 10 req/min; caching absorbs a gallery page's repeat renders.
- **Mappers/Entities**: none new — `AgentTemplate` continues to flow through `ObjectService`.
- **Events/Hooks**: none.

## Security Considerations
- **The GitHub PAT never enters Hermiq.** Both new services send only `{method, path, body}` plus
  a `credentialId` UUID to the broker; the broker injects the token server-side. When the broker
  is absent, `GitHubTemplatePushService` fails closed (`RuntimeException`) — there is no
  token-bearing fallback, mirroring `GitHubPushService`'s documented Decision 3.
- **Fixed host, no SSRF surface.** `api.github.com` is a compile-time constant in
  `GitHubTemplateCatalogService`; there is no admin-configurable base URL. `owner`/`repo`/`ref`
  are validated against the same safe patterns `ShopController`/`GitHubCatalogService` use before
  any path interpolation.
- **Untrusted content lands quarantined.** `githubInstall()` is a thin adapter in front of the
  UNCHANGED `AgentTemplateService::importPackage(source: 'hub')` — the content-scan-then-quarantine
  discipline that already guards a pasted package guards a GitHub-fetched one identically. This is
  the one property this whole change cannot regress: an externally-sourced `systemPrompt` is a
  prompt-injection vector exactly like a Skill body, and the existing gate is reused verbatim, not
  re-implemented.
- **IDOR**: `publishGithub()` reads the template via `AgentTemplateService::get()`, which is
  tenant-scoped through `ObjectService`'s native RBAC — a caller cannot publish a template outside
  their organisation's visibility, same guard `export()`/`update()` already rely on.
- **CSRF**: all three new routes are state-changing or read-with-side-effect POSTs/GETs called via
  `@nextcloud/axios` from the SPA, which attaches `requesttoken` automatically — none carries
  `#[NoCSRFRequired]` (unlike OpenBuild's plain-GET download link, none of these three is a
  `<a href>` navigation).
- **Never log the secret/token.** Both new services follow `GitHubPushService::scrub()`'s
  discipline — broker call failures log method + path only, never the body or any token-shaped
  string.

## NL Design System
The new "GitHub store" tab reuses `NcTextField`, `NcButton`, `NcNoteCard`, `NcLoadingIcon`,
`NcEmptyContent` — the exact component set `TemplateGallery.vue` already uses — plus the existing
`CnDataTable`-based template list is left untouched; the GitHub cards render as a separate grid
section within the same page, consistent with how OpenBuild's store renders alongside its other
gallery sections. No hardcoded colors; CSS custom properties only, matching the existing
`AgentTemplateGallery.vue` `<style scoped>` block's conventions.

## File Structure
```
lib/
  Controller/
    AgentTemplateController.php        (+3 methods: githubSearch, githubInstall, publishGithub)
  Service/
    GitHubTemplateCatalogService.php    (new)
    GitHubTemplatePushService.php       (new)
  Settings/
    hermiq_register.json                (agenttemplate schema: +githubOwner/githubRepo/publishedAt; version bumps)
appinfo/
  routes.php                            (+3 routes)
  info.xml                              (version bump)
src/
  api/
    agentTemplates.js                   (+3 functions)
  views/
    AgentTemplateGallery.vue             (+GitHub store tab)
tests/
  Unit/
    Service/
      GitHubTemplateCatalogServiceTest.php  (new)
      GitHubTemplatePushServiceTest.php     (new)
    Controller/
      AgentTemplateControllerTest.php        (+3 test groups)
l10n/
  en.json, nl.json                      (+new strings)
```

## Seed Data
No new schema/entity — `githubOwner`/`githubRepo`/`publishedAt` are optional properties added to
the existing `agenttemplate` schema. No seed `AgentTemplate` objects need a populated GitHub
provenance to be realistic (an un-published template is the common case); existing
agent-template-gallery seed data is unaffected.

## Trade-offs
- **Same controller vs. a new `AgentTemplateShopController`.** OpenBuild splits shop concerns into
  their own `ShopController` because OpenBuild's shop installs a distinct resource type
  (`Application`) via a distinct seam (`ApplicationsController::installFromTemplateArray`).
  Here, install/publish both operate on the SAME resource (`AgentTemplate`) the existing
  `AgentTemplateController` already owns, and install literally calls the existing
  `AgentTemplateService::importPackage()` — splitting into a second controller would duplicate the
  session/error-shaping boilerplate for no isolation benefit. Chosen: extend the existing
  controller.
- **New services vs. extending `AgentTemplateService`.** The catalogue/push concerns (GitHub REST
  calls, broker plumbing, repo pattern validation) are orthogonal to `AgentTemplateService`'s
  domain logic (CRUD, quarantine, instantiate) and match OpenBuild's own separation
  (`GitHubCatalogService`/`GitHubPushService` are standalone, injected into controllers, not
  folded into `ApplicationsController`). Chosen: two new standalone services.
- **Repo-per-publish vs. update-in-place.** OpenBuild's `assertRepoAbsent()` refuses to publish
  over an existing repo, favoring a fresh PR-reviewable bootstrap over a silent overwrite. Adopted
  identically here — a re-publish to the same `owner/repo` fails with a clear "already exists"
  error rather than force-pushing over content a maintainer may have edited on GitHub directly.
