/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Regression e2e — hermiq dashboard + agents (playwright-regression-coverage).
 *
 * The FIRST real assertion-bearing regression spec for Hermiq: it logs a user in through
 * Nextcloud's real login form, opens the Hermiq app, asserts the app shell renders, then
 * navigates to the Agents view and asserts its heading renders — all while collecting
 * page console errors and failing if any surfaced. Unlike docs-screenshots.spec.ts (a
 * screenshot skeleton excluded from the default project), this spec runs in the default
 * `chromium` project so PR pipelines actually drive the browser against these flows.
 *
 * Run against a running Nextcloud with Hermiq + OpenRegister installed:
 *
 *     NEXTCLOUD_URL=http://localhost:8080 \
 *       NC_USER=admin NC_PASS=admin \
 *       npx playwright test --project chromium
 *
 * Covers: openspec/specs/dashboard-page/spec.md (Dashboard renders) and part of
 * openspec/specs/agent-management-ui/spec.md (Agents list renders).
 */

import { test, expect, type Page } from '@playwright/test'

const NC_USER = process.env.NC_USER || 'admin'
const NC_PASS = process.env.NC_PASS || 'admin'

/**
 * Log the configured user in through Nextcloud's real login form.
 *
 * Idempotent: if a storage-state / already-authenticated session lands us straight in the
 * app, the login form is absent and we return without acting.
 *
 * @param page The Playwright page.
 */
async function login(page: Page): Promise<void> {
	await page.goto('/login', { waitUntil: 'domcontentloaded' })

	const userField = page.locator('#user')
	if ((await userField.count()) === 0) {
		// Already authenticated (redirected past the login form).
		return
	}

	await userField.fill(NC_USER)
	await page.locator('#password').fill(NC_PASS)
	await page.locator('button[type="submit"], input[type="submit"]').first().click()
	// Nextcloud holds persistent long-poll connections, so 'networkidle' never fires; the
	// login field detaching once we land past the form is the unambiguous "logged in" signal.
	await page.locator('#user').waitFor({ state: 'hidden', timeout: 30_000 })
}

/**
 * Collect console errors on a page so a flow can assert none surfaced.
 *
 * Nextcloud emits benign warnings; we only capture `error`-level messages and filter the
 * well-known noisy favicon/manifest 404s that are unrelated to the app under test.
 *
 * @param page The Playwright page.
 * @return A live array that accumulates error message strings.
 */
