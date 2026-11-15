/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The schedule webhook secret dialog driven through its rendered buttons:
 * mint, copy, rotate and revoke, the one-time reveal, and refusals; and the
 * schedule form's trigger, delivery and approval controls that lead to it.
 *
 * Mounted with the shared @nextcloud/vue and @nextcloud/dialogs stand-ins
 * (tests/vitest/stubs); the secret API and the clipboard are doubled.
 */

import { shown } from '@nextcloud/dialogs'
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../../src/api/scheduleWebhookSecret.js', () => ({
	getScheduleWebhookSecretStatus: vi.fn(),
	mintScheduleWebhookSecret: vi.fn(),
	revokeScheduleWebhookSecret: vi.fn(),
	rotateScheduleWebhookSecret: vi.fn(),
}))

vi.mock('../../src/api/budgets.js', () => ({ getBudgetEstimate: vi.fn(async () => null) }))
const scheduleStore = { registerObjectType: vi.fn(), saveObject: vi.fn() }
vi.mock('../../src/store/store.js', () => ({ useScheduleStore: () => scheduleStore }))

const api = await import('../../src/api/scheduleWebhookSecret.js')
const { default: ScheduleWebhookSecretDialog } = await import('../../src/modals/ScheduleWebhookSecretDialog.vue')
const { default: ScheduleFormModal } = await import('../../src/modals/ScheduleFormModal.vue')

/**
 * Translate as the identity, filling {placeholders}.
 *
 * @param {string} app The app id.
 * @param {string} text The text.
 * @param {object} vars The placeholders.
 * @return {string} The filled text.
 */
const t = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))

/**
 * Mount the dialog closed, then open it so it loads its status.
 *
 * @return {Promise<object>} The wrapper.
 */
async function opened() {
	const w = mount(ScheduleWebhookSecretDialog, { props: { show: false, scheduleId: 'sch-1' }, global: { mocks: { t } } })
	await w.setProps({ show: true })
	await flushPromises()
	return w
}

/**
 * Click the button whose text is given.
 *
 * @param {object} w The wrapper.
 * @param {string} text The button text.
 * @return {Promise<void>}
 */
async function click(w, text) {
	const button = w.findAllComponents({ name: 'NcButton' }).find((b) => b.text().trim() === text)
	if (!button) {
		throw new Error(`no button ${text}`)
	}
	button.vm.$emit('click')
	await flushPromises()
}

beforeEach(() => {
	vi.clearAllMocks()
	shown.length = 0
})

describe('ScheduleWebhookSecretDialog controls', () => {
	it('mints a secret, shows it once, selects it on focus and copies it', async () => {
		api.getScheduleWebhookSecretStatus.mockResolvedValue({ configured: false })
		api.mintScheduleWebhookSecret.mockResolvedValue({ secret: 's3cr3t', rotatedAt: '2026-11-13' })
		const writeText = vi.fn().mockResolvedValue()
		Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true })
		const w = await opened()
		expect(api.getScheduleWebhookSecretStatus).toHaveBeenCalledWith('sch-1')

		await click(w, 'Mint secret')
		const field = w.findComponent({ name: 'NcTextField' })
		expect(field.vm.$attrs.modelValue).toBe('s3cr3t')
		const select = vi.fn()
		field.vm.$attrs.onFocus({ target: { select } })
		expect(select).toHaveBeenCalledTimes(1)

		await click(w, 'Copy to clipboard')
		expect(writeText).toHaveBeenCalledWith('s3cr3t')
		expect(shown).toEqual([['success', 'Secret copied to clipboard.']])

		await click(w, 'Done')
		expect(w.emitted('close')).toHaveLength(1)
	})

	it('rotates and revokes a configured secret', async () => {
		api.getScheduleWebhookSecretStatus.mockResolvedValue({ configured: true, rotatedAt: '2026-01-01' })
		api.rotateScheduleWebhookSecret.mockResolvedValue({ secret: 'n3w', rotatedAt: '2026-11-13' })
		api.revokeScheduleWebhookSecret.mockResolvedValue({ configured: false })
		const w = await opened()

		await click(w, 'Rotate secret')
		expect(api.rotateScheduleWebhookSecret).toHaveBeenCalledWith('sch-1')
		expect(w.findComponent({ name: 'NcTextField' }).vm.$attrs.modelValue).toBe('n3w')

		w.vm.revealedSecret = ''
		await flushPromises()
		await click(w, 'Revoke secret')
		expect(api.revokeScheduleWebhookSecret).toHaveBeenCalledWith('sch-1')
		expect(shown).toEqual([['success', 'Webhook secret revoked.']])
		expect(w.findAllComponents({ name: 'NcButton' }).some((b) => b.text().trim() === 'Mint secret')).toBe(true)
	})

	it('shows a refusal or the fallback, treats an unreadable status as unconfigured, and closes', async () => {
		api.getScheduleWebhookSecretStatus.mockRejectedValue(new Error('down'))
		api.mintScheduleWebhookSecret.mockRejectedValueOnce({ response: { data: { error: 'Not the owner' } } }).mockRejectedValueOnce(new Error('x'))
		const w = await opened()

		await click(w, 'Mint secret')
		expect(w.findComponent({ name: 'NcNoteCard' }).text()).toBe('Not the owner')
		await click(w, 'Mint secret')
		expect(w.findComponent({ name: 'NcNoteCard' }).text()).toBe('Could not mint webhook secret')

		await click(w, 'Close')
		w.findComponent({ name: 'NcModal' }).vm.$emit('close')
		expect(w.emitted('close')).toHaveLength(2)
	})
})

