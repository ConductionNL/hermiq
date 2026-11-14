/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The AI literacy page as rendered: progress, the requirement notice, opening
 * a lesson into paragraphs and its check, answering it through the rendered
 * controls, the administrator's overview table and requirement switch, and the
 * load error. The literacy API is doubled.
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../../src/api/literacy.js', () => ({
	answerLesson: vi.fn(),
	getLessons: vi.fn(),
	getOverview: vi.fn(),
	overviewCsvUrl: vi.fn(() => '/apps/hermiq/api/literacy/overview.csv'),
	setRequirement: vi.fn(),
}))

const literacy = await import('../../src/api/literacy.js')
const { default: AiLiteracy } = await import('../../src/views/AiLiteracy.vue')

/**
 * Translate as the identity, filling {placeholders}.
 *
 * @param {string} app The app id.
 * @param {string} text The text.
 * @param {object} vars The placeholders.
 * @return {string} The filled text.
 */
const t = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))

const LESSONS = [
	{
		slug: 'bias',
		title: 'Bias',
		body: 'Models repeat their data.\n\nCheck the output.',
		checkQuestion: 'Who checks the output?',
		checkOptions: ['Nobody', 'You'],
		done: false,
	},
	{ slug: 'privacy', title: 'Privacy', body: '', checkQuestion: 'Q', checkOptions: ['A'], done: true },
]

/**
 * Mount the page on the given lessons page.
 *
 * @param {object} page What getLessons answers.
 * @return {Promise<object>} The wrapper.
 */
async function mountPage(page) {
	literacy.getLessons.mockResolvedValue(page)
	const w = mount(AiLiteracy, { global: { mocks: { t } } })
	await flushPromises()
	return w
}

/**
 * A learner's page: two lessons, one done.
 *
 * @param {object} overrides Fields to change.
 * @return {object} The page.
 */
function learnerPage (overrides = {}) {
  return {
	lessons: LESSONS.map((lesson) => ({ ...lesson })),
	done: 1,
	total: 2,
	required: false,
	mayAdminister: false,
	...overrides,
}
}

const lessonItem = (w, slug) => w.find(`[data-testid="ai-literacy-lesson-${slug}"]`)

beforeEach(() => {
	vi.clearAllMocks()
})

