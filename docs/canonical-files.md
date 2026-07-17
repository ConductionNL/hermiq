---
draft: true
description: Internal engineering note — fleet template policy, not hermiq user documentation.
---

# Canonical files policy

This template is the **source of truth** for shared configuration across every
Conduction Nextcloud app in `apps-extra/`. The categorization below tells you
which files are byte-for-byte synced, which follow the template's *shape* but
carry per-app values, which are app-private, and which should never exist in
a repo root.

The authoritative architecture decision is
[ADR-033 in hydra](https://codeberg.org/Conduction/hydra/src/branch/development/openspec/architecture/adr-033-root-config-consolidation.md);
this doc is the developer-facing summary. When the two disagree, ADR-033 wins.

## Tier A — Strictly canonical (byte-for-byte synced)

Identical across every fleet app. When the template's copy changes, the
[`hydra/scripts/fleet-sync/`](https://codeberg.org/Conduction/hydra/src/branch/development/scripts/fleet-sync)
tool — run locally by a developer — opens a PR on every app in the fleet.

| File | What it does |
|---|---|
| `phpcs.xml` | PHPCS ruleset. Wires the Conduction custom sniffs + standard NC + PHPCompatibility. |
| `phpmd.xml` | PHPMD ruleset for `lib/` source. |
| `psalm.xml` | Psalm config (level + ignored files). |
| `phpstan.neon` | PHPStan config (level + paths). |
| `phpstan-bootstrap.php` | Bootstrap stubs so PHPStan can resolve `\OC` accessors. |
| `phpcs-custom-sniffs/CustomSniffs/Sniffs/**` | The custom-sniff ruleset (SpecTagSniff, NoLegacyServerAccessorsSniff, etc.). |
| `stylelint.config.js` | CSS/SCSS lint config for `src/**`. |
| `eslint.config.js` | ESLint flat config (replaces `.eslintrc.*`) for `src/`. |
| `.prettierrc` | Prettier config. |
| `.gitattributes` | Line-ending normalization + binary-file marks. |
| `.npmrc` | npm registry policy (cooldown + `legacy-peer-deps=true`). |
| `.nvmrc` | Node version floor (currently `20`). |

**No per-app deviations.** The canonical files sync byte-for-byte including
their description / ruleset-name strings. The template's `phpcs.xml` and
`phpmd.xml` use app-generic descriptions
(`"Conduction Nextcloud app coding standard…"`) precisely so no per-app
re-stamping is needed after sync. If an app needs different rules, the
change goes into the template first and propagates to the fleet via the
next sync.

The earlier convention of allowing per-app `<description>` and
`<ruleset name>` strings was retired in 2026-05 — it created a manual
re-stamping step after every sync and made drift hard to spot at a glance
(an app's accidental rule edit looked identical to a legitimate
description-only deviation in the diff). Generic strings make any diff
visible at sync time.

## Tier B — Template-based (per-app values, same shape)

The file structure and key-set comes from the template, but content is
legitimately per-app. **Not synced.** Use the template's copy as a starting
point when scaffolding a new app; do not auto-update existing apps.

| File | Per-app variation |
|---|---|
| `composer.json` | `name`, `description`, autoload PSR-4 prefix, app-specific deps. |
| `composer.lock` | Generated from per-app `composer.json`. |
| `package.json` | `name`, app-specific deps + scripts, version. |
| `package-lock.json` | Generated from per-app `package.json`. |
| `webpack.config.js` | Entry-point list, asset paths, externals matrix. |
| `playwright.config.ts` | Per-app E2E base URL + project list. |
| `.gitignore` | Mostly common, but per-app build artefacts + ignored dirs. |
| `.license-overrides.json` | Per-app allow-list of compound-SPDX vendor packages. |
| `jest.config.js` | Test path globs differ slightly per app structure. |
| `README.md` | App name + features + screenshots, structural sections common. |

The shape conformance is checked at scaffold time (template clone) and
opportunistically when an app gets a "scaffold drift" sweep. There is no
automated fleet workflow for Tier B.

## Tier C — App-specific (never synced)

Each app owns these in full. The template ships them as scaffolding, but
they diverge immediately and stay app-private.

| File | Why per-app |
|---|---|
| `phpstan-baseline.neon` | Pre-existing-debt allow-list, regenerated per app. |
| `psalm-baseline.xml` | Same — per-app debt baseline. |
| `phpmd.baseline.xml` | Same — when an app baselines PHPMD violations. |
| `appinfo/info.xml` | App id, version, dependencies, certificate. |
| `LICENSE` | Per-app license (EUPL-1.2 for ConductionNL apps but app sets its own). |
| `Makefile` | Per-app build/install/release shortcuts. |
| `lib/Settings/<app>_register.json` | The OR register definition for this app. |

## Tier D — Gitignored / should never be in the repo

Build artefacts, IDE state, test output. These should never be committed.
If you see one in a repo root, delete it and add a `.gitignore` rule.

| File | Why ignore |
|---|---|
| `.phpunit.cache/` | PHPUnit's cache dir; regenerated. |
| `.phpunit.result.cache` | Same — PHPUnit run cache. |
| `phpcs-output.json`, `phpcs-output-after.json` | PHPCS report scratch. |
| `coverage.txt`, `coverage/`, `coverage/html/` | Coverage report output. |
| `*.phar` (e.g. `phpstan.phar`, `composer.phar`) | Tool binaries — install via composer/PATH. |
| `psalm copy.xml`, `psalm-baseline copy.xml` | Stray editor-side filename collisions. |
| `bom-npm-test.cdx.json`, `sbom.cdx.json` (uncommitted) | SBOM artefacts if generated locally. |
| `.last-update`, `.opsx-ignore` | Tool scratch files. |

The template's `.gitignore` already lists most of these. If you find a fleet
app committing one, file a small cleanup PR.

## Tier E — Per-app extras (audit + decide)

Files that exist in some apps but not the template, not Tier B/C/D. Audit
each one and pick a path:

1. **Promote to Tier A** — useful for everyone, move into the canonical set.
2. **Promote to Tier B/C** — useful but per-app, add to the scaffold.
3. **Move out of repo root** — into `scripts/`, `docs/`, or `tests/` if it
   really should live in-repo, otherwise delete.
4. **Delete** — work-in-progress notes, debug scratchpads, abandoned spikes.

The current per-app extras are inventoried in
[`docs/fleet-extras-audit.md`](fleet-extras-audit.md). When you add a new
class of file to an app, ask "should this be in scope for the fleet?" If yes,
land it in the template first.

For drift in things **other than root files** — `composer.json` scripts,
`package.json` scripts, CI workflows, `appinfo/info.xml`, README
structure — see [`docs/fleet-drift-deeper.md`](fleet-drift-deeper.md).
The drift there is significant (e.g. PHP min-version in `info.xml`
ranges from 8.0 to 8.3 across the fleet despite the composer
constraint being unified at `^8.3`); that doc has the recommendations
for each class.

## How a canonical change flows

1. Open a PR against `nextcloud-app-template` (the fleet template repo) editing the canonical
   file. Test locally that the new rule is meaningful.
2. Merge to `development`.
3. **Locally**, from inside the hydra dev container, run the fleet-sync tool:

   ```bash
   cd /home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/hydra
   ./scripts/fleet-sync/sync.sh             # all fleet apps
   ./scripts/fleet-sync/sync.sh openconnector procest pipelinq   # subset
   ```

4. The script opens a PR on every targeted fleet app using **your local
   `gh auth` credentials**. The PRs carry the same diff and PR body for
   every target so review is consistent.
5. Each app's quality gates run on the PR. If they fail, the app needs
   pre-cleanup before the canonical change can land — file a per-app cleanup
   issue, fix, merge that, then re-run the sync.

The `phpstan-baseline.neon` and `psalm-baseline.xml` files capture pre-existing
debt **per app** so a canonical rule tightening doesn't cascade into a fleet
of broken PRs.

### Why local instead of a GitHub Action

The earlier design ran the sync as a GHA workflow with a `FLEET_SYNC_TOKEN`
secret. We retired that pattern in 2026-05: a single PAT with write access
to every fleet repo, stored as a GHA secret, was a credential blast-radius
we did not want to defend. Local-dev sync uses your own `gh auth` session
— no shared token, no GHA secret to rotate, no third-party action with
broad fleet access. Trade-off: someone has to run the sync manually after
a canonical change. That's a small cost paid for a real security
improvement.

Full design rationale + the sync script are in
[`hydra/scripts/fleet-sync/`](https://codeberg.org/Conduction/hydra/src/branch/development/scripts/fleet-sync).

## What's NOT in scope

- **ExApp sidecar wrappers** (`valtimo`, `openzaak`, `opentalk`, `openklant`)
  — these are Python ExApps wrapped in a thin PHP shim; they have different
  CI shape and don't carry the PHP/JS toolchain. They're deliberately
  excluded from the fleet sync. Their root files are mostly `Dockerfile`,
  `entrypoint.sh`, `requirements.txt`, `controller.toml`.
- **External repos** (`Softwarecatalogus`, `nextcloud-vue` library,
  `hydra`) — these are not Conduction Nextcloud apps. They have their own
  config policies.
- **Apps in `apps-extra/` not listed in the sync config** — typically
  in-development apps that haven't joined the fleet yet. Add them to
  the hydra sync tool's repos list when they're ready.

## Quick reference

| Question | Answer |
|---|---|
| Where does the canonical rule live? | This repo's root. |
| Who owns the canonical? | Whoever's editing the template. Land the change in the template first. |
| What if my app needs a per-app deviation? | Don't. Edit the template instead so everyone gets the change. The cosmetic deviations in `phpcs.xml` description / `phpmd.xml` ruleset name are the only allowed exceptions. |
| How do I trigger a fleet sync? | Locally: `cd hydra && ./scripts/fleet-sync/sync.sh [target-app …]`. Uses your `gh auth` session — no GHA secrets needed. |
| What if the sync PR breaks an app's tests? | Don't merge it. File a per-app cleanup issue, fix the app's source until it passes against the new canonical, then merge the sync PR. |
