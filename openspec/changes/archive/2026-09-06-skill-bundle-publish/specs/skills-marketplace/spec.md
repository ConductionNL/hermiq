# Skills Marketplace Specification

**Status**: in-progress
**Scope**: hermiq
**OpenSpec changes**:
- skills-marketplace
- skill-self-improvement
- skill-package-multifile
- skill-bundle-publish

## Purpose

Governs how a skill enters and leaves a hermiq instance. This delta adds the *bundle*: many skills published to and installed from a single repository, so a skill set can be shipped as one artefact. It deliberately grants no new trust — a bundle is a delivery mechanism, and every skill in it still passes the per-skill quarantine gate individually.

## ADDED Requirements

### Requirement: Many skills publish to a single repository
The system MUST be able to publish a set of skills to one repository, laying each skill out under `skills/<name>/` with its `SKILL.md` and its auxiliary files at their own relative paths, and MUST write a root `hermiq-skills.json` manifest describing the set.

#### Scenario: A set of skills publishes as one artefact
- GIVEN three skills, one of which carries auxiliary files
- WHEN the set is published to a bundle repository
- THEN the repository MUST contain `hermiq-skills.json` at its root
- AND each skill MUST appear under `skills/<name>/SKILL.md`
- AND the auxiliary files MUST appear under that same skill's directory at their original relative paths

#### Scenario: An existing bundle repository is updated, not refused
- GIVEN a bundle repository that already exists
- WHEN the same set is published again with a changed skill
- THEN the publish MUST succeed
- AND the response MUST report `created: false`
- AND paths outside `skills/` and `hermiq-skills.json` MUST be preserved

#### Scenario: A bundle repo is not mistaken for a single-skill repo
- GIVEN a published bundle repository
- WHEN the single-skill catalogue searches its own discovery topic
- THEN the bundle repository MUST NOT be returned as an installable single skill

### Requirement: A bundle installs as many individually-quarantined skills
The system MUST install every skill in a bundle through the same per-skill path an individual install uses: each skill MUST be content-scanned individually, and each MUST land in the quarantine state. The system MUST NOT offer a bulk approval that clears a bundle's skills without individual review.

#### Scenario: Installing a bundle quarantines every skill
- GIVEN a bundle containing three skills
- WHEN the bundle is installed
- THEN three skills MUST be persisted
- AND every one of them MUST be in the quarantine state
- AND each MUST carry its own scan report

#### Scenario: One dangerous skill does not contaminate or block the others
- GIVEN a bundle where exactly one skill carries a dangerous payload in an auxiliary file
- WHEN the bundle is installed
- THEN that skill MUST be recorded with a dangerous verdict
- AND the remaining skills MUST still install
- AND the response MUST identify which skill was flagged

#### Scenario: A partial failure is reported, not hidden
- GIVEN a bundle in which one entry cannot be parsed
- WHEN the bundle is installed
- THEN the response MUST report a non-zero `failed` count
- AND MUST list the per-skill outcome for every entry
- AND MUST NOT report overall success

### Requirement: Bundle entries are validated before use as paths
The system MUST validate each skill name from the bundle manifest as a kebab-case slug before using it as a directory component, and MUST reject any entry whose resolved path escapes its own `skills/<name>/` prefix. Rejection MUST drop the entry rather than rewrite it to a safe form.

#### Scenario: A crafted skill name cannot escape the bundle
- GIVEN a bundle manifest declaring a skill named `../../etc`
- WHEN the bundle is installed
- THEN that entry MUST be rejected
- AND the rejection MUST be logged
- AND no file MUST be written outside the skill objects

#### Scenario: A bundle bounded by size reports truncation
- GIVEN a bundle exceeding the per-bundle skill or byte bound
- WHEN it is installed
- THEN the response MUST report `truncated: true`
- AND MUST NOT silently present a partial install as complete

## Non-Functional Requirements

- **Performance:** A bundle install MUST NOT re-fetch a skill's auxiliary files more than once per skill, and MUST bound total fetched bytes.
- **Accessibility:** No new UI surface in this change — no WCAG impact.
- **Internationalization:** Any new user-facing string MUST be available in Dutch and English (ADR-005).

## Acceptance Criteria

- A set of skills publishes to one repository under `skills/<name>/`
- Re-publishing an existing bundle updates it and reports `created: false`
- Every skill from an installed bundle lands quarantined with its own scan report
- A dangerous skill in a bundle is flagged without blocking the rest
- A crafted skill name is rejected, not sanitised
- Single-skill publish and install behaviour is unchanged

## Notes

- The bundle layout intentionally mirrors hydra's on-disk `.claude/skills/<name>/` so a real skill directory round-trips without path rewriting.
- `SkillBundleSerializer` delegates to `SkillSerializer::toPackageFiles()`/`fromPackageFiles()` rather than restating the frontmatter-fidelity or path-safety rules — one implementation, one place to be correct.
- Destructive re-sync (removing a skill absent from the source set) is deliberately out of scope; publish is additive.
