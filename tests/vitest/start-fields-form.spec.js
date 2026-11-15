/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * StartFieldsForm mounted with the @nextcloud/vue stand-ins: it renders one
 * input per declared field, labels the required ones, and reports an edit as
 * a new value object without changing the one it was given.
 */

import { t } from '@nextcloud/l10n'
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import StartFieldsForm from '../../src/components/StartFieldsForm.vue'

const fields = [
	{ key: 'topic', label: 'Topic', type: 'text', required: true },
	{
		key: 'tone',
		label: 'Tone',
		type: 'select',
		options: ['formal', 'plain'],
		required: false,
	},
	{ key: 'notes', label: 'Notes', type: 'paragraph', required: false },
]

/**
 * Mount the form with the given values and problems.
 *
 * @param {object} modelValue The values.
 * @param {object} problems The problems by field key.
 * @return {object} The wrapper.
 */
function form(modelValue, problems = {}) {
	return mount(StartFieldsForm, {
		props: { fields, modelValue, problems },
		global: { mocks: { t } },
	})
}

describe('StartFieldsForm', () => {
	it('renders one input per field, the required one labelled as such', () => {
		const wrapper = form({ topic: 'Budget' })

		expect(wrapper.find('nc-textfield').attributes('data-label')).toBe(
			'Topic (required)',
		)
		expect(wrapper.find('nc-select').attributes('data-inputlabel')).toBe('Tone')
		expect(wrapper.find('nc-textarea').attributes('data-label')).toBe('Notes')
		expect(wrapper.findAll('[data-testid^="chat-start-field-"]')).toHaveLength(3)
	})

	it('reports an edit as a new value object and leaves the given one alone', async () => {
		const values = { topic: 'Budget' }
		const wrapper = form(values)

		wrapper.findAllComponents({ name: 'NcSelect' })[0].vm.pick('plain')

		expect(wrapper.emitted('update:modelValue')).toEqual([
			[{ topic: 'Budget', tone: 'plain' }],
		])
		expect(values).toEqual({ topic: 'Budget' })
	})
})
