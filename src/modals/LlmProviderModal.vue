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
						v-model="form.openaiConfig.chatModel"
						:label="t('hermiq', 'Model')"
						:placeholder="'gpt-4o-mini'" />
					<!--
						The API-key field is gone. Hermiq no longer holds the key — it picks a
						credential from the broker, and OpenRegister injects the secret
						server-side on every call. (The keys used to sit here in CLEARTEXT.)
					-->
					<NcSelect v-model="openaiCredential"
						:options="credentialsFor('openai')"
						:input-label="t('hermiq', 'API credential')"
						:loading="loadingCredentials"
						:placeholder="t('hermiq', 'Select a credential')"
						label="label" />
					<p class="llm-provider-modal__hint">
						{{ credentialHint('openai') }}
					</p>
				</template>

				<!-- Ollama -->
				<template v-else-if="providerValue === 'ollama'">
					<NcTextField
						v-model="form.ollamaConfig.url"
						:label="t('hermiq', 'Ollama URL')"
						:placeholder="'http://localhost:11434'" />
					<NcTextField
						v-model="form.ollamaConfig.chatModel"
						:label="t('hermiq', 'Model')"
						:placeholder="'llama3'" />
				</template>

				<!-- Fireworks -->
				<template v-else-if="providerValue === 'fireworks'">
					<NcTextField
						v-model="form.fireworksConfig.baseUrl"
						:label="t('hermiq', 'Base URL')"
						:placeholder="'https://api.fireworks.ai/inference/v1'" />
					<NcTextField
						v-model="form.fireworksConfig.chatModel"
						:label="t('hermiq', 'Model')"
						:placeholder="'accounts/fireworks/models/llama-v3p1-8b-instruct'" />
					<NcSelect v-model="fireworksCredential"
						:options="credentialsFor('fireworks')"
						:input-label="t('hermiq', 'API credential')"
						:loading="loadingCredentials"
						:placeholder="t('hermiq', 'Select a credential')"
						label="label" />
					<p class="llm-provider-modal__hint">
						{{ credentialHint('fireworks') }}
					</p>
				</template>

				<!-- Anthropic (Claude / Claude Max) -->
				<template v-else-if="providerValue === 'anthropic'">
					<NcTextField
						v-model="form.anthropicConfig.chatModel"
						:label="t('hermiq', 'Model')"
						:placeholder="'claude-opus-4-8'" />
					<p class="llm-provider-modal__hint">
						{{ t('hermiq', 'Suggested models: claude-opus-4-8, claude-sonnet-5, claude-haiku-4-5, claude-fable-5. Free text is allowed.') }}
					</p>
					<NcSelect v-model="anthropicAuthMode"
						:options="authModeOptions"
						:input-label="t('hermiq', 'Authentication')"
						:clearable="false"
						label="label"
						track-by="value" />
					<NcSelect v-model="anthropicCredential"
						:options="credentialsFor(anthropicCredentialProviderId)"
						:input-label="anthropicAuthModeValue === 'oauth' ? t('hermiq', 'Claude subscription (OAuth) credential') : t('hermiq', 'API credential')"
						:loading="loadingCredentials"
						:placeholder="t('hermiq', 'Select a credential')"
						label="label" />
					<p class="llm-provider-modal__hint">
						{{ credentialHint(anthropicCredentialProviderId) }}
					</p>
					<NcNoteCard v-if="anthropicAuthModeValue === 'oauth'" type="warning">
						{{ t('hermiq', 'A Claude Max/Pro subscription (OAuth) is personal-only per the Anthropic Terms of Service. It may be set only as a personal token in your own personal settings — never as an organisation-wide credential here. OAuth tokens also cannot refresh headlessly, so a Max token may go stale.') }}
						<a href="https://www.anthropic.com/legal/consumer-terms" target="_blank" rel="noopener noreferrer">
							{{ t('hermiq', 'Anthropic Terms of Service') }}
						</a>
					</NcNoteCard>
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
import { NcButton, NcLoadingIcon, NcModal, NcNoteCard, NcSelect, NcTextField } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { getLlmSettings, patchLlmSettings } from '../api/llm.js'

