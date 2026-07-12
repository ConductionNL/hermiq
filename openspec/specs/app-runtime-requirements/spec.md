# app-runtime-requirements Specification

## Purpose
TBD - created by archiving change raise-nc-minversion-taskprocessing. Update Purpose after archive.
## Requirements
### Requirement: The declared Nextcloud floor supports every NC API the app calls unconditionally

The `appinfo/info.xml` `<nextcloud min-version>` MUST be at least as high as the newest
Nextcloud API `@since` version that `lib/AppInfo/Application.php::register()` (or any
other bootstrap path) invokes unconditionally. Because hermiq calls
`IRegistrationContext::registerTaskProcessingProvider()` (`@since 30.0.0`) unconditionally
for its four TaskProcessing providers, the declared `min-version` MUST be `30` or higher.
The app MUST NOT advertise a `min-version` on which its own bootstrap raises a fatal
error.

#### Scenario: The manifest floor matches the newest bootstrap API

- **WHEN** the app manifest is validated against the code
- **THEN** `info.xml` `min-version` MUST be `>= 30` (the `@since` of
  `registerTaskProcessingProvider`, called unconditionally in `Application::register()`)
- **AND** `max-version` MUST remain `34`

@e2e exclude manifest/version-floor invariant is a static consistency check, not a UI flow; covered by the regression guard test.

#### Scenario: Installation is refused on an unsupported Nextcloud

- **GIVEN** a Nextcloud 28 or 29 instance
- **WHEN** an administrator attempts to enable hermiq
- **THEN** Nextcloud MUST refuse the installation on the declared `min-version="30"`
  constraint (an accurate, honest incompatibility)
- **AND** hermiq MUST NOT install-and-then-fatal at boot on the missing
  `registerTaskProcessingProvider` method

@e2e exclude install-refusal is enforced by the NC app store / occ against the manifest floor; not a hermiq UI surface.

### Requirement: A guard prevents the version floor and bootstrap APIs from drifting apart

The test suite MUST include a regression check that fails if `Application::register()`
calls a Nextcloud API whose `@since` is newer than the declared `info.xml`
`min-version`, so a future unconditional use of a newer NC API cannot silently
re-introduce the boot-fatal-on-old-NC defect.

#### Scenario: The guard fails a newer-than-floor unconditional API call

- **GIVEN** the declared `min-version` is `30`
- **WHEN** the guard scans `Application::register()` for known `@since`-gated NC
  registration APIs
- **THEN** it MUST pass while every such call is `@since <= 30`
- **AND** it MUST fail if a call to an API `@since > 30` is added without either
  raising `min-version` or guarding the call behind a version check

@e2e exclude this is a build-time guard test, not a runtime UI flow.

