---
description: Run Hermiq's Playwright end-to-end tests
---
Run the browser tests in `tests/e2e`, configured in `playwright.config.ts`. There is no npm script for them, so use the Playwright CLI.

1. Make sure a Nextcloud with Hermiq and OpenRegister is running, for example `docker compose -f docker-compose.yml up -d --build` (http://localhost:8080, admin / admin). Set `NEXTCLOUD_URL` if it runs elsewhere.
2. `npx playwright test --project chromium`, or `npx playwright test --project chromium $ARGUMENTS` for one spec file.
3. Only to refresh the tutorial screenshots: `npx playwright test --project docs-capture`.

Report each failing test with its step and the error.
