# Tasks: skill-package-multifile

## Implementation Tasks

### Task 1: Directory-form package emit + parse on SkillSerializer
- **spec_ref**: `openspec/changes/skill-package-multifile/specs/skills-marketplace/spec.md#requirement-a-multi-file-skill-survives-the-install-round-trip-intact`
- **files**: `lib/Service/SkillSerializer.php`, `tests/Unit/Service/SkillSerializerTest.php`
- **notes**: Add `toPackageFiles(array $skill): array` and `fromPackageFiles(array $files): array`, both keyed `path => contents`. `fromPackageFiles()` MUST delegate the `SKILL.md` entry to the existing `fromPackage()` so the byte-for-byte frontmatter guarantee is inherited, not reimplemented. Do NOT modify `toPackage()` / `fromPackage()`.
- **acceptance_criteria**:
  - GIVEN a skill with frontmatter, body and two aux files WHEN serialised to directory form and parsed back THEN frontmatter and body are byte-identical and both aux files are recovered with names and contents intact
  - GIVEN a directory-form package with no entries other than `SKILL.md` WHEN parsed THEN `files` is an empty array
  - GIVEN the five existing SkillSerializerTest cases WHEN run THEN all pass unmodified
- [x] Implement
- [x] Test

### Task 2: Auxiliary path validation
- **spec_ref**: `openspec/changes/skill-package-multifile/specs/skills-marketplace/spec.md#requirement-auxiliary-file-paths-are-validated-on-install`
- **files**: `lib/Service/SkillSerializer.php`, `tests/Unit/Service/SkillSerializerTest.php`
- **notes**: Mirror `GitHubTemplatePushService::isSafeRepoPath()` — reject leading `/`, any `\`, any `.` / `..` / empty segment, length > 200. Reject-and-drop, never sanitise.
- **acceptance_criteria**:
  - GIVEN an aux entry named `../../etc/passwd` WHEN parsed THEN the entry is absent from `files` and the rejection is logged
  - GIVEN an aux entry named `references/persona.md` WHEN parsed THEN the name is preserved exactly including the separator
  - GIVEN a package whose every aux entry is unsafe WHEN parsed THEN parsing still yields the valid body and an empty `files`
- [x] Implement
- [x] Test

### Task 3: importSkill persists parsed files
- **spec_ref**: `openspec/changes/skill-package-multifile/specs/skills-marketplace/spec.md#requirement-a-multi-file-skill-survives-the-install-round-trip-intact`
- **files**: `lib/Service/SkillService.php`, `tests/Unit/Service/SkillServiceTest.php`
- **notes**: Replace the hardcoded `'files' => []` at `importSkill()` with the parsed set. `exportSkill()` and `publishFileSelection()` stay untouched.
- **acceptance_criteria**:
  - GIVEN a directory-form package WHEN imported THEN the persisted Skill carries the parsed `files[]`
  - GIVEN a bare string package WHEN imported THEN the persisted Skill carries an empty `files[]`
- [x] Implement
- [x] Test

### Task 4: installFromSource persists files and scans auxiliary content
- **spec_ref**: `openspec/changes/skill-package-multifile/specs/skills-marketplace/spec.md#requirement-quarantine--security-scan-on-install`
- **files**: `lib/Service/SkillMarketplaceService.php`, `tests/Unit/Service/SkillMarketplaceServiceTest.php`
- **notes**: Replace the hardcoded `'files' => []`. Extend `scanContent()` so accepted aux content is part of the scanned material — an aux file referenced by the body is agent instruction material and must not bypass the gate. Quarantine-always behaviour is unchanged.
- **acceptance_criteria**:
  - GIVEN a package whose body is benign but whose `references/steps.md` carries a dangerous pattern WHEN installed THEN the scan report records the dangerous verdict and the skill lands quarantined
  - GIVEN any package regardless of source WHEN installed THEN state is `quarantined`
  - GIVEN a dangerous aux verdict WHEN one-click approval is attempted THEN it remains blocked
- [x] Implement
- [x] Test

### Task 5: Install route accepts an optional files payload
- **spec_ref**: `openspec/changes/skill-package-multifile/contract.md`
- **files**: `lib/Controller/SkillMarketplaceController.php`, `tests/Unit/Controller/SkillMarketplaceControllerTest.php`
- **notes**: Optional `files` param. Absent → today's behaviour byte-for-byte. Present but non-array → 400. Unsafe entries drop without failing the request. Auth posture unchanged.
- **acceptance_criteria**:
  - GIVEN a request with no `files` WHEN posted THEN behaviour is identical to the current contract and `files` comes back `[]`
  - GIVEN `files` as a string WHEN posted THEN 400 with `files must be an array`
  - GIVEN a request with valid `files` WHEN posted THEN 200 and the response carries the persisted entries
