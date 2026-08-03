/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * CI-only Playwright config for the spec-coverage suite.
 *
 * 🔴 This file is what makes `playwright-test-path` mean anything. The shared
 * quality workflow resolves `<playwright-test-path>/playwright.config.ts` FIRST
 * and falls back to the repo-root config when that file is absent:
 *
 *     CONFIG="${{ inputs.playwright-test-path }}/playwright.config.ts"
 *     if [ ! -f "$CONFIG" ] && [ -f "playwright.config.ts" ]; then
 *       CONFIG="playwright.config.ts"
 *     fi
 *
 * Without this file the input is silently inert: the root config's
 * `testDir: './tests/e2e'` wins and CI runs the ENTIRE tree — 64 specs, serial
 * (`workers: 1`), up to 120s each. That is not a slow gate, it is a hung one;
 * the first attempt sat in "Run Playwright tests" for 40+ minutes. Setting the
 * input without adding this config looks like scoping and does nothing.
 *
 * OpenRegister hit the identical trap and fixed it the identical way (a second
 * config under the CI test path) — see `tests/e2e/ci/` there.
 *
 * Everything else is inherited in spirit from the root config; the deliberate
 * differences are:
 *   - `testDir: '.'`      — this directory only, which is the whole point.
 *   - shorter timeouts    — a fresh CI instance with `PHP_CLI_SERVER_WORKERS=8`
 *                           is far faster than a loaded dev box, and a long
 *                           timeout here buys nothing but a slower red.
 *   - no `docs-capture`   — screenshots are a scheduled job, never a PR gate.
 */

import { defineConfig, devices } from '@playwright/test'

export default defineConfig({
	testDir: '.',
	globalSetup: '../global-setup.ts',
	timeout: 90_000,
	expect: { timeout: 15_000 },
	fullyParallel: false,
	retries: process.env.CI ? 1 : 0,
	workers: 1,
	reporter: [
		['html', { open: 'never', outputFolder: '../playwright-report' }],
		['list'],
	],
	outputDir: '../test-results',

	use: {
		baseURL: process.env.NEXTCLOUD_URL || process.env.BASE_URL || 'http://localhost:8080',
		storageState: '../.auth/admin.json',
		trace: 'on-first-retry',
		screenshot: 'only-on-failure',
		navigationTimeout: 60_000,
		actionTimeout: 20_000,
	},

	projects: [
		{
			name: 'chromium',
			use: { ...devices['Desktop Chrome'] },
		},
	],
})
