# Test Plan: hermiq-github-store

## Test Cases

### TC-1: Store search returns kind-tagged cards from both topics
- **spec_ref**: `openspec/changes/hermiq-github-store/specs/agent-template-github-store/spec.md#requirement-the-system-must-provide-a-server-backed-search-for-hermiq-agent-template-repos`
- **type**: api
- **preconditions**: An authenticated user; GitHub reachable (anonymous or broker credential).
- **steps**: Call the Store search endpoint with no query; then with a free-text term.
- **expected result**: 200 with cards from `topic:hermiq-agent-template` and `topic:hermiq-skill`, each
  carrying a `kind`; the term narrows results; never a 5xx.
- **test command**: /test-api

### TC-2: Kind filter restricts Store results to one kind
- **spec_ref**: `openspec/changes/hermiq-github-store/specs/agent-template-github-store/spec.md#requirement-the-system-must-provide-a-server-backed-search-for-hermiq-agent-template-repos`
- **type**: functional
- **preconditions**: Store page open with results of both kinds.
- **steps**: Select the "Skills" kind filter.
- **expected result**: Only `kind: "skill"` cards remain; template cards hidden.
- **test command**: /test-functional

### TC-3: Installing a GitHub skill lands it quarantined + scanned
- **spec_ref**: `openspec/changes/hermiq-github-store/specs/agent-template-github-store/spec.md#requirement-the-system-must-install-a-discovered-skill-through-the-skill-quarantine-gate`
- **type**: api
- **preconditions**: An installable skill card (valid `topic:hermiq-skill` repo with an agentskills.io package).
- **steps**: POST the skill install with owner/repo.
- **expected result**: A `Skill` is created with `state: "quarantined"` and a populated `scanReport`,
  identical in shape to an OpenConnector hub install; invalid coordinates return `400 invalid_repo` with
  no outbound call.
- **test command**: /test-api

### TC-4: A dangerous scan verdict blocks one-click approval of a GitHub skill
- **spec_ref**: `openspec/changes/hermiq-github-store/specs/agent-template-github-store/spec.md#requirement-the-system-must-install-a-discovered-skill-through-the-skill-quarantine-gate`
- **type**: security
- **preconditions**: A quarantined GitHub-installed skill whose content scans `dangerous`.
- **steps**: Attempt approve without `force`; then with the `skill.override-scan-verdict` action.
- **expected result**: Approval refused without `force`; only an authorised override (ADR-023) allows it.
- **test command**: /test-security

### TC-5: Publishing a skill creates a tagged repo and stamps provenance
- **spec_ref**: `openspec/changes/hermiq-github-store/specs/skills-marketplace/spec.md#requirement-a-skill-can-be-published-to-a-tagged-github-repository-as-the-primary-path`
- **type**: api
- **preconditions**: A visible `Skill`; an allowed `github` broker credential.
- **steps**: Publish the skill to `YOUR_OWNER_HERE/hermiq-skill-example` (visibility private).
- **expected result**: Repo created, tagged `topic:hermiq-skill`, agentskills.io package committed; the
  `Skill` gains `githubOwner`/`githubRepo`/`publishedAt`; the committed package contains none of the
  three provenance fields.
- **test command**: /test-api

### TC-6: Skill publish fails closed / refuses overwrite / scopes by visibility
- **spec_ref**: `openspec/changes/hermiq-github-store/specs/skills-marketplace/spec.md#requirement-a-skill-can-be-published-to-a-tagged-github-repository-as-the-primary-path`
- **type**: security
- **preconditions**: Broker unavailable; an existing target repo; a skill outside the caller's visibility.
- **steps**: Attempt publish in each condition.
- **expected result**: 503 when broker unavailable (no token fallback); refusal on existing repo;
  404 (never 403) for an out-of-visibility skill; token never held or logged.
- **test command**: /test-security

### TC-7: AgentTemplateGallery retirement leaves no dead route or menu item
- **spec_ref**: `openspec/changes/hermiq-github-store/specs/agent-template-github-store/spec.md#requirement-a-single-unified-store-page-replaces-the-agent-templates-gallery`
- **type**: functional
- **preconditions**: App loaded after this change.
- **steps**: Inspect the menu and router; trigger the agent-detail action that used to open the gallery.
- **expected result**: No "Agent templates" menu item, no `/agent-templates` route; the Store page is
  reachable; the repointed action opens the Store page.
- **test command**: /test-functional

### TC-8: Agent-template search/install/publish is unchanged (regression)
- **spec_ref**: `openspec/changes/hermiq-github-store/specs/agent-template-github-store/spec.md#requirement-the-system-must-provide-a-server-backed-search-for-hermiq-agent-template-repos`
- **type**: regression
- **preconditions**: The existing `agent-template-github-store` behaviour.
- **steps**: Re-run the archived agent-template search/install/publish flows through the Store page.
- **expected result**: Template discovery, quarantined install, and GitHub publish behave exactly as
  before the generalisation.
- **test command**: /test-regression

### TC-9: Store page accessibility
- **spec_ref**: `openspec/changes/hermiq-github-store/specs/agent-template-github-store/spec.md#requirement-a-single-unified-store-page-replaces-the-agent-templates-gallery`
- **type**: accessibility
- **preconditions**: Store page open.
- **steps**: Keyboard-navigate the kind filter and publish/install dialogs; run an axe scan.
- **expected result**: The kind filter and dialog inputs are labelled (WCAG 2.1 AA); no critical
  violations.
- **test command**: /test-accessibility

## Coverage Summary
- `agent-template-github-store` MODIFIED "server-backed search (both topics)" — TC-1, TC-2, TC-8.
- `agent-template-github-store` ADDED "unified Store page replaces the gallery" — TC-2, TC-7, TC-9.
- `agent-template-github-store` ADDED "install a discovered skill through the skill quarantine gate" —
  TC-3, TC-4.
- `skills-marketplace` ADDED "skill published to a tagged GitHub repository (primary)" — TC-5, TC-6.

## Out of Scope
- The head change's schema field re-import is verified in `hermiq-github-store-skill-schema` (its Task 2
  re-import check), not re-tested here.
- The OpenConnector `publishToHub` secondary path is unchanged and covered by the existing
  `skills-marketplace` scenarios.
