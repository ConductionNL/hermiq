<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  ReadAloudButton: "Read aloud" on an answer in the chat (chat-speak-and-listen).
  Every button on the page shares one player, so starting an answer stops the
  one that was playing and pressing the playing one again stops it. The text
  goes to hermiq's own speech endpoint, never to a browser speech engine. The
  logic lives in src/utils/speech.js (createSpeaker), tested in
  tests/chat-speech.spec.js.

  @spec openspec/changes/chat-speak-and-listen/specs/speech-services/spec.md#requirement-the-chat-page-reads-answers-aloud-req-chvoice-002
-->
<template>
	<NcButton variant="tertiary" :aria-label="label" :title="label" @click="toggle">
		<template #icon>
			<StopIcon v-if="playing" :size="16" />
			<VolumeHigh v-else :size="16" />
		</template>
	</NcButton>
</template>

<script>
import { showError } from '@nextcloud/dialogs'
import { NcButton } from '@nextcloud/vue'
import { reactive } from 'vue'
import StopIcon from 'vue-material-design-icons/Stop.vue'
import VolumeHigh from 'vue-material-design-icons/VolumeHigh.vue'
import { synthesise } from '../api/speech.js'
import { createSpeaker, plainTextForSpeech } from '../utils/speech.js'

// One player for the whole page: the key of the answer now playing.
const shared = reactive({ playing: null })
const speaker = createSpeaker({
	synthesise: (text) => synthesise(text),
	createAudio: (blob) => {
		const url = URL.createObjectURL(blob)
		const audio = new Audio(url)
		audio.addEventListener('pause', () => URL.revokeObjectURL(url), {
			once: true,
		})
		return audio
	},
	onChange: (key) => {
		shared.playing = key
	},
})

export default {
	name: 'ReadAloudButton',

	components: {
		NcButton,
		StopIcon,
		VolumeHigh,
	},

	props: {
		messageKey: {
			type: String,
			required: true,
		},

		text: {
			type: String,
			required: true,
		},
	},

	computed: {
		/**
		 * Whether this answer is the one being read aloud.
		 *
		 * @return {boolean} True while it plays.
		 *
		 * @spec openspec/specs/speech-services/spec.md#requirement-the-chat-page-reads-answers-aloud-req-chvoice-002
		 */
		playing() {
			return shared.playing === this.messageKey
		},

		/**
		 * The button label: read aloud, or stop reading.
		 *
		 * @return {string} The label.
		 *
		 * @spec openspec/specs/speech-services/spec.md#requirement-the-chat-page-reads-answers-aloud-req-chvoice-002
		 */
		label() {
			return this.playing
				? this.t('hermiq', 'Stop reading')
				: this.t('hermiq', 'Read aloud')
		},
	},

	/**
	 * Stop reading when the answer leaves the page.
	 *
	 * @return {void}
	 *
	 * @spec openspec/specs/speech-services/spec.md#requirement-the-chat-page-reads-answers-aloud-req-chvoice-002
	 */
	beforeUnmount() {
		if (this.playing) {
			speaker.stop()
		}
	},

	methods: {
		/**
		 * Speak the answer, or stop it when it is the one playing.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/chat-speak-and-listen/specs/speech-services/spec.md#requirement-the-chat-page-reads-answers-aloud-req-chvoice-002
		 */
		async toggle() {
			try {
				await speaker.toggle(this.messageKey, plainTextForSpeech(this.text))
			} catch (e) {
				showError(
					e?.response?.status === 400
						? this.t('hermiq', 'This answer is too long to read aloud.')
						: this.t('hermiq', 'The speech service is unavailable.'),
				)
			}
		},
	},
}
</script>
