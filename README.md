<p align="center">
  <img src="img/app-store.svg" alt="Hermiq logo" width="80" height="80">
</p>

<h1 align="center">Hermiq</h1>

<p align="center">
  <strong>Schedule autonomous AI agents in Nextcloud</strong>
</p>

<p align="center">
  <a href="https://github.com/ConductionNL/hermiq/releases"><img src="https://img.shields.io/github/v/release/ConductionNL/hermiq" alt="Latest release"></a>
  <a href="https://github.com/ConductionNL/hermiq/blob/main/LICENSE"><img src="https://img.shields.io/badge/license-EUPL--1.2-blue" alt="License"></a>
  <a href="https://github.com/ConductionNL/hermiq/actions/workflows/code-quality.yml"><img src="https://github.com/ConductionNL/hermiq/actions/workflows/code-quality.yml/badge.svg" alt="Code quality"></a>
</p>

---

Hermiq brings autonomous AI agents to Nextcloud. Define an agent, give it tools, and run it on a schedule — all inside your own Nextcloud. Agents, their memory and skills are stored as OpenRegister objects, so every run is governed, auditable and multi-tenant.

> **Manifest-first** — pages, navigation, and dependencies are declared in `src/manifest.json`. The shell (CnAppRoot) reads the manifest at boot and renders index / detail / dashboard / settings pages without per-page Vue files. Reach for a custom Vue component only when the page is `type: "custom"`. See `openspec/architecture/` and hydra ADR-024 for the architectural rationale.