describe('ScheduleFormModal webhook delivery controls', () => {
	/**
	 * Mount the schedule form with the store doubled.
	 *
	 * @param {object|null} schedule The schedule being edited.
	 * @return {Promise<object>} The wrapper.
	 */
	async function form(schedule) {
		const w = mount(ScheduleFormModal, { props: { show: true, agentId: 'a-1', schedule }, global: { mocks: { t } } })
		await flushPromises()
		return w
	}

	/**
	 * The stand-in of a kind carrying an attribute value.
	 *
	 * @param {object} w The wrapper.
	 * @param {string} name The component name.
	 * @param {string} attr The attribute.
	 * @param {string} value Its value.
	 * @return {object|undefined} The stand-in.
	 */
	function by(w, name, attr, value) {
		return w.findAllComponents({ name }).find((c) => c.vm.$attrs[attr] === value)
	}

	it('writes the trigger, delivery, repeat, approval and reviewer fields from the rendered controls', async () => {
		const w = await form(null)

		by(w, 'NcTextField', 'label', 'Name').vm.pick('Nightly')
		by(w, 'NcSelect', 'inputLabel', 'Trigger').vm.pick({ value: 'cron' })
		await flushPromises()
		by(w, 'NcTextField', 'label', 'Cron expression').vm.pick('0 2 * * *')
		by(w, 'NcTextArea', 'label', 'Prompt').vm.pick('Summarise')
		by(w, 'NcSelect', 'inputLabel', 'Deliver output to').vm.pick({ value: 'webhook' })
		await flushPromises()
		by(w, 'NcTextField', 'label', 'Webhook URL').vm.pick('https://hook.example/x')
		by(w, 'NcTextField', 'label', 'Repeat times (empty = forever)').vm.pick('3')
		const switches = w.findAllComponents({ name: 'NcCheckboxRadioSwitch' })
		switches.find((s) => s.text() === 'Enabled').vm.pick(false)
		switches.find((s) => s.text() === 'Requires approval').vm.pick(true)
		await flushPromises()
		by(w, 'NcSelect', 'inputLabel', 'Reviewer type').vm.pick({ value: 'group' })
		await flushPromises()
		by(w, 'NcTextField', 'label', 'Reviewer group id').vm.pick('reviewers')

		expect(w.vm.form).toMatchObject({
			name: 'Nightly',
			kind: 'cron',
			cronExpr: '0 2 * * *',
			prompt: 'Summarise',
			deliver: 'webhook',
			deliverTarget: 'https://hook.example/x',
			repeatTimes: '3',
			enabled: false,
			requiresApproval: true,
			reviewerType: 'group',
			reviewer: 'reviewers',
		})
		expect(w.text()).toContain('Save this schedule first, then reopen it to mint a webhook signing secret.')
	})

	it('offers the signing secret for a saved webhook schedule and opens its dialog', async () => {
		api.getScheduleWebhookSecretStatus.mockResolvedValue({ configured: false })
		const w = await form({ id: 'sch-1', deliver: 'webhook', deliverTarget: 'https://hook.example/x' })
		await w.setProps({ show: false })
		await w.setProps({ show: true })
		await flushPromises()

		await click(w, 'Manage webhook signing secret')

		expect(w.vm.showWebhookSecretDialog).toBe(true)
		expect(w.findComponent({ name: 'ScheduleWebhookSecretDialog' }).props('scheduleId')).toBe('sch-1')
	})
})
