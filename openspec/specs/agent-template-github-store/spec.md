# agent-template-github-store Specification

**Status**: active (agent-template + skill GitHub search + publish live; unified into one Store page)

**OpenSpec changes:** `agent-template-github-store` — DONE: server-backed GitHub search for `topic:hermiq-agent-template` repos (`GitHubTemplateCatalogService`) + broker-authed publish (`GitHubTemplatePushService`, the GitHub token never reaches Hermiq). `hermiq-github-store` — DONE: generalises both services to also index/push skills, and replaces `AgentTemplateGallery` with one unified Store page (agent templates + skills, per-kind filter); the `/agent-templates` route + menu item are removed.

## Purpose
TBD - created by archiving change agent-template-github-store. Update Purpose after archive.
## Requirements
### Requirement: The system MUST provide a server-backed search for hermiq-agent-template repos
The search MUST query GitHub for repositories tagged `topic:hermiq-agent-template` **and**
`topic:hermiq-skill`, optionally narrowed by a free-text term, and return normalised result cards each
tagged with its `kind` (`agent-template` | `skill`), without exposing the raw GitHub response body or any
token. A per-kind filter MUST let the caller restrict results to one kind. Fetching a card's package
content MUST use the package filename for that card's kind (the agent-template package file for
`agent-template`, the agentskills.io skill package file for `skill`).

#### Scenario: Default search with no query returns tagged repos of both kinds
- GIVEN an authenticated Hermiq user opens the unified "Store" page
- WHEN no search term has been entered and no kind filter is applied
- THEN the system queries GitHub for both `topic:hermiq-agent-template` and `topic:hermiq-skill` and
  renders one card per result, each carrying its `kind`, owner, repo, name, description, version, and
  stars

#### Scenario: The kind filter restricts results to one kind
- GIVEN the Store page is open with results of both kinds
- WHEN the user selects the "Skills" kind filter
- THEN only `kind: "skill"` cards are shown, and template cards are hidden

#### Scenario: Free-text term narrows the search
- GIVEN the Store page is open
- WHEN the user types a search term into the search box
- THEN the term is appended to the topic query and the result cards update to the narrowed set across
  the active kind(s)

### Requirement: The system MUST degrade gracefully when GitHub is rate-limited or unreachable
The search endpoint MUST return a 200 response with an empty card list and a clear outcome/
rate-limit indicator when the anonymous GitHub call is rate-limited or fails, rather than
surfacing a 5xx to the caller, and MUST hint that supplying a GitHub credential raises the limit.

#### Scenario: Anonymous rate limit is hit
- GIVEN no `github`-provider credential is configured for the caller
- WHEN the anonymous GitHub search call returns HTTP 403/429
- THEN the search endpoint responds 200 with `cards: []`, `outcome: "github_rate_limited"`,
  `rateLimited: true`
- AND the gallery shows a non-blocking hint to add a GitHub credential to raise the limit

#### Scenario: A broker credential upgrades the call
- GIVEN the caller has an allowed `github`-provider credential
- WHEN the caller performs a search with that credential's id attached
- THEN the search is routed through the OpenRegister credential broker and the response reports
  `brokerUsed: true`

### Requirement: The system MUST install a discovered template through the existing quarantine gate
Installing a chosen GitHub result MUST fetch its template package file and pass it through the
UNCHANGED `AgentTemplateService::importPackage()` path with `source='hub'` — the same quarantine +
`ContentScanService` scan a pasted hub package already undergoes. No new quarantine or scanning
logic MUST be introduced.

#### Scenario: Installing a GitHub template lands it quarantined
- GIVEN a search result card is installable
- WHEN the user clicks "Install" on that card
- THEN the system fetches the repo's template package file, calls `importPackage(source: 'hub')`,
  and the resulting `AgentTemplate` is created with `state: "quarantined"` and a populated
  `scanReport`, identical in shape to a pasted-package hub import

#### Scenario: A dangerous scan verdict still blocks one-click approval
- GIVEN a GitHub-installed template's `systemPrompt` triggers a `dangerous` content-scan verdict
- WHEN a reviewer attempts to approve it without `force`
- THEN the approval is refused (409) exactly as `AgentTemplateController::approve()` already
  refuses a pasted-package import with the same verdict

### Requirement: The system MUST validate repo coordinates before any GitHub call
`owner`, `repo`, and an optional `ref` MUST be validated against safe patterns
(`^[A-Za-z0-9._-]{1,100}$` for owner/repo; a safe git-ref pattern) before interpolating them into
any GitHub API path, for both search-result installs and publish targets.

#### Scenario: An invalid owner/repo is rejected before any outbound call
- GIVEN a client sends an install or publish request with an owner or repo value that does not
  match the safe pattern
- WHEN the request is validated
- THEN the system returns 400 `invalid_repo` and makes no outbound GitHub call

### Requirement: The system MUST let a template owner publish it to a new tagged GitHub repository
Publishing MUST create a new GitHub repository — under a caller-chosen owner/repo name and
visibility — carrying a `topic:hermiq-agent-template` descriptor, with the committed file built
from `AgentTemplateSerializer::toPackage()` — the same package shape `exportTemplate()` already
produces.

