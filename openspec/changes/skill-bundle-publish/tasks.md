# Tasks: skill-bundle-publish

## Implementation Tasks

### Task 1: SkillBundleSerializer — build and parse the bundle tree
- **spec_ref**: `openspec/changes/skill-bundle-publish/specs/skills-marketplace/spec.md#requirement-many-skills-publish-to-a-single-repository`
- **files**: `lib/Service/SkillBundleSerializer.php`, `tests/Unit/Service/SkillBundleSerializerTest.php`
- **notes**: `toBundle(array $skills): array` and `fromBundle(array $files): array`, both `path => contents`. MUST delegate per skill to `SkillSerializer::toPackageFiles()`/`fromPackageFiles()` so frontmatter fidelity and aux path safety are inherited, never restated. Emit `hermiq-skills.json` + `skills/<name>/…`.
- **acceptance_criteria**:
  - GIVEN three skills, one multi-file, WHEN bundled and parsed back THEN every skill's frontmatter, body and aux files are recovered byte-identically
  - GIVEN a bundle map WHEN parsed THEN `hermiq-skills.json` is consumed as the manifest and never surfaces as a skill
- [x] Implement
- [x] Test

### Task 2: Bundle name + path validation
- **spec_ref**: `openspec/changes/skill-bundle-publish/specs/skills-marketplace/spec.md#requirement-bundle-entries-are-validated-before-use-as-paths`
- **files**: `lib/Service/SkillBundleSerializer.php`, `tests/Unit/Service/SkillBundleSerializerTest.php`
- **notes**: Validate manifest skill names as kebab-case slugs BEFORE using them as a directory component; reject any entry resolving outside its own `skills/<name>/` prefix. Drop and log; never rewrite.
- **acceptance_criteria**:
  - GIVEN a manifest entry named `../../etc` WHEN parsed THEN it is dropped and logged
  - GIVEN an entry whose path escapes its prefix WHEN parsed THEN it is dropped
  - GIVEN a valid nested aux path WHEN parsed THEN it is preserved exactly
- [x] Implement
- [x] Test

### Task 3: Create-or-update bundle publish
- **spec_ref**: `openspec/changes/skill-bundle-publish/specs/skills-marketplace/spec.md#requirement-many-skills-publish-to-a-single-repository`
- **files**: `lib/Service/GitHubTemplatePushService.php`, `tests/Unit/Service/GitHubTemplatePushServiceTest.php`
- **notes**: `publishBundle()` reusing the existing Git-Data chain. Create when absent, UPDATE when present (the deliberate, narrow carve-out from `assertRepoAbsent()`). MUST pass `base_tree` so unrelated repo paths survive, and MUST NOT force-push. Tag `topic:hermiq-skill-bundle`. `publish()`/`republish()` untouched.
- **acceptance_criteria**:
  - GIVEN an absent repo WHEN publishing THEN it is created, tagged, and `created: true`
  - GIVEN an existing repo WHEN publishing THEN it is updated and `created: false`
  - GIVEN unrelated files in the repo WHEN publishing THEN they survive the commit
- [x] Implement
- [x] Test

### Task 4: Bundle fetch + discovery
- **spec_ref**: `openspec/changes/skill-bundle-publish/specs/skills-marketplace/spec.md#requirement-many-skills-publish-to-a-single-repository`
- **files**: `lib/Service/GitHubTemplateCatalogService.php`, `tests/Unit/Service/GitHubTemplateCatalogServiceTest.php`
- **notes**: `fetchBundle()` — read `hermiq-skills.json`, walk the tree, fetch blobs under `skills/`. Bound to 64 skills / 16 MiB, report truncation. A repo without the manifest is 404, not a mis-parsed single skill.
- **acceptance_criteria**:
  - GIVEN a bundle repo WHEN fetched THEN the manifest and every skill directory are returned
  - GIVEN a repo with no manifest WHEN fetched THEN the result is a not-found outcome
  - GIVEN a bundle beyond the bounds WHEN fetched THEN truncation is reported, not silent
