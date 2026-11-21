/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec-coverage e2e — trace export over OpenTelemetry
 * (observability-export-traces, Tasks 1 and 5).
 *
 * Browser scenarios of openspec/changes/observability-export-traces:
 *  - REQ-TREX-001 "An administrator connects Langfuse": in the "Trace export"
 *    section of hermiq's admin settings the administrator enters the Langfuse
 *    OTLP endpoint, names the credential by its broker reference, switches
 *    export on and saves. The section then reads "Traces go to
 *    cloud.langfuse.com. With metadata only, no prompt or answer text leaves
 *    this server.", the stored setting holds the reference and no secret, and
 *    the Integrations page lists "Trace export".
 *  - REQ-TREX-004 "Switching export off loses no record": the administrator
 *    switches export off; the section reads "The audit trail stays the record.
 *    Exported traces are a copy for your own tools." and the run list answers
 *    the same total before and after.
 *
 * Left out, each marked `@e2e exclude` in the spec:
 *  - REQ-TREX-001 "A non-admin cannot change it": a crafted request; PHPUnit
 *    and Newman.
 *  - REQ-TREX-002 "A run with a tool call arrives in the tracing tool" and
 *    "Redacted text hides a secret": they inspect what a collector receives;
 *    PHPUnit against a fake OTLP endpoint and on the exporter.
 *  - REQ-TREX-003 "The collector is down": timing and retries; PHPUnit on the
 *    job with a failing endpoint.
 * The live check in tasks.md (a local Jaeger or Langfuse receiving one run's
 * trace) stays a live check: the OTLP send happens in TraceExportJob on the
 * server, which page.route cannot reach.
 *
 * 🔑 WHAT IS REAL AND WHAT IS NOT.
 *  - The settings route is real: the section, the save and the notice the
 *    server words (TraceExportSettings::destinationNotice()). No collector is
 *    needed to save, and none is contacted from the browser.
 *  - The credential field takes a broker reference as text. The spec's
 *    "choose the credential" is a pasted reference in the built UI
 *    (src/components/settings/TraceExportSettings.vue), not a picker, so the
 *    test pastes a made-up uuid. Nothing is resolved against the broker on
 *    save, and no secret is ever typed.
 *  - Export is on for the length of one test only. beforeAll keeps the
 *    instance's settings and afterAll writes them back, so a run leaves the
 *    `trace_export` value as it found it.
 *  - The Integrations page needs integriq (`requiresApp` in src/manifest.json);
 *    without it that one assertion is skipped and says so, never failed.
 *  - Runs cannot be seeded (an AuditTrail entry is written by the run path
 *    only, see runs.spec.ts), so REQ-TREX-004 holds the run list's total equal
 *    across the switch at whatever count the instance has.
 *
 * Runs as the suite's administrator (the default storage state):
 *
 *     PLAYWRIGHT_BASE_URL=<your rig> NC_USER=admin NC_PASS=admin \
 *       npx playwright test -c tests/e2e/spec-coverage/playwright.config.ts trace-export
 *
 * Covers openspec/changes/observability-export-traces/specs/trace-export/spec.md
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	appEnabled,
	appRoot,
	dismissTour,
	harvestToken,
	jsonHeaders,
} from './_fixtures.ts'

const API = '/index.php/apps/hermiq/api'

/**
 * A Langfuse-shaped OTLP/HTTP traces endpoint on a host that never resolves (RFC 2606
 * `.invalid`), so a run on the instance while export is on sends nothing anywhere. The
 * scenario's cloud.langfuse.com is swapped for it; the notice names the host the same way.
 */
const LANGFUSE = 'https://langfuse.example.invalid/api/public/otel/v1/traces'

/** A broker reference; never resolved on save, and not a secret. */
const CREDENTIAL_REF = '7c1e2f9a-3b4d-4e5f-8a6b-0c1d2e3f4a5b'

/** The Langfuse public key, sent as the basic username. */
const PUBLIC_KEY = 'pk-lf-e2e-placeholder'

const DESTINATION = 'Traces go to langfuse.example.invalid. With metadata only, no prompt or answer text leaves this server.'

const COPY_NOTICE = 'The audit trail stays the record. Exported traces are a copy for your own tools.'

/**
 * Read the stored trace export settings.
 *
 * @param req   The request context.
 * @param token The CSRF request-token.
 * @return The `{settings, destinationNotice, copyNotice}` view.
 */
async function readTraceExport(req: APIRequestContext, token: string): Promise<any> {
	const res = await req.get(`${API}/settings/trace-export`, {
		headers: jsonHeaders(token),
	})
	expect(res.status(), 'the administrator reads the setting').toBe(200)
	return res.json()
}

/**
 * The run list's total, as the Runs page reads it.
 *
 * @param req   The request context.
 * @param token The CSRF request-token.
 * @return The unpaged total.
 */
async function runTotal(req: APIRequestContext, token: string): Promise<number> {
	const res = await req.get(`${API}/runs`, { headers: jsonHeaders(token) })
	expect(res.status(), 'the run list must answer').toBe(200)
	return Number((await res.json()).total ?? 0)
}

/**
 * Open hermiq's admin settings at the trace export section.
 *
 * @param page The page.
 * @return The section locator.
 */
async function openSection(page: Page) {
	await page.goto('/index.php/settings/admin/hermiq', {
		waitUntil: 'domcontentloaded',
	})
	await dismissTour(page)
	const section = page.locator('#section-trace-export')
	await expect(section).toBeVisible({ timeout: 30_000 })
	// The section loads its settings once; the endpoint field is enabled after.
	await expect(section.getByLabel('OTLP/HTTP traces endpoint')).toBeEnabled()
	return section
}

