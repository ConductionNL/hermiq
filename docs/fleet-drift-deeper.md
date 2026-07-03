# Fleet drift research — beyond root files

The [`fleet-extras-audit.md`](fleet-extras-audit.md) snapshot covered
**presence** of root-directory files. This doc digs into **content** drift
across categories that aren't byte-for-byte canonical but still want to
follow the template's shape: `composer.json` scripts, `package.json`
scripts, GitHub Actions workflows, `appinfo/info.xml`, and `README.md`
structure.

Last audited: 2026-05-23.

The point of this audit is to identify drift that's **invisible** until
you compare apps side-by-side — and to decide for each class of drift
whether it should be:

- **Promoted to template canonical** (sync via hydra/scripts/fleet-sync).
- **Documented as scaffold-recommended** (template provides a starting
  point, per-app drift is acceptable).
- **Left alone** (legitimately per-app variation).

## 1. `composer.json` scripts

**Common-base** (scripts in ≥ 18 of 22 apps — strong canonical signal):

| Script | Apps with it | Notes |
|---|---|---|
| `lint` | 22/22 | ✓ universal |
| `cs:check`, `cs:fix` | 22/22 | ✓ universal |
| `psalm` | 22/22 | ✓ universal |
| `test:unit` | 22/22 | ✓ universal |
| `phpcs`, `phpcs:fix` | 21/22 | app-versions missing |
| `phpmd`, `phpmetrics`, `phpstan`, `test:all` | 20/22 | app-versions + opentalk missing |
| `phpqa`, `phpqa:full`, `phpqa:ci`, `qa:check`, `qa:full` | 19/22 | + zaakafhandelapp missing |
| `check`, `check:full`, `check:strict`, `fix`, `test:coverage`, `quality:score` | 18/22 | + opencatalogi missing |

**Outliers** with sub-canonical script sets:

| App | Script count | Likely reason |
|---|---|---|
| `opentalk` | 8 | ExApp sidecar — different model |
| `app-versions` | 9 | Stub / pre-rollout app |
| `zaakafhandelapp` | 16 | Partial quality-tooling sweep |
| `openregister` | 47 | Foundation repo — legitimately has extras |

**Recommendation:** the 25-script common-base IS the canonical script set.
Add a `docs/composer-scripts-template.md` to the template documenting
each script + its purpose, so the scaffold guides new apps. The
existing template's `composer.json` already carries most of them; the
fleet-sync tool doesn't need to touch composer.json (Tier B), but a
**scaffold drift** sweep could land the missing scripts on
`zaakafhandelapp` and `app-versions` if they're meant to be full
quality-tooled apps.

## 2. `package.json` scripts

**Common-base** (scripts in ≥ 15 of 18 apps with package.json):

| Script | Apps with it | Notes |
|---|---|---|
| `build` | 18/18 | ✓ universal |
| `lint` | 18/18 | ✓ universal |
| `stylelint` | 18/18 | ✓ universal |
| `dev` | 17/18 | only 1 missing |
| `watch` | 17/18 | only 1 missing |
| `lint-fix` | 15/18 | 3 missing |

**Notable drift** (only half the fleet has these):

| Script | Apps with it | Recommendation |
|---|---|---|
| `stylelint-fix` | 9/18 | Add to template scaffold — symmetric with `lint-fix` |
| `test` | 9/18 | **Real gap** — half the fleet has no JS unit-test gate |
| `test:e2e` | 9/18 | Same — half lacks E2E |
| `test-coverage` | 6/18 | Lower priority but worth standardizing |
| `check:manifest` | 6/18 | Manifest validator — should be 18/18 now that all apps use the manifest |
| `test:e2e:install`, `test:e2e:docs`, `test:e2e:ui` | 5/18, 6/18, 3/18 | Project-specific — leave alone |

**Apps with low package.json script count (≤ 9):**

| App | Scripts |
|---|---|
| `app-versions` | 5 |
| `decidesk`, `deskdesk`, `nldesign`, `planix` | 7 each |
| `opencatalogi` | 8 |
| `softwarecatalog`, `zaakafhandelapp` | 9 each |

