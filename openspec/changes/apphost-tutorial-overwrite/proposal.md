---
kind: code
---

# Proposal: App Template — AppHost Three-Layer Tutorial Restructure

## The demo-app rule (read this first)

**The template app always keeps actual code.** Unlike every other leaf app, nextcloud-app-template does not adopt the AppHost by deleting everything it can — new apps are scaffolded from this repo, and the override mechanism is itself tutorial content. When the AppHost (`apphost-observability-engine` + `apphost-boilerplate-controllers` in openregister) makes a file deletable, this app's job is to *demonstrate* the deletion path **and** the override path **and** the escape hatch, each with code a first-time scaffolder can read. This is a user decision (2026-06-12) and it is the organising principle of this change: the template is restructured into the same canonical three-layer teaching example as its sibling petstore, and the two stay byte-aligned modulo namespace tokens.

## Problem

The 2026-06-12 fleet inventory found the template's 18 PHP/template files byte-identical (namespace-normalised) to petstore — both are pure copies of the skeleton the AppHost now absorbs. Once the OR AppHost ships, the template would teach new apps to scaffold ~10 files of dead boilerplate (`HealthController`, `MetricsController`, `DashboardController`, `PreferencesController`, `SettingsController`, `SettingsService`, `ActionAuthService`, a ~100-line `Application.php`, a 27-line `routes.php`) that every other fleet app is simultaneously deleting. Worse, the template would demonstrate *nothing* about how to override generic behaviour or when to drop to imperative code — the two questions every scaffolder actually hits.

## Proposed Change

Restructure the template into a **three-layer teaching example**:

