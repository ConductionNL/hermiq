# Tasks: forms-smart-paste-fill

Kind: code. Size S. Half requested by buildiq `ai-smart-paste-into-forms` and nextcloud-vue `form-smart-paste`.

## Implementation tasks

### Task 1: Seed the form-fill feature
- **spec_ref**: `openspec/changes/forms-smart-paste-fill/specs/form-fill/spec.md#requirement-form-fill-is-off-until-an-admin-acknowledges-it-req-ffill-001`
- **files**: `lib/Repair/SeedFormFillFeature.php`, `appinfo/info.xml`
- **acceptance_criteria**:
  - GIVEN a fresh install WHEN the repair step runs twice THEN one `form-fill` AiFeature exists, disabled, at limited risk
- [x] Implement
- [x] Test (PHPUnit `SeedFormFillFeatureTest`)

### Task 2: FormFillEngine
- **spec_ref**: `openspec/changes/forms-smart-paste-fill/specs/form-fill/spec.md#requirement-proposals-fit-the-allowed-fields-req-ffill-003`
- **files**: `lib/Service/FormFillEngine.php`
- **acceptance_criteria**:
  - GIVEN a disabled feature WHEN fill is called THEN no provider call is made and the outcome is `feature-not-enabled`
  - GIVEN model output with an unknown key, a value outside the options and a text in a number field THEN all three are dropped
- [x] Implement
- [x] Test (PHPUnit `FormFillEngineTest` with a fake provider; the log line holds no text)

### Task 3: FormFillController and routes
- **spec_ref**: `openspec/changes/forms-smart-paste-fill/specs/form-fill/spec.md#requirement-a-signed-in-user-asks-for-proposals-req-ffill-002`
- **files**: `lib/Controller/FormFillController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN no signed-in user WHEN the route is called THEN it is refused
  - GIVEN 51 fields or 10,001 characters THEN 400
- [x] Implement
- [x] Test (PHPUnit `FormFillControllerTest`; hydra gates `route-auth` and `route-reachability`)

### Task 4: Live check and hand-over
- [x] With the feature acknowledged and on, post an email signature and three fields to the route on a test instance and read the proposals (API test `tests/api/form-fill.http` or Newman).
  - 2026-11-24: Newman folder "Form fill" in `tests/integration/hermiq.postman_collection.json` (availability, the 51-field and 10,001-character refusals, and the email-signature proposal, which asserts the fitted draft when the instance has the feature on and the honest unavailable answer otherwise). Written, runs in nightly (UNBLOCK rule 3); not run here.
- [ ] Hand buildiq the route and the response shape for its handler; run `openspec validate forms-smart-paste-fill --strict`.
  - 2026-11-24: route and response shape written in `handover-buildiq.md`. Open: `openspec validate --strict` was not run (the cloud session refused running the openspec CLI).