**Recommendation:** the template should ship `test`, `test:e2e`,
`stylelint-fix`, and `check:manifest` in `package.json` scripts. A
scaffold-drift sweep can land these in the apps that lack them. The
JS-tooling subset (`build`, `lint`, `stylelint`, `dev`, `watch`,
`lint-fix`, `stylelint-fix`, `test`, `test:e2e`, `test-coverage`,
`check:manifest`) is a natural canonical set for the scaffold.

## 3. CI workflows (`.github/workflows/*.yml`)

**Canonical fleet workflow set** — currently in 13–17 of 22 apps:

| Workflow | Apps with it | Purpose |
|---|---|---|
| `code-quality.yml` | 17/22 | PHPCS/PHPMD/Psalm/PHPStan/PHPUnit gate |
| `documentation.yml` | 17/22 | Docusaurus build + deploy |
| `branch-protection.yml` | 16/22 | PR-base + reviewer rules |
| `sync-to-beta.yml` | 15/22 | development → beta auto-merge |
| `issue-triage.yml` | 14/22 | Auto-label issues |
| `release-beta.yml` | 14/22 | Beta-tag releases |
| `release-stable.yml` | 14/22 | Stable-tag releases |
| `openspec-sync.yml` | 13/22 | Pull OpenSpec changes from spec issues |
| `pull-request-lint-check.yaml` | 11/22 | PR-title conventional-commit format |

**Apps missing 2+ canonical workflows:**

| App | Workflow count | Comment |
|---|---|---|
| `larpingapp`, `openconnector`, `openregister`, `zaakafhandelapp` | 6 each | Surprising — heavily-developed apps that are missing standard CI scaffolding |
| `opencatalogi`, `openklant`, `opentalk`, `openzaak`, `valtimo` | 7 each | ExApp sidecars + opencatalogi behind on workflow sync |

**Apps with workflow noise** (legacy + one-off entries):

| File | Apps | Recommendation |
|---|---|---|
| `pull-request-from-branch-check.yaml` | 5 | Legacy — superseded by `pull-request-lint-check.yaml` |
| `release-workflow.yaml`, `beta-release.yaml`, `unstable-release.yaml`, `push-development-to-beta.yaml` | 4 each | Legacy — superseded by `release-beta.yml`/`release-stable.yml`/`sync-to-beta.yml` |
| `release.yml` | 2 | Legacy |
| `lint-eslint.yml`, `lint-info-xml.yml`, `lint-php-cs.yml`, `lint-php.yml`, `lint-stylelint.yml`, `node.yml`, `block-unconventional-commits.yml`, `fixup.yml`, `npm-audit-fix.yml` | 1 each | Legacy or one-off — fold into `code-quality.yml` or delete |

`app-versions` carries **13 workflows** — almost all legacy. Worth a
cleanup PR collapsing them to the canonical 9-workflow set.

**Recommendation:** the template's `.github/workflows/` is the
source-of-truth scaffold for the 9 canonical workflows. Add a
`docs/ci-workflows.md` documenting the canonical set + their triggers.
Workflows are **NOT** auto-synced by the hydra fleet-sync tool (too
risky — workflow changes can break apps); a scaffold-drift sweep is
the right mechanism, one app at a time.

## 4. `appinfo/info.xml` — significant drift

This is the **most fragmented** category and the most user-facing
(`info.xml` drives the Nextcloud app-store listing).

### PHP min-version

| Value | Apps |
|---|---|
| `8.0` | larpingapp, opencatalogi, openconnector, openregister, softwarecatalog, zaakafhandelapp |
| `8.1` | decidesk, deskdesk, launchpad, nldesign, openbuild, pipelinq, planix, procest, shillinq |
| `8.2` | docudesk |
| `8.3` | **scholiq only** ✓ |
| (unset) | app-versions, openklant, opentalk, openzaak, valtimo |

After tonight's composer `require.php` bump to `^8.3` fleet-wide, **the
info.xml PHP min-version is incoherent with the composer constraint in
17 of 22 apps.** Apps installing from the Nextcloud store on PHP 8.0
will be told the app is compatible — but composer install will refuse.
Worth fleet-wide cleanup.

