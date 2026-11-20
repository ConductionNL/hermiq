# AGENTS.md

Instructions for coding agents (and people) working in this repository. Every command below exists in `composer.json` or `package.json`, or is the Playwright CLI the repository is configured for.

## What this app is

Hermiq brings autonomous AI agents to Nextcloud: define an agent, give it tools, and run it on a schedule. Agents, their memory and skills are stored as objects in [OpenRegister](https://github.com/ConductionNL/openregister), which must be installed first.

More context: [README.md](README.md) and [docs/intro.md](docs/intro.md).

## Setup

Evaluation stack (Nextcloud with Hermiq and OpenRegister, built from this repository):

```bash
docker compose -f docker-compose.yml up -d --build
# open http://localhost:8080/apps/hermiq/ and sign in as admin / admin
docker compose -f docker-compose.yml down -v   # remove the stack and its data
```

Dependencies for development:

```bash
composer install   # also sets git core.hooksPath to .githooks
npm install
```

## Build

```bash
npm run build   # production webpack build, then the companion CSS
npm run dev     # development build
npm run watch   # rebuild on change
```

## Test

```bash
composer test:unit    # PHPUnit (phpunit.xml: tests/Unit, tests/unit)
npm run test:unit     # Vitest (vitest.config.mjs)
npm run check:specs   # Node spec checks in tests/*.spec.js
npx playwright test --project chromium   # Playwright (playwright.config.ts, tests/e2e); no npm script exists
```

## Lint and quality

```bash
composer check:strict    # lint, phpcs, phpmd, psalm, phpstan, test:all
composer check           # quicker: lint, phpcs, psalm, test:unit
composer cs:fix          # fix PHP code style
npm run lint             # ESLint
npm run stylelint        # CSS and Vue styles
npm run format           # Prettier check
npm run check:manifest   # validate src/manifest.json
npm run test:l10n        # translation keys
```

## OpenSpec flow

Work is specified before it is built, in [`openspec/`](openspec/) (see [openspec/README.md](openspec/README.md)):

1. Propose a change in `openspec/changes/<change-name>/` with `proposal.md`, `design.md`, `tasks.md` and spec deltas in `specs/<capability>/spec.md`.
2. Build it, ticking tasks in `tasks.md`.
3. Merge the spec deltas into `openspec/specs/<capability>/spec.md`.
4. Archive the change to `openspec/changes/archive/`.

Project rules for proposals, specs and designs are in [openspec/config.yaml](openspec/config.yaml).

## Architecture decisions

App-level decision records are in [`openspec/architecture/`](openspec/architecture/). Design decisions for a single change are in that change's `design.md`.

## Repository conventions

- Pages are manifest configuration: pages, navigation and dependencies are declared in `src/manifest.json`, and the shell renders index, detail, dashboard and settings pages from it without per-page Vue files. Write a custom Vue component only for a page of `type: "custom"` ([README.md](README.md)).
- Data lives in OpenRegister. Schemas are configuration in `lib/Settings/hermiq_register.json` (OpenAPI 3.0.0), imported into OpenRegister. Check them with `npm run check:register`.
- The root `phpcs.xml`, `phpmd.xml`, `psalm.xml`, `phpstan.neon` and `phpstan-bootstrap.php` are shared across Conduction apps. Put per-app exceptions in `phpstan-baseline.neon` or `psalm-baseline.xml`, not in those files ([README.md](README.md)).
- Every user-visible string is translated; see `l10n/` and `npm run test:l10n`.

## Commits

Use [Conventional Commits](https://www.conventionalcommits.org/en/v1.0.0/), for example `docs: add AGENTS.md with build, test and lint commands`. See [CONTRIBUTING.md](CONTRIBUTING.md).
