# Skills Marketplace Specification (delta)

**Status**: in-progress (delta from change `skill-self-improvement`)
**Scope**: hermiq
**OpenSpec changes**:
- `skill-self-improvement`

## Purpose

Delta on the skills-marketplace GitHub publish requirement: (a) an explicit REPUBLISH
path for a skill whose accepted local version postdates its published copy — allowed
ONLY to the skill's own provenance-stamped repository, through the same publish action
authorization, never automatic; and (b) an export-policy refinement — the committed
agentskills.io package ships `files['learnings.md']` (a skill's vetted experience
travels with it, ADR-068 §3) but MUST strip `files['learning-candidates.md']` (unvetted
observations never leave the instance).

## MODIFIED Requirements

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

#### Scenario: Publish fails closed when the broker is unavailable
- GIVEN the OpenRegister credential broker is not available
- WHEN a user attempts to publish a skill to GitHub
- THEN the publish is refused (503) and no token-bearing fallback is attempted

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

#### Scenario: The provenance fields are never emitted into the committed package
- GIVEN a skill being published to GitHub
- WHEN `SkillSerializer` produces the agentskills.io package that is committed
- THEN the package MUST NOT contain `githubOwner`, `githubRepo`, or `publishedAt` — those are stamped on
  the `Skill` object only, mirroring `AgentTemplateSerializer::toPackage()` never emitting provenance

## Notes

- The behind-badge, publisher notification, and the Republish UI action are specified in
  the `skill-self-improvement` capability; this delta owns only the publish-path
  contract (carve-out + export file selection + authorization reuse).
- The OpenConnector `publishToHub` secondary route MUST apply the same
  `learning-candidates.md` strip (it exports through the same
  `SkillService::exportSkill()` selection).
