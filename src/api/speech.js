// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Stateless helpers for hermiq's own speech endpoints (SpeechController):
// whether the on-instance speech service answers, speech to text, and text to
// speech. The chat page uses them for dictation and "Read aloud". The
// companion in nextcloud-vue has its own client; this module is the chat
// page's. The `http` argument exists so the request shape can be asserted
// without a browser (tests/chat-speech.spec.js); callers leave it out.

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const BASE = '/apps/hermiq/api/speech'

/**
 * Ask whether the on-instance speech service is reachable.
 *
 * Any failure reads as unavailable: a control the page cannot back is not shown.
 *
 * @param {object} [http] The HTTP client (axios by default).
 * @return {Promise<{available: boolean}>} The capability answer.
 * @spec openspec/specs/speech-services/spec.md#requirement-the-chat-page-follows-the-agents-speech-policy-req-chvoice-003
 */
export async function speechCapabilities(http = axios) {
	try {
		const { data } = await http.get(generateUrl(`${BASE}/capabilities`))
		return { available: data?.available === true }
	} catch {
		return { available: false }
	}
}

/**
 * Turn a recording into text.
 *
 * @param {Blob} blob The recorded audio.
 * @param {string} [language] An optional language hint, for example "nl".
 * @param {object} [http] The HTTP client (axios by default).
 * @return {Promise<string>} The transcript.
 * @spec openspec/specs/speech-services/spec.md#requirement-the-chat-page-offers-dictation-req-chvoice-001
 */
export async function transcribe(blob, language = '', http = axios) {
	const form = new FormData()
	form.append('audio', blob, 'dictation.webm')
	if (language) {
		form.append('language', language)
	}
	const { data } = await http.post(generateUrl(`${BASE}/transcriptions`), form)
	return typeof data?.text === 'string' ? data.text : ''
}

/**
 * Turn text into speech.
 *
 * @param {string} text The plain text to speak.
 * @param {object} [http] The HTTP client (axios by default).
 * @return {Promise<Blob>} WAV audio.
 * @spec openspec/specs/speech-services/spec.md#requirement-the-chat-page-reads-answers-aloud-req-chvoice-002
 */
export async function synthesise(text, http = axios) {
	const { data } = await http.post(
		generateUrl(`${BASE}/speech`),
		{ text },
		{ responseType: 'blob' },
	)
	return data
}

/**
 * Where the translation of a recording will run, and which languages can be spoken.
 *
 * @param {object} [http] The HTTP client (axios by default).
 * @return {Promise<{enabled: boolean, provider: string, model: string, residency: string, location: string, refusal: string, voices: string[]}>} The answer.
 * @spec openspec/changes/chat-translate-a-recording/specs/speech-services/spec.md#requirement-the-translation-step-follows-the-features-provider-rules-req-strn-003
 */
export async function translationWhere(http = axios) {
	const { data } = await http.get(generateUrl(`${BASE}/translations/where`))
	return {
		enabled: data?.enabled === true,
		provider: String(data?.provider || ''),
		model: String(data?.model || ''),
		residency: String(data?.residency || ''),
		location: String(data?.location || ''),
		refusal: String(data?.refusal || ''),
		voices: Array.isArray(data?.voices) ? data.voices.map(String) : [],
	}
}

/**
 * Translate a recording: a file in the person's Files, or a recording they just made.
 *
 * @param {object} request The request.
 * @param {string} request.targetLanguage The language to translate into.
 * @param {string} [request.sourceLanguage] The language of the recording, or '' to detect it.
 * @param {number} [request.fileId] A recording in the person's Files.
 * @param {Blob} [request.blob] A recording made in the browser, when no file id is given.
 * @param {string} [request.sessionId] The chat session to add the result to.
 * @param {object} [http] The HTTP client (axios by default).
 * @return {Promise<object>} The transcript, translation, file, record and, with a session, the two turns.
 * @spec openspec/changes/chat-translate-a-recording/specs/speech-services/spec.md#requirement-a-person-can-translate-a-recording-and-hear-the-result-req-strn-001
 */
export async function translateRecording({ targetLanguage, sourceLanguage = '', fileId = 0, blob = null, sessionId = '' }, http = axios) {
	const form = new FormData()
	form.append('targetLanguage', targetLanguage)
	if (sourceLanguage) {
		form.append('sourceLanguage', sourceLanguage)
	}
	if (sessionId) {
		form.append('sessionId', sessionId)
	}
	if (fileId > 0) {
		form.append('fileId', String(fileId))
	} else if (blob) {
		form.append('audio', blob, 'recording.webm')
	}
	const { data } = await http.post(generateUrl(`${BASE}/translations`), form)
	return data
}

/**
 * The administrator's voice per language.
 *
 * @param {object} [http] The HTTP client (axios by default).
 * @return {Promise<Record<string, string>>} Voice id per language code.
 * @spec openspec/changes/chat-translate-a-recording/tasks.md#task-4-the-voice-map-in-admin-settings
 */
export async function getSpeechVoices(http = axios) {
	const { data } = await http.get(generateUrl('/apps/hermiq/api/settings/speech-voices'))
	return { ...(data?.voices || {}) }
}

/**
 * Replace the administrator's voice per language.
 *
 * @param {Record<string, string>} voices Voice id per language code.
 * @param {object} [http] The HTTP client (axios by default).
 * @return {Promise<Record<string, string>>} The stored map.
 * @spec openspec/changes/chat-translate-a-recording/tasks.md#task-4-the-voice-map-in-admin-settings
 */
export async function saveSpeechVoices(voices, http = axios) {
	const { data } = await http.put(generateUrl('/apps/hermiq/api/settings/speech-voices'), { voices })
	return { ...(data?.voices || {}) }
}
