<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  LlmProviderModal — admin picks which chat provider Hermiq's background/
  non-interactive LLM work runs on (SPECTR-NEXTCLOUD-PLAN.md §8 move 1).

  Own file per the modal-isolation gate. Reads GET /api/settings/llm (credentials
  arrive masked as *Set booleans), lets the admin pick a provider from an NcSelect
  (inputLabel set per the nc-input-labels a11y gate) plus that provider's
  model/url/key fields, and PATCHes the change. The `nextcloud` option carries no
  credential fields — it is backed by whatever TaskProcessing provider the instance
  already has installed, and is background-only (no SSE chat / no embeddings).
-->
<template>
	<NcModal
		:show="show"
		size="normal"
		:name="t('hermiq', 'LLM provider')"
		@close="$emit('close')">
		<div class="llm-provider">
			<h2 class="llm-provider__title">
				{{ t('hermiq', 'Chat provider') }}
			</h2>
			<p class="llm-provider__description">
				{{ t('hermiq', 'Choose which language-model provider Hermiq uses for background work such as conversation titles and summaries. Configure OpenAI, Ollama, or Fireworks with credentials, or select Nextcloud Assistant to reuse whatever AI provider is installed instance-wide.') }}
			</p>

			<div v-if="loading" class="llm-provider__loading">
				<NcLoadingIcon :size="32" />
			</div>

			<template v-else>
				<div class="llm-provider__field">
					<NcSelect
						v-model="selectedProvider"
						:input-label="t('hermiq', 'Provider')"
						:options="providerOptions"
						:clearable="false"
						label="label"
						track-by="value" />
				</div>

				<!-- OpenAI -->
				<template v-if="providerValue === 'openai'">
					<NcTextField
						:value.sync="form.openaiConfig.chatModel"
						:label="t('hermiq', 'Model')"
						:placeholder="'gpt-4o-mini'" />
					<NcPasswordField
						:value.sync="form.openaiConfig.apiKey"
						:label="t('hermiq', 'API key')"
						:placeholder="openaiKeyPlaceholder" />
				</template>

				<!-- Ollama -->
				<template v-else-if="providerValue === 'ollama'">
					<NcTextField
						:value.sync="form.ollamaConfig.url"
						:label="t('hermiq', 'Ollama URL')"
						:placeholder="'http://localhost:11434'" />
					<NcTextField
						:value.sync="form.ollamaConfig.chatModel"
						:label="t('hermiq', 'Model')"
						:placeholder="'llama3'" />
				</template>

				<!-- Fireworks -->
				<template v-else-if="providerValue === 'fireworks'">
					<NcTextField
						:value.sync="form.fireworksConfig.baseUrl"
						:label="t('hermiq', 'Base URL')"
						:placeholder="'https://api.fireworks.ai/inference/v1'" />
					<NcTextField
						:value.sync="form.fireworksConfig.chatModel"
						:label="t('hermiq', 'Model')"
						:placeholder="'accounts/fireworks/models/llama-v3p1-8b-instruct'" />
					<NcPasswordField
						:value.sync="form.fireworksConfig.apiKey"
						:label="t('hermiq', 'API key')"
						:placeholder="fireworksKeyPlaceholder" />
				</template>

				<!-- Nextcloud Assistant (TaskProcessing) -->
				<template v-else-if="providerValue === 'nextcloud'">
					<NcNoteCard type="info">
						{{ t('hermiq', 'Hermiq will run background text through Nextcloud’s own AI (TaskProcessing). No credentials are needed here, but a TaskProcessing provider (e.g. integration_openai, llm2, or the Assistant) must be installed on this instance. Streaming chat and embeddings are not routed through this provider.') }}
					</NcNoteCard>
				</template>

				<div class="llm-provider__actions">
					<NcButton @click="$emit('close')">
						{{ t('hermiq', 'Cancel') }}
					</NcButton>
					<NcButton type="primary" :disabled="saving" @click="save">
						<template v-if="saving" #icon>
							<NcLoadingIcon :size="20" />
						</template>
						{{ t('hermiq', 'Save') }}
					</NcButton>
				</div>

				<p v-if="error" class="llm-provider__error">
					{{ error }}
				</p>
			</template>
		</div>
	</NcModal>
</template>

<script>
import { NcButton, NcLoadingIcon, NcModal, NcNoteCard, NcPasswordField, NcSelect, NcTextField } from '@nextcloud/vue'
import { getLlmSettings, patchLlmSettings } from '../api/llm.js'

