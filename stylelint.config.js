module.exports = {
	extends: '@nextcloud/stylelint-config',
	rules: {
		// Prettier owns style formatting now (see package.json's `prettier` key and
		// `format` script). These are the two STYLISTIC rules this config still
		// arms, and stylelint itself prints a DEPRECATION warning for both on every
		// run — stylelint 15 handed this whole category to formatters.
		//
		// They have to yield, and not for tidiness: `indentation` and prettier
		// genuinely disagree. Prettier wraps a selector longer than `printWidth`
		// across lines —
		//
		//   .flow-builder
		//       :deep(
		//           .cn-graph-canvas__node:has(.flow-builder__node--trigger)
		//               .cn-graph-canvas__handle
		//       ) {
		//
		// — and `indentation` then counts the wrapped selector's depth differently
		// and demands 0 tabs where prettier writes 1. Neither tool can be satisfied
		// while both are armed: that is the unfixable state this fleet already hit
		// with php-cs-fixer versus PHPCS, and the same reason eslint.config.js ends
		// with `eslint-config-prettier`.
		//
		// Nothing is lost by switching them off. Prettier enforces the same tab
		// over a STRICTLY LARGER surface: `**/*.{css,scss,vue}` rather than this
		// config's `src/**`, which is what closes the gap this app had — nothing
		// checked the indentation of CSS outside `src/`.
		indentation: null,
		'string-quotes': null,
		'selector-pseudo-element-no-unknown': [
			true,
			{
				ignorePseudoElements: ['v-deep'],
			},
		],
	},
}
