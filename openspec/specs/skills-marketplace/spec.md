# Skills Marketplace Specification

**Status**: active (Hermiq surface live-verified; content-scan + a live hub are OpenRegister/OpenConnector seams; GitHub publish live alongside hub publish; republish carve-out + learning-candidates export strip shipped via `skill-self-improvement`)

**Feature tier**: V2

**OpenSpec changes:** `skills-marketplace` — DONE: `Skill` schema gains `quarantined` state + `source`/`quarantineReason`/`lastActivityAt`/`staleSince`/`archivedAt`; `SkillMarketplaceService` (installFromSource → quarantine; approveQuarantined review gate → active; age-based `curate()` active→stale→archived that NEVER hard-deletes; publishToHub via SkillSerializer + OpenConnector CallService, structured error when unavailable); `SkillCuratorTask` (daily TimedJob); `SkillMarketplaceController` + routes; Skills UI gains a quarantine badge + Approve + Publish + "Install from hub (quarantine)". The content **security scan** (OR has no scanner — SecurityService is auth rate-limiting) is a documented seam realised as the review gate; a live external **hub** needs an OpenConnector connector; usage-based staleness needs OR run-loop last-used stamping.
`hermiq-github-store` — DONE: adds GitHub publish for skills (generalise `GitHubTemplatePushService` to push a Skill's agentskills.io package to a `topic:hermiq-skill` repo, stamping `githubOwner`/`githubRepo`/`publishedAt`); the OpenConnector `publishToHub` path stays secondary.
`skill-self-improvement` — DONE (archived 2026-07-27; delta): the GitHub publish requirement gains (a) a REPUBLISH carve-out — an update-mode push allowed ONLY to the skill's own provenance-stamped `githubOwner`/`githubRepo`, same publish action authorization, never automatic; and (b) an export-policy refinement — publish AND republish (both routes) ship `files['learnings.md']` but STRIP `files['learning-candidates.md']` (unvetted observations never leave the instance). See `openspec/changes/archive/2026-07-27-skill-self-improvement/specs/skills-marketplace/spec.md`.
`skill-source-identity-schema` — IN PROGRESS: `Skill` gains `sourceUrl` (canonical origin, no git ref) and `sourceUpdatedAt` (last refresh FROM source, deliberately distinct from `publishedAt`, which is empty on an instance that only installs).
`skill-install-idempotency` — IN PROGRESS: installing a skill already present UPDATES it instead of duplicating it (match on `sourceUrl`, one-time name fallback, mirror hosts normalised); content is replaced but curation (`maturityLevel`/`targetLevel`/`levelEvidence`/`installedOn`) is not; any content change RE-QUARANTINES; local `learnings.md` is never overwritten when local learnings postdate the last sync.

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

@e2e exclude legacy skills-marketplace scenario predating this wave (swept into diff
scope by the skill-self-improvement publish delta only); no committed Playwright
fixture provisions a second organisation — covered by
SkillMarketplaceServiceTest::testInstallFromSourceQuarantines and
testInstallRecordsDangerousScanReport.

#### Scenario: An org installs a skill published by another tenant
- GIVEN organisation B publishes a skill to the shared marketplace
- WHEN organisation A installs that skill
- THEN the system MUST place the installed skill in a quarantine state
- AND the skill MUST NOT become usable by an agent until OR's `SecurityService` scan completes and passes

### Requirement: Curator manages lifecycle without hard-delete
The system MUST run a background Curator job that transitions skills through active → stale →
archived based on usage/age, and MUST NOT hard-delete a skill at any point in that lifecycle.

@e2e exclude legacy scenario predating this wave: a daily background-job transition
with no browser trigger — covered by
SkillMarketplaceServiceTest::testCurateTransitionsAndNeverDeletes.

#### Scenario: A skill goes unused past the staleness threshold
- GIVEN a `Skill` object has not been used by any agent run for longer than the configured staleness threshold
- WHEN the Curator background job runs
- THEN the system MUST transition the skill's state from `active` to `stale`
- AND the system MUST NOT delete the skill or its underlying object/files

### Requirement: Publish to and consume from external hubs
The system MUST support publishing a locally-authored skill to an external hub (ClawHub, skills.sh)
and importing a skill from those hubs, both in agentskills.io format, reusing the `SkillSerializer`
from the skills-catalog spec.

@e2e exclude legacy scenario predating this wave: no live external hub exists in any
test environment (the OpenConnector hub route is a documented seam) — the structured
no-connector error path is covered by
SkillMarketplaceServiceTest::testPublishStructuredErrorWithoutConnector.

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

@e2e exclude legacy scenario predating this wave: the committed Playwright fixture
authenticates as admin only (admin always passes the ADR-023 matrix), so a browser
403 cannot be produced — covered by
SkillMarketplaceControllerTest::testApproveForbiddenForNonAdmin.

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

@e2e exclude legacy scenarios predating this wave: producing a dangerous-verdict
quarantined skill plus differently-granted callers needs fixtures no committed
Playwright suite provisions — covered by
SkillMarketplaceControllerTest::testApproveForceForbiddenWithoutOverrideAction and
testApproveForceSucceedsWithOverrideAction.

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

@e2e exclude legacy scenario predating this wave: the admin-only Playwright fixture
cannot produce an action-matrix 403 — covered by
SkillMarketplaceControllerTest::testPublishForbiddenForNonAdmin.

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
MUST refuse to overwrite an existing repository — with exactly ONE carve-out: a REPUBLISH of the
same skill to the exact `githubOwner`/`githubRepo` already stamped on that skill's provenance
MUST be allowed as an update-mode push, and publishing to any OTHER existing repository MUST
still be refused — and MUST never hold or log the GitHub token. Republish MUST require the same
publish action authorization as first publish, MUST be triggered only by an explicit user action
(the system MUST NEVER republish automatically), and MUST restamp `publishedAt` on success. The
file selection committed by publish AND republish MUST include `files['learnings.md']` when
present and MUST strip `files['learning-candidates.md']` — unvetted observations never leave the
instance; the strip is file SELECTION at export time and MUST NOT alter `SkillSerializer`'s
byte-for-byte round-trip of the files it does emit. Publish MUST be scoped to skills the caller
can already see — a skill outside the caller's tenant visibility MUST yield a 404 (never a 403
that confirms existence), matching template publish. The OpenConnector `publishToHub` path MUST
remain available as the secondary publish route.
<!-- Previous behavior: publish refused to overwrite ANY existing repository with no republish
     carve-out, and the exported file selection was not constrained — learning-candidates.md did
     not exist as a concept before skill-learnings/skill-self-improvement. -->

