/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Characterisation of the AI literacy page's answer check: the request, the
 * right and wrong feedback, marking a lesson done once, and a failed check.
 */

import { describe, expect, it, vi } from 'vitest'

vi.mock('../../src/api/literacy.js', () => ({
	answerLesson: vi.fn(),
	getLessons: vi.fn(),
	getOverview: vi.fn(),
	overviewCsvUrl: vi.fn(() => '/csv'),
	setRequirement: vi.fn(),
}))

const literacy = await import('../../src/api/literacy.js')
const { default: AiLiteracy } = await import('../../src/views/AiLiteracy.vue')

/**
 * A page context: its data, its methods bound, and a t() that fills placeholders.
 *
 * @return {object} The context.
 */
function page() {
	const t = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (m, key) => (key in vars ? vars[key] : m))
	return { ...AiLiteracy.data(), ...AiLiteracy.methods, t }
}

describe('AiLiteracy check', () => {
	it('sends the lesson and the chosen answer as a number, and is busy meanwhile', async () => {
		const ctx = page()
		ctx.choice = '2'
		let resolve
		literacy.answerLesson.mockReturnValueOnce(new Promise((r) => { resolve = r }))
		const done = ctx.check({ slug: 'bias', done: false })
		expect(ctx.checking).toBe(true)
		expect(literacy.answerLesson).toHaveBeenLastCalledWith('bias', 2)
		resolve({ correct: false })
		await done
		expect(ctx.checking).toBe(false)
	})

	it('marks a right answer done once and counts it once', async () => {
		const ctx = page()
		ctx.done = 3
		const lesson = { slug: 'bias', done: false }
		literacy.answerLesson.mockResolvedValue({ correct: true })
		await ctx.check(lesson)
		expect(ctx.feedback).toEqual({ correct: true, text: 'Right. This lesson is done.' })
		expect([lesson.done, ctx.done]).toEqual([true, 4])
		await ctx.check(lesson)
		expect(ctx.done).toBe(4)
	})

	it('explains a wrong answer, with or without an explanation', async () => {
		const ctx = page()
		literacy.answerLesson.mockResolvedValueOnce({ correct: false, explanation: 'Models guess.' })
		await ctx.check({ slug: 'bias', done: false })
		expect(ctx.feedback).toEqual({ correct: false, text: 'Not quite. Models guess. Try again.' })
		literacy.answerLesson.mockResolvedValueOnce({ correct: false })
		await ctx.check({ slug: 'bias', done: false })
		expect(ctx.feedback).toEqual({ correct: false, text: 'Not quite.  Try again.' })
		expect(ctx.done).toBe(0)
	})

	it('says so when the answer could not be checked', async () => {
		const ctx = page()
		literacy.answerLesson.mockRejectedValueOnce(new Error('down'))
		await ctx.check({ slug: 'bias', done: false })
		expect(ctx.feedback).toEqual({ correct: false, text: 'Your answer could not be checked.' })
		expect(ctx.checking).toBe(false)
	})
})
