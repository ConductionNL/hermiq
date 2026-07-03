---
example: true
capability: configuration-initialization
status: example
built_by: openspec/changes/example-change
---

# Configuration Initialization Specification

> ⚠️ **EXAMPLE SPEC** — This spec lives in the `nextcloud-app-template` repository
> as a demonstration of the OpenSpec format. It describes the behaviour of
> `lib/Repair/InitializeSettings.php` in the template's own code. Apps built
> from this template will typically keep this capability almost unchanged; the
> only substitutions are the bundled config file name and the schema/register
> IDs that the import produces.

## Purpose

Populates the app's OpenRegister schemas + registers on first install (and
after upgrades that ship a new bundled configuration) from a JSON file
committed alongside the app's PHP code. The work happens inside a Nextcloud
repair step so it runs during `occ maintenance:repair` and during the
install/upgrade flow automatically.

The repair step MUST be non-fatal: a missing or failed import MUST log a
warning and allow the rest of the repair pass to continue. An app that cannot
boot without its registers is a separate, stricter contract and belongs in a
different capability if needed.

## Requirements

### REQ-INIT-001: Identify the repair step

The system MUST expose a human-readable name for the repair step so that it
appears in Nextcloud's occ repair output.

#### Scenario: Name is surfaced

- WHEN Nextcloud enumerates repair steps
- THEN `InitializeSettings::getName()` MUST return a non-empty string identifying this step
- AND the name MUST mention the app and what the step does ("Initialize AppTemplate register and schemas via ConfigurationService" or equivalent after substitution)

### REQ-INIT-002: Import configuration on install / upgrade

The system MUST, when the repair step runs, invoke `SettingsService::loadConfiguration(force: true)`. If OpenRegister is not available, the step MUST log a warning and return without throwing. If the service call throws, the step MUST catch the exception, log the error with context, and continue — it MUST NOT let the failure abort the rest of the Nextcloud repair pass.

#### Scenario: Happy-path first install

- GIVEN the `openregister` app is installed and enabled
- AND the app's bundled `app_template_register.json` is present
- WHEN `InitializeSettings::run()` executes
- THEN the system MUST write a progress message to the repair `IOutput`
- AND the system MUST call `SettingsService::loadConfiguration(force: true)`
- AND on success, it MUST record the result (including schema/register IDs) in the server-side log at info level

#### Scenario: OpenRegister is missing

- GIVEN the `openregister` app is not installed or not enabled
- WHEN `run()` executes
- THEN the step MUST detect the unavailability via `SettingsService::isOpenRegisterAvailable()`
- AND it MUST write a warning to `IOutput` and to the logger
- AND it MUST return normally (no exception) so that subsequent repair steps can run

#### Scenario: ConfigurationService throws

- GIVEN OpenRegister is installed but the bundled JSON is malformed (or any other `ConfigurationService` failure)
- WHEN `loadConfiguration()` throws
- THEN the repair step MUST catch the exception
- AND it MUST log the exception with full context (`$logger->error(message, ['exception' => $e])`)
- AND it MUST write a user-visible warning to `IOutput`
- AND the repair step MUST return normally (the surrounding repair pass keeps running)
