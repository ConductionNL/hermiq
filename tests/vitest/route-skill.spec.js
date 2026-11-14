/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The routeSkill mixin: it registers the skill type, loads the skill named
 * by the route and reports a failed load.
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'

const store = { registerObjectType: vi.fn(), fetchObject: vi.fn() }

vi.mock('../../src/store/store.js', () => ({ useSkillStore: () => store }))

const { routeSkill } = await import('../../src/widgets/routeSkill.js')

/**
 * The mixin bound to a plain host on a route.
 *
 * @param {string} id The route id
 * @return {object} The host
 */
function host(id) {
	const vm = {
		...routeSkill.data(),
		$route: { params: { id } },
		t: (app, text) => text,
		skillStore: store,
	}
	Object.defineProperty(vm, 'skillId', { get: () => routeSkill.computed.skillId.call(vm) })
	vm.load = routeSkill.methods.load.bind(vm)
	return vm
}

describe('routeSkill', () => {
	beforeEach(() => {
		store.registerObjectType.mockReset()
		store.fetchObject.mockReset()
	})

	it('starts loading with no skill', () => {
		expect(routeSkill.data()).toEqual({ skill: null, loading: true, error: '' })
	})

	it('registers the skill type and loads the routed skill on create', async () => {
		store.fetchObject.mockResolvedValue({ id: 's-1' })
		const vm = host('s-1')

		routeSkill.created.call(vm)
		await vi.waitFor(() => expect(vm.loading).toBe(false))

		expect(store.registerObjectType).toHaveBeenCalledWith('agentskill', 'agentskill', 'hermiq')
		expect(store.fetchObject).toHaveBeenCalledWith('agentskill', 's-1')
		expect(vm.skill).toEqual({ id: 's-1' })
		expect(vm.error).toBe('')
	})

	it('treats an empty answer as no skill', async () => {
		store.fetchObject.mockResolvedValue(undefined)
		const vm = host('s-2')
		vm.skill = { id: 'old' }

		await vm.load()

		expect(vm.skill).toBeNull()
		expect(vm.loading).toBe(false)
	})

	it('reports the server error of a failed load', async () => {
		store.fetchObject.mockRejectedValue({ response: { data: { error: 'Forbidden' } } })
		const vm = host('s-3')

		await vm.load()

		expect(vm.error).toBe('Forbidden')
		expect(vm.loading).toBe(false)
	})

	it('falls back to a generic message and clears it on the next load', async () => {
		store.fetchObject.mockRejectedValueOnce({}).mockResolvedValueOnce({ id: 's-4' })
		const vm = host('s-4')

		await vm.load()
		expect(vm.error).toBe('Could not load the skill.')

		await vm.load()
		expect(vm.error).toBe('')
		expect(vm.skill).toEqual({ id: 's-4' })
	})
})
