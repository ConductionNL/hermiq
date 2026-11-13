<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  SpeechVoiceSettings: which voice speaks a translated recording in each
  language (chat-translate-a-recording D3). Lists the stored map and opens
  SpeechVoiceMapModal to change it. Server data comes from the admin-gated API.

  @spec openspec/changes/chat-translate-a-recording/tasks.md#task-4-the-voice-map-in-admin-settings
-->
<template>
	<div class="speech-voices" data-testid="speech-voice-settings">
		<NcNoteCard v-if="loadError" type="error">
			{{ loadError }}
		</NcNoteCard>

		<p v-if="entries.length === 0" class="speech-voices__empty">
			{{ t('hermiq', 'No voices are set. A translated recording is shown as text only.') }}
		</p>
		<ul v-else class="speech-voices__list">
			<li v-for="[language, voice] in entries" :key="language">
				<strong>{{ language }}</strong>: {{ voice }}
			</li>
		</ul>

		<NcButton data-testid="speech-voice-edit" :disabled="loading" @click="open = true">
			{{ t('hermiq', 'Edit voices') }}
		</NcButton>

		<SpeechVoiceMapModal
			:show="open"
			:voices="voices"
			:saving="saving"
			:error="saveError"
			@close="open = false"
			@save="save" />
	</div>
</template>

<script>
import { NcButton, NcNoteCard } from '@nextcloud/vue'
import SpeechVoiceMapModal from '../../modals/SpeechVoiceMapModal.vue'
import { getSpeechVoices, saveSpeechVoices } from '../../api/speech.js'

export default {
	name: 'SpeechVoiceSettings',

	components: {
		NcButton,
		NcNoteCard,
		SpeechVoiceMapModal,
	},

	data() {
		return {
			voices: {},
			loading: true,
			loadError: '',
			open: false,
			saving: false,
			saveError: '',
		}
	},

	computed: {
		/**
		 * The map as sorted entries.
		 *
		 * @return {Array<[string, string]>} The entries.
		 */
		entries() {
			return Object.entries(this.voices).sort(([a], [b]) => a.localeCompare(b))
		},
	},

	async mounted() {
		try {
			this.voices = await getSpeechVoices()
		} catch {
			this.loadError = t('hermiq', 'The voices could not be loaded.')
		} finally {
			this.loading = false
		}
	},

	methods: {
		/**
		 * Store the edited map and close the modal; a refusal stays in the modal.
		 *
		 * @param {Record<string, string>} voices The edited map.
		 * @return {Promise<void>}
		 * @spec openspec/changes/chat-translate-a-recording/tasks.md#task-4-the-voice-map-in-admin-settings
		 */
		async save(voices) {
			this.saving = true
			this.saveError = ''
			try {
				this.voices = await saveSpeechVoices(voices)
				this.open = false
			} catch (e) {
				this.saveError = e?.response?.data?.error || t('hermiq', 'The voices could not be saved.')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.speech-voices {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: 8px;
}

.speech-voices__empty {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.speech-voices__list {
	margin: 0;
	padding: 0;
	list-style: none;
}
</style>
