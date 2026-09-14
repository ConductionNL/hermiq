# Tasks: adopt-connection-registry

## 1. Declaration

- [ ] 1.1 `lib/Settings/connections.json` with the six connections of design D1.
- [ ] 1.2 Stable ids `section-ai-provider`, `section-web-research` and `section-organisation-credentials` on the admin section roots in `src/views/AdminRoot.vue`.
- [ ] 1.3 `tests/Unit/Settings/ConnectionsDeclarationTest.php`: validates against the vendored integriq schema with a control, app id, unique keys, anchors exist, keys equal `ConnectionReporter::KEYS`, no adapter block, no em-dash.

## 2. Page

- [ ] 2.1 `src/manifest.json`: page `Integrations`, settings menu entry with `query` and `visibleIf`.
- [ ] 2.2 `src/services/connectionRegistry.js` formatters and handler; `src/customComponents.js` handler; `formatters` passed to `CnAppRoot` from `src/App.vue`.
- [ ] 2.3 New page strings in `l10n/en.json` and `l10n/nl.json`, `l10n/*.js` regenerated.
- [ ] 2.4 `tests/connections-page.spec.js` (node).

## 3. Reports

- [ ] 3.1 `lib/Service/Connection/ConnectionReporter.php`: `report()` and `reportThrottled()` behind `class_exists`, never throws.
- [ ] 3.2 Event stub under `tests/Stubs/Integriq/Event/`, loaded by `tests/bootstrap.php` only when the real class is absent. Psalm already scans `tests/Stubs` as extra files.
- [ ] 3.3 `tests/Unit/Service/Connection/ConnectionReporterTest.php`: class present sends, class absent sends nothing, unknown key or status refused, throttle, throwing listener caught.
- [ ] 3.4 Callers of design D5 with their unit tests.

## 4. End to end

- [ ] 4.1 `tests/e2e/connections-page.spec.ts`, written and not run: it needs integriq installed and synced.

## 5. After integriq ships hydra#674

- [ ] 5.1 Run the e2e spec against an instance with both apps, then archive this change.
