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
	// The shared quality.yml Playwright job is `timeout-minutes: 45`, and a job
	// cancelled by that cap produces NO verdict: Playwright never prints its
	// tally, the `if: failure()` trace upload never fires, and the
	// `if: always()` report upload does not run on a cancelled job either — the
	// run you most need to read is the one that leaves nothing behind, and it
	// still renders as "fail" in `gh pr checks` while carrying no information.
	// That is precisely the "not a slow gate, a hung one" case the header note
	// above describes, seen from CI's side. Measured overhead before
	// `Run Playwright tests` starts is 2.0-2.4 min and the uploads after it
	// take seconds, so 38m keeps ~7 min of margin while guaranteeing both a
	// tally and the artifacts that explain it.
	globalTimeout: 38 * 60_000,
	reporter: [
		[
			'html',
			{
				open: 'never',
				outputFolder: path.join(E2E_ROOT, 'playwright-report'),
			},
		],
		['list'],
	],
	outputDir: path.join(E2E_ROOT, 'test-results'),

	use: {
		baseURL:
			process.env.NEXTCLOUD_URL
			|| process.env.BASE_URL
			|| 'http://localhost:8080',
		storageState: path.join(E2E_ROOT, '.auth', 'admin.json'),
		// `on-first-retry` writes a trace only when a retry actually happens, so
		// the trace artifact is a function of `retries`. Off CI `retries` is 0
		// above, so a local failure has never produced a trace at all; on CI it
		// traces the SECOND attempt only, which means the failure that does not
		// reproduce — the one actually worth a trace — leaves no record of the
		// attempt that failed. `retain-on-failure` traces every attempt and
		// keeps the ones that failed: strictly more informative, and
		// independent of the retry count.
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
		navigationTimeout: 60_000,
		actionTimeout: 20_000,
	},

	projects: [
		{
			name: 'chromium',
			// 🔴 The allowlist is GONE — CI runs every spec in this directory.
			//
			// It existed because run 30865280923 reported 6 passed / 27 failed
			// on a fresh stable33 instance, and the failures were read as "the
			// manifest pages do not render on a clean install". That diagnosis
			// was wrong. The failure message names the actual cause: the specs
			// landed on `/index.php/apps/hermiq/`, not on the route they asked
			// for. hermiq's router base is `generateUrl('/apps/hermiq')`, which
			// is `/index.php/apps/hermiq` wherever mod_rewrite is not believed
			// to work — which is precisely CI's `php -S`. Every hard-coded
			// pretty deep link was therefore outside the router base, matched
			// no route, and was redirected to the app root by the SPA
			// catch-all. 21 of the 27. See `appRoot()` in `_fixtures.ts`.
			//
			// The other six: 2 read the seeded triage flow from the wrong store
			// (it lives in OpenRegister's native flow store, not as an
			// `agentflow` object), 2 needed Talk, which CI does not install and
			// which now skips by an explicit provisioning-API check, and 2 were
			// the `$ref must be a non-empty string` write, fixed in #136.
			//
			// 🔑 Scoping by DIRECTORY, not by an allowlist of filenames: a new
			// spec added under spec-coverage/ is in the gate the day it lands.
			// An allowlist would have to be remembered, and the one that used to
			// be here is exactly how six spec files sat outside the gate.
			use: { ...devices['Desktop Chrome'] },
		},
	],
})
