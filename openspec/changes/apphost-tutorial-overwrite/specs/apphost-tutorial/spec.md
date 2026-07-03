---
status: proposed
---

# Spec: AppHost Three-Layer Tutorial (nextcloud-app-template)

## Purpose

The template app is the canonical teaching example for AppHost adoption. It demonstrates all three layers of the AppHost model — pure declarative, declarative + override, and the imperative escape hatch — with real, running code, because new apps are scaffolded from it and the override mechanism is itself tutorial content (the demo-app rule). It stays byte-aligned (namespace-normalised) with its sibling petstore.

## Requirements

### Requirement: Three-layer structure

The app SHALL be structured as exactly three AppHost layers: Layer 1 — routes, Application bootstrap, dashboard/preferences/settings endpoints, and observability served declaratively via `\OCA\OpenRegister\AppHost\Routes::standard()`, `Bootstrap::register()`, and a manifest `observability` block; Layer 2 — exactly one local override controller (`HealthController extends GenericHealthController`); Layer 3 — exactly one imperative provider (`lib/Observability/ExampleMetricsProvider.php` implementing `IMetricsProvider`) wired by a `{"kind":"provider"}` descriptor. No other hand-written copies of AppHost-absorbed boilerplate SHALL exist; NC-mandated app-namespace classes (repair steps, admin settings, settings section) SHALL be one-line subclass stubs of the AppHost generics.

#### Scenario: Declarative routes serve the standard surface

- @e2e exclude API-only endpoint — covered by the OR AppHost Newman contract collection
- GIVEN the app is enabled and `appinfo/routes.php` returns `\OCA\OpenRegister\AppHost\Routes::standard()`
- WHEN a client requests `GET /apps/app-template/api/settings`, `GET /apps/app-template/api/preferences/{key}`, and `GET /apps/app-template/api/health`
- THEN each route resolves through the AppHost generic controllers (or the Layer-2 override for health)
- AND the response shapes, route names, verbs, and auth postures are bit-compatible with the pre-restructure baseline

#### Scenario: Declarative metric from the manifest observability block

- @e2e exclude API-only endpoint — covered by the OR AppHost Newman contract collection
- GIVEN `src/manifest.json` declares an `objectCount` metric against the template's own register and schema slugs
- WHEN an admin requests `GET /apps/app-template/api/metrics`
- THEN the response is Prometheus text format 0.0.4
- AND it contains the implicit `app_template_info` and `app_template_up` samples
- AND it contains the declared `objectCount` metric with a live value
- AND no hand-written `MetricsController` exists in `lib/Controller/`

#### Scenario: Boilerplate files are deleted, stubs are one-liners

- @e2e exclude repository-level invariant — verified by the byte-alignment check task, no runtime surface
- GIVEN the restructured working tree
- WHEN the file inventory is compared against the proposal's delete/keep table
- THEN `DashboardController.php`, `PreferencesController.php`, `SettingsController.php`, `MetricsController.php`, `SettingsService.php`, and `ActionAuthService.php` do not exist
- AND `InitializeSettings.php`, `InitializeActions.php`, `AdminSettings.php`, and `SettingsSection.php` are one-line subclass stubs of the AppHost generic classes
- AND `ExampleWidget.php`, `ExampleToolProvider.php`, and `DeepLinkRegistrationListener.php` remain unchanged as teaching artifacts

### Requirement: Layer-2 override works

The local `HealthController` SHALL extend `GenericHealthController`, override exactly one protected hook, and carry tutorial comments addressed to someone scaffolding a new app (placeholder vocabulary such as `app_template`, guidance on deleting the class when no override is needed). The override SHALL be observable in the health response while preserving the ADR-006 contract (public endpoint, JSON, 503 on critical failure).

#### Scenario: Override hook augments the health payload

- @e2e exclude API-only endpoint — covered by the OR AppHost Newman contract collection
- GIVEN the app is enabled with the Layer-2 `HealthController` registered through Bootstrap's local-class override option
- WHEN an unauthenticated client requests `GET /apps/app-template/api/health`
- THEN the response is HTTP 200 with `status: ok`
- AND the `checks` object contains the engine-executed manifest checks (`database`, `openregister`)
- AND it additionally contains the tutorial check contributed by the overridden protected hook

#### Scenario: Override preserves ADR-006 failure semantics

- @e2e exclude API-only endpoint — covered by the OR AppHost Newman contract collection
- GIVEN OpenRegister is unavailable
- WHEN an unauthenticated client requests `GET /apps/app-template/api/health`
- THEN the response is HTTP 503 with `status: error`
- AND the `openregister` check reports failure without leaking exception details

### Requirement: Layer-3 provider works

`ExampleMetricsProvider` SHALL implement `OCA\OpenRegister\AppHost\IMetricsProvider`, be registered under the ADR-035-style service alias for this app id, be referenced by a `{"kind":"provider"}` descriptor in the manifest's `metrics[]`, and carry tutorial comments explaining when imperative code is justified and the promotion rule back to declarative descriptors.

#### Scenario: Provider samples merge into the metrics exposition

- @e2e exclude API-only endpoint — covered by the OR AppHost Newman contract collection
- GIVEN the provider is registered and the `{"kind":"provider"}` descriptor is present in the manifest
- WHEN an admin requests `GET /apps/app-template/api/metrics`
- THEN the exposition contains the provider's example gauge alongside the declarative and implicit metrics
- AND removing the descriptor from the manifest removes the provider's samples without a PHP change

### Requirement: Byte-alignment with petstore

Every PHP file kept or rewritten by this change SHALL be byte-identical to the corresponding file in the petstore sibling change `apphost-tutorial-overwrite` after namespace-token normalisation (`AppTemplate`↔`Petstore`, `app-template`↔`petstore`, `app_template`↔`petstore`). The repository SHALL document or ship a namespace-normalised md5 comparison check suitable for CI so the invariant is enforced rather than aspirational.

#### Scenario: Namespace-normalised md5 comparison passes

- @e2e exclude repository-level invariant — verified by the byte-alignment check task, no runtime surface
- GIVEN the merged working trees of nextcloud-app-template and petstore
- WHEN each kept/rewritten PHP file has its namespace tokens normalised to a common placeholder and is md5-hashed on both sides
- THEN every file pair hashes identically
- AND any mismatch fails the check with the divergent file path listed

#### Scenario: Scaffolding docs describe the three layers

- @e2e exclude repository-level invariant — verified by the byte-alignment check task, no runtime surface
- GIVEN the updated `README.md` and `project.md`
- WHEN a scaffolder reads the Directory Structure and three-layer sections
- THEN the documented file inventory matches the actual working tree
- AND the docs state per layer what a scaffolded app should delete versus keep
- AND the `app-create` skill follow-up in the concurrentie-analyse repo is referenced as a tracked coordination item
