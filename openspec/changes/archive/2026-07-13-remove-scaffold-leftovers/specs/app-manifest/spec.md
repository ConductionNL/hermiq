# app-manifest (delta)

Removes template-scaffold content (an `Example`/`ExampleDetail` page pair, its nav entry,
its `CustomExample.vue` component, its OpenRegister `example` schema, and the unregistered
`ExampleToolProvider` MCP scaffold) that shipped past the app-template generation step and
was never replaced with real Hermiq content or removed, unlike the sibling `ExampleWidget`
cleanup (ADR-049).

## ADDED Requirements

### Requirement: The production manifest carries no template-scaffold pages
The system's `src/manifest.json` MUST NOT declare a page, route, or main-navigation entry
whose schema/register binding is the app-template's placeholder `example` schema.

#### Scenario: A user opens the app's main navigation
- **GIVEN** the Hermiq app is loaded for any authenticated user
- **WHEN** the user views the main navigation
- **THEN** the navigation MUST NOT contain an "Examples" entry
- **AND** no route under `/examples` MUST be registered

### Requirement: The OpenRegister register carries no template-scaffold schema
The system's `hermiq` OpenRegister register MUST NOT declare the placeholder `example`
schema.

#### Scenario: An admin browses the hermiq register's schemas
- **GIVEN** an admin viewing OpenRegister's schema browser for the `hermiq` register
- **WHEN** the admin lists the register's schemas
- **THEN** no schema slug `example` MUST be present

### Requirement: No unregistered MCP tool-provider scaffold ships in `lib/`
The system MUST NOT ship an `IMcpToolProvider` implementation that is not registered under
the `OCA\OpenRegister\Mcp\IMcpToolProvider::{appId}` DI alias (or the conventional FQCN
fallback) — an unreachable provider class is dead code.

#### Scenario: The app's DI container is inspected for MCP providers
- **GIVEN** `lib/AppInfo/Application.php`'s service registrations
- **WHEN** every class implementing `IMcpToolProvider` under `lib/Mcp/` is enumerated
- **THEN** every such class MUST be reachable via the DI alias or documented as
  intentionally unregistered scaffolding removed before release
