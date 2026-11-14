#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// chat-speech.spec.js — dictation and "Read aloud" on hermiq's chat page
// (chat-speak-and-listen): the request shapes against SpeechController, the
// agent's speech policy, the dictation state machine and the shared player,
// plus the wiring in Chat.vue that places them.
//
// Usage:
//   node tests/chat-speech.spec.js
//
// Exit codes:
//   0 — every assertion holds.
//   1 — one or more assertions failed.
//
// @spec openspec/changes/chat-speak-and-listen/specs/speech-services/spec.md#requirement-the-chat-page-offers-dictation-req-chvoice-001
// @spec openspec/changes/chat-speak-and-listen/specs/speech-services/spec.md#requirement-the-chat-page-reads-answers-aloud-req-chvoice-002
// @spec openspec/changes/chat-speak-and-listen/specs/speech-services/spec.md#requirement-the-chat-page-follows-the-agents-speech-policy-req-chvoice-003

'use strict'

const assert = require('assert')
const fs = require('fs')
const path = require('path')
const { pathToFileURL } = require('url')

const ROOT = path.resolve(__dirname, '..')
const read = (...parts) => fs.readFileSync(path.join(ROOT, ...parts), 'utf8')

const failures = []

/**
 * Run one named check and record its failure instead of stopping.
 *
 * @param {string} name What the check proves.
 * @param {function(): Promise<void>|void} fn The assertions.
 */
async function check(name, fn) {
	try {
		await fn()
		console.log(`  ok   ${name}`)
	} catch (e) {
		failures.push(name)
		console.log(
			`  FAIL ${name}\n       ${String(e.message).split('\n').join('\n       ')}`,
		)
	}
}

/**
 * A recording HTTP client standing in for axios.
 *
 * @param {object} answer The response body to return.
 * @return {object} The fake client with its `calls`.
 */
function fakeHttp(answer) {
	const calls = []
	const respond = (verb) => async (url, body, config) => {
		calls.push({ verb, url, body, config })
		if (answer instanceof Error) {
			throw answer
		}
		return { data: answer }
	}
	return { calls, get: respond('get'), post: respond('post') }
}

/**
 * Load the modules under test and run every check.
 */
