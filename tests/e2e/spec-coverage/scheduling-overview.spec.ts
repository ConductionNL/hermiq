/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec-coverage e2e — the Schedules page, how an agent starts, and a schedule
 * set in plain words (scheduling-overview-and-start-choice, Tasks 1, 3 and 4).
 *
 * Browser scenarios of openspec/changes/scheduling-overview-and-start-choice:
 *  - REQ-SCHED-001 "A functional admin checks which schedules failed last
 *    night": the Schedules page (/schedules, manifest page `Schedules`, read
 *    from GET /api/schedules) lists the seeded schedules with their agent, a
 *    readable "When", the next run and the last result; the "Failed" quick
 *    filter asks the endpoint for `failed=true` and leaves exactly the two
 *    failed rows, each with its agent, its next run and "Failed: <error>".
 *  - REQ-SCHED-002 "A case handler runs an agent by hand without a schedule":
 *    under "How this agent starts", "By hand", the prompt "Summarise the open
 *    objections" and "Run now" post that prompt to POST /api/agents/{id}/run;
 *    the widget says "The run finished. It is in the run history." and the
 *    agent still has no schedule ("On a schedule" reads "Not active",
 *    GET /api/schedules?agentId= answers none).
 *  - REQ-SCHED-002 "An owner adds an event start": "When an object is created
 *    or changed" under "On an event" creates a flow in OpenRegister with an
 *    object trigger and this agent's `hermiq.agent-step`, switched off, and
 *    opens it on the canvas (/flows/{id}); back on the agent page the flow is
 *    listed as "Draft, not on yet" and the event row stays "Not active".
 *  - REQ-SCHED-003 "A team lead schedules a weekday digest": in the schedule
 *    form, "every weekday at eight" under "When should it run?" and "Read"
 *    show the readback "Every weekday at 08:00, <the person's time zone>" and
 *    three next runs, each a weekday at 08:00; saving sends and stores
 *    `kind: cron`, `cronExpr: 0 8 * * 1-5` and never the typed words.
 *
 * Left out:
 *  - REQ-SCHED-001 "A colleague does not see another person's private agent's
 *    schedule": `@e2e exclude` in the spec, an authorization contract on
 *    GET /api/schedules; PHPUnit on ScheduleListService and Newman.
 *  - REQ-SCHED-003 "A Dutch phrase is read too": `@e2e exclude` in the spec,
 *    the parser table; PHPUnit on ScheduleWhenParser.
 *  - The run history row with trigger "manual" (second half of the by-hand
 *    scenario): the run itself needs a model, so the run route is stubbed and
 *    no AuditTrail entry is written. ManualRunService records `trigger:
 *    manual`; PHPUnit and Newman cover it (tasks.md Task 2).
 *  - The model fallback of the plain-words reader: it needs a provider; the
 *    phrase here is read by the deterministic rules (`source: rules`).
 *
 * 🔑 WHAT IS REAL AND WHAT IS NOT.
 *  - The Schedules page, its endpoint and its filter are real. The agents and
 *    schedules are seeded through the OpenRegister objects API with
 *    TEST_PREFIX names. Their `lastStatus`/`lastError` are written into the
 *    objects directly: a real failed run needs a model. Every seeded schedule
 *    has its next run in 2099, so the scheduler never picks one up.
 *  - The scenario's twelve schedules over five agents are scaled down to four
 *    over two agents, two of them failed. The instance may hold failed
 *    schedules of its own, so the counts are taken over this run's rows only.
 *  - The scenario says the error shows "on hover". The built page
 *    (src/utils/scheduleList.js, `scheduleLastResult`) writes it into the
 *    cell as "Failed: <error>", so that text is what is asserted.
 *  - Run by hand: POST /api/agents/{id}/run is answered by page.route with
 *    `{status: ok, trigger: manual}`; what the page sent is asserted. The
 *    "no schedule was created" half is read from the real endpoint.
 *  - Add event: real. The draft flow is written to OpenRegister's flow store
 *    and read back from it; it is off, so the flow engine never runs it, and
 *    afterAll deletes it. Without a readable flow store the test skips.
 *  - Plain words: POST /api/schedules/parse-when is real (the rules parser,
 *    no model). The time zone in the readback is the logged-in person's own
 *    (Europe/Amsterdam in the scenario); the test takes it from the answer
 *    and asserts the sentence around it. The schedule is saved with
 *    "Enabled" switched off, so the scheduler never runs it before cleanup.
 *  - Every test needs OpenRegister; without it the suite skips.
 *
 * Runs as the suite's administrator (the default storage state), in English:
 *
 *     PLAYWRIGHT_BASE_URL=<your rig> NC_USER=admin NC_PASS=admin \
 *       npx playwright test -c tests/e2e/spec-coverage/playwright.config.ts scheduling-overview
 *
 * Covers openspec/changes/archive/2026-11-24-scheduling-overview-and-start-choice/specs/agent-schedule/spec.md
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	appEnabled,
	appRoot,
	cleanupFamily,
	dismissTour,
	harvestToken,
	jsonHeaders,
	seedAgent,
	seedObject,
	TEST_PREFIX,
} from './_fixtures.ts'