function collectConsoleErrors(page: Page): string[] {
	const errors: string[] = []
	page.on('console', (msg) => {
		if (msg.type() !== 'error') {
			return
		}
		const text = msg.text()
		if (
			/favicon|manifest\.json|the server responded with a status of 404/i.test(
				text,
			)
		) {
			return
		}
		// The chat-health probe answers a DESIGNED 503 ({"status":"no_provider"})
		// on instances without an LLM provider configured (e.g. disposable e2e
		// instances); the browser logs that as a resource error, but it is an
		// expected response, not an app failure.
		if ((msg.location()?.url || '').includes('/api/chat/health')) {
			return
		}
		// 🔴 Only hermiq's own failures may fail a hermiq regression test.
		//
		// Nextcloud's Dashboard hosts every installed app's widgets, so a shared
		// instance reliably logs errors from apps this suite knows nothing about
		// (`opencatalogi-catalogiWidget`, `pipelinq-myLeadsWidget`, … each
		// "TypeError: Failed to fetch" when their own backend is unreachable).
		// Counting those made the assertion a report on the whole instance: it
		// passed or failed on whether somebody else's app happened to be healthy,
		// and it would go red for a hermiq change that is entirely correct.
		//
		// Attribute by the emitting script instead. Errors with no location (raw
		// `console.error` from application code, e.g. a Vue warning) are kept —
		// dropping them would silently gut what this test exists to catch.
		const source = `${msg.location()?.url || ''} ${text}`
		const foreignApp =
			source.match(/\/custom_apps\/([^/]+)\//)?.[1]
			|| source.match(/\/apps\/([^/]+)\/js\//)?.[1]
		if (foreignApp !== undefined && foreignApp !== 'hermiq') {
			return
		}
		errors.push(text)
	})
	return errors
}

/**
 * Dismiss the first-run walkthrough / setup wizard so it does not sit over the
 * dashboard grid. Both are opt-out overlays that render on a fresh session.
 *
 * @param page The Playwright page.
 */
async function dismissOnboarding(page: Page): Promise<void> {
	const modal = page.locator('[data-testid="cn-modal"]')
	// Both overlays are driven by an ASYNC status fetch, so they can appear AFTER
	// the first paint: dismissing once and moving on left the setup wizard's
	// backdrop over the page, and a click on a table row then failed with
	// "subtree intercepts pointer events" rather than anything about the row.
	// Poll until nothing is left to dismiss.
	for (let attempt = 0; attempt < 6; attempt++) {
		if ((await modal.isVisible().catch(() => false)) === false) {
			// One more beat, in case it is still on its way in.
			await page.waitForTimeout(600)
			if ((await modal.isVisible().catch(() => false)) === false) {
				break
			}
		}
		await modal
			.first()
			.getByRole('button', { name: 'Close' })
			.click({ timeout: 2_000 })
			.catch(() => {})
		await page.keyboard.press('Escape').catch(() => {})
		await page.waitForTimeout(700)
	}

	// The walkthrough is a popover, not a modal — it has its own Close.
	const closers = page.getByRole('button', { name: 'Close' })
	for (let i = 0, count = await closers.count(); i < count; i++) {
		await closers
			.nth(i)
			.click({ timeout: 2_000 })
			.catch(() => {})
	}

	// Deliberately NOT asserted gone here: the setup wizard is re-opened by an
	// async status fetch (`completed` is derived from `setup_llm_tested`, which is
	// unset on any instance where nobody has tested an LLM endpoint — including a
	// fresh CI one). It can therefore come BACK after a successful dismissal, so
	// an interaction that must land uses clickPastOverlays() instead of trusting
	// a one-shot dismissal.
}

/**
 * Click something that an async overlay keeps stealing pointer events from.
 *
 * Dismiss-then-click in a loop: the setup wizard re-mounts whenever its status
 * fetch resolves, so a dismissal followed by a click is a race the click loses
 * with "subtree intercepts pointer events" — an error that says nothing about
 * the element under test.
 *
 * @param page   The Playwright page.
 * @param target The element to click.
 */
async function clickPastOverlays(
	page: Page,
	target: ReturnType<Page['locator']>,
): Promise<void> {
	let lastError: unknown = null
	for (let attempt = 0; attempt < 5; attempt++) {
		await dismissOnboarding(page)
		try {
			await target.click({ timeout: 5_000 })
			return
		} catch (error) {
			lastError = error
		}
	}
	throw lastError
}

test.describe('hermiq regression: dashboard + agents', () => {
	test('logs in, opens the app, and renders the Agents view without console errors', async ({
		page,
	}) => {
		const errors = collectConsoleErrors(page)

		await login(page)

		// The Hermiq app shell renders (its nav lists the Agents entry).
		await page.goto('/apps/hermiq/', { waitUntil: 'domcontentloaded' })
		await expect(
			page
				.locator(
					'#app-content, .app-hermiq, [data-testid-page-id="Dashboard"]',
				)
				.first(),
		).toBeVisible()

		// Navigate to the Agents view and assert its heading renders. The app uses Vue
		// history mode (ADR-004), so the route is a real path, not a `#/` hash fragment.
		// manifest-driven-pages: AgentCatalog converted from a bespoke type:"custom" page
		// (data-testid="agent-catalog-heading") to a generic type:"index" page rendered
		// by CnPageRenderer/CnIndexPage — assert on CnPageRenderer's stable
		// data-testid-page-id instead of the removed bespoke testid.
		await page.goto('/apps/hermiq/agents', { waitUntil: 'domcontentloaded' })
		const agentsPage = page.locator('[data-testid-page-id="AgentCatalog"]')
		await expect(agentsPage).toBeVisible({ timeout: 10_000 })
		await expect(agentsPage).toContainText('Agent')

		// No app-level console errors surfaced across the flow.
		expect(
			errors,
			`Unexpected console errors: ${errors.join(' | ')}`,
		).toHaveLength(0)
	})

	test('renders the Dashboard quota tiles matching a direct API call (dashboard-org-widgets)', async ({
		page,
	}) => {
		await login(page)

		// The admin login used across this spec is an instance admin, so
		// `can_manage_killswitch` is true and the quota tiles render
		// (dashboard-org-widgets: relocated from TenantOps.vue onto the Dashboard).
		await page.goto('/apps/hermiq/', { waitUntil: 'domcontentloaded' })
		await dismissOnboarding(page)

		// Each quota is its OWN dashboard tile now (no wrapping "quota-usage"
		// widget): the predecessor drew two bordered cards inside the dashboard's
		// widget card, which read as nested chrome and did not line up with the
		// KPI tiles beside it. Both tiles resolve to the same `quota-stat`
		// registry widget, differing only by content.metric.
		const schedulesCard = page.locator('[data-testid="quota-schedules-card"]')
		const agentsCard = page.locator('[data-testid="quota-agents-card"]')
		await expect(schedulesCard).toBeVisible({ timeout: 10_000 })
		await expect(agentsCard).toBeVisible()

		// Cross-check the rendered values against a direct call to the same
		// endpoint the widget fetches (GET /api/tenant-ops/quota), sharing the
		// browser context's authenticated session.
		const response = await page.request.get('/apps/hermiq/api/tenant-ops/quota')
		expect(response.ok()).toBeTruthy()
		const quota = await response.json()

		await expect(schedulesCard).toContainText(
			`${quota.schedules.count} / ${quota.schedules.limit}`,
		)
		await expect(agentsCard).toContainText(
			`${quota.agents.count} / ${quota.agents.limit}`,
		)
	})

	/**
	 * dashboard-kpi-tiles: each headline metric is its OWN `type:"stat"` tile.
	 *
	 * The predecessor was one custom widget that drew four bordered cards inside
	 * the dashboard's widget card — a card inside a card. The guard that matters
	 * is therefore structural as well as numeric: FOUR separate stat tiles, each
	 * carrying a value that matches the same /api/analytics payload the tiles
	 * read. "Widget not available" is the failure this catches — the registry
	 * widgets render as that placeholder text when the app forgets to call
	 * registerBuiltinDashboardWidgets(), silently and with no console error.
	 */
	test('renders four separate KPI stat tiles matching /api/analytics (dashboard-kpi-tiles)', async ({
		page,
	}) => {
		const errors = collectConsoleErrors(page)

		await login(page)
		await page.goto('/apps/hermiq/', { waitUntil: 'domcontentloaded' })
		await dismissOnboarding(page)

		const tiles = page.locator('.cn-stat-widget')
		await expect(tiles).toHaveCount(4, { timeout: 20_000 })

		// No registry widget resolved to the unavailable placeholder.
		await expect(page.locator('.cn-dashboard-page__unknown')).toHaveCount(0)

		const response = await page.request.get('/apps/hermiq/api/analytics')
		expect(response.ok()).toBeTruthy()
		const metrics = await response.json()

		const dashboard = page.locator('[data-testid-page-id="Dashboard"]')
		await expect(dashboard).toContainText(String(metrics.totalRuns))
		// Success rate renders with a percent suffix and no decimals.
		await expect(dashboard).toContainText(`${Math.round(metrics.successRate)}%`)

		expect(
			errors,
			`Unexpected console errors: ${errors.join(' | ')}`,
		).toHaveLength(0)
	})

	/**
	 * cn-flow-runs-widget: the shared nc-vue widget over OpenRegister's one flow
	 * engine. hermiq ships NO code behind it — a manifest placement is the whole
	 * integration — so this asserts the placement resolves and agrees with the
	 * endpoint rather than testing the widget's internals (those are unit-tested
	 * in nextcloud-vue).
	 */
	test('renders the shared running-flows widget agreeing with /api/flow-runs/active (cn-flow-runs-widget)', async ({
		page,
	}) => {
		await login(page)
		await page.goto('/apps/hermiq/', { waitUntil: 'domcontentloaded' })
		await dismissOnboarding(page)

		const widget = page.locator('.cn-flow-runs-widget')
		await expect(widget).toBeVisible({ timeout: 20_000 })

		const response = await page.request.get(
			'/apps/openregister/api/flow-runs/active?limit=6',
		)
		expect(response.ok()).toBeTruthy()
		const payload = await response.json()

		const rows = widget.locator('.cn-flow-runs-widget__row')
		await expect(rows).toHaveCount(payload.results.length)

		if (payload.results.length === 0) {
			// Nothing running is the normal state: ONE quiet line, no error.
			await expect(widget.locator('.cn-flow-runs-widget__empty')).toBeVisible()
			await expect(widget.locator('.cn-flow-runs-widget__error')).toHaveCount(
				0,
			)
			return
		}

		// Each row identifies its flow by the name the endpoint resolved (falling
		// back to the flow id when the owning app no longer claims it).
		await expect(rows.first()).toContainText(payload.results[0].flowName)
	})

	/**
	 * cn-flow-runs-widget: the Flows index must be able to OPEN a flow.
	 *
	 * `config.rowRoute` on a `type:"index"` page was parsed, validated and
	 * ignored, so an index whose detail surface is a `type:"custom"` canvas
	 * shipped rows that were dead on click — visually identical to a working
	 * table. This is the regression guard for that: click a row, land on the
	 * builder route.
	 *
	 * The page ids are the MANIFEST's (`CnPageRenderer` binds `currentPage.id`
	 * to `data-testid-page-id`), so they moved with hermiq-flow-rename:
	 * `GraphIndex`/`GraphDetail` no longer exist and a selector naming them
	 * matches nothing at all.
	 */
	test('opens the flow builder from a Flows index row (rowRoute)', async ({
		page,
	}) => {
		await login(page)
		await page.goto('/apps/hermiq/flows', { waitUntil: 'domcontentloaded' })
		await dismissOnboarding(page)

		const index = page.locator('[data-testid-page-id="FlowIndex"]')
		await expect(index).toBeVisible({ timeout: 20_000 })

		const firstNameCell = index.locator('tbody tr').first().locator('td').nth(1)
		await expect(firstNameCell).toBeVisible()
		await clickPastOverlays(page, firstNameCell)

		// The builder is a custom page at /flows/:id — assert the URL carries an
		// id and the custom page mounted.
		await expect(page).toHaveURL(/\/apps\/hermiq\/flows\/[^/]+$/, {
			timeout: 20_000,
		})
		await expect(
			page.locator('[data-testid-page-id="FlowDetail"]'),
		).toBeVisible()
	})

	/**
	 * ONE row action, Edit, and it opens the canvas.
	 *
	 * The index used to offer BOTH built-ins, which were redundant with each
	 * other and neither did what a flow needs: View only navigated (exactly what
	 * a row click does) and Edit opened the field form, which cannot touch nodes
	 * or edges. Both were turned off in the manifest, replaced by a
	 * `type: "open-page"` action — `navigate` would not do, because it pushes its
	 * target verbatim while `open-page` pushes `{name, params:{id}}` and so
	 * carries the clicked row.
	 *
	 * ⚠️ `test.fixme` — this is a REAL failure, not a broken test, and it was
	 * invisible until now. The test entered through
	 * `[data-testid-page-id="GraphIndex"]`, a page id hermiq-flow-rename deleted,
	 * so it died on its first line and never reached an assertion. Fixing the
	 * selector let it run, and it fails at `View` with `Received: 1`.
	 *
	 * The cause has MOVED, and the test still cannot pass — so it stays fixme.
	 *
	 * It used to fail because `FlowIndex` was a hand-written `type:"custom"`
	 * component that rendered `<CnIndexPage>` with no row-action config, so
	 * nc-vue's defaults applied and View came back. That component is gone: the
	 * page is a `type:"index"` over the named `flows` source again.
	 *
	 * It still fails, for a different and narrower reason. The named source DOES
	 * declare exactly one row action (Edit), but `CnIndexPage` does not read
	 * `rowActions` from a source at all, and the `actions` prop it would map onto
	 * MERGES with the built-ins rather than replacing them. So a source cannot
	 * currently express "these actions and no others" — see
	 * `ConductionNL/nextcloud-vue`.
	 *
	 * Left failing-as-written rather than relaxed to green: an assertion quietly
	 * rewritten to match current behaviour is how the decision would be lost a
	 * second time. Re-check this once a named source can suppress the built-ins.
	 */
	test.fixme('offers exactly one row action, Edit, which opens the flow builder', async ({
		page,
	}) => {
		await login(page)
		await page.goto('/apps/hermiq/flows', { waitUntil: 'domcontentloaded' })
		await dismissOnboarding(page)

		const index = page.locator('[data-testid-page-id="FlowIndex"]')
		await expect(index).toBeVisible({ timeout: 20_000 })

		const firstRow = index.locator('tbody tr').first()
		await clickPastOverlays(
			page,
			firstRow.getByRole('button', { name: 'Actions' }),
		)

		// Edit is present; the redundant View is gone.
		const menu = page.locator('.v-popper__popper--shown, [role="menu"]').last()
		await expect(menu.getByText('Edit', { exact: true })).toBeVisible({
			timeout: 10_000,
		})
		await expect(menu.getByText('View', { exact: true })).toHaveCount(0)

		await menu.getByText('Edit', { exact: true }).click()

		await expect(page).toHaveURL(/\/apps\/hermiq\/flows\/[^/]+$/, {
			timeout: 20_000,
		})
		await expect(
			page.locator('[data-testid-page-id="FlowDetail"]'),
		).toBeVisible()
	})

	// REMOVED: `graph-editor-vocabulary` — it asserted a flow dialect that no
	// longer exists, using selectors that had already been deleted.
	//
	// Worth recording WHY, because the reasoning left here in its place was
	// itself wrong by the time it was written. It said a node is a Petri-net
	// PLACE carrying no `type`/`config` and the EDGE carries the step. That was
	// true once; `or-flow-action-nodes` INVERTED it. A node is now the action —
	// it holds the step type and its config, and it is the thing that runs —
	// while an edge is sequence: `from`, `to`, and an optional title.
	//
	// So two successive comments here each described the model as it had just
	// stopped being. That is the hazard of an app restating a shared engine's
	// semantics: the app's copy drifts and nothing fails. This app no longer has
	// an editor of its own to describe, so both the description and the coverage
	// belong to `@conduction/nextcloud-vue`.
})
