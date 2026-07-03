# Tasks: App Template — AppHost Three-Layer Tutorial Restructure

## 0. Baseline

- [ ] 0.1 Confirm OR `apphost-observability-engine` and `apphost-boilerplate-controllers` are merged and deployed in the dev container (`docker exec nextcloud php occ app:update openregister` / verify `OCA\OpenRegister\AppHost\Bootstrap` exists).
- [ ] 0.2 Record the current endpoint behaviour as the parity baseline: `GET /apps/app-template/api/health` (public, JSON shape), `GET /apps/app-template/api/metrics` (admin, Prometheus text), `GET /apps/app-template/api/settings`, `GET/PUT /apps/app-template/api/preferences/{key}`, `GET /apps/app-template/` (SPA page + catch-all). Save responses for after/before diffing.
- [ ] 0.3 Snapshot the namespace-normalised md5 inventory of all `lib/**/*.php`, `appinfo/routes.php`, `templates/index.php` vs petstore (must be identical pre-change — it is as of 2026-06-12).
- [ ] 0.4 Read the petstore sibling change `petstore/openspec/changes/apphost-tutorial-overwrite/` (authored concurrently) and reconcile any structural divergence BEFORE writing code here.

## 1. Manifest observability block (Layer 1 declarative surface)

- [ ] 1.1 Add `observability` to `src/manifest.json`: `health.checks` = `[{"id":"database","type":"database"},{"id":"openregister","type":"orAvailable"}]` (worked example, commented in README).
- [ ] 1.2 Add ONE worked-example declarative metric: `objectCount` against the template's own register/schema slugs from `lib/Settings/app_template_register.json` (use the real slugs, not placeholders, so the endpoint returns live data on a scaffolded install).
- [ ] 1.3 Add the Layer-3 wiring descriptor: `{"name":"example_escape_hatch","source":{"kind":"provider"}}` in `metrics[]`.
- [ ] 1.4 Validate against the app-manifest v2 schema (gate-22 / `hydra-gate-manifest-validation`).

## 2. Three-layer restructure

### Layer 1 — pure declarative
- [ ] 2.1 Rewrite `appinfo/routes.php` to `return \OCA\OpenRegister\AppHost\Routes::standard();` (no `$extra` — the template has no domain routes; add a comment showing the `$extra` form for scaffolders).
- [ ] 2.2 Rewrite `lib/AppInfo/Application.php` to ~20 lines: `APP_ID` const, `Bootstrap::register($context, self::APP_ID)`, keep the existing MCP tool-provider alias, Dashboard widget registration, and DeepLink listener registration (teaching artifacts).
- [ ] 2.3 Delete `lib/Controller/DashboardController.php`, `lib/Controller/PreferencesController.php`, `lib/Controller/SettingsController.php`, `lib/Controller/MetricsController.php`, `lib/Service/SettingsService.php`, `lib/Service/ActionAuthService.php`.
- [ ] 2.4 Rewrite `lib/Repair/InitializeSettings.php`, `lib/Repair/InitializeActions.php`, `lib/Settings/AdminSettings.php`, `lib/Sections/SettingsSection.php` as one-line subclass stubs of the AppHost generics (NC instantiates these by class name from info.xml — comment explains WHY the stub must exist).
- [ ] 2.5 Verify info.xml needs no edits (repair-steps + settings entries keep pointing at the app-namespace stub classes).

### Layer 2 — declarative + override
- [ ] 2.6 Rewrite `lib/Controller/HealthController.php` as `class HealthController extends GenericHealthController`, overriding exactly ONE protected hook to append one tutorial check to the health payload. Comments are written for someone scaffolding a new app: placeholder vocabulary (`app_template`), "delete this class if you don't need to override", pointer to Layer 1 and Layer 3.
- [ ] 2.7 Wire the local class through `Bootstrap::register()`'s override option (local class wins over the generic alias) and document that line as the override mechanism.