const API = '/index.php/apps/hermiq/api'

const FLOWS = '/index.php/apps/openregister/api/flows'

/** A next run far enough out that the scheduler never takes a seeded schedule. */
const NEXT_RUN = '2099-03-02T07:00:00+00:00'

const MANUAL_PROMPT = 'Summarise the open objections'

const EVENT_CHOICE = 'When an object is created or changed'

const WHEN_TEXT = 'every weekday at eight'

/**
 * The schedules the caller may see, as the Schedules page reads them.
 *
 * @param req    The request context.
 * @param token  The CSRF request-token.
 * @param params Filters (`agentId`, `enabled`, `failed`).
 * @return The rows.
 */
async function listSchedules(
	req: APIRequestContext,
	token: string,
	params: Record<string, string> = {},
): Promise<Array<Record<string, any>>> {
	const query = new URLSearchParams(params).toString()
	const res = await req.get(`${API}/schedules${query === '' ? '' : `?${query}`}`, {
		headers: jsonHeaders(token),
	})
	expect(res.status(), 'the schedule list must answer').toBe(200)
	const body = await res.json()
	return Array.isArray(body.results) ? body.results : []
}

/**
 * Open an agent's page at "How this agent starts".
 *
 * @param page    The page.
 * @param agentId The agent uuid.
 * @return The section locator.
 */
async function openStarts(page: Page, agentId: string) {
	const root = await appRoot(page)
	await page.goto(`${root}/agents/${agentId}`, { waitUntil: 'domcontentloaded' })
	await dismissTour(page)
	const starts = page.getByTestId('agent-starts')
	await expect(starts).toBeVisible({ timeout: 30_000 })
	await expect(starts.getByRole('heading', { name: 'How this agent starts' })).toBeVisible()
	return starts
}

/**
 * The weekday (0 Sunday to 6 Saturday) of an ISO 8601 timestamp's own date.
 *
 * @param iso The timestamp, with its offset.
 * @return The weekday of the wall-clock date it names.
 */
function weekdayOf(iso: string): number {
	return new Date(`${iso.slice(0, 10)}T12:00:00Z`).getUTCDay()
}

