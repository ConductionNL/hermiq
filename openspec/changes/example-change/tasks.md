# Tasks — example-change

> ⚠️ **EXAMPLE TASK LIST** — These tasks are reference material. See
> `proposal.md` for what to do when you generate an app from this template.
> All `[x]` here mean "the task's code has already been written into the
> template" — NOT "done in your app". Apps building from this template will
> flip these back to `[ ]` (or replace them entirely) for their own work.

The task numbering matches the `@spec openspec/changes/example-change/tasks.md#task-N`
annotations in `lib/**/*.php`. Do not renumber without also updating every
docblock.

## Tasks

- [x] task-1: dashboard-page#REQ-DASH-001 + REQ-DASH-002 — render the SPA entry point and provide a Vue-history-mode catch-all (`DashboardController::page`, `catchAll`)
- [x] task-2: settings-management#REQ-CFG-001 + REQ-CFG-002 + REQ-CFG-003 — expose the settings API layer with admin-gated writes and reload (`SettingsController::index`, `create`, `load`)
- [x] task-3: settings-management#REQ-CFG-001..004 — implement the settings service layer with OpenRegister-availability fallback and ConfigurationService-backed reload (`SettingsService::getSettings`, `updateSettings`, `isOpenRegisterAvailable`, `loadConfiguration`)
- [x] task-4: deep-linking#REQ-LINK-001 — subscribe to `DeepLinkRegistrationEvent` and register the Article schema's path-based URL template (`DeepLinkRegistrationListener::handle`)
- [x] task-5: configuration-initialization#REQ-INIT-001 + REQ-INIT-002 — provide the first-install/repair step that imports the app's bundled `*_register.json` via OpenRegister's ConfigurationService (`Repair\InitializeSettings::getName`, `run`). NOTE: the template's UI was refactored to the manifest-renderer pattern (hydra ADR-024), so the standalone `ItemController`/`ItemService` example was removed. The ADR-005 per-object authorization pattern that `item-management` documents is now demonstrated by `ActionAuthService` (ADR-023, see `openspec/architecture/adr-023-action-authorization.md`); the `item-management` spec remains as the canonical documentation of the per-object-auth REQ shape that template-derived apps adopt.
- [x] task-6: admin-ui#REQ-UI-002 — register the admin settings form and pass the app version into the template (`Settings\AdminSettings::getForm`, `getSection`, `getPriority`)
- [x] task-7: admin-ui#REQ-UI-001 — register the IIconSection metadata (id, localised name, priority, icon) for the admin panel navigation (`Sections\SettingsSection::getID`, `getName`, `getPriority`, `getIcon`)
- [x] task-8: observability#REQ-OBS-001 — expose the admin-only Prometheus metrics endpoint with the `{app}_info` and `{app}_health_status` gauges (`MetricsController::index`)
- [x] task-9: observability#REQ-OBS-002 — expose the public health-check endpoint with dependency-aware status codes (200 healthy / 503 degraded) (`HealthController::index`)
