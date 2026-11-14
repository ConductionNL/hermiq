/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The question an agent asks with hermiq.askPerson (oversight-ask-and-hold):
 * the card renders a button per suggested answer and a text box when allowed,
 * and the chat page keeps the question from a turn's result and sends the
 * chosen answer as the next message.
 *
 * @spec openspec/changes/oversight-ask-and-hold/specs/human-approval-gate/spec.md#requirement-an-agent-can-ask-a-person-a-question-with-suggested-answers-req-askhold-001
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/vue', async () => {
	const { defineComponent, h } = await import('vue')
	return {
		NcButton: defineComponent({
			name: 'NcButton',
			props: ['variant', 'disabled', 'type'],
			emits: ['click'],
			render() {
				return h('button', {
					class: 'NcButton',
					type: this.type || 'button',
					disabled: this.disabled || undefined,
					onClick: () => this.$emit('click'),
				}, this.$slots.default?.())
			},
		}),
		NcTextField: defineComponent({
			name: 'NcTextField',
			props: ['modelValue', 'label', 'disabled'],
			emits: ['update:modelValue'],
			render() {
				return h('input', {
					class: 'NcTextField',
					value: this.modelValue,
					'aria-label': this.label,
					onInput: (e) => this.$emit('update:modelValue', e.target.value),
				})
			},
		}),
	}
})

const { default: AgentQuestionCard, questionOptions } = await import('../../src/components/AgentQuestionCard.vue')

/**
 * Mount the card.
 *
 * @param {object} question The question.
 * @param {boolean} [disabled] Whether a turn is being sent.
 * @return {object} The wrapper.
 */
function card(question, disabled = false) {
	return mount(AgentQuestionCard, {
		props: { question, disabled },
		global: { mocks: { t: (app, text) => text } },
	})
}

describe('AgentQuestionCard', () => {
	const municipality = {
		text: 'Which municipality is this for?',
		options: ['Utrecht', 'Nieuwegein', 'Houten'],
		allowOther: true,
	}

	it('shows the question, one button per suggested answer and a text box', () => {
		const wrapper = card(municipality)

		expect(wrapper.find('.agent-question__text').text()).toBe('Which municipality is this for?')
		const buttons = wrapper.findAll('.agent-question__options button')
		expect(buttons.map((b) => b.text())).toEqual(['Utrecht', 'Nieuwegein', 'Houten'])
		expect(wrapper.find('[data-testid="chat-agent-question-other"]').exists()).toBe(true)
	})

	it('emits the chosen suggested answer', async () => {
		const wrapper = card(municipality)

		await wrapper.find('[data-testid="chat-agent-question-option-2"]').trigger('click')

		expect(wrapper.emitted('answer')).toEqual([['Houten']])
	})

	it('emits the typed answer trimmed, and never an empty one', async () => {
		const wrapper = card(municipality)
		const form = wrapper.find('[data-testid="chat-agent-question-other"]')

		await form.trigger('submit')
		expect(wrapper.emitted('answer')).toBeUndefined()

		await wrapper.find('input').setValue('  IJsselstein  ')
		await form.trigger('submit')
		expect(wrapper.emitted('answer')).toEqual([['IJsselstein']])
	})

	it('has no text box when the agent does not allow an answer of the person\'s own', () => {
		const wrapper = card({ text: 'Close them?', options: ['Yes', 'No'], allowOther: false })

		expect(wrapper.find('[data-testid="chat-agent-question-other"]').exists()).toBe(false)
		expect(wrapper.findAll('.agent-question__options button')).toHaveLength(2)
	})

	it('emits nothing while a turn is being sent', async () => {
		const wrapper = card(municipality, true)

		wrapper.vm.choose('Utrecht')
		await wrapper.find('input').setValue('Houten')
		wrapper.vm.sendOther()

		expect(wrapper.emitted('answer')).toBeUndefined()
	})

	it('keeps at most four non-empty string options', () => {
		expect(questionOptions({ options: ['a', '', 'b', 7, 'c', 'd', 'e'] })).toEqual(['a', 'b', 'c', 'd'])
		expect(questionOptions({})).toEqual([])
		expect(questionOptions(null)).toEqual([])
	})
})
