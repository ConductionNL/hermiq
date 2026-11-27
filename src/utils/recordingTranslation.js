// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Translate a recording (chat-translate-a-recording): the languages offered as
 * a target, their names in the reader's language, and the line under a result
 * that says which languages and which engines were used.
 */

import { t } from '@nextcloud/l10n'

/**
 * The languages offered as a target. A model may translate into more; these
 * are the ones a municipality's front office meets most, and every one of
 * them has a name in Intl.DisplayNames.
 *
 * @type {string[]}
 */
export const TRANSLATION_LANGUAGES = [
	'nl', 'en', 'de', 'fr', 'es', 'it', 'pt', 'pl', 'ro', 'bg', 'hu', 'cs',
	'el', 'tr', 'ar', 'fa', 'ku', 'so', 'ti', 'am', 'uk', 'ru', 'zh', 'hi',
	'ur', 'pa', 'bn', 'vi', 'th', 'id', 'tl', 'sw', 'pap', 'fy',
]

/**
 * A language's name in the reader's language, e.g. "Arabisch" for `ar` in Dutch.
 *
 * @param {string} code The language code.
 * @param {string} [locale] The reader's language; the code itself without one.
 * @return {string} The name, or the code when no name is known.
 * @spec openspec/changes/chat-translate-a-recording/specs/speech-services/spec.md#scenario-a-reviewer-can-see-what-was-used
 */
export function languageName(code, locale = 'en') {
	if (!code) {
		return ''
	}
	try {
		const name = new Intl.DisplayNames([locale || 'en'], { type: 'language' }).of(code)
		if (!name || name === code) {
			return code
		}
		return name.charAt(0).toLocaleUpperCase(locale) + name.slice(1)
	} catch {
		return code
	}
}

/**
 * The target-language options, named in the reader's language, sorted by name,
 * with the ones without a voice marked "text only".
 *
 * @param {string[]} voices The languages that have a voice.
 * @param {string} [locale] The reader's language.
 * @return {Array<{id: string, label: string, spoken: boolean}>} The options.
 * @spec openspec/changes/chat-translate-a-recording/specs/speech-services/spec.md#scenario-a-language-without-a-voice-gets-text
 */
export function targetLanguageOptions(voices = [], locale = 'en') {
	const voiced = new Set((voices || []).map((code) => String(code).split('-')[0].toLowerCase()))
	return TRANSLATION_LANGUAGES
		.map((code) => {
			const spoken = voiced.has(code)
			const name = languageName(code, locale)
			return {
				id: code,
				spoken,
				label: spoken ? name : t('hermiq', '{language} (text only)', { language: name }),
			}
		})
		.sort((a, b) => a.label.localeCompare(b.label, locale))
}

/**
 * Where a step ran, as words: "on this server", "in the EU" or "outside the EU".
 *
 * @param {string} residency The declared residency of the provider.
 * @return {string} The phrase.
 */
function placePhrase(residency) {
	if (residency === 'on-premise') {
		return t('hermiq', 'on this server')
	}
	if (residency === 'eu') {
		return t('hermiq', 'in the EU')
	}
	if (residency === 'outside-eu') {
		return t('hermiq', 'outside the EU')
	}
	return t('hermiq', 'at a place its administrator has not declared')
}

/**
 * The model's display name, e.g. "Qwen3" for `qwen3:8b`, or the provider.
 *
 * @param {object} record The translation record.
 * @return {string} The name.
 */
function modelName(record) {
	const model = String(record?.translationModel || '').split(':')[0]
	const name = model || String(record?.translationProvider || '')
	return name ? name.charAt(0).toUpperCase() + name.slice(1) : t('hermiq', 'the configured model')
}

/**
 * The line under a translated recording: the languages, then where each step ran.
 *
 * For example "Arabisch (detected) to Nederlands. Transcribed and spoken on this
 * server, translated by Qwen3 on this server."
 *
 * @param {object} record The translation record stored on the turn.
 * @param {string} [locale] The reader's language.
 * @return {string} The line, or '' without a record.
 * @spec openspec/changes/chat-translate-a-recording/specs/speech-services/spec.md#scenario-a-reviewer-can-see-what-was-used
 */
export function translationLine(record, locale = 'en') {
	if (!record || !record.targetLanguage) {
		return ''
	}

	const target = languageName(record.targetLanguage, locale)
	let languages
	if (record.sourceLanguage) {
		languages = t('hermiq', '{source} to {target}.', { source: languageName(record.sourceLanguage, locale), target })
	} else if (record.detectedLanguage) {
		languages = t('hermiq', '{source} (detected) to {target}.', { source: languageName(record.detectedLanguage, locale), target })
	} else {
		languages = t('hermiq', 'Into {target}.', { target })
	}

	const translated = t('hermiq', 'translated by {model} {place}.', {
		model: modelName(record),
		place: placePhrase(record.translationResidency),
	})
	const local = record.ttsEngine
		? t('hermiq', 'Transcribed and spoken on this server,')
		: t('hermiq', 'Transcribed on this server,')

	return `${languages} ${local} ${translated}`
}
