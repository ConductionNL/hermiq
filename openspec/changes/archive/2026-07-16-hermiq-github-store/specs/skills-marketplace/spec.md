# Skills Marketplace Specification

**Status**: in-progress
**Scope**: hermiq
**OpenSpec changes**:
- `hermiq-github-store` — adds GitHub as the primary skill-publish path (agentskills.io format),
  keeping the OpenConnector hub publish as secondary

## Purpose
Adds a GitHub publish path for `Skill` objects, mirroring the agent-template GitHub publish
(`agent-template-github-store`): a skill owner publishes a skill to a NEW repository tagged
`topic:hermiq-skill` in agentskills.io format via the generalised `GitHubTemplatePushService`, and the
publish stamps the skill's provenance fields (`githubOwner`/`githubRepo`/`publishedAt`, added by
`hermiq-github-store-skill-schema`). The existing OpenConnector `publishToHub` path
(`SkillMarketplaceController::publish`, action `skill.publish-hub`) is retained as the secondary route.
The GitHub token never reaches Hermiq (broker-mediated, fail-closed) — ADR-003/ADR-023.

## ADDED Requirements

### Requirement: A skill can be published to a tagged GitHub repository as the primary path
The system MUST let a skill owner publish an existing `Skill` to a NEW GitHub repository tagged
`topic:hermiq-skill`, committing the skill in agentskills.io format produced by `SkillSerializer`
(via `SkillService::exportSkill()`). Publish MUST reuse the broker-mediated, fail-closed
`GitHubTemplatePushService.push()` path: it MUST validate owner/repo coordinates before any GitHub call,
MUST refuse to overwrite an existing repository, and MUST never hold or log the GitHub token. Publish
MUST be scoped to skills the caller can already see — a skill outside the caller's tenant visibility
MUST yield a 404 (never a 403 that confirms existence), matching template publish. The OpenConnector
`publishToHub` path MUST remain available as the secondary publish route.

#### Scenario: Publishing a skill creates a tagged repo and stamps provenance
- GIVEN an authenticated user who can see `Skill` X and has an allowed `github` broker credential
- WHEN they publish `Skill` X to owner/repo `YOUR_OWNER_HERE/hermiq-skill-example` (visibility private)
- THEN the system exports the skill via `SkillSerializer`, creates the repo, tags it
  `topic:hermiq-skill`, and commits the agentskills.io package
- AND `Skill` X is updated with `githubOwner`, `githubRepo`, and `publishedAt` set
- AND the GitHub token is never held or logged by Hermiq

#### Scenario: Publish fails closed when the broker is unavailable
- GIVEN the OpenRegister credential broker is not available
- WHEN a user attempts to publish a skill to GitHub
- THEN the publish is refused (503) and no token-bearing fallback is attempted

#### Scenario: Publish refuses to overwrite an existing repository
- GIVEN the target owner/repo already exists on GitHub
- WHEN a user attempts to publish a skill to it
- THEN the publish is refused and no commit is made, exactly as template publish refuses

#### Scenario: Publishing a skill outside the caller's visibility is a 404
- GIVEN a `Skill` that is not visible to the caller's tenant
- WHEN the caller attempts to publish it to GitHub
- THEN the system returns 404 (never a 403), and makes no outbound GitHub call

#### Scenario: The provenance fields are never emitted into the committed package
- GIVEN a skill being published to GitHub
- WHEN `SkillSerializer` produces the agentskills.io package that is committed
- THEN the package MUST NOT contain `githubOwner`, `githubRepo`, or `publishedAt` — those are stamped on
  the `Skill` object only, mirroring `AgentTemplateSerializer::toPackage()` never emitting provenance

## Non-Functional Requirements

- **Performance:** Publish is a low-frequency, user-initiated multi-call GitHub operation; no per-request
  hot-path cost is added.
- **Accessibility:** The "Publish skill to GitHub" dialog MUST use labelled inputs (`NcSelect`
  `inputLabel`, `NcModal` in `src/modals/`), WCAG 2.1 AA (ADR-004).
- **Internationalization:** Dutch (`nl_NL`) and English (`en_US`) strings MUST be provided for the skill
  GitHub publish UI (ADR-005).

## Acceptance Criteria

- [ ] A skill can be published to a new `topic:hermiq-skill` GitHub repo in agentskills.io format.
- [ ] Publish stamps `githubOwner`/`githubRepo`/`publishedAt` on the `Skill`.
- [ ] Publish fails closed without the broker, refuses to overwrite, and never logs the token.
- [ ] A skill outside the caller's visibility returns 404 on publish.
- [ ] The committed agentskills.io package contains none of the three provenance fields.
- [ ] The OpenConnector `publishToHub` path still works as the secondary route.

## Notes
- Depends on `hermiq-github-store-skill-schema` (the three provenance fields) being live before the
  stamp write.
- The push service is the generalised `GitHubTemplatePushService`; the search/install/unified-page
  behaviour is specified in the `agent-template-github-store` delta of this change.
