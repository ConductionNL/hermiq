/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Speech-services e2e (spec-coverage).
 *
 * Covered openspec scenarios, from openspec/specs/speech-services/spec.md:
 *       #### Scenario: A confidential agent dictates
 *       #### Scenario: The speech service is down and the agent is local-only
 *       #### Scenario: Two agents, two policies
 *       #### Scenario: Speech switched off entirely
 *       #### Scenario: The speaker pauses to think
 *       #### Scenario: Recording
 *
 * ⚠️ THE ANCHOR THAT COUNTS IS THE `// @e2e` COMMENT ABOVE EACH `test()`, not
 * the prose above. gate-19 reads the TEST files; a matching line in the spec
 * file is human documentation and satisfies nothing. Written down because the
 * first version of this suite tagged only the spec and the gate still reported
 * six scenarios uncovered — a tag in the wrong place looks exactly like
 * coverage until something checks.
 *
 * WHY THIS SUITE EXISTS. The microphone shipped rendering a struck-through
 * "muted" glyph WHILE RECORDING, and nothing caught it: the composer had no
 * dictation coverage at any layer, so an inverted icon was exactly as green as
 * a correct one. Everything here asserts what the user can SEE or what leaves
 * the browser — not what the component believes about itself.
 *
 * ⚠️ WHAT IS REAL AND WHAT IS SIMULATED, stated per test rather than left to
 * the reader:
 *   - The agent-form test is entirely real: it types into the shipped form,
 *     saves, and reads the stored policy back out of the API.
 *   - The engine-policy tests intercept `/api/agents` so exactly one agent
 *     answers. On an instance carrying 50+ agents the composer selects the
 *     first one the API returns, which no test can pin otherwise; the
 *     behaviour under test — how the composer resolves an agent's policy — is
 *     still the shipped code.
 *   - The service-down test intercepts `/api/speech/capabilities`. An outage
 *     cannot be produced any other way, and this is the assertion that matters
 *     most: a local-pinned agent must NOT fall back to the browser engine,
 *     because the browser engine is Google's in Chrome.
 *   - The recording test stubs getUserMedia/MediaRecorder. Headless Chromium
 *     has no microphone; the icon logic, the state class and the track release
 *     are the shipped ones.
 *
 * Auth: shared storageState session (tests/e2e/global-setup.ts).
 * Seeding: OpenRegister objects API via _fixtures (register 'hermiq').
 */

import { test, expect, type Page } from '@playwright/test'
import {
	TEST_PREFIX,
	appRoot,
	deleteObject,
	dismissTour,
	harvestToken,
	resolveRegisterSchema,
	seedAgent,
} from './_fixtures'

/** The companion launcher, present on every Nextcloud page. */
const FAB = '[data-testid="cn-ai-fab"]'

/** The composer's microphone control. */
const MIC = '[data-testid="cn-ai-input-mic"]'

/** The hands-free conversation control, offered only where an agent allows it. */
const CONVERSE = '[data-testid="cn-ai-input-converse"]'

/**
 * One agent object, shaped the way `GET /api/agents` returns them.
 *
 * @param overrides Policy fields under test.
 * @return The agent payload.
 */
function agentFixture(overrides: Record<string, unknown> = {}) {
	return {
		uuid: '00000000-e2e0-4000-8000-00000000cafe',
		id: '00000000-e2e0-4000-8000-00000000cafe',
		name: `${TEST_PREFIX}-speech-agent`,
		description: 'Speech policy fixture',
		voiceInputEngine: 'auto',
		voiceOutputEngine: 'auto',
		voiceSilenceTimeout: 2500,
		voiceConversationEnabled: false,
		...overrides,
	}
}

/**
 * Serve exactly one agent, and a fixed answer to the speech capability probe.
 *
 * @param page The page.
 * @param agent The single agent to return.
 * @param localAvailable What the capability probe reports.
 * @return Nothing.
 */
