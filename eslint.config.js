const {
	defineConfig,
} = require('@eslint/config-helpers')

const js = require('@eslint/js')

const {
	FlatCompat,
} = require('@eslint/eslintrc')

const compat = new FlatCompat({
	baseDirectory: __dirname,
	recommendedConfig: js.configs.recommended,
	allConfig: js.configs.all,
})

module.exports = defineConfig([{
	extends: compat.extends('@nextcloud'),

	settings: {
		'import/resolver': {
			alias: {
				map: [
					['@', './src'],
					['@floating-ui/dom-actual', './node_modules/@floating-ui/dom'],
					['@conduction/nextcloud-vue', '../nextcloud-vue/src'],
				],
				extensions: ['.js', '.ts', '.vue', '.json', '.css'],
			},
		},
	},

	rules: {
		// Allow unused i18n functions (t, n) — imported for future translation wiring
		'no-unused-vars': ['error', { varsIgnorePattern: '^(t|n)$', argsIgnorePattern: '^_' }],
		'jsdoc/require-jsdoc': 'off',
		// @spec is the Conduction OpenSpec traceability tag (gate-16); it is a
		// deliberate, org-wide custom JSDoc tag, not a typo.
		'jsdoc/check-tag-names': ['warn', { definedTags: ['spec'] }],
		'vue/first-attribute-linebreak': 'off',
		'@typescript-eslint/no-explicit-any': 'off',
		'n/no-missing-import': 'off',
		// Hermiq is a Nextcloud app, never published to npm, so "unpublished import"
		// has no meaning — local relative imports (e.g. the app shell wiring in App.vue)
		// are legitimate. Consistent with n/no-missing-import above.
		'n/no-unpublished-import': 'off',
		'import/namespace': 'off', // disable namespace checking to avoid parser requirement
		'import/default': 'off', // disable default import checking to avoid parser requirement
		'import/no-named-as-default': 'off', // disable named-as-default checking to avoid parser requirement
		'import/no-named-as-default-member': 'off', // disable named-as-default-member checking to avoid parser requirement
		// Hermiq is a Vue 3 app: keying `<template v-for>` is the documented
		// Vue 3 pattern. `vue/no-v-for-template-key` is the Vue 2 rule (the
		// @nextcloud shareable config targets Vue 2.7); the Vue 3 counterpart
		// `vue/no-v-for-template-key-on-child` guards the actual mistake.
		'vue/no-v-for-template-key': 'off',
		'vue/no-v-for-template-key-on-child': 'error',
	},
}, {
	// GENERATED. `src/icons/openGemeentenIcons.js` is 227 extracted SVG paths
	// written by `npm run icons:opengemeenten`; hand-formatting a generated
	// file is work that is undone the next time it is generated.
	ignores: ['src/icons/openGemeentenIcons.js'],
}, {
	// Node-side CLI tools (build / validate / codegen scripts) legitimately use
	// console + process.exit and ship as plain JS (no shebang).
	files: ['tests/validate-manifest.js', 'scripts/*.mjs'],
	rules: {
		'no-console': 'off',
		'n/no-process-exit': 'off',
		'n/shebang': 'off',
	},
}])