test.describe('scheduling-overview-and-start-choice: the Schedules page, how an agent starts, plain words', () => {
	let token = ''
	let hasOpenRegister = false
	const flowIds: string[] = []

	test.beforeAll(async ({ browser }) => {
		const page = await browser.newPage()
		token = await harvestToken(page)
		hasOpenRegister = await appEnabled(page.request, token, 'openregister')
		await page.close()
	})

	test.beforeEach(() => {
		test.skip(!hasOpenRegister, 'OpenRegister is not enabled; hermiq keeps agents, schedules and flows there')
	})

	test.afterAll(async ({ browser }) => {
		const page = await browser.newPage()
		for (const id of flowIds) {
			await page.request
				.delete(`${FLOWS}/${id}`, { headers: jsonHeaders(token) })
				.catch(() => undefined)
		}
		await cleanupFamily(page.request, token, 'schedule')
		await cleanupFamily(page.request, token, 'agent')
		await page.close()
	})

	// @e2e agent-schedule::a-functional-admin-checks-which-schedules-failed-last-night
	test('a functional admin filters the Schedules page on a failed last result', async ({ page }) => {
		const intake = await seedAgent(page.request, token, { name: `${TEST_PREFIX}-intake-agent` })
		const reminders = await seedAgent(page.request, token, { name: `${TEST_PREFIX}-reminder-agent` })

		const schedule = (name: string, agentId: string, extra: Record<string, unknown>) =>
			seedObject(page.request, token, 'schedule', {
				name: `${TEST_PREFIX}-${name}`,
				agentId,
				kind: 'cron',
				cronExpr: '0 7 * * *',
				prompt: 'Seeded by tests/e2e/spec-coverage (safe to delete)',
				deliver: 'none',
				enabled: true,
				nextRun: NEXT_RUN,
				...extra,
			})

		await schedule('intake-nightly', intake.id, {
			lastStatus: 'error',
			lastError: 'Provider timed out',
		})
		await schedule('intake-morning', intake.id, { lastStatus: 'ok' })
		await schedule('reminders-nightly', reminders.id, {
			lastStatus: 'dead_letter',
			lastError: 'Talk room not found',
		})
		await schedule('reminders-weekly', reminders.id, {})

		const root = await appRoot(page)
		await page.goto(`${root}/schedules`, { waitUntil: 'domcontentloaded' })
		await dismissTour(page)

		const ours = page.getByRole('row').filter({ hasText: TEST_PREFIX })
		await expect(ours).toHaveCount(4, { timeout: 30_000 })

		const okRow = ours.filter({ hasText: `${TEST_PREFIX}-intake-morning` })
		await expect(okRow).toContainText(intake.name)
		await expect(okRow).toContainText('Every day at 07:00')
		await expect(okRow).toContainText('Succeeded')
		await expect(ours.filter({ hasText: `${TEST_PREFIX}-reminders-weekly` })).toContainText('Not run yet')

		const filtered = page.waitForRequest((req) => {
			const url = new URL(req.url())
			return url.pathname.endsWith('/apps/hermiq/api/schedules')
				&& url.searchParams.get('failed') === 'true'
		})
		await page
			.locator('.cn-quick-filter-bar__tabs button', {
				has: page.locator('.cn-quick-filter-bar__label', { hasText: /^Failed$/ }),
			})
			.click()
		await filtered

		await expect(ours).toHaveCount(2, { timeout: 20_000 })

		const first = ours.filter({ hasText: `${TEST_PREFIX}-intake-nightly` })
		await expect(first).toContainText(intake.name)
		await expect(first).toContainText('2099')
		await expect(first).toContainText('Failed: Provider timed out')

		const second = ours.filter({ hasText: `${TEST_PREFIX}-reminders-nightly` })
		await expect(second).toContainText(reminders.name)
		await expect(second).toContainText('2099')
		await expect(second).toContainText('Failed: Talk room not found')

		await expect(ours.filter({ hasText: `${TEST_PREFIX}-intake-morning` })).toHaveCount(0)
		await expect(ours.filter({ hasText: `${TEST_PREFIX}-reminders-weekly` })).toHaveCount(0)

		// The endpoint the page reads agrees, row for row.
		const failed = (await listSchedules(page.request, token, { failed: 'true' }))
			.filter((row) => String(row.name).startsWith(TEST_PREFIX))
			.map((row) => row.name)
			.sort()
		expect(failed).toEqual([`${TEST_PREFIX}-intake-nightly`, `${TEST_PREFIX}-reminders-nightly`])
	})

	// @e2e agent-schedule::a-case-handler-runs-an-agent-by-hand-without-a-schedule
	test('a case handler runs an agent by hand without a schedule', async ({ page }) => {
		const agent = await seedAgent(page.request, token, { name: `${TEST_PREFIX}-objections-agent` })
		expect(await listSchedules(page.request, token, { agentId: agent.id })).toEqual([])

		const sent: Array<Record<string, unknown>> = []
		await page.route(`**/apps/hermiq/api/agents/${agent.id}/run`, async (route) => {
			if (route.request().method() !== 'POST') {
				return route.fallback()
			}
			sent.push(route.request().postDataJSON())
			return route.fulfill({
				json: { status: 'ok', trigger: 'manual', output: 'Two objections are open.' },
			})
		})

		const starts = await openStarts(page, agent.id)
		const manual = starts.getByTestId('agent-start-manual')
		await expect(manual.getByRole('heading', { name: 'By hand' })).toBeVisible()
		await expect(manual).toContainText('Active')
		await expect(starts.getByTestId('agent-start-schedule')).toContainText('Not active')

		const runNow = manual.getByRole('button', { name: 'Run now' })
		await expect(runNow).toBeDisabled()
		await manual.getByLabel('What should the agent do?').fill(MANUAL_PROMPT)
		await runNow.click()

		await expect(manual.getByRole('status')).toHaveText('The run finished. It is in the run history.')
		expect(sent).toEqual([{ prompt: MANUAL_PROMPT }])

		// No schedule was created along the way.
		expect(await listSchedules(page.request, token, { agentId: agent.id })).toEqual([])
		await expect(starts.getByTestId('agent-start-schedule')).toContainText('Not active')
	})

	// @e2e agent-schedule::an-owner-adds-an-event-start
	test('an owner adds an event start as an inactive draft flow', async ({ page }) => {
		const store = await page.request.get(`${FLOWS}?app=hermiq`, { headers: jsonHeaders(token) })
		test.skip(store.status() !== 200, `OpenRegister's flow store is not readable here (HTTP ${store.status()})`)

		const agent = await seedAgent(page.request, token, { name: `${TEST_PREFIX}-event-agent` })
		const starts = await openStarts(page, agent.id)
		const event = starts.getByTestId('agent-start-event')
		await expect(event.getByRole('heading', { name: 'On an event' })).toBeVisible()
		await expect(event).toContainText('Not active')

		const created = page.waitForResponse(
			(res) => new URL(res.url()).pathname.endsWith('/apps/openregister/api/flows')
				&& res.request().method() === 'POST',
		)
		await event.getByRole('button', { name: EVENT_CHOICE }).click()
		const response = await created
		expect([200, 201], `create flow HTTP ${response.status()}`).toContain(response.status())

		const draft = response.request().postDataJSON()
		expect(draft).toMatchObject({ name: EVENT_CHOICE, app: 'hermiq', enabled: false })
		expect(draft.nodes.map((node: any) => node.type)).toEqual([
			'openregister.trigger-object',
			'hermiq.agent-step',
			'openregister.end',
		])
		expect(draft.nodes[1].config.agentId).toBe(agent.id)

		const flow = await response.json()
		const flowId = String(flow.uuid ?? flow.id ?? '')
		expect(flowId, 'the created flow must carry an id').not.toEqual('')
		flowIds.push(flowId)

		// It opens on the canvas.
		await expect(page).toHaveURL(new RegExp(`/flows/${flowId}$`), { timeout: 20_000 })
		await expect(page.locator('.cn-flow-detail')).toBeVisible({ timeout: 30_000 })

		// The store holds the draft: off, a trigger and this agent's step.
		const stored = await page.request.get(`${FLOWS}/${flowId}`, { headers: jsonHeaders(token) })
		expect(stored.status(), 'the draft flow must be readable').toBe(200)
		const body = await stored.json()
		expect(body.enabled).toBe(false)
		const types = (body.nodes ?? []).map((node: any) => node.type)
		expect(types).toContain('openregister.trigger-object')
		const step = (body.nodes ?? []).find((node: any) => node.type === 'hermiq.agent-step')
		expect(step?.config?.agentId).toBe(agent.id)

		// Back on the agent page it is listed, and not active until switched on.
		const again = await openStarts(page, agent.id)
		const listed = again.getByTestId('agent-start-event').getByRole('listitem').filter({ hasText: EVENT_CHOICE })
		await expect(listed).toHaveCount(1, { timeout: 20_000 })
		await expect(listed).toContainText('Draft, not on yet')
		await expect(again.getByTestId('agent-start-event').locator('.agent-run-ops-widget__badge')).toHaveText('Not active')
	})

	// @e2e agent-schedule::a-team-lead-schedules-a-weekday-digest
	test('a team lead schedules a weekday digest in plain words', async ({ page }) => {
		const agent = await seedAgent(page.request, token, { name: `${TEST_PREFIX}-digest-agent` })
		const starts = await openStarts(page, agent.id)
		await starts.getByTestId('agent-start-schedule').getByRole('button', { name: 'Add schedule' }).click()

		const form = page.locator('.schedule-form')
		await expect(form).toBeVisible({ timeout: 20_000 })
		await form.getByLabel(/^Name/).fill(`${TEST_PREFIX}-weekday-digest`)

		const parsed = page.waitForResponse(
			(res) => res.url().includes('/apps/hermiq/api/schedules/parse-when')
				&& res.request().method() === 'POST',
		)
		await form.getByLabel('When should it run?').fill(WHEN_TEXT)
		await form.getByRole('button', { name: 'Read', exact: true }).click()
		const answer = await parsed
		expect(answer.status(), await answer.text()).toBe(200)
		const rule = await answer.json()
		expect(rule).toMatchObject({ kind: 'cron', cronExpr: '0 8 * * 1-5', source: 'rules' })

		const zone = String(rule.readback).split(', ').pop() ?? ''
		expect(zone, 'the readback ends in the person\'s time zone').toMatch(/^[A-Za-z_]+(\/[A-Za-z_+-]+)*$/)
		expect(rule.readback).toBe(`Every weekday at 08:00, ${zone}`)

		expect(rule.nextRuns).toHaveLength(3)
		for (const run of rule.nextRuns as string[]) {
			expect(run.slice(11, 16), `${run} runs at 08:00`).toBe('08:00')
			expect([1, 2, 3, 4, 5], `${run} is a weekday`).toContain(weekdayOf(run))
		}
		expect([...rule.nextRuns].sort()).toEqual(rule.nextRuns)
		expect(new Set(rule.nextRuns).size).toBe(3)

		const readback = form.getByTestId('schedule-when-readback')
		await expect(readback.locator('.schedule-form__readback-line')).toHaveText(`Every weekday at 08:00, ${zone}`)
		await expect(readback).toContainText('Next runs')
		await expect(readback.getByRole('listitem')).toHaveCount(3)

		// Off, so the scheduler does not run it before afterAll removes it.
		const enabled = form.getByRole('switch', { name: 'Enabled' })
		await form.getByText('Enabled', { exact: true }).click()
		await expect(enabled).not.toBeChecked()

		const saved = page.waitForRequest(
			(req) => req.url().includes('/apps/openregister/api/objects/')
				&& ['POST', 'PUT'].includes(req.method())
				&& (req.postData() ?? '').includes(agent.id),
		)
		await form.getByRole('button', { name: 'Save', exact: true }).click()
		const payload = (await saved).postDataJSON()
		expect(payload).toMatchObject({
			name: `${TEST_PREFIX}-weekday-digest`,
			agentId: agent.id,
			kind: 'cron',
			cronExpr: '0 8 * * 1-5',
			enabled: false,
		})
		expect(JSON.stringify(payload)).not.toContain(WHEN_TEXT)
		await expect(form).toBeHidden({ timeout: 20_000 })

		// What is stored is the rule, read back through the Schedules endpoint.
		const rows = await listSchedules(page.request, token, { agentId: agent.id })
		expect(rows).toHaveLength(1)
		expect(rows[0]).toMatchObject({
			name: `${TEST_PREFIX}-weekday-digest`,
			kind: 'cron',
			cronExpr: '0 8 * * 1-5',
			when: 'Every weekday at 08:00',
			enabled: false,
		})
	})
})
