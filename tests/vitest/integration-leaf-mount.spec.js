/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * src/integration-leaf.js: the agent leaf registered with the integration
 * registry, and its mount/unmount hand-off that roots a Vue 3 app per host
 * element, picking the chat or the run widget by surface.
 */

import { beforeAll, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const registered = []

vi.mock('@conduction/nextcloud-vue/dist/esm/integrations/registry.js', () => ({
	registerIntegration: (descriptor) => registered.push(descriptor),
}))

vi.mock('../../src/components/CnAgentChatTab/CnAgentChatTab.vue', () => ({
	default: {
		name: 'CnAgentChatTab',
		inheritAttrs: false,
		render() {
			return h('div', { class: 'chat', 'data-props': JSON.stringify(this.$attrs) })
		},
	},
}))

vi.mock('../../src/components/CnAgentRunsWidget/CnAgentRunsWidget.vue', () => ({
	default: {
		name: 'CnAgentRunsWidget',
		inheritAttrs: false,
		render() {
			return h('div', { class: 'runs', 'data-props': JSON.stringify(this.$attrs) })
		},
	},
}))

/**
 * A fresh host-owned element attached to the document.
 *
 * @return {HTMLElement} The element.
 */
function hostElement() {
	const el = document.createElement('div')
	document.body.appendChild(el)
	return el
}

describe('agent integration leaf', () => {
	let leaf

	beforeAll(async () => {
		await import('../../src/integration-leaf.js')
		leaf = registered[0]
	})

	it('registers exactly one mount-mode descriptor for the agent leaf', () => {
		expect(registered).toHaveLength(1)
		expect(leaf).toMatchObject({
			id: 'hermiq-agent',
			label: 'Agent',
			appName: 'Hermiq',
			icon: 'Creation',
			requiredApp: 'hermiq',
			order: 60,
			group: 'workflow',
			referenceType: 'hermiq-agent',
			surfaces: ['user-dashboard', 'app-dashboard', 'detail-page', 'single-entity'],
			renderMode: 'mount',
			defaultSize: { w: 4, h: 4 },
		})
		expect(typeof leaf.mount).toBe('function')
		expect(typeof leaf.unmount).toBe('function')
		expect(leaf).not.toHaveProperty('tab')
		expect(leaf).not.toHaveProperty('widget')
	})

	it('renders the chat on the single-entity surface with the forwarded props', () => {
		const el = hostElement()

		leaf.mount(el, { surface: 'single-entity', register: 'r1', schema: 's1', objectId: 'o1' })

		const chat = el.querySelector('.chat')
		expect(chat).not.toBeNull()
		expect(el.querySelector('.runs')).toBeNull()
		expect(JSON.parse(chat.getAttribute('data-props'))).toEqual({
			surface: 'single-entity',
			register: 'r1',
			schema: 's1',
			objectId: 'o1',
		})
		leaf.unmount(el)
	})

	it.each(['detail-page', 'app-dashboard', 'user-dashboard'])('renders the run widget on %s', (surface) => {
		const el = hostElement()

		leaf.mount(el, { surface, objectId: 'o2' })

		expect(el.querySelector('.runs')).not.toBeNull()
		expect(el.querySelector('.chat')).toBeNull()
		leaf.unmount(el)
	})

	it('falls back to the chat without props', () => {
		const el = hostElement()

		leaf.mount(el)

		expect(JSON.parse(el.querySelector('.chat').getAttribute('data-props'))).toEqual({})
		leaf.unmount(el)
	})

	it('mounts once per element and ignores a missing element', () => {
		const el = hostElement()

		leaf.mount(el, { surface: 'single-entity', objectId: 'first' })
		leaf.mount(el, { surface: 'detail-page', objectId: 'second' })

		expect(el.querySelectorAll('.chat')).toHaveLength(1)
		expect(el.querySelector('.runs')).toBeNull()
		expect(JSON.parse(el.querySelector('.chat').getAttribute('data-props')).objectId).toBe('first')
		expect(() => leaf.mount(null, {})).not.toThrow()
		expect(() => leaf.mount(undefined, {})).not.toThrow()
		leaf.unmount(el)
	})

	it('keeps separate instances for separate elements', () => {
		const a = hostElement()
		const b = hostElement()

		leaf.mount(a, { surface: 'single-entity' })
		leaf.mount(b, { surface: 'detail-page' })
		leaf.unmount(a)

		expect(a.innerHTML).toBe('')
		expect(b.querySelector('.runs')).not.toBeNull()
		leaf.unmount(b)
	})

	it('unmount empties the element and allows a fresh mount', () => {
		const el = hostElement()

		leaf.mount(el, { surface: 'single-entity' })
		leaf.unmount(el)

		expect(el.innerHTML).toBe('')

		leaf.mount(el, { surface: 'detail-page' })
		expect(el.querySelector('.runs')).not.toBeNull()
		leaf.unmount(el)
	})

	it('ignores unmounting an unknown element or unmounting twice', () => {
		const el = hostElement()
		leaf.mount(el, { surface: 'single-entity' })
		leaf.unmount(el)

		expect(() => leaf.unmount(el)).not.toThrow()
		expect(() => leaf.unmount(hostElement())).not.toThrow()
	})
})
