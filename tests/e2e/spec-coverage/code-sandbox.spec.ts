/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * tools-code-sandbox task 5: the Code sandbox section in Admin settings.
 *
 * The choice of backend is saved for real through
 * `PATCH /api/settings/code-sandbox`, and the stored choice is put back after
 * each test, because it applies to every agent and flow on the instance.
 * "Run a test" is answered by page.route: it runs `print(1 + 1)` in the chosen
 * sandbox, and a CI instance has neither the sandbox app nor a hosted provider
 * behind integriq. The test route itself is pinned by
 * CodeSandboxSettingsControllerTest.
 *
 * Written from the change's scenarios; not run here, it needs a running Nextcloud.
 *
 * @spec openspec/changes/tools-code-sandbox/specs/code-sandbox/spec.md#requirement-the-admin-chooses-where-code-runs-req-sandbox-004
 */

import type { APIRequestContext, Page, Route } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { harvestToken, jsonHeaders, TEST_PREFIX } from './_fixtures.ts'

const SETTINGS = '/index.php/apps/hermiq/api/settings/code-sandbox'
const TEST_ROUTE = /\/apps\/hermiq\/api\/settings\/code-sandbox\/test(\?.*)?$/
const SOURCE = `${TEST_PREFIX}-hosted-sandbox`

/**
 * Read the stored choice.
 *
 * @param req   The Playwright request context.
 * @param token The CSRF request-token.
 * @return `{backend, hostedSource, available}`.
 */
async function storedChoice(
	req: APIRequestContext,
	token: string,
): Promise<{ backend: string; hostedSource: string | null }> {
	const res = await req.get(SETTINGS, { headers: jsonHeaders(token) })
	expect(
		res.ok(),
		`read the code sandbox setting: HTTP ${res.status()}`,
	).toBeTruthy()
	return res.json()
}

/**
 * Open Admin settings at the Code sandbox section.
 *
 * @param page The Playwright page.
 * @return The section.
 */
async function openSection(page: Page) {
	await page.goto('/index.php/settings/admin/hermiq', {
		waitUntil: 'domcontentloaded',
	})
	const section = page.locator('.code-sandbox')
	await section.scrollIntoViewIfNeeded()
	await expect(section).toBeVisible({ timeout: 20_000 })
	return section
}

test.describe('tools-code-sandbox: the admin chooses where code runs', () => {
	let token = ''
	let original: { backend: string; hostedSource: string | null } = {
		backend: 'exapp',
		hostedSource: null,
	}

	test.beforeEach(async ({ page }) => {
		token = await harvestToken(page)
		original = await storedChoice(page.request, token)
	})

	test.afterEach(async ({ page }) => {
		await page.request.patch(SETTINGS, {
			headers: jsonHeaders(token),
			data: { backend: original.backend, hostedSource: original.hostedSource },
		})
	})

	// @e2e code-sandbox::an-admin-switches-to-a-hosted-sandbox
	test('an admin switches to a hosted sandbox, runs the test and sees where code runs', async ({
		page,
	}) => {
		let tests = 0
		await page.route(TEST_ROUTE, async (route: Route) => {
			tests++
			await route.fulfill({
				json: { passed: true, output: '2', backend: 'hosted' },
			})
		})

		const section = await openSection(page)
		await section
			.getByRole('radio', { name: 'Hosted sandbox' })
			.check({ force: true })
		await section
			.getByLabel('Integriq source of the hosted sandbox')
			.fill(SOURCE)

		// The test runs against the stored choice, so it waits for the save.
		await expect(
			section.getByRole('button', { name: 'Run a test' }),
		).toBeDisabled()
		await section.getByRole('button', { name: 'Save' }).click()
		await expect(
			section.getByText(`Code runs at: ${SOURCE} (outside this server)`),
		).toBeVisible()

		const stored = await storedChoice(page.request, token)
		expect(stored.backend).toBe('hosted')
		expect(stored.hostedSource).toBe(SOURCE)

		await section.getByRole('button', { name: 'Run a test' }).click()
		await expect(section.getByText('Test passed: 2')).toBeVisible()
		expect(tests).toBe(1)
	})

	test('a hosted sandbox without a source is not saved', async ({ page }) => {
		const res = await page.request.patch(SETTINGS, {
			headers: jsonHeaders(token),
			data: { backend: 'hosted', hostedSource: '' },
		})
		expect(res.ok(), 'a hosted choice names its integriq source').toBeFalsy()

		const stored = await storedChoice(page.request, token)
		expect(stored.backend).toBe(original.backend)
	})

	test('a failed test says so, and the own sandbox reads as this server', async ({
		page,
	}) => {
		await page.request.patch(SETTINGS, {
			headers: jsonHeaders(token),
			data: { backend: 'exapp', hostedSource: null },
		})
		await page.route(TEST_ROUTE, async (route: Route) => {
			await route.fulfill({
				json: {
					passed: false,
					output: 'No code sandbox can take runs right now.',
					backend: 'exapp',
				},
			})
		})

		const section = await openSection(page)
		await expect(section.getByText('Code runs at: this server')).toBeVisible()
		await section.getByRole('button', { name: 'Run a test' }).click()
		await expect(
			section.getByText(
				'Test failed: No code sandbox can take runs right now.',
			),
		).toBeVisible()
	})
})

/*
 * tools-code-sandbox REQ-SANDBOX-005: the auditor reads what ran on the Runs page.
 *
 * The run list is answered by page.route with one run whose step recorded a
 * `codeRun`, as `AnalyticsService` returns it (`CodeRunRows` narrows the step
 * on the server, pinned by AnalyticsServiceTest and CodeRunRowsTest): a CI
 * instance has no sandbox to produce a real run. Not run here.
 *
 * @spec openspec/changes/tools-code-sandbox/specs/code-sandbox/spec.md#requirement-every-code-run-is-on-the-run-record-redacted-and-capped-req-sandbox-005
 */
const RUNS_ROUTE = /\/apps\/hermiq\/api\/runs(\?.*)?$/

test.describe('tools-code-sandbox: every code run is on the run record', () => {
	// @e2e code-sandbox::an-auditor-reads-what-ran
	test('an auditor opens the run on the Runs page and reads the code and its redacted output', async ({
		page,
	}) => {
		await page.route(RUNS_ROUTE, async (route: Route) => {
			await route.fulfill({
				json: {
					results: [
						{
							id: `${TEST_PREFIX}-code-run`,
							status: 'ok',
							agentName: `${TEST_PREFIX} calculator`,
							trigger: 'manual',
							durationMs: 900,
							summary: 'Calculated',
							codeRuns: [
								{
									language: 'python',
									code: 'print(1 + 1)',
									exitCode: 0,
									stdout: 'mailed *** the result 2',
									stdoutTruncated: true,
									stderr: '',
									stderrTruncated: false,
								},
							],
						},
					],
					total: 1,
					limit: 25,
					offset: 0,
				},
			})
		})

		await page.goto('/index.php/apps/hermiq/runs', {
			waitUntil: 'domcontentloaded',
		})
		const codeRuns = page.getByTestId('run-code-runs')
		await expect(codeRuns).toBeVisible({ timeout: 20_000 })
		await expect(codeRuns.getByText('Ran python: exit code 0')).toBeVisible()
		await expect(codeRuns.getByTestId('run-code-source')).toHaveText('print(1 + 1)')
		const stdout = codeRuns.getByTestId('run-code-stdout')
		await expect(stdout).toHaveText('mailed *** the result 2')
		await expect(stdout).not.toContainText('@')
		await expect(codeRuns.getByText('Cut to the first 4 KB')).toBeVisible()
	})
})
