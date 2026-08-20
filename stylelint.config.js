module.exports = {
	extends: '@nextcloud/stylelint-config',
	rules: {
		// `indentation` and `string-quotes` used to be switched off here because
		// prettier owns style formatting (see package.json's `prettier` key and
		// `format` script) and `indentation` genuinely disagreed with it about
		// wrapped selectors.
		//
		// stylelint 16 DELETED both rules outright — the whole stylistic category
		// went to formatters. A `null` entry is no longer a way to disable them;
		// stylelint 17 does not recognise the names at all and raises
		// "Unknown rule indentation" / "Unknown rule string-quotes" once per file
		// (78 files here, 156 errors). So the entries are removed rather than
		// re-disabled.
		//
		// Nothing is lost. Prettier still enforces the same tab over a STRICTLY
		// LARGER surface — `**/*.{css,scss,vue}` rather than this config's `src/**`.
		'selector-pseudo-element-no-unknown': [
			true,
			{
				ignorePseudoElements: ['v-deep'],
			},
		],
	},
}