### Layer 3 — imperative escape hatch
- [ ] 2.8 Create `lib/Observability/ExampleMetricsProvider.php` (same path as petstore) implementing `OCA\OpenRegister\AppHost\IMetricsProvider`, emitting one obviously-fake gauge. Heavy tutorial comments: when to use a provider (only when no descriptor kind fits), the ADR-035 alias registration, and the promotion rule (third app needing the same logic → new descriptor kind via ADR-040 amendment).
- [ ] 2.9 Register the provider alias `OCA\OpenRegister\AppHost\IMetricsProvider::app-template` in `Application.php`.

## 3. Verification

- [ ] 3.1 Update `tests/integration/app-template.postman_collection.json` (Newman): health is public + 200/JSON + contains the Layer-2 tutorial check id; metrics is admin-only + Prometheus text + contains `app_template_info`, `app_template_up`, the declarative `objectCount` metric, and the Layer-3 provider sample; settings + preferences endpoints behave identically to the 0.2 baseline.
- [ ] 3.2 Delete `tests/unit/Controller/SettingsControllerTest.php` (tests a deleted class); add a unit test for the `HealthController` override hook and one for `ExampleMetricsProvider` output shape.
- [ ] 3.3 Run the OR AppHost Newman contract collection against this app's endpoints (shape/auth/exposition guarded centrally).
- [ ] 3.4 Deploy to the dev container, `occ upgrade` if needed, and verify: SPA loads at `/apps/app-template/`, deep links survive the catch-all, admin settings page renders, dashboard widget still registers.

## 4. Docs + scaffolding sync + byte-alignment

- [ ] 4.1 Update `README.md` Directory Structure block (lines ~70–105): remove deleted files, mark the stubs, add `lib/Observability/ExampleMetricsProvider.php`.
- [ ] 4.2 Add a `README.md` "Three layers" section: Layer 1 (what a scaffolded app should DELETE and declare instead), Layer 2 (when/how to keep ONE override class), Layer 3 (when to write a provider) — with the per-file delete/keep table from the proposal.
- [ ] 4.3 Update `project.md` (file table references `lib/Settings/app_template_register.json` + `/app-create` flow) to match the new inventory.
- [ ] 4.4 Check `docs/canonical-files.md` — no tier changes expected (it covers config files, not lib/), but confirm and note.
- [ ] 4.5 COORDINATION (do not edit from this repo): open a tracking issue for the `app-create` skill in the **concurrentie-analyse** repo (`.claude/skills/app-create/`) — its rename/copy logic and file inventory must learn the new three-layer layout (fewer files to rename, stub classes, manifest observability block).
- [ ] 4.6 Byte-alignment check vs petstore: add `scripts/check-petstore-alignment.sh` (or document the one-liner) — for each kept/rewritten PHP file, normalise namespace tokens (`AppTemplate`→`__APP__`, `app-template`→`__app__`, `app_template`→`__app__`; same mapping on the petstore side) and compare md5 sums; non-zero exit on any mismatch. Suggest wiring it as a CI job in both repos (the 2026-06-12 inventory method). Reconcile domain-flavoured identifiers with the petstore owner first: the Layer-2 tutorial check id and Layer-3 sample name (petstore draft: `storefront` check, `store_status` metric) must be app-token-derived or covered by an agreed extra normalisation map entry.
- [ ] 4.7 Run the alignment check against the merged petstore branch; fix divergence on whichever side drifted (coordinate with the petstore change owner).

## 5. Quality gates

- [ ] 5.1 `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) green — fix any pre-existing issues encountered, don't defer.
- [ ] 5.2 All 18 hydra gates via `scripts/run-hydra-gates.sh` (spdx on every new/rewritten file, route-auth on the delegated routes, route-reachability for `Routes::standard()` targets, spec-coverage `@spec` tags on every changed method, redundant-controller must NOT flag the Layer-2 override — it has real body logic).
- [ ] 5.3 Gate-22 manifest validation (observability block against the canonical schema).
- [ ] 5.4 Gate-19 e2e coverage: scenarios in this change are API-only or repository-invariant — verify the `@e2e exclude` annotations are recognised (own-line form).
- [ ] 5.5 `npm run build` + frontend tests (`tests/manifest-v2.spec.js`, `tests/registry.spec.js`) still green.
