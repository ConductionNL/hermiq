/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Regression e2e — the AgentDetail grid and the app chrome it shares with the
 * rest of the app (agent-detail-redesign).
 *
 * The redesign's whole premise is an arithmetic one — every widget fits the cell
 * the manifest gives it — so it is asserted arithmetically here rather than eyeballed:
 * for each grid cell, the widget's scrollHeight must not exceed the cell's height.
 * That single check is what catches the two failure modes the redesign fixed and
 * the one it could reintroduce:
 *
 *   1. a widget crammed into too few rows (the four KPIs shared one gridHeight:2
 *      cell and spilled over Skills),
 *   2. a widget whose content is a variable-length LIST — the instance-wide tool
 *      catalogue (~100 rows = 6,600px), the invocation audit trail, run history,
 *      memory entries — which no fixed gridHeight can ever hold, and which now
 *      scroll inside their own bounded regions,
 *   3. a future gridHeight edit that shrinks a cell below its content again.
 *
 * It also pins the chrome changes that shipped alongside: the semantic nav icons
 * (an unregistered MDI name renders NOTHING rather than a fallback glyph, so
 * "the manifest names an icon" proves nothing — the SVG has to be measured),
 * Tenant ops living under Settings rather than in the main menu, and the chat
 * using the real Nextcloud user avatar for user turns.
 *
 * Run against a running Nextcloud with Hermiq + OpenRegister installed:
 *
 *     NEXTCLOUD_URL=http://localhost:8080 \
 *       NC_USER=admin NC_PASS=admin \
 *       npx playwright test tests/e2e/agent-detail-and-chrome.spec.ts --project chromium
 *
 * @spec openspec/specs/manifest-driven-pages/spec.md
 */

import { test, expect, type Page } from '@playwright/test'

const NC_USER = process.env.NC_USER || 'admin'
const NC_PASS = process.env.NC_PASS || 'admin'

/**
 * Log the configured user in through Nextcloud's real login form.
 *
 * @param page The Playwright page.
 */
async function login(page: Page): Promise<void> {
	await page.goto('/login', { waitUntil: 'domcontentloaded' })
	const userField = page.locator('#user')
	if (await userField.count() === 0) {
		return
	}
	await userField.fill(NC_USER)
	await page.locator('#password').fill(NC_PASS)
	await page.locator('button[type="submit"], input[type="submit"]').first().click()
	await page.locator('#user').waitFor({ state: 'hidden', timeout: 30_000 })
}

/**
 * Dismiss the setup wizard / walkthrough overlays.
 *
 * Both are driven by an ASYNC status fetch, so they can mount AFTER first paint —
 * dismissing once is not enough, and an overlay that steals a click reads as
 * "the element never appeared" rather than as an overlay problem.
 *
 * @param page The Playwright page.
 */
async function dismissOnboarding(page: Page): Promise<void> {
	const modal = page.locator('[data-testid="cn-modal"]')
	for (let attempt = 0; attempt < 6; attempt++) {
		if (await modal.isVisible().catch(() => false) === false) {
			await page.waitForTimeout(600)
			if (await modal.isVisible().catch(() => false) === false) {
				break
			}
		}
		await modal.first().getByRole('button', { name: 'Close' }).click({ timeout: 2_000 }).catch(() => {})
		await page.keyboard.press('Escape').catch(() => {})
		await page.waitForTimeout(700)
	}
}

/**
 * Click a target, dismissing the onboarding overlays between attempts.
 *
 * The setup wizard re-mounts on its own async status fetch, so it can land back
 * over the page AFTER `dismissOnboarding` already cleared it once. Its backdrop
 * then swallows the click and Playwright reports "subtree intercepts pointer
 * events" against the element under it — which reads as a problem with the
 * element rather than with the overlay. (Escape does not close
 * `cn-wizard-dialog`, so dismissal has to go through its Close button.)
 *
 * @param page The Playwright page.
 * @param target The locator to click.
 */
