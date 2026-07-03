---
example: true
capability: admin-ui
status: example
built_by: openspec/changes/example-change
---

# Admin UI Registration Specification

> ⚠️ **EXAMPLE SPEC** — This spec lives in the `nextcloud-app-template` repository
> as a demonstration of the OpenSpec format. It describes the Nextcloud admin-
> panel registration in `lib/Settings/AdminSettings.php` + `lib/Sections/SettingsSection.php`.
> Apps built from this template will typically keep this capability almost
> unchanged; the substitutions are the section ID string, display name, icon
> path, and priority numbers.

## Purpose

Registers the app's configuration panel inside Nextcloud's admin settings UI.
Two pieces work together:

- A **section** (IIconSection implementation) — gives the panel a unique id,
  display name, order priority, and icon. Shows up as an entry in the admin
  navigation.
- A **settings form** (ISettings implementation) — renders the actual form
  template within that section.

Both pieces MUST agree on the section id string; Nextcloud uses the id as the
join key.

## Requirements

### REQ-UI-001: Register the admin section (IIconSection)

The system MUST register a section in Nextcloud's admin settings panel so
that the app has a dedicated place to render its configuration form.

#### Scenario: Section appears in admin navigation

- GIVEN the app is installed and enabled
- WHEN Nextcloud enumerates admin-setting sections
- THEN `SettingsSection::getID()` MUST return a stable identifier (`app-template` in the template; apps MUST substitute their own id)
- AND `SettingsSection::getName()` MUST return a localised display name (via `IL10N::t()`)
- AND `SettingsSection::getPriority()` MUST return an integer controlling ordering (template uses `75`)
- AND `SettingsSection::getIcon()` MUST return an image path produced by `IURLGenerator::imagePath()` (template uses `app-template/app-dark.svg`)

### REQ-UI-002: Render the admin settings form (ISettings)

The system MUST render the admin form template when Nextcloud opens the app's
admin section, and it MUST pass the running app version into the template so
the admin UI can display it.

#### Scenario: Admin opens the app's settings section

- GIVEN an admin user opens the app section registered by REQ-UI-001
- WHEN Nextcloud invokes `AdminSettings::getForm()`
- THEN the system MUST return a `TemplateResponse` rendering the `settings/admin` template
- AND the response parameters MUST include the current app version (fetched via `IAppManager::getAppVersion()`)

#### Scenario: Section join key

- WHEN Nextcloud resolves which section the form belongs to
- THEN `AdminSettings::getSection()` MUST return the same string that `SettingsSection::getID()` returns
- AND `AdminSettings::getPriority()` MUST return an integer controlling the form's position within the section
