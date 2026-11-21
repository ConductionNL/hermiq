/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec-coverage e2e: switch AI off for one department
 * (oversight-switch-ai-off-per-department 2.1).
 *
 * WHAT THE UNIT TESTS CANNOT SHOW. DepartmentSwitchTest, ScheduleDepartmentGateTest
 * and DepartmentEvidenceTest pin the switch, the refused run and the evidence
 * with stubbed OpenRegister reads. They cannot show that a `departmentcontrol`
 * object written through OpenRegister is the one the run gate reads, that the
 * refusal reaches the audit trail the evidence file is built from, or that the
 * "Per department" list on the Approvals page renders the stored state. That
 * sits between OpenRegister, the controller and the page, so it is here.
 *
 * Two Nextcloud groups are created and marked as departments, each with an
 * agent and a schedule. Runs are started through the run-now route, which goes
 * through the same gates as a scheduled tick. The administrator runs it (the
 * project's admin storage state); a second, non-admin user is provisioned for
 * the refusal scenario. Every seeded object carries the e2espec- family prefix
 * and is removed in afterAll, with the groups and the second user.
 *
 * Not run when written: it needs a running Nextcloud with Hermiq and
 * OpenRegister installed.
 *
 *     NEXTCLOUD_URL=http://localhost:8080 NC_USER=admin NC_PASS=admin \
 *       npx playwright test --project=chromium oversight-switch-ai-off-per-department
 *
 * @spec openspec/changes/oversight-switch-ai-off-per-department/specs/human-approval-gate/spec.md
 */

import type { APIRequestContext } from '@playwright/test'
import type { SecondUser } from './_fixtures.ts'

import { expect, test } from '@playwright/test'
import {
	appRoot,
	cleanupFamily,
	createSecondUser,
	deleteSecondUser,
	dismissTour,
	harvestToken,
	jsonHeaders,
	OR_API,
	seedAgent,
	seedObject,
	TEST_PREFIX,
} from './_fixtures.ts'

const API = '/index.php/apps/hermiq/api'

/** The refusal status a run of an agent in a switched-off department records. */
const REFUSED = 'skipped_department_off'

/** The refusal status a run records while the organisation kill-switch is engaged. */
const KILLSWITCH = 'skipped_killswitch'

const OFF_GROUP = `${TEST_PREFIX}-sociaal`
const ON_GROUP = `${TEST_PREFIX}-burgerzaken`
const OFF_LABEL = `${TEST_PREFIX} Sociaal domein`
const ON_LABEL = `${TEST_PREFIX} Burgerzaken`

/**
 * Create a Nextcloud group through the provisioning API.
 *
 * OCS v1 answers 200 for failures too, so the outcome is read from
 * `ocs.meta.statuscode` (100 created, 102 exists).
 *
 * @param req   The admin request context.
 * @param token The CSRF request-token.
 * @param gid   The group id.
 */
async function createGroup(req: APIRequestContext, token: string, gid: string): Promise<void> {
	const res = await req.post('/ocs/v1.php/cloud/groups?format=json', {
		headers: { ...jsonHeaders(token), 'OCS-APIRequest': 'true' },
		data: { groupid: gid },
	})
	const body = await res.json().catch(() => null)
	const code = Number(body?.ocs?.meta?.statuscode)
	expect([100, 102].includes(code), `create group ${gid}: HTTP ${res.status()} / OCS ${code}`).toBeTruthy()
}

/**
 * Delete a Nextcloud group (best-effort).
 *
 * @param req   The admin request context.
 * @param token The CSRF request-token.
 * @param gid   The group id.
 */
async function deleteGroup(req: APIRequestContext, token: string, gid: string): Promise<void> {
	await req
		.delete(`/ocs/v1.php/cloud/groups/${encodeURIComponent(gid)}`, {
			headers: { ...jsonHeaders(token), 'OCS-APIRequest': 'true' },
		})
		.catch(() => null)
}

/**
 * Switch a department on or off through the admin route.
 *
 * @param req   The request context.
 * @param token The CSRF request-token.
 * @param group The department's group id.
 * @param off   True to switch AI off.
 * @return The response.
 */
async function toggleDepartment(req: APIRequestContext, token: string, group: string, off: boolean) {
	return req.post(`${API}/department-control/${encodeURIComponent(group)}/toggle`, {
		headers: jsonHeaders(token),
		data: { off: off ? 'true' : 'false', reason: off ? 'e2e: switched off for the test' : '' },
	})
}

/**
 * Read one department's row from the admin overview.
 *
 * @param req   The admin request context.
 * @param token The CSRF request-token.
 * @param group The department's group id.
 * @return The row, or undefined when the department is not listed.
 */
async function departmentRow(req: APIRequestContext, token: string, group: string): Promise<Record<string, unknown> | undefined> {
	const res = await req.get(`${API}/department-control`, { headers: jsonHeaders(token) })
	expect(res.ok(), `department overview HTTP ${res.status()}`).toBeTruthy()
	const body = await res.json()
	return (body.results ?? []).find((row: Record<string, unknown>) => row.group === group)
}

/**
 * Start a schedule now and return the status the run recorded.
 *
 * @param req        The request context.
 * @param token      The CSRF request-token.
 * @param scheduleId The schedule uuid.
 * @return The `status` the run-now route answers with.
 */
async function runNow(req: APIRequestContext, token: string, scheduleId: string): Promise<string> {
	const res = await req.post(`${API}/schedules/${scheduleId}/run`, { headers: jsonHeaders(token) })
	const body = await res.json().catch(() => ({}))
	return String(body.status ?? '')
}

test.describe('oversight-switch-ai-off-per-department: one department off, the others on', () => {
	let token = ''
	let offSchedule = ''
	let onSchedule = ''
	let organisation = ''
	let member: SecondUser | null = null

	test.beforeAll(async ({ browser }) => {
		const page = await browser.newPage()
		token = await harvestToken(page)
		const req = page.request

		for (const [group, label] of [[OFF_GROUP, OFF_LABEL], [ON_GROUP, ON_LABEL]]) {
			await createGroup(req, token, group)
			await seedObject(req, token, 'departmentcontrol', { name: label, group, label, aiOff: false })
		}

		const offAgent = await seedAgent(req, token, { name: `${TEST_PREFIX}-sociaal-agent`, department: OFF_GROUP })
		const onAgent = await seedAgent(req, token, { name: `${TEST_PREFIX}-burgerzaken-agent`, department: ON_GROUP })

		const schedule = (agentId: string, name: string) => seedObject(req, token, 'schedule', {
			name,
			agentId,
			kind: 'interval',
			intervalMinutes: 1440,
			prompt: 'Say hello.',
			deliver: 'none',
			enabled: true,
		})
		offSchedule = (await schedule(offAgent.id, `${TEST_PREFIX}-sociaal-schedule`)).id
		onSchedule = (await schedule(onAgent.id, `${TEST_PREFIX}-burgerzaken-schedule`)).id

		const stored = await (await req.get(`${OR_API}/objects/hermiq/schedule/${onSchedule}`, { headers: jsonHeaders(token) })).json()
		organisation = String(stored['@self']?.organisation ?? '')

		await page.close()
	})

	test.afterAll(async ({ browser }) => {
		const page = await browser.newPage()
		if (organisation !== '') {
			await page.request.post(`${API}/tenant-control/${encodeURIComponent(organisation)}/toggle`, {
				headers: jsonHeaders(token),
				data: { engaged: 'false', reason: '' },
			}).catch(() => null)
		}
		await cleanupFamily(page.request, token)
		await deleteGroup(page.request, token, OFF_GROUP)
		await deleteGroup(page.request, token, ON_GROUP)
		if (member !== null) {
			await deleteSecondUser(page.request, token, member.uid)
		}
		await page.close()
	})

	// @e2e human-approval-gate::a-department-that-is-off-refuses-its-agents-runs
	test('a department that is off refuses its agent\'s run and the other department\'s agent runs', async ({ page }) => {
		const off = await toggleDepartment(page.request, token, OFF_GROUP, true)
		expect(off.ok(), `switch off HTTP ${off.status()}`).toBeTruthy()
		expect((await off.json()).aiOff).toBe(true)

		expect(await runNow(page.request, token, offSchedule), 'the switched-off department\'s run is refused').toBe(REFUSED)
		expect(await runNow(page.request, token, onSchedule), 'the other department\'s run is not refused by the switch').not.toBe(REFUSED)

		const row = await departmentRow(page.request, token, OFF_GROUP)
		expect(row?.aiOff).toBe(true)
		expect(Number(row?.refusedRuns), 'the refusal is recorded and counted').toBeGreaterThanOrEqual(1)
	})

	// @e2e human-approval-gate::the-evidence-file-lists-the-switch-and-the-refused-runs
	test('the evidence file names the admin, the switch time and the refused run', async ({ page }) => {
		const res = await page.request.get(`${API}/department-control/${encodeURIComponent(OFF_GROUP)}/evidence`, {
			headers: jsonHeaders(token),
		})
		expect(res.ok(), `evidence HTTP ${res.status()}`).toBeTruthy()
		expect(res.headers()['content-disposition'] ?? '', 'the evidence is sent as a download').toContain('attachment')

		const evidence = await res.json()
		expect(evidence.department.group).toBe(OFF_GROUP)
		const switchOff = (evidence.switches ?? []).find((event: Record<string, unknown>) => event.aiOff === true)
		expect(switchOff, 'the switch-off event is listed').toBeTruthy()
		expect(String(switchOff.by), 'the event names who switched it').not.toBe('')
		expect(String(switchOff.at), 'the event carries when').not.toBe('')

		const refused = evidence.refusedRuns ?? []
		expect(refused.length, 'the refused run is listed').toBeGreaterThanOrEqual(1)
		expect(refused.every((run: Record<string, unknown>) => String(run.at) >= String(switchOff.at)), 'each refused run is after the switch-off').toBeTruthy()
	})

	// @e2e human-approval-gate::the-department-list-shows-who-switched-it-off
	test('the approvals page shows the department off, who switched it, when and the refused runs', async ({ page }) => {
		await page.goto(`${await appRoot(page)}/approvals`, { waitUntil: 'domcontentloaded' })
		await dismissTour(page)

		const list = page.getByRole('region', { name: 'Per department' })
		await expect(list, 'the per-department list is on the approvals page').toBeVisible({ timeout: 30_000 })
		const row = list.getByRole('row').filter({ hasText: OFF_LABEL })
		await expect(row.getByRole('button', { name: `AI for ${OFF_LABEL}` })).toContainText('Off')
		await expect(row, 'who switched it and when').toContainText(' at ')
		await expect(row.getByRole('button', { name: `Download evidence for ${OFF_LABEL}` })).toBeVisible()

		const stored = await departmentRow(page.request, token, OFF_GROUP)
		await expect(row, 'the refused-run count').toContainText(String(stored?.refusedRuns))
		await expect(row, 'the switcher').toContainText(String(stored?.switchedBy))
	})

	// @e2e human-approval-gate::only-an-admin-switches-a-department
	test('a user who is not an admin cannot switch a department and its state stays', async ({ browser, page }) => {
		member = await createSecondUser(page.request, token, 'dept-member')
		const context = await browser.newContext({
			storageState: { cookies: [], origins: [] },
			httpCredentials: { username: member.uid, password: member.password, send: 'always' },
		})
		const res = await context.request.post(`${API}/department-control/${encodeURIComponent(ON_GROUP)}/toggle`, {
			headers: { 'OCS-APIRequest': 'true', 'Content-Type': 'application/json' },
			data: { off: 'true', reason: 'not allowed' },
		})
		expect(res.status(), 'a non-admin is refused').toBe(403)
		await context.close()

		const row = await departmentRow(page.request, token, ON_GROUP)
		expect(row?.aiOff, 'the department stays on').toBe(false)
	})

	// @e2e human-approval-gate::the-organisation-kill-switch-wins-over-a-department-that-is-on
	test('the organisation kill-switch stops an agent whose department is on', async ({ page }) => {
		test.skip(organisation === '', 'the seeded schedule carries no organisation, so the kill-switch cannot be engaged for it')

		const engage = await page.request.post(`${API}/tenant-control/${encodeURIComponent(organisation)}/toggle`, {
			headers: jsonHeaders(token),
			data: { engaged: 'true', reason: 'e2e: kill-switch over a department that is on' },
		})
		expect(engage.ok(), `kill-switch HTTP ${engage.status()}`).toBeTruthy()

		expect((await departmentRow(page.request, token, ON_GROUP))?.aiOff, 'the department is on').toBe(false)
		expect(await runNow(page.request, token, onSchedule), 'the kill-switch refuses the run').toBe(KILLSWITCH)
	})
})
