<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  SpeechVoiceMapModal: the administrator's map from a language to a voice of
  the configured speech model (chat-translate-a-recording D3). A translated
  recording is spoken with the voice mapped to its target language; a language
  without a voice gets a written translation only. Voice ids are a model's own
  naming, so they are typed in, never guessed.

  Emits `save` with the edited map; the parent stores it through the admin API.
-->
<template>
	<NcModal
		:show="show"
		size="normal"
		:name="t('hermiq', 'Voices per language')"
		@close="$emit('close')">
		<div class="voice-map" data-testid="voice-map-form">
			<h2 class="voice-map__title">
				{{ t('hermiq', 'Voices per language') }}
			</h2>
			<p class="voice-map__help">
				{{ t('hermiq', 'Enter a language code, such as fr, and the id of a voice of the speech model installed on this server, such as ff_siwis.') }}
			</p>

			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>

			<ul class="voice-map__rows">
				<li
					v-for="(row, index) in rows"
					:key="index"
					class="voice-map__row"
					data-testid="voice-map-row">
					<NcTextField
						v-model="row.language"
						:label="t('hermiq', 'Language code')"
						:disabled="saving" />
					<NcTextField
						v-model="row.voice"
						:label="t('hermiq', 'Voice id')"
						:disabled="saving" />
					<NcButton
						variant="tertiary"
						:aria-label="t('hermiq', 'Remove this language')"
						:disabled="saving"
						@click="rows.splice(index, 1)">
						<template #icon>
							<Delete :size="20" />
						</template>
					</NcButton>
				</li>
			</ul>

			<NcButton
				data-testid="voice-map-add"
				:disabled="saving"
				@click="rows.push({ language: '', voice: '' })">
				<template #icon>
					<Plus :size="20" />
				</template>
				{{ t('hermiq', 'Add a language') }}
			</NcButton>

			<div class="voice-map__actions">
				<NcButton :disabled="saving" @click="$emit('close')">
					{{ t('hermiq', 'Cancel') }}
				</NcButton>
				<NcButton
					variant="primary"
					data-testid="voice-map-save"
					:disabled="saving"
					@click="$emit('save', asMap())">
					<template v-if="saving" #icon>
						<NcLoadingIcon :size="20" />
					</template>
					{{ t('hermiq', 'Save voices') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import {
	NcButton,
	NcLoadingIcon,
	NcModal,
	NcNoteCard,
	NcTextField,
} from '@nextcloud/vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import Plus from 'vue-material-design-icons/Plus.vue'

export default {
	name: 'SpeechVoiceMapModal',

	components: {
		Delete,
		NcButton,
		NcLoadingIcon,
		NcModal,
		NcNoteCard,
		NcTextField,
		Plus,
	},

	props: {
		/** Whether the modal is visible. */
		show: {
			type: Boolean,
			default: false,
		},

		/** The stored map: voice id per language code. */
		voices: {
			type: Object,
			default: () => ({}),
		},

		/** Whether the parent is saving. */
		saving: {
			type: Boolean,
			default: false,
		},

		/** The parent's save error, if any. */
		error: {
			type: String,
			default: '',
		},
	},

	emits: ['close', 'save'],

	data() {
		return {
			rows: [],
		}
	},

	watch: {
		/**
		 * Start from the stored map each time the modal opens.
		 *
		 * @param {boolean} open Whether the modal is now shown.
		 * @spec openspec/changes/chat-translate-a-recording/tasks.md#task-4-the-voice-map-in-admin-settings
		 */
		show(open) {
			if (open) {
				this.rows = Object.entries(this.voices || {}).map(([language, voice]) => ({ language, voice }))
				if (this.rows.length === 0) {
					this.rows.push({ language: '', voice: '' })
				}
			}
		},
	},

	methods: {
		/**
		 * The rows as a map, leaving out empty rows.
		 *
		 * @return {Record<string, string>} Voice id per language code.
		 * @spec openspec/changes/chat-translate-a-recording/tasks.md#task-4-the-voice-map-in-admin-settings
		 */
		asMap() {
			const map = {}
			for (const row of this.rows) {
				const language = String(row.language || '').trim()
				const voice = String(row.voice || '').trim()
				if (language !== '' && voice !== '') {
					map[language] = voice
				}
			}
			return map
		},
	},
}
</script>

<style scoped>
.voice-map {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 20px;
}

.voice-map__title {
	margin: 0 0 4px;
	font-size: 20px;
	font-weight: 600;
}

.voice-map__help {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.voice-map__rows {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin: 0;
	padding: 0;
	list-style: none;
}

.voice-map__row {
	display: flex;
	align-items: flex-end;
	gap: 8px;
}

.voice-map__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
}
</style>