#### Scenario: Publish creates a repo and commits the package
- GIVEN the caller owns/can view an active `AgentTemplate` and supplies a broker `github`
  credential, target owner, repo name, and visibility
- WHEN the caller publishes the template
- THEN the system creates the repository, commits a single blob containing the serialized
  package, and returns the repo URL and commit SHA

### Requirement: The system MUST never hold or log the GitHub token
Every publish/search/install GitHub call MUST route through the OpenRegister credential broker by
`{method, path, body}` plus a credential UUID, MUST NOT accept or persist a raw GitHub token from
the client, and MUST fail closed (never fall back to an app-held token) when the broker is
unavailable. Broker-call failures MUST be logged with method + path only, never a body or
token-shaped string.

#### Scenario: Publish without the broker installed fails closed
- GIVEN the OpenRegister credential broker is not available on this instance
- WHEN a publish is attempted
- THEN the system refuses with an error indicating the broker is required, and no GitHub call is
  attempted

#### Scenario: A broker call failure never logs the token
- GIVEN a broker-mediated GitHub call fails
- WHEN the failure is logged
- THEN the log entry contains only the HTTP method and path, never the request body or any
  token-shaped string

### Requirement: The system MUST refuse to overwrite an existing GitHub repository
Publish MUST check that the target `owner/repo` does not already exist before creating it, and
MUST refuse the publish with a clear error when it does, rather than force-pushing over existing
content.

#### Scenario: Publishing to an existing repo is refused
- GIVEN a repository already exists at the caller-chosen owner/repo
- WHEN the caller attempts to publish an `AgentTemplate` there
- THEN the system refuses with an "already exists" error and creates nothing

### Requirement: The system MUST record GitHub publish provenance without leaking it into packages
A successful publish MUST record the last publish target (`githubOwner`, `githubRepo`,
`publishedAt`) on the `AgentTemplate` object, and MUST NOT include these provenance fields in the
portable package `AgentTemplateSerializer::toPackage()` produces.

#### Scenario: A successful publish updates provenance fields
- GIVEN a publish succeeds
- WHEN the template is subsequently read
- THEN `githubOwner`, `githubRepo`, and `publishedAt` reflect the just-completed publish

#### Scenario: Provenance never leaks into the exported package
- GIVEN a template has been published and carries `githubOwner`/`githubRepo`/`publishedAt`
- WHEN the template is exported via `exportTemplate()`
- THEN the resulting package contains none of those three fields

### Requirement: The system MUST scope publish to templates the caller can already see
Publish MUST resolve the template through the same tenant-scoped `AgentTemplateService::get()`
path every other template action uses, so a caller cannot publish a template outside their
organisation's visibility.

#### Scenario: A caller cannot publish a template from another organisation
- GIVEN an `AgentTemplate` belongs to an organisation the caller is not a member of
- WHEN the caller attempts to publish it by UUID
- THEN the system responds as if the template does not exist (404), identical to `show()`/
  `update()`'s existing tenant-scoping behaviour

### Requirement: A single unified Store page replaces the Agent templates gallery
The system MUST present one "Store" manifest page that serves discovery and publish for both agent
templates and skills, and MUST retire the standalone `AgentTemplateGallery` page: its `/agent-templates`
route and its "Agent templates" menu item MUST be removed, with any in-app reference to the retired
route repointed to the Store page. The Store page MUST reuse the existing `agent-template-github-store`
store widget and the `agent-template-row-actions` / `skill-row-actions` widgets rather than introduce a
new action surface.

#### Scenario: The Agent templates menu item and route are gone
- GIVEN the app is loaded after this change
- WHEN the user inspects the navigation menu and the router
- THEN there is no "Agent templates" menu item and no `/agent-templates` route
- AND the unified "Store" page is reachable and shows both agent-template and skill discovery

#### Scenario: An in-app link that used to open the gallery now opens the Store
- GIVEN an in-app action previously navigated to the `AgentTemplateGallery` route
- WHEN that action is triggered after this change
- THEN it navigates to the Store page instead, with no dead route

### Requirement: The system MUST install a discovered skill through the skill quarantine gate
Installing a chosen GitHub result of kind `skill` MUST fetch its agentskills.io package file and pass it
through the UNCHANGED `SkillMarketplaceService::installFromSource(source: 'hub')` path — the same
quarantine + `ContentScanService` scan a pasted/OpenConnector-sourced skill package already undergoes.
No new quarantine or scanning logic MUST be introduced; coordinate validation MUST run before any GitHub
call exactly as for template installs.

#### Scenario: Installing a GitHub skill lands it quarantined
- GIVEN a search result card of kind `skill` is installable
- WHEN the user clicks "Install" on that card
- THEN the system fetches the repo's skill package file, calls
  `installFromSource(source: 'hub')`, and the resulting `Skill` is created with `state: "quarantined"`
  and a populated `scanReport`, identical in shape to an OpenConnector hub install

#### Scenario: A dangerous scan verdict still blocks one-click approval for a GitHub skill
- GIVEN a GitHub-installed skill's content triggers a `dangerous` content-scan verdict
- WHEN a reviewer attempts to approve it without `force`
- THEN the approval is refused exactly as `SkillMarketplaceController::approve()` already refuses a
  dangerous-verdict skill, requiring the `skill.override-scan-verdict` action (ADR-023)