export default {
	name: 'LlmProviderModal',

	components: {
		NcButton,
		NcLoadingIcon,
		NcModal,
		NcNoteCard,
		NcPasswordField,
		NcSelect,
		NcTextField,
	},

	props: {
		/**
		 * Whether the modal is visible.
		 *
		 * @spec exclude Trivial visibility prop; no behavioural spec.
		 */
		show: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['close', 'saved'],

	data() {
		return {
			loading: true,
			saving: false,
			error: '',
			openaiKeySet: false,
			fireworksKeySet: false,
			selectedProvider: null,
			providerOptions: [
				{ value: 'openai', label: 'OpenAI' },
				{ value: 'ollama', label: 'Ollama (local)' },
				{ value: 'fireworks', label: 'Fireworks AI' },
				{ value: 'nextcloud', label: 'Nextcloud Assistant (TaskProcessing)' },
			],
			form: {
				openaiConfig: { chatModel: '', apiKey: '' },
				ollamaConfig: { url: '', chatModel: '' },
				fireworksConfig: { baseUrl: '', chatModel: '', apiKey: '' },
			},
		}
	},

	computed: {
		/**
		 * The bare provider string of the currently-selected NcSelect option.
		 *
		 * @return {string|null} The provider value, or null when none is selected.
		 *
		 * @spec exclude Trivial computed display helper; no behavioural spec.
		 */
		providerValue() {
			return this.selectedProvider ? this.selectedProvider.value : null
		},
		/**
		 * Placeholder that tells the admin a key is already stored (so leaving the
		 * field blank keeps it) versus not yet set.
		 *
		 * @return {string} The OpenAI key field placeholder.
		 *
		 * @spec exclude Trivial computed display helper; no behavioural spec.
		 */
		openaiKeyPlaceholder() {
			return this.openaiKeySet
				? this.t('hermiq', 'A key is stored — leave blank to keep it')
				: this.t('hermiq', 'sk-…')
		},
		/**
		 * @return {string} The Fireworks key field placeholder.
		 *
		 * @spec exclude Trivial computed display helper; no behavioural spec.
		 */
		fireworksKeyPlaceholder() {
			return this.fireworksKeySet
				? this.t('hermiq', 'A key is stored — leave blank to keep it')
				: this.t('hermiq', 'API key')
		},
	},

	watch: {
		/**
		 * Reload the config each time the modal is opened.
		 *
		 * @param {boolean} visible Whether the modal became visible.
		 * @return {void}
		 *
		 * @spec exclude Trivial watch handler delegating to load(); no behavioural spec.
		 */
		show(visible) {
			if (visible) {
				this.load()
			}
		},
	},

	/**
	 * Load the config when the modal mounts already open.
	 *
	 * @return {void}
	 *
	 * @spec exclude Trivial lifecycle hook delegating to load(); no behavioural spec.
	 */
	mounted() {
		if (this.show) {
			this.load()
		}
	},

	methods: {
		/**
		 * Load the current (masked) config into the form.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/taskprocessing-consume-ui/tasks.md#task-2-2
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				const config = await getLlmSettings()
				this.openaiKeySet = config.openaiApiKeySet === true
				this.fireworksKeySet = config.fireworksApiKeySet === true
				this.form.openaiConfig.chatModel = (config.openaiConfig && config.openaiConfig.chatModel) || ''
				this.form.openaiConfig.apiKey = ''
				this.form.ollamaConfig.url = (config.ollamaConfig && config.ollamaConfig.url) || ''
				this.form.ollamaConfig.chatModel = (config.ollamaConfig && config.ollamaConfig.chatModel) || ''
				this.form.fireworksConfig.baseUrl = (config.fireworksConfig && config.fireworksConfig.baseUrl) || ''
				this.form.fireworksConfig.chatModel = (config.fireworksConfig && config.fireworksConfig.chatModel) || ''
				this.form.fireworksConfig.apiKey = ''
				this.selectedProvider = this.providerOptions.find((option) => option.value === config.chatProvider) || null
			} catch (e) {
				this.error = this.t('hermiq', 'Could not load the current LLM configuration.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Build a minimal patch for the selected provider and PATCH it.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/taskprocessing-consume-ui/tasks.md#task-2-2
		 */
		async save() {
			if (!this.providerValue) {
				this.error = this.t('hermiq', 'Select a provider first.')
				return
			}
			this.saving = true
			this.error = ''
			const payload = { chatProvider: this.providerValue }
			if (this.providerValue === 'openai') {
				payload.openaiConfig = { chatModel: this.form.openaiConfig.chatModel }
				if (this.form.openaiConfig.apiKey) {
					payload.openaiConfig.apiKey = this.form.openaiConfig.apiKey
				}
			} else if (this.providerValue === 'ollama') {
				payload.ollamaConfig = {
					url: this.form.ollamaConfig.url,
					chatModel: this.form.ollamaConfig.chatModel,
				}
			} else if (this.providerValue === 'fireworks') {
				payload.fireworksConfig = {
					baseUrl: this.form.fireworksConfig.baseUrl,
					chatModel: this.form.fireworksConfig.chatModel,
				}
				if (this.form.fireworksConfig.apiKey) {
					payload.fireworksConfig.apiKey = this.form.fireworksConfig.apiKey
				}
			}
			try {
				await patchLlmSettings(payload)
				this.$emit('saved', this.providerValue)
				this.$emit('close')
			} catch (e) {
				this.error = this.t('hermiq', 'Could not save the LLM configuration.')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.llm-provider {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 20px;
	max-height: 70vh;
	overflow-y: auto;
}

.llm-provider__title {
	margin: 0;
	font-size: 1.25rem;
}

.llm-provider__description {
	margin: 0;
	color: var(--color-text-maxcontrast);
	line-height: 1.5;
}

.llm-provider__field {
	margin-top: 4px;
}

.llm-provider__loading {
	display: flex;
	justify-content: center;
	padding: 24px 0;
}

.llm-provider__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 12px;
}

.llm-provider__error {
	margin: 0;
	color: var(--color-error);
}
</style>