- [x] Implement
- [x] Test

### Task 6: Seed fixtures and translation strings — NO CHANGE NEEDED
- **spec_ref**: `openspec/changes/skill-package-multifile/design.md#seed-data`
- **files**: none
- **outcome**: Both halves turned out to be already satisfied; adding either would have been noise.
  - **Seed fixtures**: `lib/Repair/SeedMaturityExampleSkills.php` already seeds `tender-summary` with four `files[]` entries (`references/exemption-grounds.md`, `examples/tender-summary-example.md`, `learnings.md`, `learning-candidates.md`) — the exact mixed asset + learnings shape design.md specified, plus `meeting-notes-cleanup` and `woo-request-triage` covering the single-file path. Three more near-identical fixtures would add nothing.
  - **i18n**: the new `files must be an array` string is a JSON API error. All three existing errors on this controller (`A non-empty package is required`, `Unauthenticated`, `Install failed`) are untranslated — the controller has no `IL10N` dependency at all. Translating one of four would break the convention, not honour ADR-007.
- [x] Implement
- [x] Test

### Task 6b: GitHub install must carry auxiliary files (found during Task 7)
- **spec_ref**: `openspec/changes/skill-package-multifile/specs/skills-marketplace/spec.md#requirement-a-multi-file-skill-survives-the-install-round-trip-intact`
- **files**: `lib/Service/GitHubTemplateCatalogService.php`, `lib/Controller/SkillController.php`, `tests/Unit/Controller/SkillControllerTest.php`
- **notes**: Not in the original plan. `githubInstall()` fetched only the package file and called `installFromSource()` without `auxFiles`, so fixing the serializer alone would have left the app-repo install path — the one buildiq-hydra depends on — still lossy. Added `fetchAuxFiles()` (tree walk, bounded 128 files / 4 MiB, truncation logged).
- **acceptance_criteria**:
  - GIVEN a published repo with auxiliary blobs WHEN installed via GitHub THEN the persisted skill carries them
  - GIVEN the fix is reverted THEN the new test fails (verified by mutation, not assumed)
- [x] Implement
- [x] Test

### Task 7: Playwright e2e — multi-file install round trip
- **spec_ref**: `openspec/changes/skill-package-multifile/specs/skills-marketplace/spec.md#requirement-a-multi-file-skill-survives-the-install-round-trip-intact`
- **files**: `tests/e2e/skill-multifile-install.spec.ts`
- **notes**: Drive the real install surface against a live instance, not a mock — API-green is not UI-green. Assert the installed skill's aux files are visible in the UI, not merely present in the API response. Pin the base URL explicitly rather than relying on a default.
- **acceptance_criteria**:
  - GIVEN a multi-file package installed through the UI THEN the skill detail surface lists every accepted aux file by name
  - GIVEN a single-file package installed through the UI THEN the surface renders with no aux files and no error
- [x] Implement
- [x] Test

### Task 8: Gates and quality green
- **spec_ref**: `openspec/changes/skill-package-multifile/proposal.md`
- **files**: n/a — verification only
- **notes**: `/hydra-gates` (SPDX, forbidden-patterns, stub-scan, route-auth, semantic-auth, spec-coverage, e2e-coverage in particular) plus `composer check:strict`. Fix any pre-existing issues encountered rather than deferring them.
- **acceptance_criteria**:
  - GIVEN the full gate script WHEN run THEN it emits its summary line and reports no failures
  - GIVEN `composer check:strict` WHEN run THEN PHPCS, PHPMD, Psalm and PHPStan all pass
- [x] Implement
- [x] Test

## Quality checklist

- All new/changed business logic covered by PHPUnit unit tests (`tests/Unit/`)
- New/changed API endpoints covered by Newman/Postman tests
- UI changes covered by Playwright browser tests
- All tests pass (`composer test`, `newman run`)
- Feature documentation updated in `docs/` if user-facing (ADR-010)
- Dutch (`nl_NL`) and English (`en_US`) translation strings added for any new user-facing strings (ADR-007)
- `openspec validate` passes
