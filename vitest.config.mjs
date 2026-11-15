/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Vitest configuration for hermiq's Vue component tests.
 *
 * Single-file components are compiled by @vitejs/plugin-vue and mounted with
 * @vue/test-utils in jsdom. The @nextcloud packages are aliased to small stubs
 * under tests/vitest/stubs, so a component renders without a Nextcloud page:
 * each stub component renders its own name and props, which is what a golden
 * of a template compares. Only tests/vitest is collected; PHPUnit and the node
 * specs under tests/ are untouched.
 */

import vue from '@vitejs/plugin-vue'
import { fileURLToPath } from 'node:url'

/**
 * The path of one stub module.
 *
 * @param {string} name The stub's file name, without extension.
 * @return {string} The absolute path.
 */
function stub(name) {
	return fileURLToPath(new URL(`./tests/vitest/stubs/${name}.js`, import.meta.url))
}

export default {
	plugins: [vue()],
	test: {
		environment: 'jsdom',
		include: ['tests/vitest/**/*.spec.js'],
	},
	resolve: {
		alias: [
			{ find: /^@nextcloud\/vue$/, replacement: stub('nextcloud-vue') },
			{ find: /^@nextcloud\/l10n$/, replacement: stub('nextcloud-l10n') },
			{ find: /^@nextcloud\/axios$/, replacement: stub('nextcloud-axios') },
			{ find: /^@nextcloud\/router$/, replacement: stub('nextcloud-router') },
			{
				find: /^@nextcloud\/dialogs$/,
				replacement: stub('nextcloud-dialogs'),
			},
			{
				find: /^@nextcloud\/initial-state$/,
				replacement: stub('nextcloud-initial-state'),
			},
		],
	},
}
