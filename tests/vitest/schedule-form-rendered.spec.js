/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The schedule form mounted in full over the @nextcloud/vue stand-ins: each
 * field the chosen kind, channel and retry switch reveal writes the form, the
 * buttons and the modal close it, and the webhook secret dialog opens and
 * closes for a saved schedule.
 */

import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

vi.mock('../../src/api/budgets.js', () => ({ getBudgetEstimate: vi.fn(async () => null) }))
const scheduleStore = { registerObjectType: vi.fn(), saveObject: vi.fn(), errors: {} }
vi.mock('../../src/store/store.js', () => ({ useScheduleStore: () => scheduleStore }))

const t = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))
globalThis.t = t

const { default: ScheduleFormModal } = await import('../../src/modals/ScheduleFormModal.vue')

/**
 * Mount the form open.
 *
 * @param {object} props Its props.
 * @return {Promise<object>} The wrapper.
 */
async function render(props = {}) {
	const w = mount(ScheduleFormModal, { props: { show: true, ...props }, global: { mocks: { t } } })
	await flushPromises()
	return w
}

/**
 * The stand-in field with a label.
 *
 * @param {object} w The wrapper.
 * @param {string} name The stand-in name.
 * @param {string} label The label.
 * @return {object} The component wrapper.
 */
function field(w, name, label) {
	const found = w.findAllComponents({ name }).find((c) => c.attributes('data-label') === label)
	expect(found, label).toBeDefined()
	return found
}

describe('ScheduleFormModal as rendered', () => {
	it('shows the field of each kind and writes what is typed into it', async () => {
		const w = await render()

		w.vm.form.kind = 'interval'
		await flushPromises()
		field(w, 'NcTextField', 'Interval (minutes)').vm.pick('15')
		expect(w.vm.form.intervalMinutes).toBe('15')

		w.vm.form.kind = 'once'
		await flushPromises()
		field(w, 'NcTextField', 'Run at').vm.pick('2026-12-01T09:00')
		expect(w.vm.form.runAt).toBe('2026-12-01T09:00')
		expect(w.findAllComponents({ name: 'NcTextField' }).some((c) => c.attributes('data-label') === 'Interval (minutes)')).toBe(false)
	})

	it('asks for the target each delivery channel needs', async () => {
		const w = await render()

		w.vm.form.deliver = 'talk'
		await flushPromises()
		field(w, 'NcTextField', 'Talk room token').vm.pick('room-1')
		expect(w.vm.form.deliverTarget).toBe('room-1')

		w.vm.form.deliver = 'email'
		await flushPromises()
		field(w, 'NcTextField', 'Email recipient').vm.pick('team@example.org')
		expect(w.vm.form.deliverTarget).toBe('team@example.org')
	})

	it('reveals the retry policy behind its switch and writes each limit', async () => {
		const w = await render()
		expect(w.findAllComponents({ name: 'NcTextField' }).some((c) => c.attributes('data-label') === 'Retry max attempts')).toBe(false)

		const retry = w.findAllComponents({ name: 'NcCheckboxRadioSwitch' }).find((c) => c.text().includes('Retry on failure'))
		retry.vm.pick(true)
		await flushPromises()
		expect(w.vm.form.retryEnabled).toBe(true)

		field(w, 'NcTextField', 'Retry max attempts').vm.pick('4')
		field(w, 'NcTextField', 'Retry backoff base (seconds)').vm.pick('30')
		field(w, 'NcTextField', 'Circuit breaker threshold').vm.pick('6')
		expect([w.vm.form.retryMaxAttempts, w.vm.form.retryBackoffBaseSeconds, w.vm.form.circuitBreakerThreshold]).toEqual(['4', '30', '6'])
	})

	it('closes from the modal and from Cancel', async () => {
		const w = await render()

		w.findComponent({ name: 'NcModal' }).vm.$emit('close')
		const cancel = w.findAllComponents({ name: 'NcButton' }).find((c) => c.text() === 'Cancel')
		cancel.vm.$emit('click')

		expect(w.emitted('close')).toHaveLength(2)
	})

	it('shows the save error, the spinner while saving, and the secret dialog of a saved webhook schedule', async () => {
		const w = await render({ schedule: { id: 's-1', name: 'Digest', deliver: 'webhook', kind: 'cron', cronExpr: '0 8 * * *' } })
		w.vm.error = 'Name taken'
		w.vm.saving = true
		w.vm.form.deliver = 'webhook'
		w.vm.form.name = 'Digest'
		await flushPromises()

		const note = w.findAllComponents({ name: 'NcNoteCard' }).find((c) => c.attributes('data-heading') === 'Could not save schedule')
		expect(note.text()).toContain('Name taken')
		expect(w.findComponent({ name: 'NcLoadingIcon' }).exists()).toBe(true)

		w.vm.saving = false
		await flushPromises()
		w.findAllComponents({ name: 'NcButton' }).find((c) => c.text().includes('secret')).vm.$emit('click')
		await flushPromises()
		expect(w.vm.showWebhookSecretDialog).toBe(true)

		w.findComponent({ name: 'ScheduleWebhookSecretDialog' }).vm.$emit('close')
		expect(w.vm.showWebhookSecretDialog).toBe(false)
	})
})