async function main() {
	globalThis.window ??= globalThis
	globalThis.OC ??= { webroot: '', config: { modRewriteWorking: true } }
	globalThis._oc_webroot ??= ''
	const api = await import(
		pathToFileURL(path.join(ROOT, 'src', 'api', 'speech.js')).href
	)
	const speech = await import(
		pathToFileURL(path.join(ROOT, 'src', 'utils', 'speech.js')).href
	)

	await check(
		'transcribe() posts the recording as multipart field "audio" to the transcription route',
		async () => {
			const http = fakeHttp({
				text: 'Which permits expire this month',
				language: 'en',
			})
			const blob = new Blob(['x'], { type: 'audio/webm' })
			const text = await api.transcribe(blob, 'en', http)
			assert.strictEqual(text, 'Which permits expire this month')
			assert.strictEqual(http.calls.length, 1)
			assert.strictEqual(http.calls[0].verb, 'post')
			assert.match(
				http.calls[0].url,
				/\/apps\/hermiq\/api\/speech\/transcriptions$/,
			)
			assert.ok(
				http.calls[0].body instanceof FormData,
				'the body is multipart',
			)
			assert.ok(
				http.calls[0].body.get('audio') instanceof Blob,
				'the recording is field "audio"',
			)
			assert.strictEqual(http.calls[0].body.get('language'), 'en')
		},
	)

	await check(
		'synthesise() posts the text to the speech route and asks for a blob',
		async () => {
			const http = fakeHttp(new Blob(['wav']))
			await api.synthesise('Two permits expire.', http)
			assert.match(http.calls[0].url, /\/apps\/hermiq\/api\/speech\/speech$/)
			assert.deepStrictEqual(http.calls[0].body, {
				text: 'Two permits expire.',
			})
			assert.strictEqual(http.calls[0].config.responseType, 'blob')
		},
	)

	await check(
		'speechCapabilities() reads "available" and treats a failure as unavailable',
		async () => {
			assert.deepStrictEqual(
				await api.speechCapabilities(
					fakeHttp({ available: true, reason: '' }),
				),
				{ available: true },
			)
			assert.deepStrictEqual(
				await api.speechCapabilities(fakeHttp({ available: false })),
				{ available: false },
			)
			assert.deepStrictEqual(
				await api.speechCapabilities(fakeHttp(new Error('502'))),
				{ available: false },
			)
		},
	)

	const up = { available: true }
	await check(
		'an agent with dictation off shows no microphone (REQ-CHVOICE-003)',
		() => {
			const agent = {
				name: 'Permit helper',
				voiceInputEngine: 'off',
				voiceOutputEngine: 'local',
			}
			assert.deepStrictEqual(speech.speechControls(agent, up, true), {
				dictate: false,
				readAloud: true,
			})
		},
	)
	await check(
		'an agent with spoken replies off shows no "Read aloud" (REQ-CHVOICE-003)',
		() => {
			const agent = { voiceInputEngine: 'local', voiceOutputEngine: 'off' }
			assert.deepStrictEqual(speech.speechControls(agent, up, true), {
				dictate: true,
				readAloud: false,
			})
		},
	)
	await check(
		'a speech service that does not answer shows neither control (REQ-CHVOICE-003)',
		() => {
			const agent = { voiceInputEngine: 'local', voiceOutputEngine: 'local' }
			assert.deepStrictEqual(
				speech.speechControls(agent, { available: false }, true),
				{ dictate: false, readAloud: false },
			)
			assert.deepStrictEqual(speech.speechControls(agent, null, true), {
				dictate: false,
				readAloud: false,
			})
		},
	)
	await check(
		'auto, browser and an unset engine all show the controls; no recorder, no microphone',
		() => {
			for (const engine of ['auto', 'browser', 'local', undefined]) {
				const agent = { voiceInputEngine: engine, voiceOutputEngine: engine }
				assert.deepStrictEqual(
					speech.speechControls(agent, up, true),
					{ dictate: true, readAloud: true },
					String(engine),
				)
			}
			assert.strictEqual(speech.speechControls({}, up, false).dictate, false)
		},
	)

	await check(
		'a person dictates a question: the words land in the message box and nothing is sent (REQ-CHVOICE-001)',
		async () => {
			const states = []
			let box = ''
			const dictation = speech.createDictation({
				startRecording: async () => ({ stop: async () => new Blob(['a']) }),
				transcribe: async () => 'Which permits expire this month',
				onTranscript: (words) => {
					box = speech.appendTranscript(box, words)
				},
				onState: (s) => states.push(s),
				onError: () => {
					throw new Error('no error expected')
				},
			})
			await dictation.toggle()
			assert.strictEqual(dictation.state(), 'listening')
			await dictation.toggle()
			assert.deepStrictEqual(states, ['listening', 'transcribing', 'idle'])
			assert.strictEqual(box, 'Which permits expire this month')
			assert.strictEqual(
				speech.appendTranscript('Hello ', 'there'),
				'Hello there',
			)
		},
	)

	await check(
		'a recording that cannot be transcribed keeps what was typed and reports the service (REQ-CHVOICE-001)',
		async () => {
			let box = 'Typed before'
			const errors = []
			const dictation = speech.createDictation({
				startRecording: async () => ({ stop: async () => new Blob(['a']) }),
				transcribe: async () => {
					throw new Error('502')
				},
				onTranscript: (words) => {
					box = speech.appendTranscript(box, words)
				},
				onState: () => {},
				onError: (kind) => errors.push(kind),
			})
			await dictation.toggle()
			await dictation.toggle()
			assert.deepStrictEqual(errors, ['unavailable'])
			assert.strictEqual(box, 'Typed before')
			assert.strictEqual(dictation.state(), 'idle')
		},
	)

	await check('a refused microphone reports it and stays idle', async () => {
		const errors = []
		const dictation = speech.createDictation({
			startRecording: async () => {
				throw new Error('NotAllowedError')
			},
			transcribe: async () => '',
			onTranscript: () => {},
			onState: () => {},
			onError: (kind) => errors.push(kind),
		})
		await dictation.toggle()
		assert.deepStrictEqual(errors, ['refused'])
		assert.strictEqual(dictation.state(), 'idle')
	})

	await check(
		'a person listens to an answer; pressing again, or another answer, stops it (REQ-CHVOICE-002)',
		async () => {
			const spoken = []
			const audios = []
			const changes = []
			const speaker = speech.createSpeaker({
				synthesise: async (text) => {
					spoken.push(text)
					return new Blob(['wav'])
				},
				createAudio: () => {
					const a = {
						paused: false,
						play: async () => {},
						pause() {
							this.paused = true
						},
						onended: null,
					}
					audios.push(a)
					return a
				},
				onChange: (key) => changes.push(key),
			})
			await speaker.toggle(
				'm1',
				speech.plainTextForSpeech('**Two** permits [expire](https://x).'),
			)
			assert.deepStrictEqual(spoken, ['Two permits expire.'])
			assert.strictEqual(speaker.playing(), 'm1')
			await speaker.toggle('m2', 'Another answer')
			assert.strictEqual(audios[0].paused, true, 'the first answer stopped')
			assert.strictEqual(speaker.playing(), 'm2')
			await speaker.toggle('m2', 'Another answer')
			assert.strictEqual(audios[1].paused, true, 'pressing again stops it')
			assert.strictEqual(speaker.playing(), null)
			assert.strictEqual(changes.at(-1), null)
		},
	)

	await check(
		'Chat.vue places the microphone in the composer and "Read aloud" on answers, gated by the policy',
		() => {
			const chat = read('src', 'views', 'Chat.vue')
			assert.ok(
				/<DictateButton[^>]*?v-if="speechControls\.dictate"/.test(chat),
				'the composer has a DictateButton gated by speechControls.dictate',
			)
			assert.ok(
				/<ReadAloudButton[^>]*?v-if="[^"]*speechControls\.readAloud/.test(
					chat,
				),
				'answers have a ReadAloudButton gated by speechControls.readAloud',
			)
			assert.ok(
				/speechCapabilities\(\)/.test(chat),
				'the page asks the speech service once',
			)
			const dictate = read('src', 'components', 'DictateButton.vue')
			assert.match(dictate, /createDictation/)
			assert.doesNotMatch(
				dictate,
				/SpeechRecognition/,
				'the chat page never uses a browser speech engine',
			)
			assert.doesNotMatch(
				dictate,
				/handleSend|sendMessage/,
				'dictation never sends',
			)
			const readAloud = read('src', 'components', 'ReadAloudButton.vue')
			assert.doesNotMatch(
				readAloud,
				/speechSynthesis/,
				'the chat page never uses a browser speech engine',
			)
		},
	)

	if (failures.length > 0) {
		console.log(`\n${failures.length} check(s) failed`)
		process.exit(1)
	}
	console.log('\nall checks passed')
}

main().catch((e) => {
	console.error(e)
	process.exit(1)
})
