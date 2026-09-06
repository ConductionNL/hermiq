# Skills Marketplace Specification

**Status**: in-progress
**Scope**: hermiq
**OpenSpec changes**:
- skills-marketplace
- skill-self-improvement
- skill-package-multifile

## Purpose

Governs how a skill enters and leaves a hermiq instance: quarantine and content scanning on install, curator lifecycle, publication to a tagged GitHub repository or an external hub, and the fidelity guarantees of the package format in both directions. This delta closes the asymmetry between the publish and install halves — publish has carried auxiliary files since `skill-self-improvement`; install discarded them.

## ADDED Requirements

### Requirement: A multi-file skill survives the install round trip intact
The system MUST persist auxiliary files supplied with an installed skill into the Skill's `files[]`, so that a skill published with auxiliary files installs with those same files. The system MUST NOT report a successful install while silently discarding auxiliary content.

#### Scenario: A skill published with reference files installs with them
- GIVEN a skill whose package carries `references/local-checks.md` and `learnings.md`
- WHEN a user installs that package
- THEN the persisted Skill MUST have exactly two `files[]` entries
- AND the entry named `references/local-checks.md` MUST have byte-identical content to the supplied file
- AND the entry named `learnings.md` MUST be present

#### Scenario: A single-file package still installs unchanged
- GIVEN a package consisting only of a `---` fenced frontmatter block and a body
- WHEN a user installs that package
- THEN the install MUST succeed
- AND the persisted Skill MUST have an empty `files[]`
- AND the frontmatter MUST round-trip byte-for-byte as it does today

#### Scenario: Frontmatter fidelity is preserved through the directory form
- GIVEN a skill package in directory form whose `SKILL.md` carries a frontmatter block with comments and non-canonical key ordering
- WHEN the package is parsed and then re-serialised
- THEN the emitted `SKILL.md` frontmatter MUST be byte-identical to the input
- AND the auxiliary file set MUST be unchanged in both names and contents

### Requirement: Auxiliary file paths are validated on install
The system MUST reject an auxiliary file whose name is absolute, contains a `.` or `..` or empty path segment, contains a backslash, or exceeds 200 characters. The system MUST reject the offending entry rather than rewriting it to a safe form, and MUST record that the entry was rejected.

#### Scenario: A traversal path is rejected, not sanitised
- GIVEN a package carrying an auxiliary file named `../../etc/passwd`
- WHEN a user installs that package
- THEN that entry MUST NOT appear in the persisted `files[]`
- AND the rejection MUST be logged
- AND no file MUST be written outside the Skill object

#### Scenario: A package of entirely unsafe paths still installs its body
- GIVEN a package whose every auxiliary entry has an unsafe name
- WHEN a user installs that package
- THEN the install MUST succeed
- AND the persisted Skill MUST have an empty `files[]`
- AND the Skill's body and frontmatter MUST be persisted unchanged

#### Scenario: A nested path within bounds is accepted
- GIVEN a package carrying an auxiliary file named `references/persona.md`
- WHEN a user installs that package
- THEN the entry MUST be persisted with its name preserved exactly, including the `/` separator

## MODIFIED Requirements

### Requirement: Quarantine + security scan on install
The system MUST place any skill installed from another organisation or an external hub into a
quarantine state and MUST run OR's `SecurityService` scan on it before the skill can transition to
`active`. The scanned material MUST include the content of every accepted auxiliary file in addition
to the body and frontmatter, because an auxiliary file referenced by the body becomes agent
instruction material and would otherwise be an unscanned bypass of this gate.

<!-- Previous behavior: the scan covered only `body` + `frontmatter`; auxiliary files were never
     persisted, so there was nothing to scan. With `files[]` now populated on install, restricting
     the scan to the body would let a dangerous payload evade the gate by moving into
     `references/`. -->

@e2e exclude legacy skills-marketplace scenario predating this wave (swept into diff
scope by the skill-self-improvement publish delta only); no committed Playwright
fixture provisions a second organisation — covered by
SkillMarketplaceServiceTest::testInstallFromSourceQuarantines,
testInstallRecordsDangerousScanReport and testAuxFileContentIsScanned.

#### Scenario: An org installs a skill published by another tenant
- GIVEN organisation B publishes a skill to the shared marketplace
- WHEN organisation A installs that skill
- THEN the system MUST place the installed skill in a quarantine state
- AND the skill MUST NOT become usable by an agent until OR's `SecurityService` scan completes and passes

#### Scenario: A dangerous payload hidden in an auxiliary file is caught
- GIVEN a package whose body is benign but whose `references/steps.md` carries a dangerous pattern
- WHEN a user installs that package
- THEN the scan report MUST reflect the dangerous verdict
- AND the skill MUST land quarantined
- AND one-click approval MUST remain blocked until the stricter override is used

## Non-Functional Requirements

- **Performance:** Installing a skill with up to 64 auxiliary files MUST complete within the same request budget as a single-file install; no per-file network or filesystem round trip is permitted during parse.
- **Accessibility:** No new UI surface — no WCAG impact.
- **Internationalization:** Any new user-facing error or log string MUST be available in Dutch and English (ADR-005).

## Acceptance Criteria

- A skill with auxiliary files installs with those files populated in `files[]`
- A single-file package installs exactly as before, with `files[]` empty
- Frontmatter round-trips byte-for-byte through the directory form
- Unsafe auxiliary paths are dropped and logged, never sanitised into a safe form
- Auxiliary file content is included in the pre-quarantine content scan
- The five existing `SkillSerializerTest` cases pass unchanged

## Notes

- The publish half of this capability is already correct and is deliberately untouched: `GitHubTemplatePushService::publish()` accepts `auxFiles`, `isSafeRepoPath()` already permits nested paths, and `SkillService::publishFileSelection()` already applies the `learning-candidates.md` strip. This delta only brings install into line.
- The `path => contents` seam is chosen to match `GitHubTemplatePushService`'s tree shape and OpenBuild's `AppRepoSerializer::serialize()` return shape, so the follow-on bundle change and OpenBuild's `app-repo-format-v2` can compose without a further translation layer.
- ADR-068 §3 governs `learnings.md` travelling with a skill while `learning-candidates.md` does not; that selection stays on the publish side.
