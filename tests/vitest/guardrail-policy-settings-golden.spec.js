/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Golden test for GuardrailPolicySettings: the rendered page and the payload
 * sent on save, for creating a policy and for editing one, with every field
 * changed through its input. GOLDEN_RECORD=1 rewrites the fixture; a run in
 * record mode compares nothing.
 */

import { state } from '@nextcloud/initial-state'
import { t } from '@nextcloud/l10n'
import { flushPromises, mount } from '@vue/test-utils'
import { readFileSync, writeFileSync } from 'node:fs'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const FIXTURE = 'tests/vitest/__fixtures__/guardrail-policy-settings.json'

const api = vi.hoisted(() => ({
	listGuardrailPolicies: vi.fn(),
	createGuardrailPolicy: vi.fn(),
	updateGuardrailPolicy: vi.fn(),
}))

vi.mock('../../src/api/guardrailPolicy.js', () => api)

const { default: GuardrailPolicySettings } =
	await import('../../src/views/GuardrailPolicySettings.vue')

const POLICIES = [
	{
		id: 'gp-1',
		organisation: 'org-a',
		inputFilters: { piiAction: 'redact', promptInjectionAction: 'block' },
		outputFilters: { piiAction: 'off' },
		toolPolicy: [{ toolId: 'mail.send', classification: 'confirm' }],
	},
]

/**
 * Mount the page for an organisation admin and let the policies load.
 *
 * @return {Promise<object>} The wrapper.
 */
async function page() {
	const wrapper = mount(GuardrailPolicySettings, { global: { mocks: { t } } })
	await flushPromises()
	return wrapper
}

/**
 * Change every field through its input: the three action selects and the tool list.
 *
 * @param {object} wrapper The page.
 * @return {Promise<void>}
 */
async function changeEveryField(wrapper) {
	const selects = wrapper.findAllComponents({ name: 'NcSelect' })
	const actions = selects.slice(selects.length - 3)
	actions[0].vm.pick({ value: 'block' })
	actions[1].vm.pick(null)
	actions[2].vm.pick({ value: 'redact' })
	wrapper
		.findComponent({ name: 'NcTextArea' })
		.vm.pick('files.delete: deny\n\n  : auto\nmail.send')
	await flushPromises()
}

/**
 * The button with the given text.
 *
 * @param {object} wrapper The page.
 * @param {string} label The button text.
 * @return {object} The button wrapper.
 */
function button(wrapper, label) {
	const found = wrapper
		.findAllComponents({ name: 'NcButton' })
		.find((candidate) => candidate.text().trim() === label)
	if (found === undefined) {
		throw new Error(`no button "${label}" in ${wrapper.html()}`)
	}
	return found
}

/**
 * The page's HTML without scoped-style ids. The page's scoped CSS styles only
 * its own wrappers, never the inputs, so which component renders an input
 * does not change how it looks.
 *
 * @param {object} wrapper The page.
 * @return {string} The HTML.
 */
function html(wrapper) {
	return wrapper.html().replace(/ data-v-[0-9a-f]+=""/g, '')
}

const results = {}

describe('GuardrailPolicySettings golden', () => {
	beforeEach(() => {
		vi.resetAllMocks()
		state.can_manage_killswitch = true
		state.managed_organisations = [
			{ id: 'org-a', name: 'Alpha' },
			{ id: 'org-b', name: 'Beta' },
		]
		api.listGuardrailPolicies.mockResolvedValue(POLICIES)
		api.createGuardrailPolicy.mockResolvedValue({})
		api.updateGuardrailPolicy.mockResolvedValue({})
	})

	it('creates a policy', async () => {
		const wrapper = await page()
		await button(wrapper, 'New guardrail policy').vm.$emit('click')
		await flushPromises()
		const opened = html(wrapper)
		await changeEveryField(wrapper)
		const changed = html(wrapper)
		await button(wrapper, 'Create policy').vm.$emit('click')
		await flushPromises()

		results.create = {
			opened,
			changed,
			sent: api.createGuardrailPolicy.mock.calls,
		}
	})

	it('edits a policy', async () => {
		const wrapper = await page()
		const listed = html(wrapper)
		await button(wrapper, 'Edit').vm.$emit('click')
		await flushPromises()
		const opened = html(wrapper)
		await changeEveryField(wrapper)
		const changed = html(wrapper)
		await button(wrapper, 'Save policy').vm.$emit('click')
		await flushPromises()

		results.edit = {
			listed,
			opened,
			changed,
			sent: api.updateGuardrailPolicy.mock.calls,
		}
	})

	it('matches the recorded fixture', () => {
		const actual = JSON.stringify(results, null, 1) + '\n'
		if (process.env.GOLDEN_RECORD === '1') {
			writeFileSync(FIXTURE, actual)
			return
		}

		expect(JSON.parse(actual)).toStrictEqual(
			JSON.parse(readFileSync(FIXTURE, 'utf8')),
		)
	})
})
