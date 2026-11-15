/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * src/settings.js, the admin settings entry point: it waits for the app's
 * translations, then mounts AdminRoot into #hermiq-settings with t/n global
 * and the app's pinia installed.
 */

import { getActivePinia } from 'pinia'
import { describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const loaded = []

vi.mock('@nextcloud/l10n', () => ({
	loadTranslations: (app, callback) => loaded.push({ app, callback }),
	translate: (app, text) => `t:${app}:${text}`,
	translatePlural: (app, singular, plural, count) => `n:${app}:${count === 1 ? singular : plural}`,
}))

vi.mock('../../src/views/AdminRoot.vue', () => ({
	default: {
		name: 'AdminRoot',
		render() {
			return h('div', { class: 'admin-root' }, [
				this.t('hermiq', 'Settings'),
				'|',
				this.n('hermiq', 'agent', 'agents', 2),
				'|',
				getActivePinia() !== undefined ? 'pinia' : 'no-pinia',
			])
		},
	},
}))

describe('admin settings entry point', () => {
	it('mounts AdminRoot only after the hermiq translations are loaded', async () => {
		const target = document.createElement('div')
		target.id = 'hermiq-settings'
		document.body.appendChild(target)

		await import('../../src/settings.js')

		expect(loaded).toHaveLength(1)
		expect(loaded[0].app).toBe('hermiq')
		expect(target.querySelector('.admin-root')).toBeNull()

		loaded[0].callback()

		const root = document.querySelector('.admin-root')
		expect(root).not.toBeNull()
		expect(root.textContent).toBe('t:hermiq:Settings|n:hermiq:agents|pinia')
	})
})
