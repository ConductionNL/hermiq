# Lane log: hq (hermiq)

## Change: message-translation-delegate

- Branch: `feat/message-translation-delegate` (from `origin/development`)
- Setup note: the brief's source checkout (`/home/rubenlinde/memcap-work/dexie-hermiq`) does not exist on this box; used `/home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/hermiq` instead (a valid, up-to-date clone of the same repo, read-only as a clone source). `composer.lock` hash matched `origin/development` exactly, so the copied `vendor/` was consistent; only `vendor/bin/*` proxies and `vendor/conduction/hydra-gates` were missing (source's own vendor was in the same state) — ran `composer install` once (genuinely missing, per lane rules), `composer.lock` itself untouched.
- Status: implementation complete, diff-scoped gates green, full `composer check:strict` queued (heavy box contention — see `.tmp/check-strict.log`)
- openspec: `openspec validate message-translation-delegate` passes. Schema: `conduction` (proposal, specs, design, contract, tasks — discovery and migration skipped: approach was already clear from the shipped `course-recommendations` precedent, and no OpenRegister schema change is introduced, only one new seeded row on the existing `agentaifeature` schema).
- What shipped:
  - `lib/Repair/SeedMessageTranslationFeature.php` (new): idempotent repair step seeding the `message-translation` `agentaifeature` object, `lifecycle: disabled`, `riskCategory: limited`, `tenantId: ''` — mirrors `SeedCourseRecommendationFeature` exactly.
  - `lib/Service/MessageTranslationEngine.php` (new): gate (`AiFeatureService::findBySlug`) -> glossary-aware prompt -> `ProviderFactory::generateText()`; never throws, degrades to `{available: false, reason}`.
  - `lib/Controller/MessageTranslationController.php` (new): `POST /api/translate`, 401 unauthenticated, 400 on missing `sourceText`/`targetLanguage`, otherwise returns the engine's result verbatim.
  - `appinfo/routes.php`: one new route (`messageTranslation#translate`).
  - `appinfo/info.xml`: `SeedMessageTranslationFeature` registered in both the `install` and `repair-steps` (upgrade) blocks, alongside `SeedCourseRecommendationFeature`.
  - `CHANGELOG.md`: Unreleased/Added entry.
  - Tests (all passing): `tests/Unit/Repair/SeedMessageTranslationFeatureTest.php` (7), `tests/Unit/Service/MessageTranslationEngineTest.php` (7), `tests/Unit/Controller/MessageTranslationControllerTest.php` (6).
- Verified so far (exit codes):
  - `php -l` on every touched/new file: 0 (all)
  - `php vendor/bin/phpcs --standard=phpcs.xml` on every new/touched lib file + `appinfo/routes.php`: 0 errors on new files; 2 pre-existing findings on `routes.php` (line 38 warning, line 237 error) confirmed via `git diff` to be outside this change's added lines
  - `php vendor/bin/phpstan analyse` on every new/touched file: `[OK] No errors`
  - `php vendor/bin/phpunit` on every new test class + the pre-existing `SeedCourseRecommendationFeatureTest` (regression check): 27/27 pass
  - `npm run lint`: exit 0 (52 pre-existing warnings, 0 errors; this change touched no JS/Vue)
  - `npm run format` (prettier --check): exit 0
  - `npm run test:l10n`: exit 0
  - `TMPDIR=$PWD/.tmp COMPOSER_PROCESS_TIMEOUT=0 composer check:strict` (via `with-slot.sh`): **exit 1** — `lint`/`phpcs`/`phpmd`/`psalm`/`phpstan` all green (phpmd 0 findings; psalm "No errors found!", 878 pre-existing non-blocking issues; phpstan `[OK] No errors` across 271 files); `test:all` is red with 97 errors + 4 failures, **all confirmed pre-existing/environmental**: every one traces to `tests/Stubs/Service/Capability/ToolGrantResolver.php`'s deliberate refusal ("resolved from hermiq's ANALYSIS STUB rather than from OpenRegister... tests/bootstrap.php maps it from ../openregister") because no sibling `openregister` checkout exists at `../openregister` relative to this lane dir. Verified via `git stash` + rerunning the exact same failing test file (`OutsideAgentGatewayTest`) against unmodified `origin/development`: identical failure, same message, same line numbers. Zero new PHPUnit failures caused by this change (my three new test classes — 20 tests — pass individually and were included in that full run).
- Inherited findings (not fixed, reported per lane rules): `appinfo/routes.php` line 38 (comment style) and line 237 (comment capitalisation), both pre-existing on `origin/development`, neither on a line this change touches. The `../openregister` sibling-checkout gap above is an environment/setup gap in this lane, not a code defect.
- Done: ready to commit, push, and open the PR, noting the environmental test:all gap honestly in the PR body per lane rules ("a gate that did not run is not a pass").
- Out of scope per proposal.md: no consuming app (portaliq, learniq) is wired up in this change — this ships the delegate only.
