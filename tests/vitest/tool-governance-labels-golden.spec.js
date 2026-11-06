/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Golden cases for the tool invocation result label and the tool governance
 * tab keys, recorded on the code before both became lookup tables.
 */

import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/auth', () => ({ getCurrentUser: () => null }))

vi.mock('../../src/store/store.js', () => ({
	useAgentStore: () => ({}),
}))

const { default: ToolInvocationTable } =
	await import('../../src/components/ToolInvocationTable.vue')
const { default: AgentToolGovernanceWidget } =
	await import('../../src/widgets/AgentToolGovernanceWidget.vue')

/**
 * A translation stand-in that shows the key and its parameters.
 *
 * @param {string} app The app id.
 * @param {string} text The source text.
 * @param {object} vars The parameters.
 * @return {string} The text with its parameters.
 */
function fakeT(app, text, vars) {
	return vars ? `${text} ${JSON.stringify(vars)}` : text
}

describe('ToolInvocationTable resultLabel()', () => {
	const label = (summary) =>
		ToolInvocationTable.methods.resultLabel.call(
			{ t: fakeT },
			{ resultSummary: summary },
		)

	it('labels every summary shape as before', () => {
		expect(
			[
				undefined,
				null,
				{},
				{ isError: true, status: 'failed', count: 2, id: 'a' },
				{ isError: false, status: 'done', count: 2 },
				{ status: 0, count: 0 },
				{ status: 'queued' },
				{ count: 0, id: 'a' },
				{ count: '3', id: 'b' },
				{ id: 'c' },
				{ id: '' },
				{ isError: 0, status: '' },
			].map(label),
		).toEqual([
			'—',
			'—',
			'OK',
			'Error',
			'done',
			'{count} result(s) {"count":0}',
			'queued',
			'{count} result(s) {"count":0}',
			'Object {id} {"id":"b"}',
			'Object {id} {"id":"c"}',
			'OK',
			'OK',
		])
	})
})

describe('AgentToolGovernanceWidget onTabKeydown()', () => {
	/**
	 * Press one key on one tab and report what moved.
	 *
	 * @param {string} key The key.
	 * @param {number} index The tab the key was pressed on.
	 * @return {object} The new active tab, whether the default was prevented and which button was focused.
	 */
	function press(key, index) {
		const focused = []
		const buttons = [
			{ focus: () => focused.push(0) },
			{ focus: () => focused.push(1) },
		]
		const self = {
			tabs: [{ id: 'grants' }, { id: 'activity' }],
			activeTab: 'start',
			$refs: { tablist: { querySelectorAll: () => buttons } },
			$nextTick: (callback) => callback(),
		}
		let prevented = false
		AgentToolGovernanceWidget.methods.onTabKeydown.call(
			self,
			{ key, preventDefault: () => (prevented = true) },
			index,
		)
		return { active: self.activeTab, prevented, focused }
	}

	it('moves, wraps and jumps as before, and ignores other keys', () => {
		const cases = []
		for (const key of ['ArrowRight', 'ArrowLeft', 'Home', 'End', 'Enter', 'a']) {
			for (const index of [0, 1]) {
				cases.push([key, index, press(key, index)])
			}
		}

		expect(cases).toEqual([
			['ArrowRight', 0, { active: 'activity', prevented: true, focused: [1] }],
			['ArrowRight', 1, { active: 'grants', prevented: true, focused: [0] }],
			['ArrowLeft', 0, { active: 'activity', prevented: true, focused: [1] }],
			['ArrowLeft', 1, { active: 'grants', prevented: true, focused: [0] }],
			['Home', 0, { active: 'grants', prevented: true, focused: [0] }],
			['Home', 1, { active: 'grants', prevented: true, focused: [0] }],
			['End', 0, { active: 'activity', prevented: true, focused: [1] }],
			['End', 1, { active: 'activity', prevented: true, focused: [1] }],
			['Enter', 0, { active: 'start', prevented: false, focused: [] }],
			['Enter', 1, { active: 'start', prevented: false, focused: [] }],
			['a', 0, { active: 'start', prevented: false, focused: [] }],
			['a', 1, { active: 'start', prevented: false, focused: [] }],
		])
	})
})
