# Tasks: models-no-training-guarantee

Kind: code. Size M. Row `hermiq:td-no-training`.

## Implementation tasks

### Task 1: The data-use declaration
- **spec_ref**: `openspec/changes/models-no-training-guarantee/specs/provider-data-use/spec.md#requirement-a-configured-provider-states-what-it-does-with-data-req-notrain-001`
- **files**: `lib/Service/AiFeature/ProviderDataUseRegistry.php`, `lib/Controller/Settings/ProviderDataUseSettingsController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN an admin WHEN they declare no-training with a reference THEN it is stored with declaredBy and declaredAt, and an unknown value or provider is refused
  - GIVEN a non-admin WHEN they call the route THEN the call is refused
- [x] Implement
- [x] Test (PHPUnit: ProviderDataUseTest::testAnAdminDeclaresWhatAProviderDoesWithData, testNothingIsInferred; the admin route carries #[AuthorizedAdminSetting], a non-admin refusal is Nextcloud's middleware; Playwright tests/e2e/spec-coverage/models-no-training-guarantee.spec.ts on the route)

### Task 2: requireNoTraining on the model policy
- **spec_ref**: `openspec/changes/models-no-training-guarantee/specs/provider-data-use/spec.md#requirement-an-organisation-can-require-providers-that-never-train-on-its-data-req-notrain-002`
- **files**: `lib/Settings/hermiq_register.json` (ModelPolicy), `lib/Service/TenantModelPolicyService.php`
- **acceptance_criteria**:
  - GIVEN an instance default with requireNoTraining WHEN an organisation has no policy of its own THEN its effective policy requires it
- [x] Implement
- [x] Test (PHPUnit: ProviderDataUseTest::testTheRequirementIsReadFromTheEffectivePolicy, testThePolicyPayloadMatchesTheRegisterSchema against the real fragment with Opis)

### Task 3: The data-use step on every run
- **spec_ref**: `openspec/changes/models-no-training-guarantee/specs/provider-data-use/spec.md#requirement-an-organisation-can-require-providers-that-never-train-on-its-data-req-notrain-002`
- **files**: `lib/Service/AiFeature/DataUseViolationException.php`, `lib/Service/AiFeature/FeatureProviderResolver.php`, `lib/Service/Llm/ProviderFactory.php`, `lib/Service/Engine/ResponseGenerationHandler.php` (the chat message)
- **acceptance_criteria**:
  - GIVEN requireNoTraining and an undeclared provider WHEN a run with or without a feature resolves to it THEN it is refused before any request with step data-use
  - GIVEN a zero-retention provider WHEN it runs THEN it passes
- [x] Implement
- [x] Test (PHPUnit on both paths: ProviderFactoryTest::testTheDataUseStepRefusesARunWithoutAFeature, FeatureProviderResolverTest::testTheDataUseStepRefusesOnTheFeaturePath; ChatControllerTest::testARunRefusedOnDataUseTellsThePersonWhy)

### Task 4: The term on every run's disclosure
- **spec_ref**: `openspec/changes/models-no-training-guarantee/specs/provider-data-use/spec.md#requirement-every-run-records-the-data-use-term-in-force-req-notrain-003`
- **files**: `lib/Service/AiFeature/FeatureProviderResolver.php` (disclosure), `lib/Service/Engine/ResponseGenerationHandler.php`, `lib/Service/Engine/RunTraceCollector.php`, `src/views/Runs.vue`
- **acceptance_criteria**:
  - GIVEN a run without a feature WHEN it is recorded THEN its disclosure carries provider, model, residency and data use
  - GIVEN a declaration changed after a run WHEN the run is read THEN it shows the old term
- [x] Implement
- [x] Test (PHPUnit: FeatureProviderResolverTest::testTheDisclosureCarriesTheDataUseTerm, ScheduleServiceTest::testTheRunRecordKeepsTheProviderDisclosure, AnalyticsServiceTest::testARunRowShowsTheDataUseTermInForce, RunRetentionCleanerTest; the seeded-run Playwright check carries a reason-bearing @e2e exclude)

### Task 5: The provider form and the policy switch
- **spec_ref**: `openspec/changes/models-no-training-guarantee/specs/provider-data-use/spec.md#requirement-a-configured-provider-states-what-it-does-with-data-req-notrain-001`
- **files**: `src/modals/LlmProviderModal.vue`, `src/api/llm.js`, `src/views/TenantOps.vue`, `src/api/modelPolicy.js`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN an admin on a hosted provider WHEN they save residency and data use THEN both are stored through their routes
  - GIVEN the policy switch on WHEN allowed providers lack the term THEN they are listed before saving
- [x] Implement
- [x] Test (Playwright under tests/e2e/spec-coverage/models-no-training-guarantee.spec.ts; not run on 30 Sep, the browser service was down)

## Verification
- [x] `openspec validate models-no-training-guarantee --type change --strict` passes
- [x] PHPUnit run once before push, exit code read (PR body). Newman and Playwright not run: no Newman collection covers these routes, and the browser service was down
- [ ] One live chat turn in an organisation with requireNoTraining on an undeclared provider shows the refusal and no provider request in the broker log (open: recipe in the PR body)