async function pinComposerContext(
	page: Page,
	agent: Record<string, unknown>,
	localAvailable: boolean,
): Promise<void> {
	// 🔴 THE LAUNCHER FAILS CLOSED WITHOUT AN LLM PROVIDER, and CI configures
	// none: `CnAiCompanion` probes `/api/chat/health` once at mount and simply
	// does not render the button on a non-2xx. Locally the dev instance has a
	// provider and the button appears; in CI it never did, so every composer
	// test failed on "cn-ai-fab not found" — a missing provider reported as a
	// missing feature.
	//
	// Stubbed rather than skipped: a suite that skips itself in CI is a suite
	// that only ever runs where somebody happens to be looking. Nothing under
	// test here is the health probe.
	await page.route('**/apps/hermiq/api/chat/health', async (route) => {
		await route.fulfill({
			status: 200,
			contentType: 'application/json',
			body: JSON.stringify({ status: 'ok', capabilities: ['chat', 'stream'] }),
		})
	})
	await page.route('**/apps/hermiq/api/agents', async (route) => {
		await route.fulfill({
			status: 200,
			contentType: 'application/json',
			body: JSON.stringify([agent]),
		})
	})
	await page.route('**/apps/hermiq/api/speech/capabilities', async (route) => {
		await route.fulfill({
			status: 200,
			contentType: 'application/json',
			body: JSON.stringify({
				available: localAvailable,
				reason: localAvailable ? '' : 'speech_service_unreachable',
			}),
		})
	})
}

/**
 * Replace the microphone hardware headless Chromium does not have.
 *
 * Installed with `addInitScript` so it is in place before the app boots —
 * the composer reads `MediaRecorder` and `navigator.mediaDevices` when it
 * decides whether recording is possible at all.
 *
 * @param page The page.
 * @return Nothing.
 */
async function stubMicrophone(page: Page): Promise<void> {
	await page.addInitScript(() => {
		const w = window as unknown as Record<string, unknown>
		w.__tracksStopped = 0
		const track = {
			stop() {
				;(window as unknown as Record<string, number>).__tracksStopped += 1
			},
		}
		Object.defineProperty(navigator, 'mediaDevices', {
			configurable: true,
			value: { getUserMedia: async () => ({ getTracks: () => [track] }) },
		})
		w.MediaRecorder = class {
			state = 'recording'
			ondataavailable: ((e: unknown) => void) | null = null
			onstop: (() => void) | null = null

			start() {
				this.state = 'recording'
			}

			stop() {
				this.state = 'inactive'
				this.ondataavailable?.({
					data: new Blob(['x'], { type: 'audio/webm' }),
				})
				this.onstop?.()
			}
		}
		w.AudioContext = class {
			createAnalyser() {
				return {
					fftSize: 2048,
					getFloatTimeDomainData(a: Float32Array) {
						a.fill(0.5)
					},
				}
			}

			createMediaStreamSource() {
				return { connect() {} }
			}

			close() {}
		}
	})
}

/**
 * Open the AI companion and wait for its composer.
 *
 * @param page The page.
 * @return Nothing.
 */
async function openCompanion(page: Page): Promise<void> {
	await page.goto('/apps/files/', { waitUntil: 'domcontentloaded' })

	// A fresh instance opens its onboarding wizard over everything, and a modal
	// that intercepts pointer events reports as "the button is not clickable"
	// rather than as "something is in front of it".
	await dismissTour(page)

	// ⚠️ THE LAUNCHER IS MOUNTED BY A LATE INIT SCRIPT, so it can be absent for
	// a while on a busy instance — waiting for it before clicking is the
	// difference between "the panel did not open" and "the click went nowhere
	// because there was nothing there yet".
	//
	// And when it never arrives at all, a RELOAD is the repair: the init script
	// either evaluated or it did not, and waiting longer on a page that already
	// finished loading only spends the timeout. Measured across nine runs on
	// this box, this was the residual flake after the click retry — always
	// "cn-ai-fab not found", never a wrong assertion.
	const fab = page.locator(FAB).first()
	try {
		await expect(fab).toBeVisible({ timeout: 20_000 })
	} catch (e) {
		await page.reload({ waitUntil: 'domcontentloaded' })
		await dismissTour(page)
		await expect(fab).toBeVisible({ timeout: 30_000 })
	}

	const composer = page.locator('[data-testid="cn-ai-input-textarea"]')

	// One retry, because the first click can land while the companion's own
	// bundle is still evaluating and is then swallowed. Measured on this box:
	// three consecutive full-suite runs each failed a DIFFERENT test here, all
	// of them on the composer never appearing — a slow instance, not a defect,
	// and a flake that moves around is still a flake.
	for (let attempt = 0; attempt < 2; attempt++) {
		await fab.click()
		try {
			await expect(composer).toBeVisible({ timeout: 20_000 })

			return
		} catch (e) {
			if (attempt === 1) {
				throw e
			}
		}
	}
}

