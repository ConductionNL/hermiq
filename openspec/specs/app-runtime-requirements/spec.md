# app-runtime-requirements Specification

## Purpose
What hermiq needs from the platform it runs on: the Nextcloud version range it declares and the guard that keeps that range honest, sign-in and second factor left to Nextcloud, and the licence it ships under.

## Requirements

### Requirement: The declared Nextcloud floor supports every NC API the app calls unconditionally

The `appinfo/info.xml` `<nextcloud min-version>` MUST be at least the newest Nextcloud
API `@since` version that a bootstrap path (`lib/AppInfo/Application.php::register()` or
another) invokes unconditionally. The app MUST NOT advertise a `min-version` on which its
own bootstrap raises a fatal error.

#### Scenario: The manifest floor matches the newest bootstrap API

- **WHEN** the app manifest is validated against the code
- **THEN** `info.xml` `min-version` MUST be `>= 30`, the `@since` of
  `IRegistrationContext::registerTaskProcessingProvider()`, which `Application::register()`
  calls unconditionally for each TaskProcessing provider

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

### Requirement: Sign-in and single sign-on SHALL be Nextcloud's (REQ-PLAT-001)

hermiq SHALL resolve every caller through Nextcloud's session (`OCP\IUserSession`) and SHALL
hold no sign-in, password or single sign-on code of its own. A person who signs in to Nextcloud
with the organisation's existing accounts and groups, through `user_saml`, `user_oidc` or
`user_ldap`, SHALL reach hermiq with that identity and those groups and no hermiq setting.

Rows: `op-sso`. No board: sign-in happens on the Nextcloud sign-in page.

#### Scenario: A person signed in through single sign-on uses hermiq
- **GIVEN** a Nextcloud instance with `user_oidc` connected to the organisation's identity provider
- **WHEN** a person signs in through it and opens hermiq's chat page
- **THEN** hermiq shows that person's own agents and conversations, with no hermiq sign-in step

@e2e exclude sign-in through an outside identity provider is Nextcloud's flow, not a hermiq page; guarded by PlatformFoundationsTest.

### Requirement: A second factor at sign-in SHALL be Nextcloud's (REQ-PLAT-002)

Every hermiq page and every API route that is not a token or secret guarded public endpoint
SHALL require a Nextcloud session, so a second factor that Nextcloud enforces at sign-in
(for example `twofactor_totp`) SHALL apply to hermiq. hermiq SHALL add no second factor of its
own and SHALL offer no route around Nextcloud's sign-in.

Rows: `dm-second-factor`. No board: the second factor is on the Nextcloud sign-in page.

#### Scenario: A required second factor guards hermiq
- **GIVEN** an instance that enforces a TOTP second factor for all users
- **WHEN** a person who has entered only a password requests a hermiq page or `/api/agents`
- **THEN** Nextcloud asks for the second factor first and hermiq returns nothing until it is given

@e2e exclude the second factor is Nextcloud's sign-in flow; hermiq's part is that no session-free route exists, checked by the route-auth gate.

### Requirement: hermiq SHALL run on any self-managed Nextcloud in its declared range (REQ-PLAT-003)

hermiq SHALL install and run on a Nextcloud instance the organisation runs itself, on its own
servers or at a hosting party of its choice, within the Nextcloud versions `appinfo/info.xml`
declares. hermiq SHALL need no Conduction-hosted service to run; outside model providers are
used only when an administrator configures them, and a local model (Ollama or Nextcloud's own
task processing) keeps every model call on the organisation's infrastructure.

Rows: `op-self-host`. No board: hosting happens on the server.

#### Scenario: hermiq installs on a self-hosted instance
- **GIVEN** a self-hosted Nextcloud in the declared range with OpenRegister installed
- **WHEN** an administrator enables hermiq and configures an Ollama provider
- **THEN** the app installs, its pages load and an agent answers without any call to a Conduction-hosted service

@e2e exclude installation on a self-managed server is an operations check, not a UI flow.

### Requirement: hermiq SHALL be distributed under the EUPL 1.2 (REQ-PLAT-004)

The hermiq source SHALL be licensed under the European Union Public Licence 1.2: the root
`LICENSE` file SHALL hold the EUPL 1.2 text, `appinfo/info.xml` SHALL declare
`<licence>EUPL-1.2</licence>`, and source files SHALL carry an `SPDX-License-Identifier`
header naming EUPL-1.2.

Rows: `op-open-source`. No board: the licence is a fact about the source code.

#### Scenario: The declared licence matches the licence file
- **WHEN** the app manifest and the `LICENSE` file are read
- **THEN** both name the EUPL 1.2

@e2e exclude a licence is a static fact about the repository; guarded by PlatformFoundationsTest.