export default {
	name: 'LlmProviderModal',

	components: {
		NcButton,
		NcLoadingIcon,
		NcModal,
		NcNoteCard,
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
			// The user's broker credentials. Hermiq only ever learns their UUIDs — never
			// the keys behind them.
			credentials: [],
			loadingCredentials: false,
			openaiCredential: null,
			fireworksCredential: null,
			anthropicCredential: null,
			anthropicAuthMode: { value: 'api_key', label: 'API key' },
			authModeOptions: [
				{ value: 'api_key', label: 'API key' },
				{ value: 'oauth', label: 'Claude Max subscription (OAuth)' },
			],
			providerOptions: [
				{ value: 'openai', label: 'OpenAI' },
				{ value: 'anthropic', label: 'Anthropic (Claude)' },
				{ value: 'ollama', label: 'Ollama (local)' },
				{ value: 'fireworks', label: 'Fireworks AI' },
				{ value: 'nextcloud', label: 'Nextcloud Assistant (TaskProcessing)' },
			],
			form: {
				openaiConfig: { chatModel: '', credentialId: '' },
				ollamaConfig: { url: '', chatModel: '' },
				fireworksConfig: { baseUrl: '', chatModel: '', credentialId: '' },
				anthropicConfig: { chatModel: '', credentialId: '', authMode: 'api_key' },
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
		 * Whether a credential is selected for each provider.
		 *
		 * These replace the old `*KeyPlaceholder` computeds, which existed to tell the
		 * admin "a key is stored — leave blank to keep it". There is no stored key to keep
		 * any more: the picker shows which credential is selected, and the credential is
		 * a reference the broker resolves.
		 *
		 * @return {boolean} True when OpenAI has a credential selected.
		 *
		 * @spec exclude Trivial computed display helper; no behavioural spec.
		 */
		openaiHasCredential() {
			return this.openaiCredential !== null
		},
		/**
		 * @return {boolean} True when Fireworks has a credential selected.
		 *
		 * @spec exclude Trivial computed display helper; no behavioural spec.
		 */
		fireworksHasCredential() {
			return this.fireworksCredential !== null
		},
		/**
		 * The bare authMode string of the currently-selected Anthropic auth option.
		 *
		 * @return {string} `api_key` or `oauth`.
		 *
		 * @spec exclude Trivial computed display helper; no behavioural spec.
		 */
		anthropicAuthModeValue() {
			return this.anthropicAuthMode ? this.anthropicAuthMode.value : 'api_key'
		},

		/**
		 * The credential-broker provider id whose credentials the Anthropic
		 * credential picker should list, keyed off the selected auth mode.
		 *
		 * A Claude Max/Pro OAuth token is stored under the `anthropic-oauth`
		 * broker provider (injected as `Authorization: Bearer`); an API key
		 * under `anthropic` (injected as `x-api-key`). The picker must show the
		 * matching set — otherwise an OAuth credential is invisible when the
		 * user selects OAuth auth, and vice versa.
		 *
		 * @return {string} The broker provider id.
		 * @spec exclude Trivial computed display helper; no behavioural spec.
		 */
		anthropicCredentialProviderId() {
			return this.anthropicAuthModeValue === 'oauth' ? 'anthropic-oauth' : 'anthropic'
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
				// Credentials first: the pickers below are preselected from this list.
				await this.fetchCredentials()

				const config = await getLlmSettings()
				this.openaiKeySet = config.openaiApiKeySet === true
				this.fireworksKeySet = config.fireworksApiKeySet === true
				this.form.openaiConfig.chatModel = (config.openaiConfig && config.openaiConfig.chatModel) || ''
				this.form.openaiConfig.credentialId = (config.openaiConfig && config.openaiConfig.credentialId) || ''
				this.form.ollamaConfig.url = (config.ollamaConfig && config.ollamaConfig.url) || ''
				this.form.ollamaConfig.chatModel = (config.ollamaConfig && config.ollamaConfig.chatModel) || ''
				this.form.fireworksConfig.baseUrl = (config.fireworksConfig && config.fireworksConfig.baseUrl) || ''
				this.form.fireworksConfig.chatModel = (config.fireworksConfig && config.fireworksConfig.chatModel) || ''
				this.form.fireworksConfig.credentialId = (config.fireworksConfig && config.fireworksConfig.credentialId) || ''
				this.form.anthropicConfig.chatModel = (config.anthropicConfig && config.anthropicConfig.chatModel) || ''
				this.form.anthropicConfig.credentialId = (config.anthropicConfig && config.anthropicConfig.credentialId) || ''
				this.form.anthropicConfig.authMode = (config.anthropicConfig && config.anthropicConfig.authMode) || 'api_key'

				// Reflect the stored credential references back into the pickers.
				this.openaiCredential = this.credentialsFor('openai')
					.find((o) => o.value === this.form.openaiConfig.credentialId) || null
				this.fireworksCredential = this.credentialsFor('fireworks')
					.find((o) => o.value === this.form.fireworksConfig.credentialId) || null
				this.anthropicCredential = this.credentialsFor('anthropic')
					.find((o) => o.value === this.form.anthropicConfig.credentialId) || null
				this.anthropicAuthMode = this.authModeOptions
					.find((o) => o.value === this.form.anthropicConfig.authMode) || this.authModeOptions[0]

				this.selectedProvider = this.providerOptions.find((option) => option.value === config.chatProvider) || null
			} catch (e) {
				this.error = this.t('hermiq', 'Could not load the current LLM configuration.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Load the user's broker credentials.
		 *
		 * The endpoint already scopes to the caller's own credentials, and the response
		 * carries no secrets — only names, providers and UUIDs.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/llm-keys-via-broker/tasks.md#task-5-admin-ui
		 */
		async fetchCredentials() {
			this.loadingCredentials = true
			try {
				const { data } = await axios.get(generateUrl('/apps/openregister/api/credentials'))
				this.credentials = data.results || []
			} catch (e) {
				this.credentials = []
			} finally {
				this.loadingCredentials = false
			}
		},

		/**
		 * The broker credentials that can serve a given LLM provider.
		 *
		 * @param {string} provider `openai`, `anthropic`, or `fireworks`.
		 * @return {Array} NcSelect options.
		 *
		 * @spec openspec/changes/llm-keys-via-broker/tasks.md#task-5-admin-ui
		 */
		credentialsFor(provider) {
			return this.credentials
				.filter((c) => c.provider === provider)
				.map((c) => ({ label: c.name || c.id, value: c.id }))
		},

		/**
		 * Explain where the key lives — or how to add one when there is none.
		 *
		 * @param {string} provider `openai`, `anthropic`, or `fireworks`.
		 * @return {string} The hint text.
		 *
		 * @spec openspec/changes/llm-keys-via-broker/tasks.md#task-5-admin-ui
		 */
		credentialHint(provider) {
			if (!this.loadingCredentials && !this.credentialsFor(provider).length) {
				return this.t('hermiq', 'No credential yet. Add one under Personal settings → Additional settings, then reopen this dialog.')
			}
			return this.t('hermiq', 'The key stays in your credential vault. Hermiq sends only the request it wants made, and the broker injects the key.')
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
			// Only a credential REFERENCE is ever sent — never a key. Hermiq has none.
			if (this.providerValue === 'openai') {
				if (!this.openaiCredential) {
					this.error = this.t('hermiq', 'Pick an OpenAI credential first.')
					this.saving = false
					return
				}
				payload.openaiConfig = {
					chatModel: this.form.openaiConfig.chatModel,
					credentialId: this.openaiCredential.value,
				}
			} else if (this.providerValue === 'ollama') {
				payload.ollamaConfig = {
					url: this.form.ollamaConfig.url,
					chatModel: this.form.ollamaConfig.chatModel,
				}
			} else if (this.providerValue === 'fireworks') {
				if (!this.fireworksCredential) {
					this.error = this.t('hermiq', 'Pick a Fireworks AI credential first.')
					this.saving = false
					return
				}
				payload.fireworksConfig = {
					baseUrl: this.form.fireworksConfig.baseUrl,
					chatModel: this.form.fireworksConfig.chatModel,
					credentialId: this.fireworksCredential.value,
				}
			} else if (this.providerValue === 'anthropic') {
				// Claude Max (OAuth) is personal-only per the Anthropic ToS — it may never be
				// configured as an organisation credential here (admin settings). The server
				// rejects it too; this guard gives an immediate, clear message.
				if (this.anthropicAuthModeValue === 'oauth') {
					this.error = this.t('hermiq', 'A Claude Max subscription (OAuth) may only be set as a personal token in your personal settings, per the Anthropic Terms of Service. Use an API key here.')
					this.saving = false
					return
				}
				if (!this.anthropicCredential) {
					this.error = this.t('hermiq', 'Pick an Anthropic credential first.')
					this.saving = false
					return
				}
				payload.anthropicConfig = {
					chatModel: this.form.anthropicConfig.chatModel,
					credentialId: this.anthropicCredential.value,
					authMode: this.anthropicAuthModeValue,
					// Admin settings are always organisation scope.
					scope: 'organisation',
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
