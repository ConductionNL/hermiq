<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  DictateButton: the microphone beside the chat composer's send button
  (chat-speak-and-listen). Press to record, press again to stop; the recording
  goes to hermiq's own transcription endpoint and the words are emitted as
  `transcript` for the page to put in the message box. It never sends a
  message and never uses a browser speech engine. The logic lives in
  src/utils/speech.js (createDictation), tested in tests/chat-speech.spec.js.

  @spec openspec/changes/chat-speak-and-listen/specs/speech-services/spec.md#requirement-the-chat-page-offers-dictation-req-chvoice-001
-->
<template>
	<NcButton
		variant="tertiary"
		:disabled="disabled || state === 'transcribing'"
		:aria-label="label"
		:title="label"
		:pressed="state === 'listening'"
		@click="toggle">
		<template #icon>
			<NcLoadingIcon v-if="state === 'transcribing'" :size="20" />
			<Microphone v-else-if="state === 'listening'" :size="20" />
			<MicrophoneOutline v-else :size="20" />
		</template>
	</NcButton>
</template>

<script>
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import Microphone from 'vue-material-design-icons/Microphone.vue'
import MicrophoneOutline from 'vue-material-design-icons/MicrophoneOutline.vue'
import { transcribe } from '../api/speech.js'
import { createDictation } from '../utils/speech.js'

/**
 * Start recording from the microphone.
 *
 * @return {Promise<{stop: function(): Promise<Blob>}>} The running recording.
 */
async function startRecording() {
	const stream = await navigator.mediaDevices.getUserMedia({ audio: true })
	const recorder = new MediaRecorder(stream)
	const chunks = []
	recorder.ondataavailable = (event) => {
		if (event.data && event.data.size > 0) {
			chunks.push(event.data)
		}
	}
	recorder.start()
	return {
		stop: () =>
			new Promise((resolve) => {
				recorder.onstop = () => {
					stream.getTracks().forEach((track) => track.stop())
					resolve(
						new Blob(chunks, {
							type: recorder.mimeType || 'audio/webm',
						}),
					)
				}
				recorder.stop()
			}),
	}
}

export default {
	name: 'DictateButton',

	components: {
		Microphone,
		MicrophoneOutline,
		NcButton,
		NcLoadingIcon,
	},

	props: {
		disabled: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['transcript', 'error'],

	data() {
		return {
			state: 'idle',
		}
	},

	computed: {
		label() {
			if (this.state === 'listening') {
				return this.t('hermiq', 'Listening, press to stop')
			}
			if (this.state === 'transcribing') {
				return this.t('hermiq', 'Transcribing')
			}
			return this.t('hermiq', 'Dictate a message')
		},
	},

	created() {
		this.dictation = createDictation({
			startRecording,
			transcribe: (blob) => transcribe(blob),
			onTranscript: (words) => this.$emit('transcript', words),
			onState: (state) => {
				this.state = state
			},
			onError: (kind) =>
				this.$emit(
					'error',
					kind === 'refused'
						? this.t('hermiq', 'Microphone access was refused.')
						: this.t('hermiq', 'The speech service is unavailable.'),
				),
		})
	},

	methods: {
		/**
		 * Start or stop the recording.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/chat-speak-and-listen/specs/speech-services/spec.md#requirement-the-chat-page-offers-dictation-req-chvoice-001
		 */
		toggle() {
			return this.dictation.toggle()
		},
	},
}
</script>
