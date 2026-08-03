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
import * as path from 'path'

// 🔴 Every path is resolved from __dirname, never left relative.
//
// Playwright does NOT use one base for all of them: `testDir` resolves against
// the CONFIG FILE's directory, while `storageState`, `globalSetup` and
// `outputDir` resolve against the CURRENT WORKING DIRECTORY — which in CI is
// the app root, two levels up from here. A `../.auth/admin.json` that reads
// correctly from this file therefore resolved to `server/apps/.auth/admin.json`
// and every one of the 32 tests died in ~6ms on ENOENT before its first
// assertion. Absolute paths remove the distinction entirely.
const E2E_ROOT = path.resolve(__dirname, '..')

export default defineConfig({
	testDir: __dirname,
	globalSetup: path.join(E2E_ROOT, 'global-setup.ts'),
	timeout: 90_000,
	expect: { timeout: 15_000 },
	fullyParallel: false,
	retries: process.env.CI ? 1 : 0,
	workers: 1,
	reporter: [
		['html', { open: 'never', outputFolder: path.join(E2E_ROOT, 'playwright-report') }],
		['list'],
	],
	outputDir: path.join(E2E_ROOT, 'test-results'),

	use: {
		baseURL: process.env.NEXTCLOUD_URL || process.env.BASE_URL || 'http://localhost:8080',
		storageState: path.join(E2E_ROOT, '.auth', 'admin.json'),
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
