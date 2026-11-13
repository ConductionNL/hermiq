/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * src/companion.js, the init script attached to every Nextcloud page: which
 * pages get a companion, the stylesheet it requests once, and the page context
 * (app id, open file) it hands the companion panel.
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

vi.mock('@nextcloud/router', () => ({
	generateFilePath: (app, type, file) => `/custom_apps/${app}/${type}/${file}`,
}))

vi.mock('@conduction/nextcloud-vue/dist/esm/components/CnAiCompanion/CnAiCompanion.vue.js', () => ({
	__esModule: true,
	default: {
		name: 'CnAiCompanion',
		inheritAttrs: false,
		render() {
			return h('div', { class: 'companion-stub', 'data-props': JSON.stringify(this.$attrs) })
		},
	},
}))

/**
 * Load the companion script on a page at the given URL.
 *
 * @param {string} url The page path and query.
 * @param {string} user The signed-in uid, '' for none.
 * @return {Promise<void>} Resolves once the module has evaluated.
 */
async function loadOn(url, user = 'admin') {
	window.history.pushState({}, '', url)
	if (user === '') {
		document.head.removeAttribute('data-user')
	} else {
		document.head.setAttribute('data-user', user)
	}
	vi.resetModules()
	await import('../../src/companion.js')
}

/**
 * Fire the stylesheet's load event and wait for the panel to render.
 *
 * @return {Promise<object>} The props the companion was rendered with.
 */
async function renderedProps() {
	const link = await vi.waitFor(() => {
		const el = document.getElementById('hermiq-companion-stylesheet')
		expect(el).not.toBeNull()
		return el
	})
	link.dispatchEvent(new Event('load'))
	const stub = await vi.waitFor(() => {
		const el = document.querySelector('#hermiq-companion-root .companion-stub')
		expect(el).not.toBeNull()
		return el
	})
	return JSON.parse(stub.getAttribute('data-props'))
}

/**
 * Let pending microtasks run.
 *
 * @return {Promise<void>} Resolves on the next macrotask.
 */
const settle = () => new Promise((resolve) => setTimeout(resolve, 0))

describe('companion init script', () => {
	beforeEach(() => {
		document.head.innerHTML = ''
		document.body.innerHTML = ''
	})

	afterEach(() => {
		window.history.pushState({}, '', '/')
	})

	it('mounts on another app\'s page with that app as context and no file', async () => {
		await loadOn('/index.php/apps/files/')

		const props = await renderedProps()

		expect(props).toEqual({
			chatAppId: 'hermiq',
			position: 'bottom-right',
			context: {
				appId: 'files',
				pageKind: 'custom',
				route: { path: '/index.php/apps/files/' },
			},
		})
		const root = document.getElementById('hermiq-companion-root')
		expect(root.style.zIndex).toBe('2001')
		expect(root.style.position).toBe('relative')
	})

	it('requests its stylesheet from the app\'s own css directory', async () => {
		await loadOn('/apps/files/')

		const link = await vi.waitFor(() => {
			const el = document.getElementById('hermiq-companion-stylesheet')
			expect(el).not.toBeNull()
			return el
		})

		expect(link.rel).toBe('stylesheet')
		expect(link.getAttribute('href')).toBe('/custom_apps/hermiq/css/companion.css')
		// Not mounted until the sheet has applied.
		expect(document.getElementById('hermiq-companion-root')).toBeNull()
	})

	it('still mounts when the stylesheet fails to load', async () => {
		await loadOn('/apps/files/')

		const link = await vi.waitFor(() => {
			const el = document.getElementById('hermiq-companion-stylesheet')
			expect(el).not.toBeNull()
			return el
		})
		link.dispatchEvent(new Event('error'))

		await vi.waitFor(() => {
			expect(document.querySelector('#hermiq-companion-root .companion-stub')).not.toBeNull()
		})
	})

	it('takes the open file from the fileId query parameter', async () => {
		await loadOn('/apps/richdocuments/index?fileId=4711')

		const props = await renderedProps()

		expect(props.context).toEqual({
			appId: 'richdocuments',
			pageKind: 'file',
			route: { path: '/apps/richdocuments/index' },
			fileId: 4711,
		})
	})

	it('takes the open file from a numeric last path segment', async () => {
		await loadOn('/apps/onlyoffice/24753')

		const props = await renderedProps()

		expect(props.context.appId).toBe('onlyoffice')
		expect(props.context.pageKind).toBe('file')
		expect(props.context.fileId).toBe(24753)
	})

	it('ignores a non-numeric fileId and reports an app-less path as unknown', async () => {
		await loadOn('/settings/user?fileId=abc')

		const props = await renderedProps()

		expect(props.context).toEqual({
			appId: 'unknown',
			pageKind: 'custom',
			route: { path: '/settings/user' },
		})
	})

	it('does not mount on Hermiq\'s own pages', async () => {
		await loadOn('/index.php/apps/hermiq/agents')
		await settle()

		expect(document.getElementById('hermiq-companion-stylesheet')).toBeNull()
		expect(document.getElementById('hermiq-companion-root')).toBeNull()
	})

	it('does mount on an app whose id only starts with hermiq', async () => {
		await loadOn('/apps/hermiqx/')

		const props = await renderedProps()

		expect(props.context.appId).toBe('hermiqx')
	})

	it('does not mount without a signed-in user', async () => {
		await loadOn('/login', '')
		await settle()

		expect(document.getElementById('hermiq-companion-stylesheet')).toBeNull()
		expect(document.getElementById('hermiq-companion-root')).toBeNull()
	})

	it('does not mount a second companion when a root already exists', async () => {
		const existing = document.createElement('div')
		existing.id = 'hermiq-companion-root'
		document.body.appendChild(existing)

		await loadOn('/apps/files/')
		await settle()

		expect(document.getElementById('hermiq-companion-stylesheet')).toBeNull()
		expect(document.querySelectorAll('#hermiq-companion-root')).toHaveLength(1)
		expect(existing.children).toHaveLength(0)
	})

	it('reuses a stylesheet that is already attached', async () => {
		const link = document.createElement('link')
		link.id = 'hermiq-companion-stylesheet'
		document.head.appendChild(link)

		await loadOn('/apps/files/')

		await vi.waitFor(() => {
			expect(document.querySelector('#hermiq-companion-root .companion-stub')).not.toBeNull()
		})
		expect(document.querySelectorAll('#hermiq-companion-stylesheet')).toHaveLength(1)
	})
})