test.describe('speech-services: the microphone says what it is doing', () => {
	// @e2e speech-services::recording
	test('shows a HOLLOW mic idle and a FILLED mic while recording — never the muted glyph', async ({
		page,
	}) => {
		await stubMicrophone(page)
		await pinComposerContext(
			page,
			agentFixture({ voiceInputEngine: 'local' }),
			true,
		)
		// Answer the transcription the stop triggers, so the button settles back
		// to idle deterministically. Left unrouted, the stubbed one-byte clip
		// reaches the real endpoint and the icon sits in its "transcribing"
		// spinner for however long that takes to fail — a flake, not a finding.
		await page.route(
			'**/apps/hermiq/api/speech/transcriptions',
			async (route) => {
				await route.fulfill({
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify({
						text: '',
						language: 'nl',
						engine: 'base',
					}),
				})
			},
		)
		await openCompanion(page)

		const mic = page.locator(MIC)
		await expect(mic).toBeVisible()

		// Idle: the outline glyph, and specifically NOT the struck-through one.
		await expect(mic.locator('.microphone-outline-icon')).toHaveCount(1)
		await expect(mic.locator('.microphone-off-icon')).toHaveCount(0)
		await expect(mic).toHaveAttribute('aria-pressed', 'false')

		await mic.click()

		// 🔴 THE REGRESSION THIS SUITE EXISTS FOR. A struck-through microphone
		// here reads as "muted" to every user on the planet, and that is what
		// shipped.
		await expect(mic.locator('.microphone-icon')).toHaveCount(1)
		await expect(mic.locator('.microphone-off-icon')).toHaveCount(0)
		await expect(mic).toHaveClass(/--recording/)
		await expect(mic).toHaveAttribute('aria-pressed', 'true')

		// Stopping releases the microphone rather than holding the tracks open
		// through the transcription request — otherwise the browser's recording
		// indicator stays lit with nothing being recorded.
		await mic.click()
		await expect(mic.locator('.microphone-outline-icon')).toHaveCount(1)
		await expect
			.poll(() =>
				page.evaluate(
					() =>
						(window as unknown as Record<string, number>)
							.__tracksStopped,
				),
			)
			.toBeGreaterThan(0)
	})

	// @e2e speech-services::the-speaker-pauses-to-think
	test('dictation NEVER sends by itself — the transcript waits in the composer', async ({
		page,
	}) => {
		await stubMicrophone(page)
		await pinComposerContext(
			page,
			agentFixture({ voiceInputEngine: 'local', voiceSilenceTimeout: 1000 }),
			true,
		)

		// A transcript arrives, so the only reason nothing is sent is the design.
		await page.route(
			'**/apps/hermiq/api/speech/transcriptions',
			async (route) => {
				await route.fulfill({
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify({
						text: 'een gedicteerde zin',
						language: 'nl',
						engine: 'base',
					}),
				})
			},
		)

		const sends: string[] = []
		page.on('request', (r) => {
			if (r.url().includes('/api/chat/') && r.method() === 'POST') {
				sends.push(r.url())
			}
		})

		await openCompanion(page)
		await page.locator(MIC).click()
		await page.locator(MIC).click() // stop → transcribe → text lands

		const textarea = page.locator('[data-testid="cn-ai-input-textarea"]')
		await expect(textarea).toHaveValue(/een gedicteerde zin/, {
			timeout: 20_000,
		})

		// The words are in the box, unsent. Dictation that posts by itself turns
		// a pause for thought into a sent message, and there is no unsending.
		await page.waitForTimeout(3_000)
		expect(sends, 'dictation must not post to the chat').toEqual([])
	})
})

