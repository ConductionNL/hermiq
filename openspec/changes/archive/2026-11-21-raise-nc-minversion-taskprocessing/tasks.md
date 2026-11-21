# Tasks: raise-nc-minversion-taskprocessing

## 1. Correct the declared Nextcloud floor

- [x] 1.1 In `appinfo/info.xml`, change `<nextcloud min-version="28" max-version="34"/>` to `<nextcloud min-version="30" max-version="34"/>`. Use the Edit tool; do not touch `max-version`.
  - **spec_ref**: `specs/app-runtime-requirements/spec.md#requirement-the-declared-nextcloud-floor-supports-every-nc-api-the-app-calls-unconditionally`
  - **acceptance_criteria**:
    - `info.xml` declares `min-version="30"`
    - `registerTaskProcessingProvider` (`@since 30.0.0`) is now within the declared range
    - No `lib/` change (the provider registrations are correct for NC ≥ 30)
  - Done: `info.xml` was raised to 30 by the fix of 2026-07-07 and has since moved to `min-version="32"` (OpenRegister's own floor, commented in `info.xml`), which keeps `registerTaskProcessingProvider` (`@since 30.0.0`) in range.

## 2. Regression guard

- [x] 2.1 Add a test (matching the app's PHPUnit conventions) that parses `lib/AppInfo/Application.php::register()` for the known `@since`-gated `IRegistrationContext` registration APIs and asserts each one's `@since` is `<= ` the `info.xml` `min-version`; seed it with the `registerTaskProcessingProvider` → `30.0.0` mapping so it fails if `min-version` is ever lowered below 30 while that call remains, or a newer-`@since` unconditional call is added.
  - **spec_ref**: `specs/app-runtime-requirements/spec.md#requirement-a-guard-prevents-the-version-floor-and-bootstrap-apis-from-drifting-apart`
  - **acceptance_criteria**:
    - The guard passes at `min-version="30"` with the current four `registerTaskProcessingProvider` calls
    - The guard fails if `min-version` is set below 30 or a `@since > 30` unconditional NC API call is introduced without a version guard
  - Done: `tests/Unit/AppInfo/VersionFloorGuardTest.php`, green at `min-version="32"`.

## 3. Verify

- [x] 3.1 `openspec validate raise-nc-minversion-taskprocessing --strict` is clean; the app-manifest schema still validates with the new floor; no other spec references `min-version="28"`.
  - **spec_ref**: all
  - **acceptance_criteria**:
    - Strict validation passes
    - A grep for `min-version="28"` across the repo returns only historical/archived references, not the live manifest
  - Done 2026-11-21: strict validation is clean after the long requirement text was shortened (the TaskProcessing detail moved into its scenario) and the stale `max-version MUST remain 34` line was dropped, in the change and in `openspec/specs/app-runtime-requirements/spec.md`, which already held both requirements. `npm run check:manifest` passes (Ajv, 0 errors). `min-version="28"` appears only in this change's own history text; `info.xml` declares `min-version="32"`.
