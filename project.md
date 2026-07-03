# Hermiq

## Overview

Hermiq brings autonomous AI agents to Nextcloud. Define an agent, give it tools, and run it on a schedule — daily reports, inbox triage, monitoring, morning briefings — all inside your own Nextcloud, delivered to Nextcloud Talk. Agents, their memory and their self-improving skills are stored as OpenRegister objects, so every run is governed, auditable and multi-tenant.

## Architecture

- **Type**: Nextcloud App (PHP backend + Vue 2 frontend)
- **Data layer**: OpenRegister (all data stored as register objects)
- **Pattern**: Thin client — Hermiq provides UI/UX, OpenRegister handles persistence
- **License**: EUPL-1.2

## Tech Stack

| Layer | Technology |
|-------|------------|
| Backend | PHP 8.1+, Nextcloud AppFramework |
| Frontend | Vue 2.7, Pinia, @nextcloud/vue |
| Data | OpenRegister (JSON object store) |
| Testing | PHPUnit (unit + integration), Newman (API) |
| Quality | PHPCS, PHPMD, Psalm, PHPStan, ESLint, Stylelint |

## Key Files

| File | Purpose |
|------|---------|
| `lib/AppInfo/Application.php` | App bootstrap, listener + repair registration |
| `lib/Controller/SettingsController.php` | Settings API endpoints |
| `lib/Service/SettingsService.php` | Settings business logic, OpenRegister integration |
| `lib/Listener/DeepLinkRegistrationListener.php` | Registers deep link patterns with OpenRegister search |
| `lib/Repair/InitializeSettings.php` | Import register on install/upgrade |
| `lib/Settings/hermiq_register.json` | OpenAPI 3.0 register schema definition |
| `src/App.vue` | App shell (navigation + routing) |
| `src/navigation/MainMenu.vue` | App navigation sidebar |
| `src/views/settings/UserSettings.vue` | User settings dialog |
| `openspec/config.yaml` | OpenSpec project configuration |

## Development Setup

See the workspace-level `.claude/docs/` for:
- `commands.md` — available Claude commands
- `testing.md` — testing workflows
- `app-lifecycle.md` — full development lifecycle

## Standards

This app follows all [Conduction app standards](../.claude/openspec/architecture/).
