# Skills Marketplace Specification

**Status**: active (Hermiq surface live-verified; content-scan + a live hub are OpenRegister/OpenConnector seams; GitHub publish live alongside hub publish) — republish/export delta in-progress via `skill-self-improvement`

**Feature tier**: V2

**OpenSpec changes:** `skills-marketplace` — DONE: `Skill` schema gains `quarantined` state + `source`/`quarantineReason`/`lastActivityAt`/`staleSince`/`archivedAt`; `SkillMarketplaceService` (installFromSource → quarantine; approveQuarantined review gate → active; age-based `curate()` active→stale→archived that NEVER hard-deletes; publishToHub via SkillSerializer + OpenConnector CallService, structured error when unavailable); `SkillCuratorTask` (daily TimedJob); `SkillMarketplaceController` + routes; Skills UI gains a quarantine badge + Approve + Publish + "Install from hub (quarantine)". The content **security scan** (OR has no scanner — SecurityService is auth rate-limiting) is a documented seam realised as the review gate; a live external **hub** needs an OpenConnector connector; usage-based staleness needs OR run-loop last-used stamping.
`hermiq-github-store` — DONE: adds GitHub publish for skills (generalise `GitHubTemplatePushService` to push a Skill's agentskills.io package to a `topic:hermiq-skill` repo, stamping `githubOwner`/`githubRepo`/`publishedAt`); the OpenConnector `publishToHub` path stays secondary.
`skill-self-improvement` — in-progress (delta): the GitHub publish requirement gains (a) a REPUBLISH carve-out — an update-mode push allowed ONLY to the skill's own provenance-stamped `githubOwner`/`githubRepo`, same publish action authorization, never automatic; and (b) an export-policy refinement — publish AND republish (both routes) ship `files['learnings.md']` but STRIP `files['learning-candidates.md']` (unvetted observations never leave the instance). See `openspec/changes/skill-self-improvement/specs/skills-marketplace/spec.md`.

## Purpose

Extends the skills catalog (V1) to let organisations share skills across their own tenants and
publish to/consume from external hubs (ClawHub, skills.sh) in the agentskills.io format. Every
inbound skill passes through quarantine and a security scan before activation, and a background
Curator job manages the skill lifecycle without ever hard-deleting a skill.
## Requirements
### Requirement: Quarantine + security scan on install
The system MUST place any skill installed from another organisation or an external hub into a
quarantine state and MUST run OR's `SecurityService` scan on it before the skill can transition to
`active`.

#### Scenario: An org installs a skill published by another tenant
- GIVEN organisation B publishes a skill to the shared marketplace
- WHEN organisation A installs that skill
- THEN the system MUST place the installed skill in a quarantine state
- AND the skill MUST NOT become usable by an agent until OR's `SecurityService` scan completes and passes

### Requirement: Curator manages lifecycle without hard-delete
The system MUST run a background Curator job that transitions skills through active → stale →
archived based on usage/age, and MUST NOT hard-delete a skill at any point in that lifecycle.

#### Scenario: A skill goes unused past the staleness threshold
- GIVEN a `Skill` object has not been used by any agent run for longer than the configured staleness threshold
- WHEN the Curator background job runs
- THEN the system MUST transition the skill's state from `active` to `stale`
- AND the system MUST NOT delete the skill or its underlying object/files

### Requirement: Publish to and consume from external hubs
The system MUST support publishing a locally-authored skill to an external hub (ClawHub, skills.sh)
and importing a skill from those hubs, both in agentskills.io format, reusing the `SkillSerializer`
from the skills-catalog spec.

#### Scenario: A user publishes a local skill to an external hub
- GIVEN a locally-authored `Skill` object in `active` state
- WHEN the user chooses to publish it to an external hub
- THEN the system MUST serialize it to agentskills.io format via `SkillSerializer`
- AND the system MUST submit it to the selected hub's publish endpoint

### Requirement: Approving a quarantined skill requires action authorization
The system MUST require the caller to hold the `skill.approve-quarantined` action (via
`ActionAuthService::requireAction()`) before transitioning a `quarantined` skill towards
`active`. A caller without the action MUST receive `403 Forbidden` and the skill MUST
remain unchanged.

#### Scenario: A non-admin tenant member attempts to approve a quarantined skill
- **GIVEN** a `quarantined` `Skill` and a caller whose groups are not mapped to
  `skill.approve-quarantined` in the action matrix
- **WHEN** the caller calls `POST /api/skills/{id}/approve`
- **THEN** the system MUST respond `403 Forbidden`
- **AND** the skill's `state` MUST remain `quarantined`

### Requirement: Overriding a dangerous scan verdict requires a stricter action
The system MUST require the caller to additionally hold the `skill.override-scan-verdict`
action before applying `force=true` to a skill whose content-scan verdict is `dangerous`.
Holding `skill.approve-quarantined` alone MUST NOT be sufficient to override a dangerous
verdict.

#### Scenario: A caller with approve rights but not override rights forces a dangerous skill
- **GIVEN** a `quarantined` `Skill` with a `dangerous` scan verdict
- **AND** a caller granted `skill.approve-quarantined` but not `skill.override-scan-verdict`
- **WHEN** the caller calls `POST /api/skills/{id}/approve` with `force=true`
- **THEN** the system MUST respond `403 Forbidden`
- **AND** the skill's `state` MUST remain `quarantined`

#### Scenario: An admin overrides a dangerous scan verdict
- **GIVEN** a `quarantined` `Skill` with a `dangerous` scan verdict
- **AND** an instance admin caller
- **WHEN** the admin calls `POST /api/skills/{id}/approve` with `force=true`
- **THEN** the system MUST transition the skill's `state` to `active`

### Requirement: Publishing a skill to a hub requires action authorization
The system MUST require the caller to hold the `skill.publish-hub` action before submitting
a skill to an external hub via OpenConnector.

#### Scenario: A non-admin tenant member attempts to publish a skill
- **GIVEN** a caller whose groups are not mapped to `skill.publish-hub`
- **WHEN** the caller calls `POST /api/skills/{id}/publish`
- **THEN** the system MUST respond `403 Forbidden`
- **AND** no outbound OpenConnector call MUST be made

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

### Requirement: A locally-authored skill can be installed through the quarantine gate

The system MUST accept `source: "local"` on the install-from-source quarantine path
(`SkillMarketplaceController::installFromSource` → `SkillMarketplaceService::installFromSource`)
so a skill authored inside this instance (e.g. via the chat "Save as skill" seam) is landed
`quarantined`, content-scanned via OpenRegister `ContentScanService`, and recorded with honest
`source` `local` provenance. `local` is already a valid value of the `Skill` schema's `source`
enum (`["local","org","hub"]`), so this MUST require no schema change — only the controller's
existing `org`/`hub` source whitelist is relaxed to also accept `local`. The quarantine
invariant MUST hold unchanged: a skill installed through this path MUST NOT be `active`, and
MUST require the existing action-gated Approve (`skill.approve-quarantined`) before an agent
can use it.

#### Scenario: A chat-authored skill lands quarantined with local provenance

- GIVEN a user has reviewed a chat-produced SKILL.md in the authoring modal
- WHEN the seam installs it through `installFromSource` with `source: "local"`
- THEN the resulting `Skill` MUST have `state` `quarantined` and `source` `local`
- AND `ContentScanService` MUST have run over its body + frontmatter, recording a `scanReport`
- AND the skill MUST NOT be usable by an agent until it is Approved

#### Scenario: An unknown source value still defaults safely

- GIVEN a caller passes a `source` value that is not one of `local`, `org`, or `hub`
- WHEN `installFromSource` runs
- THEN the source MUST default to `hub` (the existing safe fallback), and the skill MUST
  still land `quarantined`

## User Stories

- As an org admin, I want to share skills across tenants within my organisation so that teams don't duplicate work.
- As a skill author, I want to publish my skill to ClawHub or skills.sh so that the wider community can use it.
- As a security reviewer, I want every externally-sourced skill quarantined and scanned before it can run so that malicious skills can't execute unchecked.
- As a platform operator, I want stale skills archived rather than deleted so that historical agent configurations remain reconstructable.

## Acceptance Criteria

- [ ] Skills installed from another org or external hub start in a quarantine state
- [ ] OR `SecurityService` scan must pass before a quarantined skill can become `active`
- [ ] Curator background job transitions active→stale→archived on a schedule
- [ ] No code path hard-deletes a `Skill` object
- [ ] Publish/import to at least one external hub (ClawHub or skills.sh) works via `SkillSerializer`

## Notes

Depends on `skills-catalog` (V1) for the base `Skill`/`SkillSource` schemas and `SkillSerializer`, and
on OpenRegister's `SecurityService`. Related: ADR-003 (memory & skills as OR objects). External hub
API contracts (ClawHub, skills.sh) are unconfirmed and need research before moving to `planned`.
