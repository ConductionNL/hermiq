---
description: Run Hermiq's Playwright end-to-end tests
---
Run the browser tests in `tests/e2e`, configured in `playwright.config.ts`. There is no npm script for them, so use the Playwright CLI.

1. Make sure a Nextcloud with Hermiq and OpenRegister is running, for example `docker compose -f docker-compose.yml up -d --build` (http://localhost:8080, admin / admin).
2. `npx playwright test`, or `npx playwright test $ARGUMENTS` for one spec file.

Report each failing test with its step and the error.
