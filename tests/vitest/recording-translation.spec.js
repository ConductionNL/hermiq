/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Translate a recording (chat-translate-a-recording): the language names, the
 * target options with "text only" for a language without a voice, and the line
 * under a result naming the languages and where each step ran.
 */

import { describe, expect, it } from 'vitest'
import {
	languageName,
	targetLanguageOptions,
	translationLine,
} from '../../src/utils/recordingTranslation.js'

describe('languageName', () => {
	it('names a language in the reader\'s language, capitalised', () => {
		expect(languageName('ar', 'nl')).toBe('Arabisch')
		expect(languageName('nl', 'nl')).toBe('Nederlands')
		expect(languageName('fr', 'en')).toBe('French')
	})

	it('falls back to the code for no code or an unknown one', () => {
		expect(languageName('', 'nl')).toBe('')
		expect(languageName('not a code', 'nl')).toBe('not a code')
	})
})

describe('targetLanguageOptions', () => {
	it('marks a language without a voice as text only and keeps a voiced one plain', () => {
		const options = targetLanguageOptions(['fr', 'en-GB'], 'en')
		const byId = Object.fromEntries(options.map((option) => [option.id, option]))

		expect(byId.fr).toEqual({ id: 'fr', spoken: true, label: 'French' })
		expect(byId.en.spoken).toBe(true)
		expect(byId.nl).toEqual({ id: 'nl', spoken: false, label: 'Dutch (text only)' })
	})

	it('sorts the options by their label', () => {
		const labels = targetLanguageOptions([], 'en').map((option) => option.label)
		expect(labels).toEqual([...labels].sort((a, b) => a.localeCompare(b, 'en')))
	})
})

describe('translationLine', () => {
	it('reads as the spec\'s line for a detected Arabic recording translated on the server', () => {
		const record = {
			sourceLanguage: '',
			detectedLanguage: 'ar',
			targetLanguage: 'nl',
			translationProvider: 'ollama',
			translationModel: 'qwen3:8b',
			translationResidency: 'on-premise',
			ttsEngine: 'kokoro',
		}

		expect(translationLine(record, 'nl')).toBe(
			'Arabisch (detected) to Nederlands. Transcribed and spoken on this server, translated by Qwen3 on this server.',
		)
	})

	it('names a given source language, a hosted model and an unspoken result', () => {
		const record = {
			sourceLanguage: 'en',
			detectedLanguage: 'en',
			targetLanguage: 'fr',
			translationProvider: 'openai',
			translationModel: 'gpt-4o',
			translationResidency: 'outside-eu',
			ttsEngine: '',
		}

		expect(translationLine(record, 'en')).toBe(
			'English to French. Transcribed on this server, translated by Gpt-4o outside the EU.',
		)
	})

	it('is empty without a record', () => {
		expect(translationLine(null)).toBe('')
		expect(translationLine({})).toBe('')
	})
})