/**
 * Press "Save trace export" and wait for the stored answer.
 *
 * @param page    The page.
 * @param section The section locator.
 * @return The PUT body sent and the answer.
 */
async function save(page: Page, section: ReturnType<Page['locator']>) {
	const saved = page.waitForResponse(
		(res) =>
			res.url().includes('/apps/hermiq/api/settings/trace-export')
			&& res.request().method() === 'PUT',
	)
	await section.getByRole('button', { name: 'Save trace export' }).click()
	const response = await saved
	expect(response.status(), `save HTTP ${response.status()}`).toBe(200)
	return { sent: response.request().postDataJSON(), answer: await response.json() }
}

test.describe('observability-export-traces: the admin section', () => {
	let token = ''
	let original: Record<string, unknown> | null = null

	test.beforeAll(async ({ browser }) => {
		const page = await browser.newPage()
		token = await harvestToken(page)
		original = (await readTraceExport(page.request, token)).settings
		await page.close()
	})

	test.afterAll(async ({ browser }) => {
		if (original === null) {
			return
		}
		const page = await browser.newPage()
		const cleanupToken = await harvestToken(page)
		await page.request
			.put(`${API}/settings/trace-export`, {
				headers: jsonHeaders(cleanupToken),
				data: original,
			})
			.catch(() => null)
		await page.close()
	})

	// e2e: trace-export::an-administrator-connects-langfuse
	test('an administrator connects Langfuse', async ({ page }) => {
		const section = await openSection(page)

		await section.getByLabel('OTLP/HTTP traces endpoint').fill(LANGFUSE)
		await section
			.getByLabel('Credential (OpenRegister broker reference)')
			.fill(CREDENTIAL_REF)
		await section.getByRole('combobox', { name: 'Credential type' }).click()
		await page
			.getByRole('option', { name: 'Basic (username and password, such as Langfuse)' })
			.click()
		await section.getByLabel('Username (for Langfuse, the public key)').fill(PUBLIC_KEY)
		await section
			.getByText('Metadata only: names, timings, outcomes, model and token counts', { exact: true })
			.click()

		// Switch export on, unless this instance already had it on.
		const stored = await readTraceExport(page.request, token)
		if (stored.settings?.enabled !== true) {
			await section.getByText('Export traces', { exact: true }).click()
		}

		const { sent, answer } = await save(page, section)
		expect(sent).toMatchObject({
			enabled: true,
			endpoint: LANGFUSE,
			credentialId: CREDENTIAL_REF,
			authScheme: 'basic',
			username: PUBLIC_KEY,
			content: 'metadata',
		})
		expect(answer.destinationNotice).toBe(DESTINATION)

		await expect(section.getByText(DESTINATION, { exact: true })).toBeVisible()
		await expect(section.getByText(COPY_NOTICE, { exact: true })).toBeVisible()

		// Stored: the reference, and no secret field at all.
		const after = await readTraceExport(page.request, token)
		expect(after.settings).toMatchObject({
			enabled: true,
			endpoint: LANGFUSE,
			credentialId: CREDENTIAL_REF,
		})
		expect(Object.keys(after.settings).sort()).toEqual([
			'allowPrivateEndpoint',
			'authScheme',
			'content',
			'credentialId',
			'enabled',
			'endpoint',
			'organisation',
			'sources',
			'username',
		])

		// The Integrations page lists the connection (needs integriq).
		if (!(await appEnabled(page.request, token, 'integriq'))) {
			test.info().annotations.push({
				type: 'skipped-assertion',
				description: 'integriq is not enabled: the Integrations page does not exist on this instance',
			})
			return
		}
		const root = await appRoot(page)
		await page.goto(`${root}/settings/integrations?app=hermiq`, {
			waitUntil: 'domcontentloaded',
		})
		await dismissTour(page)
		await expect(page.getByText('Trace export', { exact: true }).first()).toBeVisible({
			timeout: 30_000,
		})
	})

	// e2e: trace-export::switching-export-off-loses-no-record
	test('switching export off loses no record', async ({ page }) => {
		// Export on first, through the same route the section saves to.
		const on = await page.request.put(`${API}/settings/trace-export`, {
			headers: jsonHeaders(token),
			data: {
				enabled: true,
				endpoint: LANGFUSE,
				credentialId: CREDENTIAL_REF,
				authScheme: 'basic',
				username: PUBLIC_KEY,
				content: 'metadata',
				sources: ['runs', 'chat'],
			},
		})
		expect(on.status(), await on.text()).toBe(200)

		const before = await runTotal(page.request, token)

		const section = await openSection(page)
		await expect(section.getByText(COPY_NOTICE, { exact: true })).toBeVisible()
		await section.getByText('Export traces', { exact: true }).click()
		const { sent } = await save(page, section)
		expect(sent.enabled).toBe(false)
		expect((await readTraceExport(page.request, token)).settings.enabled).toBe(false)

		// The settings page still says the audit trail is the record.
		await expect(section.getByText(COPY_NOTICE, { exact: true })).toBeVisible()

		// The run list holds the same runs as before the switch.
		expect(await runTotal(page.request, token)).toBe(before)
		const root = await appRoot(page)
		await page.goto(`${root}/runs`, { waitUntil: 'domcontentloaded' })
		await dismissTour(page)
		await expect(page.locator('.hermiq-runs')).toBeVisible({ timeout: 20_000 })
	})
})