> **Pre-wired for [OpenRegister](https://github.com/ConductionNL/openregister)** — `manifest.dependencies` lists `openregister`, so CnAppRoot's dependency-check phase ensures the OR app is installed and enabled before the UI mounts. If your app does not need OpenRegister, remove the entry from `src/manifest.json`, `appinfo/info.xml`, and `openspec/app-config.json`.

> **Canonical root configs** — `phpcs.xml`, `phpmd.xml`, `psalm.xml`, `phpstan.neon`, and `phpstan-bootstrap.php` in this repo are the fleet canonical. All Conduction PHP apps are expected to mirror these files byte-for-byte; per-app deviations belong in baselines (`phpstan-baseline.neon`, `psalm-baseline.xml`) not in the canonical files. Submit changes here and they propagate to the fleet via the template-sync flow — do **not** diverge per-app.

## Screenshots

Screenshots are captured automatically from the tutorial flows, not pasted in by hand. The journeydoc scaffold (hydra ADR-030) ships two tutorial stories under [`docs/tutorials/`](docs/tutorials/) — a user "first launch" walkthrough (the Dashboard) and an admin "manage settings" walkthrough (Admin Settings) — and a Playwright `docs-capture` project that turns each documented step into a PNG under `docs/static/screenshots/tutorials/`.

- Add a tutorial story: `/journeydoc-add-story`
- Add stable `data-testid` hooks to a Vue component: `/journeydoc-instrument`
- (Re)capture screenshots against a running Nextcloud: `NEXTCLOUD_URL=http://localhost:8080 npx playwright test --project docs-capture`

See the [Documentation](#documentation) section below for how the docs site itself is built and deployed.

## Features

Features are defined in [`openspec/specs/`](openspec/specs/). See the [roadmap](openspec/ROADMAP.md) for planned work.

### Core
- **Dashboard** — Personal overview page with key information at a glance
- **Admin Settings** — Configurable settings panel for administrators

### Supporting
- **OpenRegister Integration** — Pre-wired data layer using OpenRegister objects
- **Quality Pipeline** — PHPCS, PHPMD, Psalm, PHPStan, ESLint, Stylelint

## Architecture

```mermaid
graph TD
    A[Vue 2 Frontend] -->|REST API| B[OpenRegister API]
    B --> C[(PostgreSQL JSON store)]
    A --> D[Nextcloud Activity]
    A --> E[Nextcloud Search]
```

_Update this diagram during `/app-explore` sessions as the architecture evolves._

### Data Model

| Object | Description |
|--------|-------------|
| _(define your data objects here)_ | — |

_Data model is defined using OpenRegister schemas. See [`openspec/specs/`](openspec/specs/) for feature-level design decisions and [`openspec/architecture/`](openspec/architecture/) for architectural decisions._

### Directory Structure

```
hermiq/
├── appinfo/                    # Nextcloud app manifest, routes, navigation
├── lib/                        # PHP backend
│   ├── AppInfo/Application.php
│   ├── Controller/             # DashboardController, SettingsController
│   ├── Dashboard/              # Nextcloud Dashboard widget classes (ExampleWidget)
│   ├── Mcp/ExampleToolProvider.php  # AI Chat Companion tools (hydra ADR-034/035)
│   ├── Service/SettingsService.php
│   ├── Listener/DeepLinkRegistrationListener.php
│   ├── Repair/InitializeSettings.php
│   └── Settings/               # AdminSettings, hermiq_register.json
├── templates/                  # PHP templates (SPA shells)
├── src/                        # Vue 2 frontend
│   ├── manifest.json           # Pages + menu + dependencies (v2 — the source of truth)
│   ├── main.js                 # App entry — bootstraps CnAppRoot from manifest
│   ├── App.vue                 # Mounts CnAppRoot + #sidebar slot
│   ├── registry.js             # v2 five-kind component registry (widget/modal/page/form-field/cell-renderer)
│   ├── customComponents.js     # v1 registry (kept for backward-compat; remove once v2 migration complete)
│   ├── widgets/                # kind: "widget" components (ExampleWidget.vue)
│   ├── modals/                 # kind: "modal" components (ExampleModal.vue)
│   ├── formFields/             # kind: "form-field" components (EmailField.vue)
│   ├── cellRenderers/          # kind: "cell-renderer" components (StatusBadge.vue)
│   ├── exampleWidget.js        # Sample Nextcloud Dashboard widget entry-point
│   ├── settings.js             # Nextcloud admin settings webpack entry-point
│   ├── store/                  # Pinia stores (used by AdminSettings)
│   ├── views/CustomExample.vue # Example custom component (registry demo)
│   └── views/widgets/          # Dashboard widget Vue components (ExampleWidget.vue)
├── openspec/                   # Specifications, decisions, and roadmap
│   ├── app-config.json         # Canonical app config (id, goal, dependencies, CI)
│   ├── config.yaml             # OpenSpec CLI configuration
│   ├── specs/                  # Feature specs (input for OpenSpec changes)
│   ├── architecture/           # App-specific Architectural Decision Records
│   ├── ROADMAP.md              # Product roadmap
│   └── changes/                # OpenSpec change directories (created on first change)
├── docs/                       # Docusaurus documentation site (@conduction/docusaurus-preset)
│   ├── docusaurus.config.js    # Site config — title, url, navbar, brand theme
│   ├── intro.md                # Docs landing page
│   ├── src/pages/index.js      # Marketing landing page (brand <DetailHero> / <WidgetShelf>)
│   ├── tutorials/              # journeydoc tutorial stories — user/ + admin/ tracks (ADR-030)
│   └── static/screenshots/     # Captured tutorial screenshots (populated by docs-capture)
├── tests/                      # Unit and integration tests
│   ├── e2e/docs-screenshots.spec.ts # journeydoc screenshot capture suite (Playwright docs-capture project)
│   ├── validate-manifest.js    # Ajv schema validator for src/manifest.json (nc-vue manifest schema)
│   ├── validate-register.js    # Structural validator for lib/Settings/*_register.json (slugs, lifecycle requires→PHP, clobber heuristic)
│   └── validate-json-strict.js # Strict JSON parse — rejects duplicate keys + appendOnly nested in x-openregister
├── playwright.config.ts        # Playwright config — `chromium` (regression) + `docs-capture` (screenshots) projects
├── l10n/                       # Translations (en, en_US, nl)
├── .github/workflows/          # CI/CD pipelines (incl. documentation.yml — deploys docs/ from `development`)
├── Makefile                    # Dev helpers (make dev-link)
└── img/                        # App icons and screenshots
```

## Requirements

| Dependency | Version |
|-----------|---------|
| Nextcloud | 28 – 33 |
| PHP | 8.1+ |
| Node.js | 20+ |
| [OpenRegister](https://github.com/ConductionNL/openregister) | latest |

## Installation

### From the Nextcloud App Store

1. Go to **Apps** in your Nextcloud instance
2. Search for **Hermiq**
3. Click **Download and enable**

> OpenRegister must be installed first. [Install OpenRegister →](https://apps.nextcloud.com/apps/openregister)

### From Source

```bash
cd /var/www/html/custom_apps
git clone https://github.com/ConductionNL/hermiq.git hermiq
cd hermiq
npm install && npm run build
php occ app:enable hermiq
```

## Development

### Start the environment

```bash
docker compose -f ../openregister/docker-compose.yml up -d
```

### Frontend development

```bash
npm install
npm run dev              # Watch mode
npm run build            # Production build
npm run check:manifest   # Validate src/manifest.json against the nc-vue manifest schema
npm run check:register   # Validate lib/Settings/*_register.json (schema shape, slugs, lifecycle requires → PHP class exists)
npm run check:json-strict # Strict JSON parse of the config files — fails on duplicate keys (the silent-data-loss class of bug a bad git merge produces)
npm run check:specs      # All three of the above — run this before committing any register/manifest change
```

> **Why `check:specs`?** `git` merges JSON files line-by-line — two branches that both add an object at the same key but at different file positions produce **no textual conflict**, just a document with a duplicate key, and `json_decode()` keeps the *last* one (so the earlier, fuller definition is silently dropped). `check:json-strict` fails CI on that. `check:register` also catches a lifecycle `requires:` pointing at a PHP class that doesn't exist, and `appendOnly` nested in `x-openregister` (which OpenRegister silently ignores). The `Spec Validation` GitHub workflow runs `check:specs` on every push/PR; add it to your branch-protection ruleset's required checks to make it block merges.

### Settings menu / NcAppSettingsDialog

The `Settings` menu entry uses `action: "user-settings"` → opens `NcAppSettingsDialog` via CnAppRoot's `cnOpenUserSettings` inject; feed your settings sections into `App.vue`'s `#user-settings` slot.

### Adding a page (manifest-first)

Pages live in [`src/manifest.json`](src/manifest.json) — NOT in
`src/views/`. To add a page, edit the manifest:

1. **Add a menu entry** in `manifest.menu` (id, label, icon, route).
2. **Add a page** in `manifest.pages` (id matches the menu's `route`).
   Pick the `type`:

   | Type        | Use when                                                    |
   |-------------|-------------------------------------------------------------|
   | `dashboard` | Home / overview with KPI widgets                            |
   | `index`     | Schema-backed list view (CnDataTable + sidebar)             |
   | `detail`    | Single-object view at `/things/:id`                         |
   | `settings`  | Admin/user settings with `version-info` / `register-mapping`|
   | `logs`      | Tail-style audit log view                                   |
   | `chat`      | Conversation thread page                                    |
   | `files`     | Folder browser                                              |
   | `custom`    | Bespoke Vue component (last resort)                         |

3. **Run `npm run check:manifest`** to verify the manifest validates.

You only write a Vue file when the page is `type: "custom"`. In that
case, drop the component into `src/views/`, register it in
[`src/customComponents.js`](src/customComponents.js), and reference
its registry name in the manifest entry's `component` field. See
[`src/views/CustomExample.vue`](src/views/CustomExample.vue) for the
canonical example.

### Renaming the app

Search-and-replace `hermiq` (the `<id>` from `appinfo/info.xml`)
in: `appinfo/info.xml`, `package.json`, `openspec/app-config.json`,
`src/main.js` (the `app-id` prop, the `loadTranslations` arg, and the
`generateUrl` base path), `src/App.vue` (the `app-id` prop and
`translateForApp` argument), and `webpack.config.js` (the `appId`
constant). The manifest itself does not carry the app id.

### Manifest v2 ready

The scaffold ships a **v2 manifest** by default (`src/manifest.json` declares
`$schema` pointing to the v2 schema URL). V2 collapses the four v1 widget
shapes into a single uniform `widgets[]` array with grid coordinates on every
page type, and introduces a five-kind component registry.

**Design reference:** hydra
[ADR-036 (Universal Widget Manifest v2)](https://github.com/ConductionNL/hydra/blob/development/openspec/architecture/adr-036-universal-widget-manifest.md)

**Migration guide** (for apps migrating from v1): `@conduction/nextcloud-vue`
docs `migrating-to-v2.md` covers the codemod CLI and manual migration steps.

#### Extending the app via the registry (`src/registry.js`)

The v2 way to add custom UI is `src/registry.js`. It has five kinds:

| kind | Use when |
|------|----------|
| `widget` | Custom placeable widget — add to any page's `widgets[]` via `widgetKey` |
| `modal` | Dialog opened by manifest actions with `type: "open-modal"` |
| `page` | Bespoke full-page component (`type: "custom"` in the manifest — use sparingly) |
| `form-field` | Custom form input auto-bound by JSON Schema `format` |
| `cell-renderer` | Custom table-cell rendering auto-bound by schema + property |

Steps to add a custom widget:

1. Create `src/widgets/<YourWidget>.vue`.
2. Add an entry to `src/registry.js` with `kind: "widget"` + `defaultSize`, `minSize`, `maxSize`, `allowedSlots`, `propsSchema`.
3. Add a `widgets[]` entry to the target page in `src/manifest.json` with `widgetKey: "<your-key>"`, `slot`, and grid coordinates.
4. Run `npm run check:manifest-v2` to validate.

The scaffold ships one example per kind as a starting point. Delete or
replace the examples when you clone the template.

> **Backward compat:** `src/customComponents.js` is kept for the v1 → v2
> transition period. Both the `customComponents` and `registry` props coexist
> on `CnAppRoot`. Once fully migrated, `customComponents.js` and its import in
> `main.js` can be removed.

### Adding a dashboard widget

The template ships with a working `ExampleWidget` you can copy. Each widget is
**three files plus two registration points**:

1. `lib/Dashboard/<Foo>Widget.php` — implements `OCP\Dashboard\IWidget`. The
   `load()` method MUST attach the two shared chunks (`-shared-vendor`,
   `-shared-nc-vue`) **before** the per-widget bundle. Order matters; see
   `ExampleWidget.php` for the reference.
2. `src/<foo>Widget.js` — webpack entry-point that registers the renderer via
   `OCA.Dashboard.register('<id>', (el, { widget }) => { ... })`. The id MUST
   equal `Widget::getId()` from PHP.
3. `src/views/widgets/<Foo>Widget.vue` — the renderer itself. Wrap in
   `<NcDashboardWidget>` for free loading + empty states.
4. Register in `lib/AppInfo/Application.php`: add
   `$context->registerDashboardWidget(<Foo>Widget::class);`.
5. Add a webpack entry in `webpack.config.js` so `npm run build` produces
   `<appId>-<foo>Widget.js`.

The `optimization.splitChunks` block in `webpack.config.js` extracts shared
framework code (Vue, `@nextcloud/vue`, pinia, icons) into two chunks loaded
once across the page. Without it every widget bundle would inline ~3 MB of
duplicated framework code per entry-point. See ADR-004 (Build / bundling)
in the hydra repo for the full rationale.

### AI Chat Companion / MCP tools

The template ships an example **MCP tool provider** so the in-app AI Chat
Companion (a floating assistant rendered by `CnAppRoot` from
`@conduction/nextcloud-vue`) can call your app's capabilities. See:

- `lib/Mcp/ExampleToolProvider.php` — the heavily-commented starting point. It
  implements `OCA\OpenRegister\Mcp\IMcpToolProvider` and exposes two trivial
  tools: `hermiq.ping` (echoes a message) and `hermiq.describeApp`
  (returns the app id, version, and name).
- `lib/AppInfo/Application.php` — registers the provider under the service
  alias `OCA\OpenRegister\Mcp\IMcpToolProvider::{appId}`; OpenRegister's
  `McpToolsService` discovers per-app providers by exactly that alias.
- `tests/Stubs/Mcp/IMcpToolProvider.php` — a stand-in for the interface until
  [openregister](https://github.com/ConductionNL/openregister) Codeberg PR #1466
  (pre-migration, not migrated to GitHub)
  merges; once the openregister app is installed alongside your app the real
  interface takes over transparently.
- `tests/Unit/Mcp/ExampleToolProviderTest.php` — the contract test.

**To wire up your app:**

1. Rename `ExampleToolProvider` → `{YourApp}ToolProvider` (update the alias in
   `Application.php` and the test).
2. Replace the two example tools with real ones — each descriptor needs
   `id` (`{appId}.{toolName}`), `name`, `description`, and an `inputSchema`
   (JSON Schema object).
3. In `invokeTool()`, **run per-object authorisation before any business logic
   or data access** — validate args, then authorise, then delegate, then
   return. `invokeTool()` MUST NOT throw — every failure path returns a
   structured `['error' => ['code' => ..., 'message' => ...]]` array.

References: hydra
[ADR-034 (AI Chat Companion)](https://github.com/ConductionNL/hydra/blob/development/openspec/architecture/adr-034-ai-chat-companion.md)
and ADR-035; and decidesk's `OCA\Decidesk\Mcp\DecideskToolProvider` as the
production example (five real tools, deep links, source descriptors).

### Code quality

```bash
# PHP
composer check:strict   # All quality checks (PHPCS, PHPMD, Psalm, PHPStan, tests)
composer cs:fix         # Auto-fix PHPCS issues
composer phpmd          # Mess detection
composer phpmetrics     # HTML metrics report

# Frontend
npm run lint            # ESLint
npm run stylelint       # CSS linting
```

### Enable locally

Nextcloud requires the app directory name to match the `<id>` in `appinfo/info.xml` (`hermiq`).
When this repo is cloned as `hermiq`, no symlink is needed.

> **Note:** The `js/` build output is not committed. You must build the frontend before enabling the app, or the UI will be blank.

```bash
npm install && npm run build
docker exec nextcloud php occ app:enable hermiq
```

## Tech Stack

| Layer | Technology |
|-------|-----------|
| Frontend | Vue 2.7, Pinia, @nextcloud/vue |
| Build | Webpack 5, @nextcloud/webpack-vue-config |
| Backend | PHP 8.1+, Nextcloud App Framework |
| Data | OpenRegister (PostgreSQL JSON objects) |
| UX | @conduction/nextcloud-vue |
| Quality | PHPCS, PHPMD, Psalm, PHPStan, ESLint, Stylelint |

## Branches

| Branch | Purpose |
|--------|---------|
| `main` | Stable releases — triggers release workflow |
| `beta` | Beta / pre-release builds |
| `development` | Active development — merge target for feature branches |

## Documentation

The user-facing documentation site lives in [`docs/`](docs/) — a Docusaurus site built on [`@conduction/docusaurus-preset`](https://www.npmjs.com/package/@conduction/docusaurus-preset) with the brand `<DetailHero>` / `<WidgetShelf>` landing page, the journeydoc tutorial scaffold ([`docs/tutorials/`](docs/tutorials/) — user "first launch" + admin "manage settings"), and the Playwright `docs-capture` project for screenshots (hydra ADR-030).

`.github/workflows/documentation.yml` deploys the site on every push to `development`: it runs `cd docs && npm ci && npm run build` and publishes to `<slug>.conduction.nl` (hermiq's slug is `hermiq`, so `hermiq.conduction.nl` — `docs/static/CNAME` carries this). Build the site locally with:

```bash
cd docs
npm ci --legacy-peer-deps
npm run build      # → build/  ([SUCCESS] Generated static files)
npm run start      # local dev server with hot reload
```

Project / spec documentation:

| Resource | Description |
|----------|-------------|
| [`openspec/app-config.json`](openspec/app-config.json) | App identity, goals, dependencies, and CI configuration |
| [`openspec/specs/`](openspec/specs/) | Feature specs — what the app should do |
| [`openspec/architecture/`](openspec/architecture/) | App-specific Architectural Decision Records |
| [`openspec/ROADMAP.md`](openspec/ROADMAP.md) | Product roadmap |
| [`openspec/`](openspec/) | Implementation specifications and changes |

## Standards & Compliance

- **Accessibility:** WCAG AA (Dutch government requirement)
- **Authorization:** RBAC via OpenRegister
- **Audit trail:** Full change history on all objects
- **Localization:** English and Dutch

## Related Apps

- **[OpenRegister](https://github.com/ConductionNL/openregister)** — Object storage layer (required dependency)

_Add related apps here as integrations are built._

## Troubleshooting

### App UI is blank after enabling

The `js/` build output is not committed to the repo. Run the frontend build before enabling the app:

```bash
npm install && npm run build
```

## Support

For support, contact us at [support@conduction.nl](mailto:support@conduction.nl).

For a Service Level Agreement (SLA), contact [sales@conduction.nl](mailto:sales@conduction.nl).

## License

This project is licensed under the [EUPL-1.2](LICENSE).

### Dependency license policy

All dependencies (PHP and JavaScript) are automatically checked against an approved license allowlist during CI. The following SPDX license families are approved:

- **Permissive:** MIT, ISC, BSD-2-Clause, BSD-3-Clause, 0BSD, Apache-2.0, Unlicense, CC0-1.0, CC-BY-3.0, CC-BY-4.0, Zlib, BlueOak-1.0.0, Artistic-2.0, BSL-1.0
- **Copyleft (EUPL-compatible):** LGPL-2.0/2.1/3.0, GPL-2.0/3.0, AGPL-3.0, EUPL-1.1/1.2, MPL-2.0
- **Font licenses:** OFL-1.0, OFL-1.1

## Authors

Built by [Conduction](https://conduction.nl) — open-source software for Dutch government and public sector organizations.
