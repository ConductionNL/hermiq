/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Characterisation of the organisation picker the kill switch and the tenant
 * operations page share: the options, the selected option, and what choosing
 * one sets and reloads.
 */

import { describe, expect, it, vi } from 'vitest'

vi.mock('@conduction/nextcloud-vue', () => ({ CnDataTable: {} }))
vi.mock('vue-material-design-icons/AlertOctagon.vue', () => ({ default: {} }))
vi.mock('vue-material-design-icons/ShieldLockOutline.vue', () => ({ default: {} }))
vi.mock('../../src/modals/BudgetFormModal.vue', () => ({ default: {} }))

const { default: KillSwitchToggle } = await import('../../src/components/KillSwitchToggle.vue')
const { default: TenantOps } = await import('../../src/views/TenantOps.vue')

/**
 * A context exposing the component's computed properties (mixins first) over the given state.
 *
 * @param {object} component The component.
 * @param {object} state The data it reads.
 * @return {object} The context.
 */
function picker(component, state) {
	const ctx = { ...state }
	const computed = Object.assign({}, ...(component.mixins || []).map((mixin) => mixin.computed || {}), component.computed)
	for (const name of ['orgOptions', 'orgOption']) {
		const definition = computed[name]
		Object.defineProperty(ctx, name, {
			get: () => (typeof definition === 'function' ? definition.call(ctx) : definition.get.call(ctx)),
			set: (value) => {
				definition.set.call(ctx, value)
			},
		})
	}
	return ctx
}

describe.each([
	['KillSwitchToggle', KillSwitchToggle, 'load'],
	['TenantOps', TenantOps, 'loadBudgets'],
])('%s organisation picker', (name, component, reload) => {
	const organisations = [{ id: 'o1', label: 'Gemeente Aa' }, { id: 'o2' }]

	it('offers each organisation by label, else by id', () => {
		const ctx = picker(component, { organisations, selectedOrg: '' })
		expect(ctx.orgOptions).toEqual([{ label: 'Gemeente Aa', value: 'o1' }, { label: 'o2', value: 'o2' }])
	})

	it('shows the selected organisation, else the first', () => {
		expect(picker(component, { organisations, selectedOrg: 'o2' }).orgOption).toEqual({ label: 'o2', value: 'o2' })
		expect(picker(component, { organisations, selectedOrg: 'gone' }).orgOption).toEqual({ label: 'Gemeente Aa', value: 'o1' })
		expect(picker(component, { organisations: [], selectedOrg: '' }).orgOption).toBeUndefined()
	})

	it('selects the chosen organisation, or none, and reloads', () => {
		const ctx = picker(component, { organisations, selectedOrg: 'o1', [reload]: vi.fn() })
		ctx.orgOption = { label: 'o2', value: 'o2' }
		expect(ctx.selectedOrg).toBe('o2')
		ctx.orgOption = null
		expect(ctx.selectedOrg).toBe('')
		expect(ctx[reload]).toHaveBeenCalledTimes(2)
	})
})
