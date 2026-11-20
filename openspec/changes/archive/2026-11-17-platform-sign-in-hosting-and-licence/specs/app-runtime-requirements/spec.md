# app-runtime-requirements

## ADDED Requirements

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
