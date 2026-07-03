---
example: true
capability: settings-management
status: example
built_by: openspec/changes/example-change
---

# Settings Management Specification

> ⚠️ **EXAMPLE SPEC** — This spec lives in the `nextcloud-app-template` repository
> as a demonstration of the OpenSpec format. It describes the behaviour of
> `lib/Controller/SettingsController.php` + `lib/Service/SettingsService.php` in
> the template's own code. Apps built from this template should replace this
> content with their own settings REQs.

## Purpose

Defines how a template-derived app reads, writes, and reloads its application
configuration. The shape of the pattern matters more than the specific setting
keys: a thin admin-guarded controller (ADR-003) delegates to a service that
owns the OpenRegister-backed configuration. Generic client-facing error
messages plus server-side logging (ADR-005) apply throughout.

## Data Model

- **App config keys** (illustrative): a whitelist `CONFIG_KEYS` of setting
  names; reads return an empty string for unset keys.
- **OpenRegister availability**: runtime flag, surfaced to callers so the UI
  can degrade gracefully when the `openregister` app is not installed.

## Requirements

### REQ-CFG-001: Read current settings

The system MUST expose a `GET /api/settings` endpoint that returns the current
set of configuration values plus two derived fields: `openregisters` (boolean,
whether the OpenRegister app is available) and `isAdmin` (boolean, whether the
requesting user is in the Nextcloud admin group). The endpoint MUST be
accessible to any authenticated user (`#[NoAdminRequired]`).

#### Scenario: Non-admin user reads settings

- GIVEN a signed-in, non-admin user sends `GET /api/settings`
- WHEN `SettingsController::index()` invokes `SettingsService::getSettings()`
- THEN the system MUST return HTTP 200 with a JSON object containing every `CONFIG_KEYS` entry (empty string if unset)
- AND the body MUST include `openregisters` (boolean) and `isAdmin: false`
- AND unauthenticated access MUST still be rejected by the Nextcloud framework

#### Scenario: Admin user reads settings

- GIVEN a signed-in user who is a member of the Nextcloud `admin` group
- WHEN the same request is made
- THEN the response body MUST include `isAdmin: true`

### REQ-CFG-002: Update settings (admin only)

The system MUST expose a `POST /api/settings` endpoint that accepts a partial
settings payload and writes values to the app config. Only keys in `CONFIG_KEYS`
are persisted; unknown keys MUST be silently ignored. The endpoint MUST be
restricted to admin users (no `#[NoAdminRequired]`).

#### Scenario: Admin updates a known setting

- GIVEN an authenticated admin sends `POST /api/settings` with `{ "someKey": "new-value" }`
- WHEN `SettingsController::create()` invokes `SettingsService::updateSettings()`
- THEN the system MUST persist the new value to app config
- AND the response MUST be HTTP 200 with `{ "success": true, "config": <freshly-read settings> }`

#### Scenario: Non-admin tries to update

- GIVEN a signed-in non-admin user sends `POST /api/settings`
- WHEN the Nextcloud framework evaluates the controller attributes
- THEN the system MUST reject the request per the framework's admin gate (the controller itself does not need an explicit re-check)

#### Scenario: Payload contains an unknown key

- GIVEN an admin sends `POST /api/settings` with `{ "unknown": "x", "allowed": "y" }`
- WHEN `updateSettings()` iterates `CONFIG_KEYS`
- THEN the system MUST persist only `allowed`
- AND the response MUST reflect the updated state without surfacing an error for the unknown key

### REQ-CFG-003: Reload configuration from JSON file (admin only)

The system MUST expose a `POST /api/settings/load` endpoint that triggers a
fresh import of the app's bundled `*_register.json` configuration via
OpenRegister's `ConfigurationService::importFromApp()`. The endpoint MUST be
admin-only and MUST be callable at any time (not only on install).

#### Scenario: Admin triggers re-import while OpenRegister is available

- GIVEN OpenRegister is installed and enabled
- WHEN an admin sends `POST /api/settings/load`
- THEN the system MUST invoke `SettingsService::loadConfiguration(force: true)`
- AND the response MUST be HTTP 200 with the ConfigurationService result (an array including `success: true` and the configured schema/register IDs)

#### Scenario: Admin triggers re-import but OpenRegister is missing

- GIVEN OpenRegister is not installed or disabled
- WHEN `loadConfiguration()` is invoked
- THEN the system MUST emit a server-side warning via `LoggerInterface::warning()`
- AND the system MUST return `{ "success": false, "message": "OpenRegister is not installed or enabled." }`
- AND the HTTP response MUST NOT leak implementation detail beyond that generic message (per ADR-005)

### REQ-CFG-004: Graceful handling when OpenRegister is absent

The system MUST provide a stable way to detect whether the OpenRegister app is
installed so that UI and services can degrade gracefully. A missing
OpenRegister installation MUST NOT cause request handlers to throw — they MUST
log server-side and return safe fallback responses.

#### Scenario: Probe OpenRegister availability

- GIVEN any caller that needs to know whether OpenRegister is present
- WHEN `SettingsService::isOpenRegisterAvailable()` is invoked
- THEN the system MUST return a boolean derived from `IAppManager::isInstalled('openregister')`
- AND the result MUST be safe to call in any request phase (no throw, no heavy I/O)