async function clickPastOverlays(page: Page, target: ReturnType<Page['locator']>): Promise<void> {
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

/**
 * Resolve an agent uuid to open the detail page for, preferring the agent with the
 * MOST populated fields.
 *
 * Sizing a layout against whichever agent happens to be first would size it
 * against the emptiest case: a 1-field agent leaves every cell looking correct
 * while a fully populated one overflows.
 *
 * @param page The Playwright page (already authenticated).
 * @return The agent uuid, or null when the instance has no agents.
 */
async function richestAgentId(page: Page): Promise<string | null> {
	return await page.evaluate(async () => {
		const token = (window as unknown as { OC: { requestToken: string } }).OC.requestToken
		const res = await fetch('/apps/openregister/api/objects/hermiq/agent?_limit=50', {
			headers: { requesttoken: token },
		})
		if (!res.ok) {
			return null
		}
		const body = await res.json()
		const list: Array<Record<string, unknown>> = body.results || body
		const ranked = list
			.map((o) => ({
				id: String((o['@self'] as Record<string, unknown>)?.id ?? o.id ?? ''),
				fields: Object.keys(o).filter((k) => !k.startsWith('@')).length,
			}))
			.filter((o) => o.id !== '')
			.sort((a, b) => b.fields - a.fields)
		return ranked.length > 0 ? ranked[0].id : null
	})
}

test.describe('hermiq regression: agent detail grid + app chrome', () => {
	test('every AgentDetail widget fits the grid cell the manifest gives it (agent-detail-redesign)', async ({ page }) => {
		await login(page)
		const agentId = await richestAgentId(page)
		test.skip(agentId === null, 'instance has no hermiq agents to open')

		await page.goto(`/apps/hermiq/agents/${agentId}`, { waitUntil: 'domcontentloaded' })
		await dismissOnboarding(page)

		const cells = page.locator('.grid-stack-item')
		await expect(cells.first()).toBeVisible({ timeout: 30_000 })

		// Every widget self-fetches; measuring mid-flight reports a widget as
		// fitting simply because its content has not arrived. Wait until the
		// measurements stop moving instead of sleeping a fixed amount.
		let previous = ''
		for (let attempt = 0; attempt < 20; attempt++) {
			await page.waitForTimeout(1_000)
			const signature = await page.evaluate(() =>
				[...document.querySelectorAll('.grid-stack-item')]
					.map((el) => el.querySelector('.grid-stack-item-content')?.scrollHeight ?? 0)
					.join(','),
			)
			if (signature === previous && signature.includes(',')) {
				break
			}
			previous = signature
		}

		const measured = await page.evaluate(() =>
			[...document.querySelectorAll('.grid-stack-item')].map((el) => {
				const inner = el.querySelector('.grid-stack-item-content')
				const header = inner?.querySelector('.cn-widget-wrapper__title, .cn-detail-page__widget-title')
				return {
					name: header?.textContent?.trim()
						|| (inner as HTMLElement)?.innerText?.split('\n')[0]
						|| '(unlabelled)',
					cell: Math.round(el.getBoundingClientRect().height),
					content: inner ? Math.round(inner.scrollHeight) : 0,
				}
			}),
		)

		// The manifest declares 11 widgets; a smaller number means the grid did
		// not finish materialising and the fit assertion below would be vacuous.
		expect(measured.length, 'AgentDetail should render its full widget grid').toBe(11)

		const overflowing = measured
			.filter((m) => m.content > m.cell)
			.map((m) => `${m.name}: ${m.content}px of content in a ${m.cell}px cell`)
		expect(overflowing, `Widgets overflow their grid cells: ${overflowing.join(' | ')}`).toEqual([])

		// No widget type resolved to nothing. A missing registry type renders the
		// "Widget not available" placeholder, which FITS its cell happily and so
		// would sail through the check above.
		await expect(page.locator('.cn-dashboard-page__unknown')).toHaveCount(0)
	})

	test('the KPI tiles render their label once, not twice (showTitle)', async ({ page }) => {
		await login(page)
		const agentId = await richestAgentId(page)
		test.skip(agentId === null, 'instance has no hermiq agents to open')

		await page.goto(`/apps/hermiq/agents/${agentId}`, { waitUntil: 'domcontentloaded' })
		await dismissOnboarding(page)
		await expect(page.locator('.grid-stack-item').first()).toBeVisible({ timeout: 30_000 })
		await page.waitForTimeout(3_000)

		// A stat renderer draws its own label from content.label, so the wrapper
		// header would print the same words a second time. The four KPI tiles
		// carry showTitle:false for exactly that reason — which CnDetailPage
		// honoured only for the grid <h3> until the paired library fix.
		//
		// Select the tiles by WHAT THEY ARE (a stat renderer, `.cn-stat-widget`),
		// not by grid position. The 2026-08-04 recomposition moved the four KPIs
		// out of the first four cells into the right-hand rail and put agent-core
		// ("Configuration", legitimately showTitle:true) at gridY 0 — so the old
		// `.slice(0, 4)` asserted showTitle:false against a widget that is
		// SUPPOSED to draw a header, and failed on correct markup.
		const kpis = await page.evaluate(() =>
			[...document.querySelectorAll('.grid-stack-item')]
				.filter((el) => el.querySelector('.cn-stat-widget') !== null)
				.map((el) => {
					const inner = el.querySelector('.grid-stack-item-content')
					return {
						text: (inner as HTMLElement)?.innerText?.trim() ?? '',
						headers: inner?.querySelectorAll('.cn-widget-wrapper__title').length ?? 0,
					}
				}),
		)

		// Guard the selector itself: if no stat tile matched, every assertion in
		// the loop below would be skipped and the test would report green while
		// checking nothing.
		expect(kpis.length, 'no stat tiles matched — the showTitle assertions would be vacuous').toBe(4)

		for (const kpi of kpis) {
			expect(kpi.headers, `KPI tile "${kpi.text.split('\n')[0]}" still renders a wrapper header`).toBe(0)
			// The label must appear exactly once in the tile's text.
			const label = kpi.text.split('\n')[0]
			const occurrences = kpi.text.split(label).length - 1
			expect(occurrences, `KPI label "${label}" appears ${occurrences}x`).toBe(1)
		}
	})

	test('every nav entry renders a real icon, and Tenant ops sits under Settings (ADR-077)', async ({ page }) => {
		await login(page)
		await page.goto('/apps/hermiq/', { waitUntil: 'domcontentloaded' })
		await dismissOnboarding(page)

		const nav = page.locator('.app-navigation__content')
		await expect(nav).toBeVisible({ timeout: 30_000 })

		// Tenant ops is an operator surface, so it belongs in the Settings
		// foldout, not the main menu. Assert BOTH halves: not reachable until the
		// foldout is opened, reachable after. Checking only the second half would
		// pass with the entry duplicated in both places.
		//
		// By VISIBILITY, not by presence: a collapsed foldout keeps its children
		// in the DOM, so `allInnerTexts()` lists "Tenant ops" either way and an
		// assertion on the text list can never tell the two placements apart.
		const tenantOps = nav.getByRole('link', { name: 'Tenant ops' })
		await expect(tenantOps).toBeHidden()

		await clickPastOverlays(page, nav.getByTestId('cn-nav-settings').getByRole('button'))
		await expect(tenantOps).toBeVisible({ timeout: 10_000 })

		// An MDI name the app never registered renders NOTHING — no fallback
		// glyph, no console error. So measure the SVG, don't trust the manifest.
		const iconless = await page.evaluate(() =>
			[...document.querySelectorAll('.app-navigation__content a')]
				.map((a) => {
					const svg = a.querySelector('svg')
					const box = svg?.getBoundingClientRect()
					return {
						label: (a as HTMLElement).innerText.trim().split('\n')[0],
						renders: !!box && box.width > 0 && box.height > 0,
					}
				})
				.filter((row) => row.label !== '' && !row.renders)
				.map((row) => row.label),
		)
		expect(iconless, `Nav entries render no icon: ${iconless.join(', ')}`).toEqual([])

		// The two entries this change re-pointed, by rendered icon class.
		const iconFor = async (label: string) => await page.evaluate((wanted) => {
			const link = [...document.querySelectorAll('.app-navigation__content a')]
				.find((a) => (a as HTMLElement).innerText.trim().split('\n')[0] === wanted)
			const icon = link?.querySelector('.material-design-icon')
			return icon
				? [...icon.classList].find((c) => c.endsWith('-icon') && c !== 'material-design-icon') ?? null
				: null
		}, label)

		// One mark per meaning: the AI sparkles (Creation) denote "an agent", so
		// they belong on Agents. Chat had carried them, which read as "AI chat"
		// and made the sparkles ambiguous between the two entries; Chat now takes
		// a plain message mark. (Superseded the original Agents=robot pairing —
		// the robot no longer appears in the nav or in the chat thread.)
		expect(await iconFor('Agents')).toBe('creation-icon')
		expect(await iconFor('Chat')).toBe('message-text-outline-icon')
	})

	test('chat shows the real user avatar for user turns and the AI mark for the agent', async ({ page }) => {
		await login(page)
		await page.goto('/apps/hermiq/chat', { waitUntil: 'domcontentloaded' })
		await dismissOnboarding(page)

		// Open the first existing conversation; a conversation with no turns
		// renders no avatar at all and the assertion would be vacuous.
		//
		// By testid: the row is a `<div role="button">`, so a CSS `button`
		// selector matches nothing and the test SKIPPED — reporting green while
		// asserting nothing at all, which is worse than failing.
		//
		// WAIT for the first row rather than counting straight away: the list is
		// fetched async, so a bare count() raced it and failed in a full-suite run
		// while passing when the test ran alone.
		const conversations = page.getByTestId('chat-conversation-row')
		await expect(
			conversations.first(),
			'no conversation rows rendered — the avatar assertions below would be vacuous',
		).toBeVisible({ timeout: 30_000 })

		// SEARCH for a thread that actually contains a user turn instead of
		// asserting against whichever conversation happens to be first. Not every
		// thread has one — agent-authored and scheduled-run threads are all
		// assistant turns — so the first row was a coin toss: it skipped, and a
		// skip asserts nothing at all while reporting green.
		//
		// WAIT for the turn rather than sleeping a fixed 3s: the thread is fetched
		// async, and on a cold instance (opcache warming after a deploy costs
		// whole seconds on the first request) the sleep expired before the
		// messages landed, so every avatar count read 0 and the failure looked
		// like "NcAvatar never rendered" rather than "nothing had loaded".
		// Return to the list between candidates: opening a thread re-renders the
		// conversation list, so holding an nth() locator across the click made the
		// SECOND iteration time out waiting for a row index that no longer
		// existed — a failure of the loop, not of the avatars.
		const userTurn = page.locator('.chat-page__message--user').first()
		const candidates = Math.min(await conversations.count(), 8)
		let found = false
		for (let index = 0; index < candidates && found === false; index++) {
			if (index > 0) {
				await page.goto('/apps/hermiq/chat', { waitUntil: 'domcontentloaded' })
				await dismissOnboarding(page)
				await expect(conversations.nth(index)).toBeVisible({ timeout: 30_000 })
			}
			await clickPastOverlays(page, conversations.nth(index))
			// waitFor, NOT isVisible: `isVisible()` resolves IMMEDIATELY — it does
			// not auto-wait, and its `timeout` option buys nothing. It therefore
			// sampled the thread in the moment after the click, before the async
			// fetch had painted a single bubble, and reported false for every
			// candidate — "no user turn in the first 8 conversations" against a
			// page whose very first thread has one.
			found = await userTurn.waitFor({ state: 'visible', timeout: 15_000 })
				.then(() => true)
				.catch(() => false)
		}
		expect(
			found,
			`no user turn in the first ${candidates} conversations — the avatar assertions would be vacuous`,
		).toBe(true)

		const avatars = await page.evaluate(() => {
			const main = document.querySelector('main')
			return {
				// NcAvatar renders .avatardiv; the placeholder icon it replaced was
				// AccountCircle, so its absence is the other half of the check.
				userAvatars: main?.querySelectorAll('.chat-page__message--user .avatardiv').length ?? 0,
				accountCircles: main?.querySelectorAll('.account-circle-icon').length ?? 0,
				// One mark per meaning: the assistant turn carries the AI sparkles
				// (Creation), the same mark the Agents nav entry uses. The robot is
				// gone from the app entirely.
				aiMarks: main?.querySelectorAll('.chat-page__message--assistant .creation-icon').length ?? 0,
				robots: main?.querySelectorAll('.robot-icon').length ?? 0,
			}
		})

		expect(avatars.accountCircles, 'chat still renders the generic AccountCircle placeholder').toBe(0)
		expect(avatars.robots, 'chat still renders the retired robot mark').toBe(0)
		expect(avatars.userAvatars, 'no real user avatar rendered for the user turn').toBeGreaterThan(0)
		expect(avatars.aiMarks, 'no AI mark rendered for the agent turn').toBeGreaterThan(0)
	})
})
