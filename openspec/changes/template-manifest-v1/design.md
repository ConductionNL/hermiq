# Design — Template manifest v1

## Goal

Make `nextcloud-app-template` ship the JSON manifest renderer pattern
on first clone. After this change, the canonical "create a new
Conduction Nextcloud app" workflow is:

1. Clone the template.
2. `npm install && npm run build`.
3. Edit `src/manifest.json`: rename app, swap menu entries, add
   pages.
4. Drop a Vue component into `src/customComponents.js` only when a
   page is `type: "custom"`.

No Vue file edits are required to add an index, detail, dashboard,
or settings page. That is the value the template captures.

## File-by-file inventory (after this change)

```
nextcloud-app-template/
├── src/
│   ├── manifest.json              # NEW — canonical 4-page manifest
│   ├── main.js                    # REWRITTEN — Tier-4 mount-survivable bootstrap
│   ├── App.vue                    # REWRITTEN — <CnAppRoot> + #sidebar slot
│   ├── customComponents.js        # NEW — empty-by-default registry + 1 example
│   ├── exampleWidget.js           # KEPT — Nextcloud Dashboard widget entry
│   ├── settings.js                # KEPT — Nextcloud admin settings webpack entry
│   ├── pinia.js                   # KEPT — Pinia plugin install
│   ├── store/                     # KEPT — settings store still used by AdminSettings
│   ├── views/
│   │   ├── CustomExample.vue      # NEW — trivial example custom component
│   │   └── widgets/
│   │       └── ExampleWidget.vue  # KEPT — Nextcloud Dashboard widget
│   └── assets/app.css             # KEPT
│
│   ── DELETED ─────────────────
│   ├── router/index.js            # DELETED — routes built from manifest
│   ├── navigation/MainMenu.vue    # DELETED — CnAppNav replaces it
│   ├── views/Dashboard.vue        # DELETED — manifest type:"dashboard"
│   └── views/settings/            # DELETED — manifest type:"settings"
│
├── tests/
│   ├── validate-manifest.js       # NEW — Ajv validator (decidesk copy)
│   └── …                          # KEPT existing PHP test scaffold
│
├── l10n/
│   ├── en.json                    # NEW — empty placeholder
│   ├── en_US.json                 # NEW — empty placeholder
│   └── nl.json                    # KEPT
│
├── package.json                   # MODIFIED — bump nc-vue to ^1.0.0-beta.12, add check:manifest
├── webpack.config.js              # MODIFIED — add @nextcloud/axios$ alias
└── README.md                      # MODIFIED — manifest-first quickstart
```

## Manifest contents (`src/manifest.json`)

The template manifest is intentionally minimal — 4 pages, 4 menu
entries — so cloners see the shape of every supported page type
(`dashboard`, `index`, `detail`, `settings`) and can copy-paste.

| Page id      | Type      | Route          | Purpose                                                     |
|--------------|-----------|----------------|-------------------------------------------------------------|
| `Dashboard`  | dashboard | `/`            | Home; one example KPI widget pinned to the OR sample schema |
| `Items`      | index     | `/items`       | Schema-backed list view; demonstrates `register` + `schema` |
| `ItemDetail` | detail    | `/items/:id`   | Detail view with default audit + data tabs                  |
| `Settings`   | settings  | `/settings`    | `version-info` widget rich section                          |

The OR `register` slug is the placeholder string `"app-template"` and
the `schema` slug is `"item"` — both intentionally placeholder names
that show the cloner where to substitute their own register / schema
slugs.

## Bootstrap pattern (`src/main.js`)

Decidesk's mount-survivable pattern (commits `50e4df7c` + `866ff132`)
solves three Vue 2 + frozen-export gotchas the manifest renderer
exposes:

1. **`Vue.extend()` mutates component options** — Vue 2 caches a
   constructor on `_Ctor`. Webpack ESM module records are
   non-extensible, so passing `CnPageRenderer` directly throws
   "Cannot add property `_Ctor`". Fix: shallow-clone before passing
   to `vue-router`.
2. **`defaultPageTypes` is a frozen object** — same root cause; the
   lib exports it via the barrel. Fix: shallow-clone before passing
   to `<CnAppRoot :pageTypes>`.
3. **`loadTranslations` 404s in dev** — most NC dev installs only
   serve JS/CSS through Apache; `/l10n/<locale>.json` returns 404,
   `loadTranslations` rejects, and wrapping the mount inside its
   callback silently kills boot. Fix: mount immediately on
   `#content` and fire-and-forget the translation load.

## Smoke-test recipe (downstream cloner)

This is the recipe a downstream user runs after cloning to verify
the template still produces a working app:

```bash
# 1. Clone + rename
git clone https://codeberg.org/Conduction/nextcloud-app-template.git my-app
cd my-app

# 2. Customise app id (search-and-replace 'app-template' → 'my-app'
#    in appinfo/info.xml, package.json, openspec/app-config.json,
#    src/manifest.json — the manifest does NOT carry the app id;
#    main.js passes it via the app-id prop on CnAppRoot).
#
#    Pages: edit src/manifest.json. Add menu entries + pages[];
#    set the right `register` + `schema` slugs for your data.
#
#    OpenRegister optional? Remove "openregister" from
#    manifest.dependencies + appinfo/info.xml + openspec/app-config.json.

# 3. Install + build
npm install
npm run build
npm run check:manifest   # validates src/manifest.json against the schema

# 4. Mount in Nextcloud
make dev-link            # creates ../my-app symlink
docker exec nextcloud php occ app:enable my-app

# 5. Verify boot
#    Browse to http://localhost:8080/index.php/apps/my-app/
#    Expected:
#      - CnAppNav renders 3 left-side menu entries (Dashboard,
#        Items, Documentation) + Settings in the footer section.
#      - Dashboard renders the placeholder widget without errors.
#      - Items lists rows from register=app-template / schema=item
#        (or "Schema not found" if the cloner hasn't created it
#        yet — this is the expected next step, not a template bug).
#      - Settings renders the Version widget.
#
#    Browser console MUST be free of:
#      - "Cannot add property _Ctor, object is not extensible"
#      - "[CnAppRoot] manifest is required"
#      - "[useAppManifest] schema validation failed"
```

If the cloner sees the first error, the bootstrap pattern was
broken (a recent edit reintroduced the frozen-component issue).
If the second, `main.js` is no longer importing `manifest.json`.
If the third, `src/manifest.json` no longer validates against the
installed lib's schema (run `npm run check:manifest` to see why).

## Out-of-scope (deliberately deferred)

- A `make new-app NAME=...` scaffolder. Today the rename is a
  manual sed-style search-and-replace; a generator would also need
  to ship a list of files to template. Worth doing, but separate
  scope.
- Removing the OpenRegister wiring from PHP backend (`lib/Service`,
  `lib/Repair/InitializeSettings.php`). The template stays
  pre-wired for OR because the majority of new Conduction apps
  use OR; opting out is a 4-line README delta.
- A "kitchen-sink" manifest demonstrating every config knob the
  schema exposes (logs, chat, files page types). Adding too many
  example pages raises the cloner's deletion burden. The 4-page
  example is calibrated against decidesk's lived experience.
- Backend `/api/manifest` override endpoint. Tier 4 consumers
  ship the bundled manifest only; the override hook is opt-in
  per ADR-024 §4 and worth its own per-app change.

## Cleanup follow-up

None — this change makes the template the canonical Tier-4
scaffold. There is no follow-up "remove obsolete file X" commit
because the template's pre-manifest shell is fully replaced in
this single change.