test.describe('speech-services: the engine is the agent’s choice', () => {
	// @e2e speech-services::a-confidential-agent-dictates
	// @e2e speech-services::two-agents-two-policies
	test('a local-pinned agent dictates through the instance, and says so', async ({
		page,
	}) => {
		await pinComposerContext(
			page,
			agentFixture({ voiceInputEngine: 'local' }),
			true,
		)
		await openCompanion(page)

		const mic = page.locator(MIC)
		await expect(mic).toHaveAttribute('data-engine', 'local')
		// The label names WHERE THE AUDIO GOES — the user cannot discover that
		// any other way, and it is the whole point of the setting.
		await expect(mic).toHaveAttribute('title', /private/i)
	})

	// @e2e speech-services::the-speech-service-is-down-and-the-agent-is-local-only
	test('🔴 a local-pinned agent does NOT fall back to the browser when the service is down', async ({
		page,
	}) => {
		// The browser engine is available in this Chromium. It must still not be
		// used: falling back would send confidential audio to Google, which is
		// the exact thing the agent was pinned to `local` to prevent.
		await pinComposerContext(
			page,
			agentFixture({ voiceInputEngine: 'local' }),
			false,
		)

		// ⚠️ A PLAIN WINDOW COUNTER, NOT `page.exposeFunction`. The first version
		// of this test exposed a binding and had the spy's constructor call it;
		// the app then failed to boot at all and the failure surfaced as "the
		// companion launcher never appeared" — a page-level break reported as a
		// missing button. Counting in the page and reading it afterwards has no
		// such reach into the boot sequence.
		await page.addInitScript(() => {
			const w = window as unknown as Record<string, unknown>
			w.__recognisersConstructed = 0
			const spy = class {
				constructor() {
					;(
						window as unknown as Record<string, number>
					).__recognisersConstructed += 1
				}

				start() {}
				stop() {}
			}
			w.SpeechRecognition = spy
			w.webkitSpeechRecognition = spy
		})

		await openCompanion(page)

		const mic = page.locator(MIC)
		// The control stays, disabled, carrying its reason — a control that
		// vanishes reads as a missing feature rather than a stated refusal.
		await expect(mic).toBeVisible()
		await expect(mic).toHaveAttribute('aria-disabled', 'true')
		await expect(mic).toHaveAttribute('title', /private speech service/i)

		// ⚠️ `force`, because Playwright counts `aria-disabled="true"` as
		// not-enabled and refuses to click. The control is deliberately NOT
		// natively `disabled`: a disabled button suppresses hover, so its tooltip
		// can never appear and it would refuse without ever saying why. A real
		// user's click lands; only the test tool's actionability check does not.
		await mic.click({ force: true })
		await expect(
			page.locator('[data-testid="cn-ai-input-dictation-error"]'),
		).toContainText(/private/i)

		// The assertion that matters: nothing reached for the cloud engine.
		const constructed = await page.evaluate(
			() =>
				(window as unknown as Record<string, number>)
					.__recognisersConstructed,
		)
		expect(constructed, 'no browser recogniser may be constructed').toBe(0)
	})

	// @e2e speech-services::speech-switched-off-entirely
	test('an agent with speech switched off is offered no microphone at all', async ({
		page,
	}) => {
		// `off` is the one value that HIDES the control rather than disabling it
		// with a reason: the agent has decided there is no dictation, so there is
		// nothing to explain to the user.
		await pinComposerContext(
			page,
			agentFixture({ voiceInputEngine: 'off' }),
			true,
		)
		await openCompanion(page)

		await expect(page.locator(MIC)).toHaveCount(0)
		await expect(page.locator(CONVERSE)).toHaveCount(0)
	})

	test('the conversation control appears only where the agent allows it', async ({
		page,
	}) => {
		await pinComposerContext(
			page,
			agentFixture({ voiceConversationEnabled: false }),
			true,
		)
		await openCompanion(page)
		await expect(page.locator(CONVERSE)).toHaveCount(0)

		await page.unrouteAll()
		await pinComposerContext(
			page,
			agentFixture({
				voiceConversationEnabled: true,
				voiceInputEngine: 'local',
			}),
			true,
		)
		await openCompanion(page)

		const converse = page.locator(CONVERSE)
		await expect(converse).toBeVisible()
		// The label warns about the one thing that separates it from dictation.
		await expect(converse).toHaveAttribute(
			'title',
			/sent when you stop speaking/i,
		)
	})
})

