/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @nextcloud/l10n stand-in: strings come back untranslated, with {placeholders} filled.
 */

/**
 * Return the text with its placeholders filled.
 *
 * @param {string} app The app id.
 * @param {string} text The source text.
 * @param {object} vars The placeholder values.
 * @return {string} The text.
 */
export function t(app, text, vars = {}) {
	return text.replace(/{(\w+)}/g, (match, key) =>
		key in vars ? String(vars[key]) : match,
	)
}

/**
 * Return the singular or plural text with its placeholders filled.
 *
 * @param {string} app The app id.
 * @param {string} singular The singular text.
 * @param {string} plural The plural text.
 * @param {number} count The count.
 * @param {object} vars The placeholder values.
 * @return {string} The text.
 */
export function n(app, singular, plural, count, vars = {}) {
	return t(app, count === 1 ? singular : plural, { count, ...vars })
}

export { t as translate, n as translatePlural }

/**
 * The page language; the stand-in page is English.
 *
 * @return {string} The language code.
 */
export function getLanguage() {
	return 'en'
}
