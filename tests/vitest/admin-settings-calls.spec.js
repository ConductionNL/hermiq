/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Characterisation of the four admin settings blocks (prompt library, intake
 * threshold, report grouping, run retention): what each call reads and writes,
 * the loading and busy flags around it, and the message a failure shows.
 */

import { describe, expect, it, vi } from 'vitest'

vi.mock('../../src/api/assistantPrompts.js', () => ({
	disableAllAssistantPrompts: vi.fn(),
	listAssistantPrompts: vi.fn(),
	saveAssistantPrompt: vi.fn(),
}))
vi.mock('../../src/api/intake.js', () => ({ getIntakeSettings: vi.fn(), setIntakeSettings: vi.fn() }))
vi.mock('../../src/api/reportSimilarity.js', () => ({
	getReportSimilaritySettings: vi.fn(),
	setReportSimilaritySettings: vi.fn(),
}))
vi.mock('../../src/api/runRetention.js', () => ({ getRunRetention: vi.fn(), setRunRetention: vi.fn() }))

const prompts = await import('../../src/api/assistantPrompts.js')
const intake = await import('../../src/api/intake.js')
const similarity = await import('../../src/api/reportSimilarity.js')
const retention = await import('../../src/api/runRetention.js')
const { default: AssistantPromptLibrary } = await import('../../src/components/settings/AssistantPromptLibrary.vue')
const { default: IntakeSettings } = await import('../../src/components/settings/IntakeSettings.vue')
const { default: ReportGroupingSettings } = await import('../../src/components/settings/ReportGroupingSettings.vue')
const { default: RunRetentionSettings } = await import('../../src/components/settings/RunRetentionSettings.vue')

/**
 * A component context: its data, its methods bound, and a t() that echoes.
 *
 * @param {object} component The component.
 * @return {object} The context.
 */
function block(component) {
	const mixins = component.mixins || []
	const data = Object.assign({}, ...mixins.map((mixin) => (mixin.data ? mixin.data() : {})), component.data())
	const methods = Object.assign({}, ...mixins.map((mixin) => mixin.methods || {}), component.methods)
	return { ...data, ...methods, t: (app, text) => text }
}

/**
 * Run every created hook (mixins first) against a context.
 *
 * @param {object} component The component.
 * @param {object} ctx The context.
 * @return {void}
 */
function create(component, ctx) {
	const hooks = [...(component.mixins || []).map((mixin) => mixin.created), component.created]
	hooks.filter(Boolean).forEach((hook) => hook.call(ctx))
}

/**
 * A promise with its resolve and reject exposed.
 *
 * @return {object} The promise and its settlers.
 */
function deferred() {
	let resolve
	let reject
	const promise = new Promise((res, rej) => {
		resolve = res
		reject = rej
	})
	return { promise, resolve, reject }
}

describe.each([
	['AssistantPromptLibrary', AssistantPromptLibrary],
	['IntakeSettings', IntakeSettings],
	['ReportGroupingSettings', ReportGroupingSettings],
	['RunRetentionSettings', RunRetentionSettings],
])('%s', (name, component) => {
	it('starts loading, idle and without an error, and loads once when created', () => {
		const ctx = block(component)
		expect([ctx.loading, ctx.busy, ctx.error]).toEqual([true, false, ''])
		ctx.load = vi.fn()
		create(component, ctx)
		expect(ctx.load).toHaveBeenCalledTimes(1)
	})

	it('words a failure from the server, else the error, else a fallback', () => {
		const ctx = block(component)
		expect(ctx.messageFor({ response: { data: { error: 'From server' } }, message: 'm' })).toBe('From server')
		expect(ctx.messageFor({ message: 'Network down' })).toBe('Network down')
		expect(ctx.messageFor({})).toBe('Unknown error')
		expect(ctx.messageFor(undefined)).toBe('Unknown error')
	})
})