### Nextcloud min/max-version

Wildly varying:

| min | max | Apps |
|---|---|---|
| 28 | 33 | decidesk, nldesign, opencatalogi, pipelinq, procest, softwarecatalog, zaakafhandelapp |
| 28 | 34 | deskdesk, openbuild, openregister, planix, shillinq |
| 28 | 32 | larpingapp, openconnector |
| 29 | 34 | launchpad |
| 30 | 33 | docudesk (max 34 actually), openklant, opentalk, openzaak, valtimo |
| 31 | 33 | app-versions |
| 33 | 34 | scholiq |

**Recommendation:** the fleet should pick **one** canonical NC range
(probably 28..34) and converge there. Apps that don't actually work on
Nextcloud 28 should raise their floor. Apps that DO work on 28+ should
not have artificially-low maximums (32, 33).

### License field

| Spelling | Apps |
|---|---|
| `agpl` (lowercase) | 13 |
| `eupl` (lowercase) | planix, scholiq, shillinq |
| `EUPL-1.2` (uppercase + dash) | launchpad |
| `AGPL-3.0-or-later` | app-versions |

Per memory (`feedback_apps-eupl-license`), all Conduction apps are
EUPL-1.2 canonically but use `agpl` in `info.xml` because the NC store
picker doesn't include EUPL. So the canonical value is **`agpl`** in
info.xml — and 5 apps need to fix their casing or value.

**Recommendation:** add an `info.xml` audit + cleanup to the fleet sync
roadmap. Probably script-driven — read the current value, rewrite to
canonical (`agpl`, NC `28..34`, PHP `8.3`, `<version>` from
`composer.json`). Run via `hydra/scripts/fleet-sync/info-xml.sh` (new
subcommand). Tier change to consider: maybe `info.xml` becomes Tier A
canonical for the **structure + license + NC range + PHP min** fields,
with `<id>`, `<name>`, `<description>`, `<version>` still per-app.

## 5. README structure

**Common sections** (≥ 12 of 22 apps):

| Section | Apps with it |
|---|---|
| Installation | 21/22 |
| Architecture | 19/22 |
| Requirements | 18/22 |
| Development | 18/22 |
| Features | 16/22 |
| PHP | 15/22 |
| Frontend | 15/22 |
| Tech stack | 15/22 |
| License | 15/22 |
| Standards & Compliance | 14/22 |
| Related apps | 14/22 |
| Screenshots | 13/22 |
| Documentation | 13/22 |
| Authors | 12/22 |

**Recommendation:** the template ships a README skeleton with these 14
sections (currently it has most of them). Apps scaffolded from the
template inherit the structure. Apps that DON'T match this structure
are good candidates for opportunistic README refreshes when they get
touched for other reasons.

## What to do with these findings

Three tiers of follow-up, ordered by leverage:

### Tier A — Script-driven fleet cleanup (high leverage)

1. **`info.xml` canonicalization** — PHP min-version, NC range, license
   spelling. Write a `hydra/scripts/fleet-sync/info-xml.sh` subcommand
   that reads each app's `<id>` and `<version>` from composer/info.xml,
   rewrites the rest to canonical values. One run, 22 PRs.
2. **Workflow cleanup on app-versions** — collapse 13 workflows to the
   canonical 9. Single PR.
3. **Missing canonical workflows on larpingapp/openconnector/openregister/zaakafhandelapp** —
   copy missing workflow files from the template. Per-app PR.

### Tier B — Scaffold-drift sweeps (medium leverage)

4. **package.json scripts** — add `test`, `test:e2e`, `stylelint-fix`,
   `check:manifest` to template and to apps lacking them.
5. **composer.json scripts** — close gaps on `zaakafhandelapp`,
   `app-versions`, `opencatalogi` to bring them to the 25-script common
   base.
6. **README structure** — opportunistic, low priority.

### Tier C — Documentation (low cost, useful for future drift)

7. Add `docs/composer-scripts-template.md` listing the canonical script
   set + purpose.
8. Add `docs/ci-workflows.md` listing the canonical workflow set +
   triggers + per-app extension hooks.
9. Re-run this drift audit quarterly; track convergence over time.
