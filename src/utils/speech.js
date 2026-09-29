// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The chat page's speech logic, kept free of Vue and of the browser so it can
// be tested in plain node (tests/chat-speech.spec.js): which controls the
// agent's speech policy allows, how a transcript joins what was typed, the
// dictation state machine, and the one shared "Read aloud" player.

/**
 * Which speech controls the chat page shows for an agent.
 *
 * An engine of `off` hides its control. Every other value (`auto`, `browser`,
 * `local`, or none set) shows it, served by the on-instance engine: this page
 * never hands audio to a browser engine. Neither shows when the speech service
 * does not answer, and dictation needs a browser that can record.
 *
 * @param {object|null} agent The chat's agent.
 * @param {{available: boolean}|null} capabilities The speech service answer.
 * @param {boolean} canRecord Whether the browser offers MediaRecorder.
 * @return {{dictate: boolean, readAloud: boolean}} The controls to show.
 * @spec openspec/changes/chat-speak-and-listen/specs/speech-services/spec.md#requirement-the-chat-page-follows-the-agents-speech-policy-req-chvoice-003
 */
export function speechControls(agent, capabilities, canRecord) {
	if (!agent || capabilities?.available !== true) {
		return { dictate: false, readAloud: false }
	}
	return {
		dictate: canRecord === true && agent.voiceInputEngine !== 'off',
		readAloud: agent.voiceOutputEngine !== 'off',
	}
}

/**
 * Put a transcript after what was already typed.
 *
 * @param {string} current The message box content.
 * @param {string} transcript The dictated words.
 * @return {string} The new message box content.
 * @spec openspec/changes/chat-speak-and-listen/specs/speech-services/spec.md#requirement-the-chat-page-offers-dictation-req-chvoice-001
 */
export function appendTranscript(current, transcript) {
	const words = (transcript || '').trim()
	if (!words) {
		return current
	}
	const before = (current || '').replace(/\s+$/, '')
	return before ? `${before} ${words}` : words
}

/**
 * Reduce an answer's markdown to the text a listener should hear.
 *
 * @param {string} markdown The answer as stored.
 * @return {string} Plain text.
 * @spec openspec/changes/chat-speak-and-listen/specs/speech-services/spec.md#requirement-the-chat-page-reads-answers-aloud-req-chvoice-002
 */
export function plainTextForSpeech(markdown) {
	return (markdown || '')
		.replace(/```[\s\S]*?```/g, ' ')
		.replace(/`([^`]*)`/g, '$1')
		.replace(/!\[([^\]]*)\]\([^)]*\)/g, '$1')
		.replace(/\[([^\]]*)\]\([^)]*\)/g, '$1')
		.replace(/^\s{0,3}(#{1,6}|>|[-*+]|\d+\.)\s+/gm, '')
		.replace(/(\*\*|__|\*|_|~~)(.+?)\1/g, '$2')
		.replace(/<[^>]+>/g, '')
		.replace(/\s+/g, ' ')
		.trim()
}

/**
 * The dictation state machine behind the microphone button.
 *
 * States: `idle`, `listening`, `transcribing`. Stopping hands the recording to
 * `transcribe`; the transcript goes to `onTranscript` and nowhere else, so
 * dictation never sends a message. A failure goes to `onError` and leaves the
 * message box alone.
 *
 * @param {object} deps The collaborators.
 * @param {function(): Promise<object>} deps.startRecording Starts a recorder; resolves to `{stop(): Promise<Blob>}`.
 * @param {function(Blob): Promise<string>} deps.transcribe Speech to text.
 * @param {function(string): void} deps.onTranscript Receives the words.
 * @param {function(string): void} deps.onState Receives each state.
 * @param {function(string): void} deps.onError Receives the error kind: `refused` or `unavailable`.
 * @return {{toggle: function(): Promise<void>, state: function(): string}} The machine.
 * @spec openspec/changes/chat-speak-and-listen/specs/speech-services/spec.md#requirement-the-chat-page-offers-dictation-req-chvoice-001
 */
export function createDictation(deps) {
	let state = 'idle'
	let recording = null
	const set = (next) => {
		state = next
		deps.onState(next)
	}
	return {
		state: () => state,
		async toggle() {
			if (state === 'transcribing') {
				return
			}
			if (state === 'idle') {
				try {
					recording = await deps.startRecording()
				} catch {
					deps.onError('refused')
					return
				}
				set('listening')
				return
			}
			set('transcribing')
			try {
				const blob = await recording.stop()
				recording = null
				deps.onTranscript(await deps.transcribe(blob))
			} catch {
				deps.onError('unavailable')
			} finally {
				set('idle')
			}
		},
	}
}

/**
 * One player for every "Read aloud" button on the page, so starting an answer
 * stops the one that was playing.
 *
 * @param {object} deps The collaborators.
 * @param {function(string): Promise<Blob>} deps.synthesise Text to speech.
 * @param {function(Blob): object} deps.createAudio Receives a Blob, returns an element with play(), pause() and onended; wraps the audio for playing.
 * @param {function(?string): void} deps.onChange Receives the key now playing, or null.
 * @return {{toggle: function(string, string): Promise<void>, stop: function(): void, playing: function(): ?string}} The player.
 * @spec openspec/changes/chat-speak-and-listen/specs/speech-services/spec.md#requirement-the-chat-page-reads-answers-aloud-req-chvoice-002
 */
export function createSpeaker(deps) {
	let current = null
	let audio = null
	const stop = () => {
		if (audio) {
			audio.pause()
			audio.onended = null
		}
		audio = null
		current = null
		deps.onChange(null)
	}
	return {
		playing: () => current,
		stop,
		async toggle(key, text) {
			const wasPlaying = current === key
			stop()
			if (wasPlaying) {
				return
			}
			current = key
			deps.onChange(key)
			let blob
			try {
				blob = await deps.synthesise(text)
			} catch (e) {
				stop()
				throw e
			}
			if (current !== key) {
				return
			}
			audio = deps.createAudio(blob)
			audio.onended = stop
			await audio.play()
		},
	}
}
