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
 * @spec openspec/changes/chat-speak-and-listen/specs/speech-services/spec.md#requirement-the-chat-page-follows-the-agents-speech-policy-req-chvoice-003
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
 * @spec openspec/changes/chat-speak-and-listen/specs/speech-services/spec.md#requirement-the-chat-page-offers-dictation-req-chvoice-001
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
 * @spec openspec/changes/chat-speak-and-listen/specs/speech-services/spec.md#requirement-the-chat-page-reads-answers-aloud-req-chvoice-002
 */
export async function synthesise(text, http = axios) {
	const { data } = await http.post(
		generateUrl(`${BASE}/speech`),
		{ text },
		{ responseType: 'blob' },
	)
	return data
}
