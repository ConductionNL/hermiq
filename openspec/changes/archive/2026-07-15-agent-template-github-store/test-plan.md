# Test Plan: agent-template-github-store

## Test Cases

### TC-1: Default GitHub search renders tagged repo cards
- **spec_ref**: `openspec/changes/agent-template-github-store/specs/agent-template-github-store/spec.md#requirement-the-system-must-provide-a-server-backed-search-for-hermiq-agent-template-repos`
- **type**: api
- **persona**: N/A
- **preconditions**: A `github`-tagged test fixture repo (or a stubbed GitHub client) exists
- **steps**: `GET /api/agent-templates/github/search` with no `q` param
- **expected result**: 200 with `cards` containing normalised owner/repo/name/description/version/stars fields; no raw GitHub body in the response
- **test command**: /test-api

### TC-2: Free-text term narrows search results
- **spec_ref**: `...spec.md#requirement-the-system-must-provide-a-server-backed-search-for-hermiq-agent-template-repos`
- **type**: functional
- **persona**: Priya (ZZP Developer/Integrator)
- **preconditions**: The GitHub store tab is open with the default result set loaded
- **steps**: Type a term into the search box; wait for debounce
- **expected result**: The result grid updates to the narrowed set matching the term
- **test command**: /test-functional

### TC-3: Rate-limited search shows the credential hint, not an error page
- **spec_ref**: `...spec.md#requirement-the-system-must-degrade-gracefully-when-github-is-rate-limited-or-unreachable`
- **type**: functional
- **persona**: Mark (MKB Software Vendor)
- **preconditions**: No `github`-provider credential configured; GitHub anonymous search returns 403/429 (simulated)
- **steps**: Open the GitHub store tab
- **expected result**: An `NcNoteCard` hint appears suggesting a GitHub credential; the page does not error out; `cards` is empty
- **test command**: /test-functional

### TC-4: A broker credential upgrades the search call
- **spec_ref**: `...spec.md#requirement-the-system-must-degrade-gracefully-when-github-is-rate-limited-or-unreachable`
- **type**: api
- **persona**: N/A
- **preconditions**: A `github`-provider credential exists and is allowed for hermiq
- **steps**: `GET /api/agent-templates/github/search?credentialId=<uuid>`
- **expected result**: Response reports `brokerUsed: true`
- **test command**: /test-api

### TC-5: Installing a GitHub template lands it quarantined and content-scanned
- **spec_ref**: `...spec.md#requirement-the-system-must-install-a-discovered-template-through-the-existing-quarantine-gate`
- **type**: api
- **persona**: N/A
- **preconditions**: A fetchable repo carries a valid template package file
- **steps**: `POST /api/agent-templates/github/install` with `{owner, repo}`
- **expected result**: 201 with the created `AgentTemplate` at `state: "quarantined"` and a populated `scanReport`
- **test command**: /test-api

### TC-6: A dangerous scan verdict still blocks one-click approval on a GitHub install
- **spec_ref**: `...spec.md#requirement-the-system-must-install-a-discovered-template-through-the-existing-quarantine-gate`
- **type**: security
- **persona**: Noor (Municipal CISO)
- **preconditions**: A GitHub-installed template's `systemPrompt` contains a dangerous-pattern trigger
- **steps**: `POST /api/agent-templates/{id}/approve` without `force`
- **expected result**: 409 with `scanReport`/`quarantineReason`, template remains quarantined
- **test command**: /test-security

### TC-7: Invalid owner/repo is rejected before any outbound GitHub call
- **spec_ref**: `...spec.md#requirement-the-system-must-validate-repo-coordinates-before-any-github-call`
- **type**: security
- **persona**: N/A
- **preconditions**: None
- **steps**: `POST /api/agent-templates/github/install` with `owner` containing `../` or shell metacharacters
- **expected result**: 400 `invalid_repo`; no outbound HTTP call is made (assert via mock/log absence)
- **test command**: /test-security

### TC-8: Publish creates a new tagged repo and returns repo URL + commit SHA
- **spec_ref**: `...spec.md#requirement-the-system-must-let-a-template-owner-publish-it-to-a-new-tagged-github-repository`
- **type**: api
- **persona**: N/A
- **preconditions**: Caller owns an active `AgentTemplate`; a broker `github` credential is configured; target repo does not exist
- **steps**: `POST /api/agent-templates/{id}/publish-github` with `{owner, repo, visibility, credentialId}`
- **expected result**: 201 with `{repoUrl, commitSha}`; the committed file round-trips through `AgentTemplateSerializer::fromPackage()` back to the same portable fields
- **test command**: /test-api