#### Scenario: Publishing a skill creates a tagged repo and stamps provenance
- GIVEN an authenticated user who can see `Skill` X and has an allowed `github` broker credential
- WHEN they publish `Skill` X to owner/repo `YOUR_OWNER_HERE/hermiq-skill-example` (visibility private)
- THEN the system exports the skill via `SkillSerializer`, creates the repo, tags it
  `topic:hermiq-skill`, and commits the agentskills.io package
- AND `Skill` X is updated with `githubOwner`, `githubRepo`, and `publishedAt` set
- AND the GitHub token is never held or logged by Hermiq

@e2e exclude no test environment performs real outbound GitHub calls (repo creation,
topic tagging), so the happy path cannot be asserted in a browser — covered by
SkillMarketplaceControllerTest::testGithubPublishSucceedsAndStampsProvenance and the
GitHubTemplatePushServiceTest suite.

#### Scenario: Publish fails closed when the broker is unavailable
- GIVEN the OpenRegister credential broker is not available
- WHEN a user attempts to publish a skill to GitHub
- THEN the publish is refused (503) and no token-bearing fallback is attempted

@e2e exclude broker unavailability cannot be staged from the committed Playwright
suite — fail-closed posture covered by
SkillMarketplaceControllerTest::testGithubPublishFailsClosedWhenBrokerUnavailable.

#### Scenario: Publish refuses to overwrite an existing repository
- GIVEN the target owner/repo already exists on GitHub
- AND it is NOT the repo stamped on this skill's own `githubOwner`/`githubRepo` provenance
- WHEN a user attempts to publish a skill to it
- THEN the publish is refused and no commit is made, exactly as template publish refuses

#### Scenario: Republishing to the skill's own provenance repo updates it
- GIVEN `Skill` X was previously published and carries `githubOwner`/`githubRepo`/`publishedAt`
- AND an accepted new version postdates `publishedAt`
- WHEN a caller holding the publish action authorization triggers Republish
- THEN the system MUST push the current active version to exactly that provenance repo
  (update mode) and restamp `publishedAt`
- AND a republish attempt targeting any other owner/repo MUST be refused

#### Scenario: Republish never happens automatically
- GIVEN a published skill whose draft was just accepted (local version now newer)
- WHEN no user triggers the Republish action
- THEN the system MUST make no outbound GitHub call for that skill, indefinitely

#### Scenario: The committed package ships learnings but never learning-candidates
- GIVEN a skill whose `files` map contains both `learnings.md` and `learning-candidates.md`
- WHEN it is published or republished to GitHub
- THEN the committed package MUST contain `learnings.md`
- AND MUST NOT contain `learning-candidates.md`
- AND the files that ARE emitted MUST round-trip byte-for-byte per the serializer contract

#### Scenario: Publishing a skill outside the caller's visibility is a 404
- GIVEN a `Skill` that is not visible to the caller's tenant
- WHEN the caller attempts to publish it to GitHub
- THEN the system returns 404 (never a 403), and makes no outbound GitHub call