test.describe('speech-services: the policy is editable and it persists', () => {
	test('the agent form stores the ENGINE VALUE, not the picker object', async ({
		page,
		request,
	}) => {
		// Entirely real: no route interception in this test at all.
		const token = await harvestToken(page)
		await resolveRegisterSchema(request, token, 'agent')
		const seeded = await seedAgent(request, token, {
			name: `${TEST_PREFIX}-policy-agent`,
		})

		const root = await appRoot(page)
		await page.goto(`${root}/agents/${seeded.id}`, {
			waitUntil: 'domcontentloaded',
		})

		// 🔴 A FRESH INSTANCE OPENS ITS ONBOARDING WIZARD OVER EVERYTHING. In CI
		// this test failed with "locator resolved to <button …edit-agent>" and
		// then a click timeout — the button was found, visible and enabled, and
		// a `cn-wizard-dialog` modal in front of it swallowed every click. The
		// failure names the button, never the thing covering it.
		await dismissTour(page)

		// ⚠️ Wait for the detail page to exist BEFORE reaching for its actions.
		// Clicking straight after `goto` failed once on an instance still
		// settling after an upgrade, and the failure was a bare 30s click
		// timeout — which reads as "the button is missing" rather than "the page
		// had not hydrated yet". Asserting the page first makes the two
		// distinguishable.
		await expect(
			page.getByRole('button', { name: /edit agent/i }).first(),
		).toBeVisible({
			timeout: 30_000,
		})
		await page
			.getByRole('button', { name: /edit agent/i })
			.first()
			.click()

		const form = page.locator('.agent-form')
		await expect(form).toBeVisible({ timeout: 20_000 })

		// The four fields exist, and the engine options are labelled by
		// destination rather than by API name.
		await expect(form).toContainText('Dictation (speech to text)')
		await expect(form).toContainText('Spoken replies (text to speech)')
		await expect(form).toContainText('Silence before the microphone closes (ms)')
		await expect(form).toContainText('Allow spoken conversation')

		await form.getByRole('combobox', { name: /dictation/i }).click()
		await page
			.locator('li[role="option"]', { hasText: 'On this instance' })
			.first()
			.click()
		await form.getByRole('button', { name: /^save$/i }).click()
		await expect(form).toBeHidden({ timeout: 20_000 })

		// 🔴 Read back from the API, not from the form. The pickers hold
		// `{value,label}` objects; storing the object would persist
		// "[object Object]", which normalises to the permissive default on read
		// — an agent pinned to the private engine would silently become one that
		// may use a cloud engine.
		try {
			await expect
				.poll(
					async () => {
						const res = await request.get(
							`/index.php/apps/hermiq/api/agents/${seeded.id}`,
							{ headers: { 'OCS-APIRequest': 'true' } },
						)
						const body = await res.json()
						return body.voiceInputEngine
					},
					{ timeout: 20_000 },
				)
				.toBe('local')
		} finally {
			// In a `finally` so a failed assertion still takes its fixture with
			// it — a suite that leaves agents behind on every red run poisons the
			// instance it is measuring.
			await deleteObject(request, token, 'agent', seeded.id).catch(
				() => undefined,
			)
		}
	})
})