### TC-9: Publish without the broker fails closed
- **spec_ref**: `...spec.md#requirement-the-system-must-never-hold-or-log-the-github-token`
- **type**: security
- **persona**: Noor (Municipal CISO)
- **preconditions**: `CredentialBrokerService` class is not resolvable (broker absent)
- **steps**: `POST /api/agent-templates/{id}/publish-github`
- **expected result**: Refused with a clear "broker required" error; no GitHub call attempted (assert via log/mock absence)
- **test command**: /test-security

### TC-10: Broker call failure never logs the token or body
- **spec_ref**: `...spec.md#requirement-the-system-must-never-hold-or-log-the-github-token`
- **type**: security
- **persona**: Noor (Municipal CISO)
- **preconditions**: Broker call configured to fail (simulated 500)
- **steps**: Trigger a publish/install/search call that fails at the broker
- **expected result**: The resulting log entry contains only HTTP method + path, never the request body or a token-shaped string (`ghp_...`, `gho_...`, etc.)
- **test command**: /test-security

### TC-11: Publishing to an existing repo is refused
- **spec_ref**: `...spec.md#requirement-the-system-must-refuse-to-overwrite-an-existing-github-repository`
- **type**: api
- **persona**: N/A
- **preconditions**: Target `owner/repo` already exists (simulated 200 on `GET /repos/{owner}/{repo}`)
- **steps**: `POST /api/agent-templates/{id}/publish-github` targeting that repo
- **expected result**: Refused with an "already exists" error; no create-repo call is made
- **test command**: /test-api

### TC-12: Successful publish records provenance without leaking it into the package
- **spec_ref**: `...spec.md#requirement-the-system-must-record-github-publish-provenance-without-leaking-it-into-packages`
- **type**: functional
- **persona**: Mark (MKB Software Vendor)
- **preconditions**: A publish succeeds
- **steps**: Re-fetch the template (`GET /api/agent-templates/{id}`); export it (`GET /api/agent-templates/{id}/export`)
- **expected result**: The re-fetched template shows `githubOwner`/`githubRepo`/`publishedAt`; the exported package JSON contains none of those three keys
- **test command**: /test-functional

### TC-13: A caller cannot publish a template from another organisation
- **spec_ref**: `...spec.md#requirement-the-system-must-scope-publish-to-templates-the-caller-can-already-see`
- **type**: security
- **persona**: Noor (Municipal CISO)
- **preconditions**: `AgentTemplate` belongs to org A; caller is only a member of org B
- **steps**: `POST /api/agent-templates/{id}/publish-github` as the org-B caller
- **expected result**: 404 (mirrors `show()`/`update()`'s existing tenant-scoping — never a 403 that would confirm existence)
- **test command**: /test-security

### TC-14: GitHub store tab is keyboard/screen-reader accessible
- **spec_ref**: `...spec.md#non-functional-requirements`
- **type**: accessibility
- **persona**: Henk (Elderly Citizen)
- **preconditions**: The GitHub store tab is rendered
- **steps**: Tab through the search field, result cards, and publish form using only the keyboard
- **expected result**: The search field has an explicit label; no bare `<label>`+`NcSelect` pairing; focus order is logical
- **test command**: /test-accessibility

## Coverage Summary
- REQ "server-backed search" — TC-1, TC-2 (covered)
- REQ "graceful degradation" — TC-3, TC-4 (covered)
- REQ "install through existing quarantine gate" — TC-5, TC-6 (covered)
- REQ "validate repo coordinates" — TC-7 (covered)
- REQ "publish creates tagged repo" — TC-8 (covered)
- REQ "never hold/log the token" — TC-9, TC-10 (covered)
- REQ "refuse to overwrite existing repo" — TC-11 (covered)
- REQ "record provenance without leaking into package" — TC-12 (covered)
- REQ "scope publish to visible templates" — TC-13 (covered)
- Non-functional accessibility — TC-14 (covered)

## Out of Scope
- Load/performance testing of GitHub's own API (outside Hermiq's control) — only the 60s
  server-side cache behaviour is verified (TC-1 repeat-call assertion, folded into unit tests, not
  a separate performance TC).
- End-to-end testing against the real `api.github.com` (network-dependent, flaky in CI) — all
  cases above test against a stubbed/mocked GitHub client or broker, consistent with how
  `GitHubTemplateCatalogServiceTest`/`GitHubTemplatePushServiceTest` unit-test the OpenBuild
  originals.