describe('AiLiteracy page', () => {
	it('shows the progress and each lesson\'s state, with no admin section for a learner', async () => {
		const w = await mountPage(learnerPage())

		expect(w.find('[data-testid="ai-literacy-progress"]').text()).toBe('1 of 2 done')
		expect(lessonItem(w, 'bias').find('.ai-literacy__lesson-state').text()).toBe('Not done')
		expect(lessonItem(w, 'privacy').find('.ai-literacy__lesson-state').text()).toBe('Done')
		expect(lessonItem(w, 'privacy').find('.ai-literacy__lesson-state').classes()).toContain('ai-literacy__lesson-state--done')
		expect(w.find('.ai-literacy__admin').exists()).toBe(false)
		expect(w.findAllComponents({ name: 'NcNoteCard' })).toHaveLength(0)
	})

	it('asks to finish a required course only while lessons remain', async () => {
		const unfinished = await mountPage(learnerPage({ required: true }))
		const notes = unfinished.findAllComponents({ name: 'NcNoteCard' })
		expect(notes).toHaveLength(1)
		expect(notes[0].text()).toBe('Your organisation asks you to finish this course before you use an agent.')

		const finished = await mountPage(learnerPage({ required: true, done: 2 }))
		expect(finished.findAllComponents({ name: 'NcNoteCard' })).toHaveLength(0)
	})

	it('opens a lesson into its paragraphs and check, and closes it again', async () => {
		const w = await mountPage(learnerPage())
		const head = lessonItem(w, 'bias').find('button.ai-literacy__lesson-head')
		expect(head.attributes('aria-expanded')).toBe('false')
		expect(lessonItem(w, 'bias').find('.ai-literacy__lesson-body').exists()).toBe(false)

		await head.trigger('click')

		const body = lessonItem(w, 'bias').find('.ai-literacy__lesson-body')
		expect(head.attributes('aria-expanded')).toBe('true')
		expect(body.findAll('p').map((p) => p.text())).toEqual(['Models repeat their data.', 'Check the output.'])
		expect(body.find('legend').text()).toBe('Who checks the output?')
		const options = body.findAllComponents({ name: 'NcCheckboxRadioSwitch' })
		expect(options.map((o) => o.text())).toEqual(['Nobody', 'You'])
		expect(options.map((o) => o.attributes('data-value'))).toEqual(['0', '1'])
		expect(options[0].attributes('data-name')).toBe('check-bias')
		// Nothing chosen yet, so the check cannot run.
		expect(body.findComponent({ name: 'NcButton' }).attributes('data-disabled')).toBe('true')

		await head.trigger('click')
		expect(lessonItem(w, 'bias').find('.ai-literacy__lesson-body').exists()).toBe(false)
	})

	it('checks the picked answer and shows the right-answer feedback', async () => {
		literacy.answerLesson.mockResolvedValue({ correct: true })
		const w = await mountPage(learnerPage())
		await lessonItem(w, 'bias').find('button.ai-literacy__lesson-head').trigger('click')
		const body = () => lessonItem(w, 'bias').find('.ai-literacy__lesson-body')

		await body().findAllComponents({ name: 'NcCheckboxRadioSwitch' })[1].vm.pick('1')
		const button = body().findComponent({ name: 'NcButton' })
		expect(button.attributes('data-disabled')).toBe('false')
		await button.vm.$emit('click')
		await flushPromises()

		expect(literacy.answerLesson).toHaveBeenCalledWith('bias', 1)
		const feedback = body().findComponent({ name: 'NcNoteCard' })
		expect(feedback.attributes('data-type')).toBe('success')
		expect(feedback.text()).toBe('Right. This lesson is done.')
		expect(w.find('[data-testid="ai-literacy-progress"]').text()).toBe('2 of 2 done')
		expect(lessonItem(w, 'bias').find('.ai-literacy__lesson-state').text()).toBe('Done')
	})

	it('shows a wrong answer as a warning with the explanation', async () => {
		literacy.answerLesson.mockResolvedValue({ correct: false, explanation: 'You stay responsible.' })
		const w = await mountPage(learnerPage())
		await lessonItem(w, 'bias').find('button.ai-literacy__lesson-head').trigger('click')
		const body = () => lessonItem(w, 'bias').find('.ai-literacy__lesson-body')

		await body().findAllComponents({ name: 'NcCheckboxRadioSwitch' })[0].vm.pick('0')
		await body().findComponent({ name: 'NcButton' }).vm.$emit('click')
		await flushPromises()

		const feedback = body().findComponent({ name: 'NcNoteCard' })
		expect(feedback.attributes('data-type')).toBe('warning')
		expect(feedback.text()).toBe('Not quite. You stay responsible. Try again.')
		expect(w.find('[data-testid="ai-literacy-progress"]').text()).toBe('1 of 2 done')
	})

	it('shows an administrator the people, the CSV link and the requirement switch', async () => {
		literacy.getOverview.mockResolvedValue({ people: [{ userId: 'anna', done: 2, total: 6 }, { userId: 'bob', done: 0, total: 6 }] })
		literacy.setRequirement.mockResolvedValue({ required: true })
		const w = await mountPage(learnerPage({ mayAdminister: true }))

		const admin = w.find('.ai-literacy__admin')
		const rows = admin.findAll('tbody tr').map((tr) => tr.findAll('td').map((td) => td.text()))
		expect(rows).toEqual([['anna', '2 of 6 done'], ['bob', '0 of 6 done']])
		expect(admin.find('a').attributes('href')).toBe('/apps/hermiq/api/literacy/overview.csv')

		const toggle = admin.findComponent({ name: 'NcCheckboxRadioSwitch' })
		expect(toggle.attributes('data-modelvalue')).toBe('false')
		await toggle.vm.pick(true)
		await flushPromises()

		expect(literacy.setRequirement).toHaveBeenCalledWith(true)
		expect(admin.findComponent({ name: 'NcCheckboxRadioSwitch' }).attributes('data-modelvalue')).toBe('true')
	})

	it('tells an administrator when nobody has done a lesson yet', async () => {
		literacy.getOverview.mockResolvedValue({ people: [] })
		const w = await mountPage(learnerPage({ mayAdminister: true }))

		const admin = w.find('.ai-literacy__admin')
		expect(admin.find('table').exists()).toBe(false)
		expect(admin.find('p.ai-literacy__intro').text()).toBe('Nobody in your organisation has done a lesson yet.')
	})

	it('shows a load failure in place of the course', async () => {
		literacy.getLessons.mockRejectedValue(new Error('down'))
		const w = mount(AiLiteracy, { global: { mocks: { t } } })
		await flushPromises()

		const card = w.findComponent({ name: 'NcNoteCard' })
		expect(card.attributes('data-type')).toBe('error')
		expect(card.text()).toBe('The course could not be loaded.')
		expect(w.find('[data-testid="ai-literacy-progress"]').exists()).toBe(false)
	})
})