describe('AssistantPromptLibrary calls', () => {
	it('loads the library, clearing an old error while it runs', async () => {
		const ctx = block(AssistantPromptLibrary)
		ctx.error = 'old'
		const call = deferred()
		prompts.listAssistantPrompts.mockReturnValueOnce(call.promise)
		const done = ctx.load()
		expect([ctx.loading, ctx.error]).toEqual([true, ''])
		call.resolve([{ id: 1 }])
		await done
		expect(ctx.prompts).toEqual([{ id: 1 }])
		expect(ctx.loading).toBe(false)
	})

	it('shows a failed load and stops loading', async () => {
		const ctx = block(AssistantPromptLibrary)
		prompts.listAssistantPrompts.mockRejectedValueOnce({ response: { data: { error: 'nope' } } })
		await ctx.load()
		expect([ctx.loading, ctx.error, ctx.prompts]).toEqual([false, 'nope', []])
	})

	it('toggles a prompt to the opposite state, then reloads', async () => {
		const ctx = block(AssistantPromptLibrary)
		ctx.load = vi.fn(async () => {
			expect(ctx.busy).toBe(true)
		})
		prompts.saveAssistantPrompt.mockResolvedValue({})
		await ctx.toggle({ id: 7, enabled: false })
		expect(prompts.saveAssistantPrompt).toHaveBeenLastCalledWith(7, { enabled: true })
		await ctx.toggle({ id: 8 })
		expect(prompts.saveAssistantPrompt).toHaveBeenLastCalledWith(8, { enabled: false })
		expect(ctx.load).toHaveBeenCalledTimes(2)
		expect(ctx.busy).toBe(false)
	})

	it('shows a failed toggle without reloading', async () => {
		const ctx = block(AssistantPromptLibrary)
		ctx.load = vi.fn()
		prompts.saveAssistantPrompt.mockRejectedValueOnce(new Error('locked'))
		await ctx.toggle({ id: 7, enabled: true })
		expect([ctx.busy, ctx.error]).toEqual([false, 'locked'])
		expect(ctx.load).not.toHaveBeenCalled()
	})

	it('disables every prompt in one call, then reloads', async () => {
		const ctx = block(AssistantPromptLibrary)
		ctx.error = 'old'
		ctx.load = vi.fn()
		const call = deferred()
		prompts.disableAllAssistantPrompts.mockReturnValueOnce(call.promise)
		const done = ctx.disableAll()
		expect([ctx.busy, ctx.error]).toEqual([true, ''])
		call.resolve()
		await done
		expect(ctx.load).toHaveBeenCalledTimes(1)
		expect(ctx.busy).toBe(false)
	})

	it('shows a failed disable-all without reloading', async () => {
		const ctx = block(AssistantPromptLibrary)
		ctx.load = vi.fn()
		prompts.disableAllAssistantPrompts.mockRejectedValueOnce({})
		await ctx.disableAll()
		expect([ctx.busy, ctx.error]).toEqual([false, 'Unknown error'])
		expect(ctx.load).not.toHaveBeenCalled()
	})
})

describe('IntakeSettings calls', () => {
	it('loads and saves the threshold as text', async () => {
		const ctx = block(IntakeSettings)
		intake.getIntakeSettings.mockResolvedValueOnce({ abstentionThreshold: 0.7 })
		await ctx.load()
		expect([ctx.threshold, ctx.loading]).toEqual(['0.7', false])

		ctx.threshold = '0.65'
		const call = deferred()
		intake.setIntakeSettings.mockReturnValueOnce(call.promise)
		const done = ctx.save()
		expect(ctx.busy).toBe(true)
		call.resolve({ abstentionThreshold: 0.6 })
		await done
		expect(intake.setIntakeSettings).toHaveBeenCalledWith(0.65)
		expect([ctx.threshold, ctx.busy]).toEqual(['0.6', false])
	})

	it('keeps the typed threshold when a save fails', async () => {
		const ctx = block(IntakeSettings)
		ctx.threshold = '2'
		intake.setIntakeSettings.mockRejectedValueOnce({ response: { data: { error: 'out of range' } } })
		await ctx.save()
		expect([ctx.threshold, ctx.busy, ctx.error]).toEqual(['2', false, 'out of range'])

		intake.getIntakeSettings.mockRejectedValueOnce(new Error('down'))
		await ctx.load()
		expect([ctx.loading, ctx.error]).toEqual([false, 'down'])
	})
})

