# agent-template-github-store Specification

**Status**: in-progress
**Scope**: hermiq
**OpenSpec changes**:
- `hermiq-github-store` — generalises the GitHub store to serve both agent templates and skills, and
  unifies the two galleries into one "Store" page

## Purpose
Generalises Hermiq's GitHub-backed store from agent-template-only to a unified store that indexes,
installs, and publishes **both** `AgentTemplate` and `Skill` objects, reusing the proven broker-mediated,
fail-closed, coordinate-validated services (`GitHubTemplateCatalogService`, `GitHubTemplatePushService`)
and folding the `AgentTemplateGallery` page into a single "Store" surface. External-integration
imperative services per the ADR-031 exception; write actions stay behind their existing guarded
endpoints (ADR-023).

## MODIFIED Requirements

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

## ADDED Requirements

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

## Non-Functional Requirements

- **Performance:** Search MUST stay a single round-trip class of operation with the existing per-search
  cache TTL; indexing a second topic MUST NOT change the 200-always, never-5xx contract of the search
  endpoint.
- **Accessibility:** The kind filter MUST be a labelled control (`NcSelect` `inputLabel` or an
  accessible segmented control), WCAG 2.1 AA (ADR-004).
- **Internationalization:** Dutch (`nl_NL`) and English (`en_US`) MUST be provided for all new Store
  strings (page title, kind filter, skill publish/install labels) (ADR-005).

## Acceptance Criteria

- [ ] Store search returns kind-tagged cards from both `topic:hermiq-agent-template` and
  `topic:hermiq-skill`, filterable by kind.
- [ ] A GitHub skill install lands quarantined + scanned via `installFromSource(source: 'hub')`.
- [ ] The `AgentTemplateGallery` page, `/agent-templates` route, and "Agent templates" menu item are
  removed; in-app references repoint to the Store page.
- [ ] Existing agent-template search/install/publish behaviour is unchanged (regression-verified).

## Notes
- Reuses `GitHubTemplateCatalogService`/`GitHubTemplatePushService` generalised with a kind seam; the
  broker/fail-closed/validate-coordinates invariants are unchanged.
- Skill publish provenance is specified in the `skills-marketplace` delta of this change.