1. **Layer 1 — pure declarative.** `appinfo/routes.php` becomes `return \OCA\OpenRegister\AppHost\Routes::standard();`. `lib/AppInfo/Application.php` shrinks to ~20 lines: `APP_ID` const + `Bootstrap::register($context, self::APP_ID)` (plus the existing MCP/widget registrations, which stay). `src/manifest.json` gains an `observability` block with 1–2 simple worked-example descriptors (a `database` + `orAvailable` health check; one `objectCount` metric against the template's own register/schema slugs). Dashboard, Preferences, and Settings endpoints are served by the AppHost generic controllers via Bootstrap aliases — the local copies are deleted.
2. **Layer 2 — declarative + override.** Exactly ONE local controller survives as a worked override: `lib/Controller/HealthController.php` is rewritten as `class HealthController extends GenericHealthController` overriding **one** protected hook to add a tutorial-only check to the response. It is heavily commented, written for someone scaffolding a new app (placeholder names like `app_template`, "rename this to your app id" guidance), and `Bootstrap::register()`'s option to keep a local class for one alias is the demonstrated mechanism.
3. **Layer 3 — imperative escape hatch.** A new, heavily commented `lib/Observability/ExampleMetricsProvider.php` (same path as the petstore sibling) implements `OCA\OpenRegister\AppHost\IMetricsProvider`, is registered under the ADR-035-style service alias (`...IMetricsProvider::app_template`), and is wired by a `{"kind":"provider"}` descriptor in the manifest's `metrics[]`. It emits one obviously-fake sample so a scaffolder sees the full provider path end to end.

### Per-file delete/keep table

| File | Action | Layer / reason |
|---|---|---|
| `appinfo/routes.php` | **Rewrite** → one-statement `Routes::standard()` delegation | Layer 1 |
| `lib/AppInfo/Application.php` | **Rewrite** → ~20-line stub: `APP_ID` + `Bootstrap::register()` + existing MCP/widget/listener registrations | Layer 1 |
| `lib/Controller/DashboardController.php` | **Delete** — served by `GenericDashboardController` alias | Layer 1 |
| `lib/Controller/PreferencesController.php` | **Delete** — `GenericPreferencesController` alias | Layer 1 |
| `lib/Controller/SettingsController.php` | **Delete** — `GenericSettingsController` alias | Layer 1 |
| `lib/Controller/MetricsController.php` | **Delete** — `GenericMetricsController` + manifest `observability.metrics` | Layer 1 |
| `lib/Controller/HealthController.php` | **Rewrite** — `extends GenericHealthController`, ONE protected-hook override, tutorial comments | **Layer 2** |
| `lib/Service/SettingsService.php` | **Delete** — `AppHostSettingsService` | Layer 1 |
| `lib/Service/ActionAuthService.php` | **Delete** — `GenericActionAuthService` | Layer 1 |
| `lib/Repair/InitializeSettings.php` | **Rewrite** → one-line subclass stub (`extends GenericInitializeSettings`) — NC instantiates repair steps by class name from info.xml | Layer 1 floor |
| `lib/Repair/InitializeActions.php` | **Rewrite** → one-line subclass stub | Layer 1 floor |
| `lib/Settings/AdminSettings.php` | **Rewrite** → one-line subclass stub (`extends GenericAdminSettings`) — info.xml `<settings>` needs a concrete app-namespace class | Layer 1 floor |
| `lib/Sections/SettingsSection.php` | **Rewrite** → one-line subclass stub | Layer 1 floor |
| `lib/Settings/app_template_register.json` | **Keep** — the data model; consumed by `GenericInitializeSettings` | Layer 1 |
| `lib/Dashboard/ExampleWidget.php` | **Keep** unchanged — teaching artifact (NC Dashboard API, not AppHost scope) | tutorial |
| `lib/Mcp/ExampleToolProvider.php` | **Keep** unchanged — teaching artifact (ADR-034/035 MCP provider pattern) | tutorial |
| `lib/Listener/DeepLinkRegistrationListener.php` | **Keep** unchanged — teaching artifact (event-listener pattern) | tutorial |
| `lib/Observability/ExampleMetricsProvider.php` | **New** — commented `IMetricsProvider` + `{"kind":"provider"}` descriptor | **Layer 3** |
| `templates/index.php` | **Keep** — generic chunk loader, still required until OR-served shell lands (open question in `apphost-boilerplate-controllers` design) | Layer 1 |
| `templates/settings/admin.php` | **Keep** — rendered by the admin settings stub | Layer 1 |
| `src/manifest.json` | **Modify** — add `observability` block with worked-example descriptors | Layer 1 |
| `tests/unit/Controller/SettingsControllerTest.php` | **Delete** — tests a deleted local class; generic behaviour is covered by OR's AppHost unit + Newman contract tests; replaced by a HealthController-override unit test | verification |

### Scaffolding impact (template-specific)

This repo is what `app-create` scaffolding copies. Every doc that inventories the template's files must be updated in this change: `README.md` (Directory Structure block, lines ~70–105), `project.md` (file table + `/app-create` note), and a new three-layer section in `README.md` describing exactly what a scaffolded app should delete vs keep at each layer. The `app-create` skill itself lives in the **concurrentie-analyse** repo and is out of scope here — recorded as a coordination task, not edited from this change.

### Byte-alignment invariant (template-specific)

Every PHP file this change keeps or rewrites MUST stay byte-identical to the petstore sibling change `petstore/openspec/changes/apphost-tutorial-overwrite` modulo namespace tokens (`AppTemplate`↔`Petstore`, `app-template`↔`petstore`, `app_template`↔`petstore`). The check is the namespace-normalised md5 comparison used in the 2026-06-12 inventory, proposed as a CI-able script (see tasks). Domain-flavoured identifiers inside aligned files (the Layer-2 tutorial check id, the Layer-3 provider sample name — petstore's draft uses `store_status`) must either use app-token-derived names or be added to the agreed normalisation map; this is reconciled with the petstore change owner before implementation.

## Impact

- **Deleted**: 6 PHP files (~1,200 lines of drifting boilerplate); 1 obsolete unit test.
- **Rewritten**: 7 PHP files (1 Layer-2 override, 1 routes delegation, 1 Application stub, 4 one-line subclass stubs).
- **New**: 1 Layer-3 provider class, `observability` manifest block, byte-alignment check script suggestion.
- **Docs**: README three-layer section + inventory updates, project.md, scaffolding coordination issue.
- **Risk**: scaffolded apps copy whatever the template contains — a half-done restructure ships broken starters. Mitigated by the Newman parity assertions and the full quality-gate run before merge.

## Dependencies

- `apphost-observability-engine` (openregister) — generic health/metrics controllers, descriptor execution, `IMetricsProvider`.
- `apphost-boilerplate-controllers` (openregister) — `Bootstrap::register()`, `Routes::standard()`, generic controllers/services/stub base classes.
- Sibling: `petstore/openspec/changes/apphost-tutorial-overwrite` — must land with byte-aligned (namespace-normalised) PHP.
- ADR-040 (hydra), ADR-022, ADR-006 — unchanged, now demonstrated rather than hand-copied.