- [x] Implement
- [x] Test

### Task 5: Bundle publish + install routes
- **spec_ref**: `openspec/changes/skill-bundle-publish/contract.md`
- **files**: `lib/Controller/SkillController.php`, `appinfo/routes.php`, `tests/Unit/Controller/SkillControllerTest.php`
- **notes**: Install MUST fan out through the UNCHANGED `installFromSource()` — one call per skill, so quarantine and per-skill scanning are inherited rather than re-proved. Return the per-skill outcome list. A partial failure is 200 with `failed > 0`, never a blanket 500. Auth posture mirrors `githubInstall()`.
- **acceptance_criteria**:
  - GIVEN a bundle of three WHEN installed THEN three quarantined skills exist, each with its own scan report
  - GIVEN one unparseable entry WHEN installed THEN `failed` is non-zero and every entry has an outcome
  - GIVEN an invalid owner/repo WHEN posted THEN 400 before any GitHub call
- [x] Implement
- [x] Test

### Task 6: Playwright e2e — bundle round trip on a live instance
- **spec_ref**: `openspec/changes/skill-bundle-publish/specs/skills-marketplace/spec.md#requirement-a-bundle-installs-as-many-individually-quarantined-skills`
- **files**: `tests/e2e/skill-bundle.spec.ts`
- **notes**: Prove routing with a marker before trusting any result. Assert one dangerous skill in a bundle is flagged WITHOUT blocking its siblings — a bundle that fails open or fails whole is the failure mode that matters.
- **outcome (scope corrected)**: The e2e covers the contract half only — routes registered and reachable (marker: `invalid_repo` 400, which a router 404 could not produce), coordinates validated before any GitHub call, a real non-bundle repo (`ConductionNL/openbuild`) refused as `not_a_bundle`, and no anonymous use. **4/4 green against `ob-vue3-e2e:8099`.**
  A full publish→install round trip is NOT covered here: it needs a real GitHub repository and a broker credential, which arrive when `buildiq-hydra` is wired. Asserting a round trip that cannot actually be performed would be theatre. The quarantine/partial-failure/dangerous-sibling behaviours ARE covered, at unit level, by `SkillControllerTest::testBundleInstallQuarantinesEverySkill` and `::testBundleInstallReportsPartialFailure`.
- **acceptance_criteria**:
  - GIVEN the bundle routes on a live instance THEN they are reachable and reject bad coordinates before any outbound call — VERIFIED
  - GIVEN a real repository without `hermiq-skills.json` THEN it is refused rather than mis-parsed — VERIFIED
  - GIVEN a bundle installed end-to-end from GitHub THEN every skill lands quarantined — DEFERRED to the buildiq-hydra wiring step
- [x] Implement
- [x] Test

### Task 7: Gates and quality green
- **spec_ref**: `openspec/changes/skill-bundle-publish/proposal.md`
- **files**: n/a — verification only
- **notes**: `run-hydra-gates.sh --scope-to-diff` (must emit its summary line — an aborted run reads as green) plus PHPCS/PHPStan/Psalm/PHPMD. Fix pre-existing issues encountered rather than deferring.
- **acceptance_criteria**:
  - GIVEN the gate script WHEN run THEN it emits ALL GATES GREEN with exit 0
  - GIVEN the static analysers WHEN run THEN all pass
- [x] Implement
- [x] Test

## Quality checklist

- All new/changed business logic covered by PHPUnit unit tests (`tests/Unit/`)
- New/changed API endpoints covered by Newman/Postman tests
- UI changes covered by Playwright browser tests
- All tests pass (`composer test`)
- Feature documentation updated in `docs/` if user-facing (ADR-010)
- Dutch (`nl_NL`) and English (`en_US`) strings for any new user-facing text (ADR-007)
- `openspec validate` passes