@e2e exclude no committed hermiq Playwright fixture provisions a second tenant, so
the cross-tenant 404 cannot be produced in a browser — covered by
SkillMarketplaceControllerTest::testGithubPublishSkillOutsideVisibilityIsNotFound.

#### Scenario: The provenance fields are never emitted into the committed package
- GIVEN a skill being published to GitHub
- WHEN `SkillSerializer` produces the agentskills.io package that is committed
- THEN the package MUST NOT contain `githubOwner`, `githubRepo`, or `publishedAt` — those are stamped on
  the `Skill` object only, mirroring `AgentTemplateSerializer::toPackage()` never emitting provenance

@e2e exclude the committed package's byte content is a serializer property no browser
assertion can observe — covered by the SkillSerializerTest suite (the serializer
emits only frontmatter/body/files; provenance fields are never part of the package
shape) and SkillServiceTest::testPublishFileSelectionStripsCandidatesAndKeepsLearnings.

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

@e2e exclude legacy scenarios predating this wave: the local-source quarantine landing
and the unknown-source fallback are controller-level whitelist rules — covered by
SkillMarketplaceControllerTest::testInstallFromSourceAcceptsLocalSource and
testInstallFromSourceUnknownSourceDefaultsToHub.

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

### Requirement: A skill bundle MAY additionally carry agent definitions

A skill bundle repository (`hermiq-skills.json` + `skills/<name>/…`) MUST be extendable
with a sibling `agents[]` manifest section + `agents/<name>.json` files, so a virtual
app whose functionality lives in Hermiq AGENTS (reasoning personas with tools/prompts —
not documentation skills) can still be exported and reinstalled through the same
GitHub-backed bundle mechanism OpenBuild uses for its `skills` channel. This closes a
gap where an app's most operationally critical content (its agents) was invisible to
every export path: neither the connectors channel (OpenConnector-shaped) nor the
existing skills channel (SKILL.md-shaped) had anywhere to put an agent.

`SkillBundleSerializer::toBundle()`/`fromBundle()`/`agentsFromBundle()` MUST treat a
1.0 bundle (no `agents` key) as valid and agent-less — this is purely additive, and
`FORMAT_VERSION` only bumped its MINOR component (1.0 → 1.1) for exactly that reason.
`SkillBundleInstaller::installAgents()` MUST NOT overwrite an existing agent matched by
`name` — an agent's live-tuned prompt/tools are hand-authored content a silent
re-import must not clobber, unlike a skill (which the existing install path DOES
update). Publishing agents MUST go through the same modifiable-agent authorization
`collectPublishableAgentPayloads()` already applies to `update()` elsewhere on
`SkillController` — a caller without edit rights on an agent must not be able to
publish its prompt out from under its owner.

@e2e exclude bundle (de)serialization and idempotent-agent-upsert are unit-level
properties with no distinct UI surface beyond the existing bundle-publish/
bundle-install screens already covered — see SkillBundleSerializerTest below.

#### Scenario: A bundle with both skills and agents installs both

- GIVEN a GitHub repository whose `hermiq-skills.json` manifest declares both `skills[]`
  and `agents[]`, each with matching `skills/<name>/` or `agents/<name>.json` content
- WHEN `SkillBundleInstaller::installFromRepo()` runs
- THEN every skill MUST install through the unchanged per-skill quarantine path, AND
  every agent MUST be created (if no agent of that `name` already exists) via
  `ObjectService::saveObject()` against the `hermiq`/`agent` register/schema

#### Scenario: Reinstalling a bundle never overwrites an existing agent

- GIVEN an agent named "Hydra Triage" already exists on this instance, hand-tuned since
  its last import
- WHEN a bundle also declaring an agent named "Hydra Triage" is installed again
- THEN the existing agent's `prompt`/`tools`/other fields MUST be left unchanged, and the
  outcome MUST report `unchanged` for that agent, never `installed` or a silent overwrite

#### Scenario: A 1.0 bundle with no agents key still installs its skills

- GIVEN a bundle published before this requirement existed (no `agents` key in its manifest)
- WHEN it is installed
- THEN every skill MUST install exactly as before, and `agentsFromBundle()` MUST return an
  empty array rather than treating the bundle as unparseable

Covered by SkillBundleSerializerTest (testAgentRoundTripsThroughBundle,
testLegacyBundleWithNoAgentsKeyParsesAsAgentless,
testDuplicateAgentFileNameIsDroppedNotOverwritten). `SkillBundleInstaller`'s
idempotent-upsert behaviour (never overwriting an existing agent) has no
dedicated unit test yet — verified instead via the live clean-install cycle
this requirement was built to pass; a `SkillBundleInstallerTest` is follow-up
work, not fabricated here.

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