describe('ReportGroupingSettings calls', () => {
	it('loads both thresholds and the window, saves only the thresholds', async () => {
		const ctx = block(ReportGroupingSettings)
		similarity.getReportSimilaritySettings.mockResolvedValueOnce({ upper: 0.9, lower: 0.5, defaultWindowMinutes: 60 })
		await ctx.load()
		expect([ctx.upper, ctx.lower, ctx.defaultWindowMinutes, ctx.loading]).toEqual(['0.9', '0.5', 60, false])

		ctx.upper = '0.8'
		ctx.lower = '0.4'
		similarity.setReportSimilaritySettings.mockResolvedValueOnce({ upper: 0.81, lower: 0.41, defaultWindowMinutes: 5 })
		await ctx.save()
		expect(similarity.setReportSimilaritySettings).toHaveBeenCalledWith({ upper: 0.8, lower: 0.4 })
		expect([ctx.upper, ctx.lower, ctx.defaultWindowMinutes, ctx.busy]).toEqual(['0.81', '0.41', 60, false])
	})

	it('shows a refused save and a failed load', async () => {
		const ctx = block(ReportGroupingSettings)
		similarity.setReportSimilaritySettings.mockRejectedValueOnce({ response: { data: { error: 'lower must sit below upper' } } })
		await ctx.save()
		expect([ctx.busy, ctx.error, ctx.upper]).toEqual([false, 'lower must sit below upper', '0.85'])

		similarity.getReportSimilaritySettings.mockRejectedValueOnce({})
		await ctx.load()
		expect([ctx.loading, ctx.error]).toEqual([false, 'Unknown error'])
	})
})

describe('RunRetentionSettings calls', () => {
	it('loads the retention, its range and the last cleanup', async () => {
		const ctx = block(RunRetentionSettings)
		const cleanup = { ran: true, at: '2026-01-01', removed: 3 }
		retention.getRunRetention.mockResolvedValueOnce({ defaultDays: 30, minimumDays: 7, maximumDays: 365, lastCleanup: cleanup })
		await ctx.load()
		expect([ctx.days, ctx.storedDays, ctx.minimumDays, ctx.maximumDays, ctx.lastCleanup, ctx.loading])
			.toEqual(['30', '30', 7, 365, cleanup, false])
	})

	it('saves the typed retention and takes the stored value back', async () => {
		const ctx = block(RunRetentionSettings)
		ctx.days = '45'
		const cleanup = { ran: false, at: '', removed: null }
		retention.setRunRetention.mockResolvedValueOnce({ defaultDays: 44, lastCleanup: cleanup })
		await ctx.save()
		expect(retention.setRunRetention).toHaveBeenCalledWith(45)
		expect([ctx.days, ctx.storedDays, ctx.lastCleanup, ctx.busy]).toEqual(['44', '44', cleanup, false])
	})

	it('shows a refused save and a failed load', async () => {
		const ctx = block(RunRetentionSettings)
		ctx.days = '9999'
		retention.setRunRetention.mockRejectedValueOnce({ response: { data: { error: 'between 1 and 3650' } } })
		await ctx.save()
		expect([ctx.days, ctx.storedDays, ctx.busy, ctx.error]).toEqual(['9999', '90', false, 'between 1 and 3650'])

		retention.getRunRetention.mockRejectedValueOnce(new Error('down'))
		await ctx.load()
		expect([ctx.loading, ctx.error]).toEqual([false, 'down'])
	})
})
