---
kind: code
---

# Proposal: remove-scaffold-leftovers

# Why

Hermiq is at `appinfo/info.xml` version `0.1.44`, 44 releases past scaffold, and already
has one precedent commit for exactly this class of cleanup: `10fdc34 chore: drop
scaffold-leftover ExampleWidget (ADR-049)` (merged via PR #10, `a15585d`). Two more
scaffold leftovers from the same app-template generation remain unremoved:

1. **`lib/Mcp/ExampleToolProvider.php`** — a template "copy-me starting point" MCP tool
   provider (`lib/Mcp/ExampleToolProvider.php:4-10`: "Every new Conduction app generated
   from this template ships this class ... rename it to `<YourApp>ToolProvider`"). It is
   never registered in `lib/AppInfo/Application.php` — only `HermiqToolProvider` is
   (`lib/AppInfo/Application.php:95-96`). It is dead code: unreachable from OpenRegister's
   `McpToolsService` discovery, exercised only by its own unit test
   (`tests/Unit/Mcp/ExampleToolProviderTest.php`) and referenced in `tests/bootstrap-unit.php:38`.
2. **The `example` schema + `Examples`/`ExampleDetail` pages** — `lib/Settings/hermiq_register.json:19-45`
   defines a schema literally titled `"Example schema — replace with your app's actual
   schemas."`; `src/manifest.json` still carries live `Examples` (`route: /examples`) and
   `ExampleDetail` (`route: /examples/:id`) pages, WITH a main-nav entry (`"id": "Examples",
   "label": "Examples", "order": 30"`) that ships to every end user; `src/views/CustomExample.vue`
   is wired through both `src/registry.js:45-92` and `src/customComponents.js:27-47`.

None of this is used by any real Hermiq feature — grepping the 17 non-archived
`openspec/changes/*` directories shows no spec referencing the `example` schema or the
`Examples` page. It is pure scaffold residue shipping to production: a confusing extra
menu entry for end users, a schema devs might mistake for a real seam, and PHP/JS that
inflates the codebase for no product value — the same rationale ADR-049
("custom widgets → 0") already used to justify removing `ExampleWidget`.

# What Changes

- Delete `lib/Mcp/ExampleToolProvider.php` and `tests/Unit/Mcp/ExampleToolProviderTest.php`;
  remove its reference in `tests/bootstrap-unit.php:38`.
- Remove the `example` schema block from `lib/Settings/hermiq_register.json`; bump the
  register's `info.version`; confirm the repair-step re-import drops the schema cleanly
  (no orphaned `example` objects expected — scaffold schema, never used to create real
  data; if any exist in a dev DB, log a warning rather than silently deleting user data).
- Remove the `Examples` and `ExampleDetail` page entries from `src/manifest.json`
  (`pages[]`) and the `Examples` nav entry from `src/manifest.json` (`menu[]`).
- Delete `src/views/CustomExample.vue` and its wiring in `src/registry.js` (the
  `example-modal` custom action + `CustomExample` component registration + the "example"
  schema custom cell renderer) and `src/customComponents.js`.
- **BREAKING**: removes the `/examples` and `/examples/:id` routes and the `example`
  OpenRegister schema. Any external deep link or integration pointing at either disappears
  — acceptable per ADR-044's no-functionality-loss invariant because this is dead scaffold
  content with zero real usage, not a live feature (the invariant protects real pages, not
  template placeholders).

# Impact

- Affected code: `lib/Mcp/ExampleToolProvider.php` (deleted), `lib/Settings/hermiq_register.json`,
  `src/manifest.json`, `src/views/CustomExample.vue` (deleted), `src/registry.js`,
  `src/customComponents.js`, `tests/Unit/Mcp/ExampleToolProviderTest.php` (deleted),
  `tests/bootstrap-unit.php`.
- Affected specs: none of the 17 active `openspec/changes/*` reference the `example` schema
  or `Examples` pages — no spec cross-reference to update.
