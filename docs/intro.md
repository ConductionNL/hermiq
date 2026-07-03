---
sidebar_position: 1
description: Schedule autonomous AI agents in Nextcloud.
---

# Hermiq

Hermiq brings autonomous AI agents to Nextcloud. Define an agent, give it
tools, and run it on a schedule — all inside your own Nextcloud. Agents,
their memory and skills are stored as OpenRegister objects, so every run
is governed, auditable and multi-tenant.

## What is this?

Hermiq is built on ConductionNL conventions — a manifest-first Vue 2
frontend rendered by CnAppRoot, an OpenRegister data layer, a Dashboard
widget, an admin settings panel, an AI Chat Companion tool provider, and
the full PHP + frontend quality pipeline. It ships:

- **A manifest-driven UI** — pages, navigation, and dependencies are
  declared in `src/manifest.json`; the shell (CnAppRoot) reads the
  manifest at boot and renders index / detail / dashboard / settings
  pages without per-page Vue files.
- **A Dashboard widget** — a working `ExampleWidget` (PHP `IWidget`
  class + webpack entry + `NcDashboardWidget` renderer) you copy and
  rename.
- **Admin settings** — a settings panel wired through
  `NcAppSettingsDialog`, backed by an OpenRegister settings register.
- **An MCP tool provider** — `ExampleToolProvider` exposes the app's
  capabilities to the in-app AI Chat Companion over MCP.
- **OpenRegister integration** — `manifest.dependencies` lists
  `openregister`, so the dependency-check phase ensures it is installed
  before the UI mounts. Remove the entry if your app does not need it.
- **The quality pipeline** — PHPCS, PHPMD, Psalm, PHPStan, ESLint,
  Stylelint, plus manifest/register/JSON-strict validators.
- **This documentation site** — Docusaurus on `@conduction/docusaurus-preset`,
  the journeydoc tutorial scaffold, and a Playwright `docs-capture`
  project for screenshots (ADR-030).

## Getting started

Clone the repo and build:

```bash
cd /var/www/html/custom_apps
git clone https://codeberg.org/Conduction/hermiq.git hermiq
cd hermiq
npm install && npm run build
php occ app:enable hermiq
```

> OpenRegister must be installed first unless you remove the dependency
> from `src/manifest.json`, `appinfo/info.xml`, and `openspec/app-config.json`.

- New here? Start with the **[User guide](/docs/category/user-guide)** — open
  the app for the first time.
- Setting things up? See the **[Admin guide](/docs/category/admin-guide)** —
  manage the app's settings.

Free and open source under the EUPL-1.2 license. For support, contact
support@conduction.nl.
