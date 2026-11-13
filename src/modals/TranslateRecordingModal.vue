<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  TranslateRecordingModal: the chat action "Translate a recording"
  (chat-translate-a-recording D5). The person records with the microphone or
  chooses an audio file from Files, chooses the language to translate into,
  and starts. Before they start the modal says where the translation step will
  run; transcription and speech always run on this server.

  Posts to POST /apps/hermiq/api/speech/translations and emits `translated`
  with the two stored turns.
-->
<template>
	<NcModal
		:show="show"
		size="normal"
		:name="t('hermiq', 'Translate a recording')"
		@close="$emit('close')">
		<div class="translate-form" data-testid="translate-form">
			<h2 class="translate-form__title">
				{{ t('hermiq', 'Translate a recording') }}
			</h2>
			<p class="translate-form__help">
				{{
					t(
						'hermiq',
						'The recording is transcribed and the translation is spoken on this server. The spoken translation is saved in your Files under Hermiq/Translations and marked as made by an agent.',
					)
				}}
			</p>

			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>
			<NcNoteCard
				v-else-if="whereLine"
				:type="where.refusal || !where.enabled ? 'warning' : 'info'"
				data-testid="translate-where">
				{{ whereLine }}
			</NcNoteCard>

			<div class="translate-form__source">
				<NcButton
					data-testid="translate-choose-file"
					:disabled="busy || recording"
					@click="chooseFile">
					<template #icon>
						<FolderOutline :size="20" />
					</template>
					{{ t('hermiq', 'Choose from Files') }}
				</NcButton>
				<NcButton
					data-testid="translate-record"
					:disabled="busy"
					:aria-pressed="recording ? 'true' : 'false'"
					@click="toggleRecording">
					<template #icon>
						<Microphone v-if="recording" :size="20" />
						<MicrophoneOutline v-else :size="20" />
					</template>
					{{ recording ? t('hermiq', 'Stop recording') : t('hermiq', 'Record with the microphone') }}
				</NcButton>
				<span v-if="recordingLabel" class="translate-form__chosen" data-testid="translate-chosen">
					{{ recordingLabel }}
				</span>
			</div>

			<NcSelect
				v-model="target"
				data-testid="translate-target"
				:options="targetOptions"
				:inputLabel="t('hermiq', 'Translate into')"
				label="label"
				:clearable="false"
				:disabled="busy" />

			<NcSelect
				v-model="source"
				data-testid="translate-source"
				:options="sourceOptions"
				:inputLabel="t('hermiq', 'Language of the recording')"
				label="label"
				:clearable="false"
				:disabled="busy" />

			<div class="translate-form__actions">
				<NcButton :disabled="busy" @click="$emit('close')">
					{{ t('hermiq', 'Cancel') }}
				</NcButton>
				<NcButton
					variant="primary"
					data-testid="translate-start"
					:disabled="!canStart"
					@click="start">
					<template v-if="busy" #icon>
						<NcLoadingIcon :size="20" />
					</template>
					{{ t('hermiq', 'Translate') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { getFilePickerBuilder } from '@nextcloud/dialogs'
import { getLanguage } from '@nextcloud/l10n'
import {
	NcButton,
	NcLoadingIcon,
	NcModal,
	NcNoteCard,
	NcSelect,
} from '@nextcloud/vue'
import FolderOutline from 'vue-material-design-icons/FolderOutline.vue'
import Microphone from 'vue-material-design-icons/Microphone.vue'
import MicrophoneOutline from 'vue-material-design-icons/MicrophoneOutline.vue'
import { translateRecording, translationWhere } from '../api/speech.js'
import {
	languageName,
	targetLanguageOptions,
	TRANSLATION_LANGUAGES,
} from '../utils/recordingTranslation.js'

export default {
	name: 'TranslateRecordingModal',

	components: {
		FolderOutline,
		Microphone,
		MicrophoneOutline,
		NcButton,
		NcLoadingIcon,
		NcModal,
		NcNoteCard,
		NcSelect,
	},

	props: {
		/** Whether the modal is visible. */
		show: {
			type: Boolean,
			default: false,
		},

		/** The session the result is added to. */
		session: {
			type: Object,
			default: null,
		},
	},

	emits: ['close', 'translated'],

	data() {
		return {
			where: { enabled: false, provider: '', model: '', residency: '', location: '', refusal: '', voices: [] },
			target: null,
			source: null,
			file: null,
			blob: null,
			recorder: null,
			recording: false,
			busy: false,
			error: '',
		}
	},

	computed: {
		/**
		 * The reader's language.
		 *
		 * @return {string} The language code.
		 */
		locale() {
			try {
				return getLanguage() || 'en'
			} catch {
				return 'en'
			}
		},

		/**
		 * The target options, "text only" where no voice is mapped.
		 *
		 * @return {Array<{id: string, label: string, spoken: boolean}>} The options.
		 */
		targetOptions() {
			return targetLanguageOptions(this.where.voices, this.locale)
		},

		/**
		 * The source options: detect, then every language by name.
		 *
		 * @return {Array<{id: string, label: string}>} The options.
		 */
		sourceOptions() {
			const named = TRANSLATION_LANGUAGES
				.map((code) => ({ id: code, label: languageName(code, this.locale) }))
				.sort((a, b) => a.label.localeCompare(b.label, this.locale))
			return [{ id: '', label: t('hermiq', 'Detect the language') }, ...named]
		},

		/**
		 * What the person will translate, as a label.
		 *
		 * @return {string} The label, or ''.
		 */
		recordingLabel() {
			if (this.recording) {
				return t('hermiq', 'Recording…')
			}
			if (this.file) {
				return this.file.name
			}
			if (this.blob) {
				return t('hermiq', 'Your recording')
			}
			return ''
		},

		/**
		 * Where the translation step will run, said before the person starts.
		 *
		 * @return {string} The line, or '' while it is not known.
		 * @spec openspec/changes/chat-translate-a-recording/specs/speech-services/spec.md#requirement-the-translation-step-follows-the-features-provider-rules-req-strn-003
		 */
		whereLine() {
			if (!this.where.enabled) {
				return t('hermiq', 'Translating a recording has not been enabled by your organisation yet.')
			}
			if (this.where.refusal) {
				return this.where.refusal
			}
			if (!this.where.provider) {
				return ''
			}
			const model = this.where.model || this.where.provider
			if (this.where.residency === 'on-premise') {
				return t('hermiq', 'Translation runs on {model} on this server.', { model })
			}
			if (this.where.residency === 'eu') {
				return t('hermiq', 'Translation runs on {model} ({provider}), in the EU.', { model, provider: this.where.provider })
			}
			if (this.where.residency === 'outside-eu') {
				return t('hermiq', 'Translation runs on {model} ({provider}), outside the EU.', { model, provider: this.where.provider })
			}
			return t('hermiq', 'Translation runs on {model} ({provider}); where it runs has not been declared.', { model, provider: this.where.provider })
		},

		/**
		 * Whether the translation can start.
		 *
		 * @return {boolean} True with a recording, a target and an enabled feature.
		 */
		canStart() {
			return !this.busy
				&& !this.recording
				&& (this.file !== null || this.blob !== null)
				&& this.target !== null
				&& this.where.enabled
				&& !this.where.refusal
				&& Boolean(this.session?.uuid)
		},
	},

	watch: {
		/**
		 * Start afresh each time the modal opens, and ask where the translation runs.
		 *
		 * @param {boolean} open Whether the modal is now shown.
		 * @spec openspec/changes/chat-translate-a-recording/specs/speech-services/spec.md#requirement-the-translation-step-follows-the-features-provider-rules-req-strn-003
		 */
		async show(open) {
			if (!open) {
				this.stopRecorder()
				return
			}
			this.file = null
			this.blob = null
			this.error = ''
			this.source = this.sourceOptions[0]
			this.target = null
			try {
				this.where = await translationWhere()
			} catch {
				this.where = { enabled: false, provider: '', model: '', residency: '', location: '', refusal: '', voices: [] }
			}
		},
	},

	beforeUnmount() {
		this.stopRecorder()
	},

	methods: {
		/**
		 * Choose one audio file from Files; it is sent by id, so no copy is made.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/chat-translate-a-recording/specs/speech-services/spec.md#scenario-a-front-office-employee-translates-a-voicemail
		 */
		async chooseFile() {
			const picker = getFilePickerBuilder(t('hermiq', 'Choose a recording'))
				.setMultiSelect(false)
				.allowDirectories(false)
				.setMimeTypeFilter(['audio/mpeg', 'audio/mp4', 'audio/x-m4a', 'audio/wav', 'audio/x-wav', 'audio/ogg', 'audio/webm', 'audio/flac', 'audio/aac'])
				.addButton({
					label: t('hermiq', 'Choose'),
					variant: 'primary',
					callback: (nodes) => {
						const node = nodes?.[0]
						if (node) {
							this.file = { fileId: node.fileid, name: node.basename }
							this.blob = null
						}
					},
				})
				.build()
			try {
				await picker.pick()
			} catch {
				// Closing the picker without choosing is not an error.
			}
		},

		/**
		 * Start recording with the microphone, or stop and keep the recording.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/chat-translate-a-recording/specs/speech-services/spec.md#requirement-a-person-can-translate-a-recording-and-hear-the-result-req-strn-001
		 */
		async toggleRecording() {
			if (this.recording) {
				this.recorder?.stop()
				return
			}
			this.error = ''
			try {
				const stream = await navigator.mediaDevices.getUserMedia({ audio: true })
				const recorder = new MediaRecorder(stream)
				const chunks = []
				recorder.ondataavailable = (event) => {
					if (event.data && event.data.size > 0) {
						chunks.push(event.data)
					}
				}
				recorder.onstop = () => {
					stream.getTracks().forEach((track) => track.stop())
					this.blob = new Blob(chunks, { type: recorder.mimeType || 'audio/webm' })
					this.file = null
					this.recording = false
					this.recorder = null
				}
				recorder.start()
				this.recorder = recorder
				this.recording = true
			} catch {
				this.error = t('hermiq', 'Microphone access was refused.')
			}
		},

		/**
		 * Stop a running recording without keeping it.
		 *
		 * @return {void}
		 */
		stopRecorder() {
			if (this.recorder && this.recording) {
				this.recorder.stop()
			}
		},

		/**
		 * Translate and hand the two turns to the parent.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/chat-translate-a-recording/specs/speech-services/spec.md#scenario-a-front-office-employee-translates-a-voicemail
		 */
		async start() {
			if (!this.canStart) {
				return
			}
			this.busy = true
			this.error = ''
			try {
				const result = await translateRecording({
					targetLanguage: this.target.id,
					sourceLanguage: this.source?.id || '',
					fileId: this.file?.fileId || 0,
					blob: this.file ? null : this.blob,
					sessionId: this.session.uuid,
				})
				this.$emit('translated', result)
			} catch (e) {
				this.error = e?.response?.data?.error
					|| t('hermiq', 'The recording could not be translated.')
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.translate-form {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 20px;
}

.translate-form__title {
	margin: 0 0 4px;
	font-size: 20px;
	font-weight: 600;
}

.translate-form__help {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.translate-form__source {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px;
}

.translate-form__chosen {
	color: var(--color-main-text);
	overflow-wrap: anywhere;
}

.translate-form__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
}
</style>
