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
				{{
					t(
						'hermiq',
						'Choose which language-model provider Hermiq uses for background work such as session titles and summaries. Configure OpenAI, Ollama, or Fireworks with credentials, or select Nextcloud Assistant to reuse whatever AI provider is installed instance-wide.',
					)
				}}
			</p>

			<div v-if="loading" class="llm-provider__loading">
				<NcLoadingIcon :size="32" />
			</div>

			<template v-else>
				<div class="llm-provider__field">
					<NcSelect
						v-model="selectedProvider"
						:inputLabel="t('hermiq', 'Provider')"
						:options="providerOptions"
						:clearable="false"
						label="label"
						trackBy="value" />
				</div>

				<!-- OpenAI -->
				<template v-if="providerValue === 'openai'">
					<NcTextField
						v-model="form.openaiConfig.chatModel"
						:label="t('hermiq', 'Model')"
						placeholder="gpt-4o-mini" />
					<!--
						The API-key field is gone. Hermiq no longer holds the key — it picks a
						credential from the broker, and OpenRegister injects the secret
						server-side on every call. (The keys used to sit here in CLEARTEXT.)
					-->
					<NcSelect
						v-model="openaiCredential"
						:options="credentialsFor('openai')"
						:inputLabel="t('hermiq', 'API credential')"
						:loading="loadingCredentials"
						:placeholder="t('hermiq', 'Select a credential')"
						label="label" />
					<p class="llm-provider-modal__hint">
						{{ credentialHint('openai') }}
					</p>
					<NcTextField
						v-model="form.openaiConfig.baseUrl"
						:label="t('hermiq', 'Base URL (optional)')"
						placeholder="https://api.openai.com/v1"
						data-testid="llm-openai-base-url" />
					<p class="llm-provider-modal__hint">
						{{
							t(
								'hermiq',
								'Leave empty to use api.openai.com. A base URL without a credential is called directly; with a credential the broker sends the call.',
							)
						}}
					</p>
					<NcCheckboxRadioSwitch
						v-if="form.openaiConfig.baseUrl"
						v-model="form.openaiConfig.ownNetwork"
						type="checkbox">
						{{ t('hermiq', 'This is a server on our own network') }}
					</NcCheckboxRadioSwitch>
				</template>

				<!-- OpenAI-compatible endpoint (models-provider-connections) -->
				<template v-else-if="providerValue === 'openai-compatible'">
					<NcTextField
						v-model="form.openaiCompatibleConfig.label"
						:label="t('hermiq', 'Name')"
						placeholder="vLLM Rekencentrum"
						data-testid="llm-compatible-label" />
					<NcTextField
						v-model="form.openaiCompatibleConfig.baseUrl"
						:label="t('hermiq', 'Base URL')"
						placeholder="https://llm.example.nl/v1"
						data-testid="llm-compatible-base-url" />
					<NcTextField
						v-model="form.openaiCompatibleConfig.chatModel"
						:label="t('hermiq', 'Model')"
						placeholder="mistral-small-3.2" />
					<NcSelect
						v-model="compatibleCredential"
						:options="credentialsFor('openai-compatible')"
						:inputLabel="t('hermiq', 'API credential (optional)')"
						:loading="loadingCredentials"
						:disabled="!compatibleKeyAvailable"
						:placeholder="t('hermiq', 'No key: call the base URL directly')"
						label="label"
						data-testid="llm-compatible-credential" />
					<p class="llm-provider-modal__hint" data-testid="llm-compatible-credential-hint">
						{{
							compatibleKeyAvailable
								? credentialHint('openai-compatible')
								: t('hermiq', 'A key needs OpenRegister\'s OpenAI-compatible credential type.')
						}}
					</p>
					<NcCheckboxRadioSwitch
						v-if="!compatibleCredential"
						v-model="form.openaiCompatibleConfig.ownNetwork"
						type="checkbox">
						{{ t('hermiq', 'This is a server on our own network') }}
					</NcCheckboxRadioSwitch>
				</template>

				<!-- Ollama -->
				<template v-else-if="providerValue === 'ollama'">
					<NcTextField
						v-model="form.ollamaConfig.url"
						:label="t('hermiq', 'Ollama URL')"
						placeholder="http://localhost:11434" />
					<NcTextField
						v-model="form.ollamaConfig.chatModel"
						:label="t('hermiq', 'Model')"
						placeholder="llama3" />
				</template>

				<!-- Fireworks -->
				<template v-else-if="providerValue === 'fireworks'">
					<NcTextField
						:modelValue="fireworksHost"
						:label="t('hermiq', 'Host')"
						:helperText="t('hermiq', 'Calls go to the host locked to the credential.')"
						readonly
						data-testid="llm-fireworks-host" />
					<NcTextField
						v-model="form.fireworksConfig.baseUrl"
						:label="t('hermiq', 'API path')"
						placeholder="/inference/v1"
						data-testid="llm-fireworks-path" />
					<NcTextField
						v-model="form.fireworksConfig.chatModel"
						:label="t('hermiq', 'Model')"
						placeholder="accounts/fireworks/models/llama-v3p1-8b-instruct" />
					<NcSelect
						v-model="fireworksCredential"
						:options="credentialsFor('fireworks')"
						:inputLabel="t('hermiq', 'API credential')"
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
						placeholder="claude-opus-4-8" />
					<p class="llm-provider-modal__hint">
						{{
							t(
								'hermiq',
								'Suggested models: claude-opus-4-8, claude-sonnet-5, claude-haiku-4-5, claude-fable-5. Free text is allowed.',
							)
						}}
					</p>
					<NcSelect
						v-model="anthropicAuthMode"
						:options="authModeOptions"
						:inputLabel="t('hermiq', 'Authentication')"
						:clearable="false"
						label="label"
						trackBy="value" />
					<NcSelect
						v-model="anthropicCredential"
						:options="credentialsFor(anthropicCredentialProviderIds)"
						:inputLabel="
							anthropicAuthModeValue === 'oauth'
								? t(
										'hermiq',
										'Claude subscription (OAuth) credential',
									)
								: t('hermiq', 'API credential')
						"
						:loading="loadingCredentials"
						:placeholder="t('hermiq', 'Select a credential')"
						label="label" />
					<p class="llm-provider-modal__hint">
						{{ credentialHint(anthropicCredentialProviderIds) }}
					</p>
					<NcNoteCard
						v-if="anthropicAuthModeValue === 'oauth'"
						type="warning">
						{{
							t(
								'hermiq',
								'A Claude Max/Pro subscription (OAuth) is personal-only per the Anthropic Terms of Service. It may be set only as a personal token in your own personal settings — never as an organisation-wide credential here. OAuth tokens also cannot refresh headlessly, so a Max token may go stale.',
							)
						}}
						<a
							href="https://www.anthropic.com/legal/consumer-terms"
							target="_blank"
							rel="noopener noreferrer">
							{{ t('hermiq', 'Anthropic Terms of Service') }}
						</a>
					</NcNoteCard>
				</template>

				<!-- Nextcloud Assistant (TaskProcessing) -->
				<template v-else-if="providerValue === 'nextcloud'">
					<NcNoteCard type="info">
						{{
							t(
								'hermiq',
								'Hermiq will run background text through Nextcloud’s own AI (TaskProcessing). No credentials are needed here, but a TaskProcessing provider (e.g. integration_openai, llm2, or the Assistant) must be installed on this instance. Streaming chat and embeddings are not routed through this provider.',
							)
						}}
					</NcNoteCard>
				</template>

				<!-- chat-attachments-and-images: what the configured model reads natively,
					declared by the admin, never guessed from its name. -->
				<fieldset
					v-if="providerValue && providerValue !== 'nextcloud'"
					class="llm-provider__capabilities"
					data-testid="llm-model-capabilities">
					<legend>
						{{ t('hermiq', 'What this model reads directly') }}
					</legend>
					<template v-if="currentModel">
						<NcCheckboxRadioSwitch
							v-model="readsImages"
							type="checkbox"
							data-testid="llm-reads-images">
							{{ t('hermiq', 'Reads images') }}
						</NcCheckboxRadioSwitch>
						<NcCheckboxRadioSwitch
							v-model="readsPdfs"
							type="checkbox"
							data-testid="llm-reads-pdfs">
							{{ t('hermiq', 'Reads PDFs') }}
						</NcCheckboxRadioSwitch>
						<p class="llm-provider-modal__hint">
							{{
								t(
									'hermiq',
									'Tick only what the model supports. Attachments it cannot read are sent as text, or left out with a notice.',
								)
							}}
						</p>
					</template>
					<p v-else class="llm-provider-modal__hint">
						{{ t('hermiq', 'Enter a model first.') }}
					</p>
				</fieldset>

				<!-- models-no-training-guarantee: where the provider runs and what it
					does with the data it is sent, stated by the admin who configures it. -->
				<ProviderDeclarations
					v-if="providerValue"
					ref="declarations"
					:provider="providerValue" />

				<div class="llm-provider__actions">
					<NcButton @click="$emit('close')">
						{{ t('hermiq', 'Cancel') }}
					</NcButton>
					<NcButton variant="primary" :disabled="saving" @click="save">
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
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcLoadingIcon,
	NcModal,
	NcNoteCard,
	NcSelect,
	NcTextField,
} from '@nextcloud/vue'
import ProviderDeclarations from '../components/ProviderDeclarations.vue'
import { getLlmSettings, patchLlmSettings } from '../api/llm.js'
import { apiPathOf, applyStoredConfig, configKeyOf, optionWithValue } from './llmProviderForm.js'

/** The host the broker locks Fireworks credentials to. */
const FIREWORKS_HOST = 'api.fireworks.ai'
import { brokerCredentials, reloadOnOpen } from './settingsModalMixins.js'
export default {
	name: 'LlmProviderModal',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcModal,
		NcNoteCard,
		NcSelect,
		NcTextField,
		ProviderDeclarations,
	},

	mixins: [reloadOnOpen, brokerCredentials],

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
			compatibleCredential: null,
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
				{ value: 'openai-compatible', label: 'OpenAI-compatible endpoint' },
				{
					value: 'nextcloud',
					label: 'Nextcloud Assistant (TaskProcessing)',
				},
			],

			// Declared native inputs per `provider/model` (hermiq.modelCapabilities).
			modelCapabilities: {},

			form: {
				openaiConfig: { chatModel: '', credentialId: '', baseUrl: '', ownNetwork: false },
				openaiCompatibleConfig: { label: '', baseUrl: '', chatModel: '', credentialId: '', ownNetwork: false },
				ollamaConfig: { url: '', chatModel: '' },
				fireworksConfig: { baseUrl: '', chatModel: '', credentialId: '' },
				anthropicConfig: {
					chatModel: '',
					credentialId: '',
					authMode: 'api_key',
				},
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
		 * Whether a key can be chosen for an OpenAI-compatible endpoint.
		 *
		 * A credential of that provider exists only where OpenRegister ships the
		 * OpenAI-compatible credential type (models-provider-connections D3). Until
		 * one is listed the field is disabled and the keyless transport is offered.
		 *
		 * @return {boolean} True when such a credential is listed.
		 * @spec openspec/changes/models-provider-connections/specs/llm-provider-connections/spec.md#requirement-a-chosen-host-is-reached-without-hermiq-holding-a-key-req-pconn-002
		 */
		compatibleKeyAvailable() {
			return this.credentialsFor('openai-compatible').length > 0
		},

		/**
		 * The host Fireworks calls go to: the broker sends every call to the host
		 * locked to the credential, so it is shown, not edited.
		 *
		 * @return {string} The host.
		 * @spec openspec/changes/models-provider-connections/specs/llm-provider-connections/spec.md#requirement-a-chosen-host-is-reached-without-hermiq-holding-a-key-req-pconn-002
		 */
		fireworksHost() {
			const credential = this.credentials.find((c) => c.id === this.fireworksCredential?.value)
			return credential?.host || FIREWORKS_HOST
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
		 * The credential-broker provider ids whose credentials the Anthropic
		 * credential picker lists, keyed off the selected auth mode.
		 *
		 * An API key lives under `anthropic` (injected as `x-api-key`). A Claude
		 * Max or Pro subscription token lives under one of two providers, and
		 * which one depends on the transport, not on the auth mode: `anthropic-oauth`
		 * for `executionMode: http`, `anthropic-cli` for `executionMode: cli`.
		 *
		 * Both are listed under OAuth auth. `executionMode` is API-only configuration
		 * and this dialog cannot read it, so listing one provider hid the other:
		 * a subscription credential stored for the CLI transport, which is the
		 * only transport Anthropic permits for a subscription, could not be
		 * selected here at all.
		 *
		 * @return {Array<string>} The broker provider ids to list.
		 * @spec exclude Trivial computed display helper; no behavioural spec.
		 */
		anthropicCredentialProviderIds() {
			return this.anthropicAuthModeValue === 'oauth'
				? ['anthropic-oauth', 'anthropic-cli']
				: ['anthropic']
		},

		/**
		 * The model id typed for the selected provider, trimmed.
		 *
		 * @return {string} The model id, or '' when none is set.
		 * @spec openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#requirement-a-model-that-reads-images-or-pdfs-natively-gets-them-natively-req-catt-004
		 */
		currentModel() {
			const block = this.form[configKeyOf(this.providerValue)]
			return block ? String(block.chatModel || '').trim() : ''
		},

		/**
		 * The `provider/model` key the capabilities are stored under.
		 *
		 * @return {string} The key, or '' when no model is set.
		 * @spec openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#requirement-a-model-that-reads-images-or-pdfs-natively-gets-them-natively-req-catt-004
		 */
		capabilityKey() {
			return this.currentModel
				? `${this.providerValue}/${this.currentModel}`
				: ''
		},

		/**
		 * "Reads images" for the current model.
		 *
		 * @spec openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#requirement-a-model-that-reads-images-or-pdfs-natively-gets-them-natively-req-catt-004
		 */
		readsImages: {
			/**
			 * Whether the current model is declared to read images.
			 *
			 * @return {boolean} True when declared.
			 * @spec openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#requirement-a-model-that-reads-images-or-pdfs-natively-gets-them-natively-req-catt-004
			 */
			get() {
				return this.hasCapability('image')
			},

			/**
			 * Declare or withdraw reading images for the current model.
			 *
			 * @param {boolean} value Ticked or not.
			 * @spec openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#requirement-a-model-that-reads-images-or-pdfs-natively-gets-them-natively-req-catt-004
			 */
			set(value) {
				this.setCapability('image', value)
			},
		},

		/**
		 * "Reads PDFs" for the current model.
		 *
		 * @spec openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#requirement-a-model-that-reads-images-or-pdfs-natively-gets-them-natively-req-catt-004
		 */
		readsPdfs: {
			/**
			 * Whether the current model is declared to read PDFs.
			 *
			 * @return {boolean} True when declared.
			 * @spec openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#requirement-a-model-that-reads-images-or-pdfs-natively-gets-them-natively-req-catt-004
			 */
			get() {
				return this.hasCapability('pdf')
			},

			/**
			 * Declare or withdraw reading PDFs for the current model.
			 *
			 * @param {boolean} value Ticked or not.
			 * @spec openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#requirement-a-model-that-reads-images-or-pdfs-natively-gets-them-natively-req-catt-004
			 */
			set(value) {
				this.setCapability('pdf', value)
			},
		},
	},

	methods: {
		/**
		 * Whether the current model was declared to read one kind of input.
		 *
		 * @param {string} capability `image` or `pdf`.
		 * @return {boolean} True when declared.
		 * @spec openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#requirement-a-model-that-reads-images-or-pdfs-natively-gets-them-natively-req-catt-004
		 */
		hasCapability(capability) {
			const declared = this.modelCapabilities[this.capabilityKey] || []
			return this.capabilityKey !== '' && declared.includes(capability)
		},

		/**
		 * Tick or untick one capability for the current model.
		 *
		 * @param {string} capability `image` or `pdf`.
		 * @param {boolean} value Whether it is ticked.
		 * @return {void}
		 * @spec openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#requirement-a-model-that-reads-images-or-pdfs-natively-gets-them-natively-req-catt-004
		 */
		setCapability(capability, value) {
			if (this.capabilityKey === '') {
				return
			}
			const others = (this.modelCapabilities[this.capabilityKey] || []).filter(
				(c) => c !== capability,
			)
			this.modelCapabilities = {
				...this.modelCapabilities,
				[this.capabilityKey]: value ? [...others, capability] : others,
			}
		},

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
				this.applyConfig(config)
				this.reflectPickers(config.chatProvider)
			} catch (e) {
				this.error = this.t(
					'hermiq',
					'Could not load the current LLM configuration.',
				)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Copy the stored (masked) config into the key flags, the form and the
		 * declared model capabilities.
		 *
		 * @param {object} config The stored configuration.
		 * @return {void}
		 *
		 * @spec openspec/changes/taskprocessing-consume-ui/tasks.md#task-2-2
		 */
		applyConfig(config) {
			this.openaiKeySet = config.openaiApiKeySet === true
			this.fireworksKeySet = config.fireworksApiKeySet === true
			applyStoredConfig(this.form, config)
			this.modelCapabilities = { ...(config.modelCapabilities || {}) }
		},

		/**
		 * Reflect the stored credential references, auth mode and provider back into
		 * the pickers.
		 *
		 * @param {string} chatProvider The stored provider.
		 * @return {void}
		 *
		 * @spec openspec/changes/archive/2026-07-12-llm-keys-via-broker/tasks.md#task-5-admin-ui
		 */
		reflectPickers(chatProvider) {
			this.openaiCredential = optionWithValue(this.credentialsFor('openai'), this.form.openaiConfig.credentialId)
			this.fireworksCredential = optionWithValue(this.credentialsFor('fireworks'), this.form.fireworksConfig.credentialId)
			this.compatibleCredential = optionWithValue(this.credentialsFor('openai-compatible'), this.form.openaiCompatibleConfig.credentialId)
			this.form.fireworksConfig.baseUrl = apiPathOf(this.form.fireworksConfig.baseUrl)
			this.anthropicCredential = optionWithValue(this.credentialsFor('anthropic'), this.form.anthropicConfig.credentialId)
			this.anthropicAuthMode = optionWithValue(this.authModeOptions, this.form.anthropicConfig.authMode)
				|| this.authModeOptions[0]
			this.selectedProvider = optionWithValue(this.providerOptions, chatProvider)
		},

		/**
		 * The broker credentials that can serve a given LLM provider.
		 *
		 * @param {string|Array<string>} provider One broker provider id, or several.
		 *                                        Anthropic passes several: an OAuth
		 *                                        subscription token is stored under
		 *                                        `anthropic-oauth` or `anthropic-cli`
		 *                                        depending on the transport.
		 * @return {Array} NcSelect options.
		 *
		 * @spec openspec/changes/archive/2026-07-12-llm-keys-via-broker/tasks.md#task-5-admin-ui
		 */
		credentialsFor(provider) {
			const providers = Array.isArray(provider) ? provider : [provider]
			return this.credentials
				.filter((c) => providers.includes(c.provider))
				.map((c) => ({ label: c.name || c.id, value: c.id }))
		},

		/**
		 * Explain where the key lives — or how to add one when there is none.
		 *
		 * @param {string|Array<string>} provider One broker provider id, or several.
		 * @return {string} The hint text.
		 *
		 * @spec openspec/changes/archive/2026-07-12-llm-keys-via-broker/tasks.md#task-5-admin-ui
		 */
		credentialHint(provider) {
			if (!this.loadingCredentials && !this.credentialsFor(provider).length) {
				return this.t(
					'hermiq',
					'No credential yet. Add one in Hermiq under Settings, Credentials. Then reopen this dialog.',
				)
			}
			return this.t(
				'hermiq',
				'The key stays in your credential vault. Hermiq sends only the request it wants made, and the broker injects the key.',
			)
		},

		/**
		 * The minimal patch for the selected provider, or null when a required
		 * choice is missing (the error then says which). Only a credential
		 * REFERENCE is ever sent — never a key. Hermiq has none.
		 *
		 * @return {object|null} The patch.
		 *
		 * @spec openspec/changes/taskprocessing-consume-ui/tasks.md#task-2-2
		 */
		providerPayload() {
			const payload = { chatProvider: this.providerValue }
			const section = {
				openai: this.openaiSection,
				ollama: this.ollamaSection,
				fireworks: this.fireworksSection,
				anthropic: this.anthropicSection,
				'openai-compatible': this.compatibleSection,
			}[this.providerValue]
			if (!section) {
				return payload
			}
			const config = section()
			if (config === null) {
				return null
			}
			payload[configKeyOf(this.providerValue)] = config
			return payload
		},

		/**
		 * The OpenAI section of the patch, or null without a credential.
		 *
		 * @return {object|null} The section.
		 *
		 * @spec openspec/changes/archive/2026-07-12-llm-keys-via-broker/tasks.md#task-5-admin-ui
		 */
		openaiSection() {
			const baseUrl = String(this.form.openaiConfig.baseUrl || '').trim()
			if (!this.openaiCredential && baseUrl === '') {
				return this.refuse(this.t('hermiq', 'Pick an OpenAI credential first.'))
			}
			return {
				chatModel: this.form.openaiConfig.chatModel,
				credentialId: this.openaiCredential ? this.openaiCredential.value : '',
				baseUrl,
				ownNetwork: baseUrl !== '' && this.form.openaiConfig.ownNetwork === true,
			}
		},

		/**
		 * The OpenAI-compatible section of the patch, or null without a base URL or model.
		 *
		 * @return {object|null} The section.
		 * @spec openspec/changes/models-provider-connections/specs/llm-provider-connections/spec.md#requirement-an-admin-can-connect-an-openai-compatible-endpoint-by-name-req-pconn-001
		 */
		compatibleSection() {
			const block = this.form.openaiCompatibleConfig
			const baseUrl = String(block.baseUrl || '').trim()
			if (baseUrl === '') {
				return this.refuse(this.t('hermiq', 'Enter the base URL of the endpoint first.'))
			}
			if (String(block.chatModel || '').trim() === '') {
				return this.refuse(this.t('hermiq', 'Enter the model the endpoint serves first.'))
			}
			const credentialId = this.compatibleCredential ? this.compatibleCredential.value : ''
			return {
				label: String(block.label || '').trim(),
				baseUrl,
				chatModel: String(block.chatModel).trim(),
				credentialId,
				ownNetwork: credentialId === '' && block.ownNetwork === true,
			}
		},

		/**
		 * The Ollama section of the patch.
		 *
		 * @return {object} The section.
		 *
		 * @spec openspec/changes/taskprocessing-consume-ui/tasks.md#task-2-2
		 */
		ollamaSection() {
			return {
				url: this.form.ollamaConfig.url,
				chatModel: this.form.ollamaConfig.chatModel,
			}
		},

		/**
		 * The Fireworks AI section of the patch, or null without a credential.
		 *
		 * @return {object|null} The section.
		 *
		 * @spec openspec/changes/archive/2026-07-12-llm-keys-via-broker/tasks.md#task-5-admin-ui
		 */
		fireworksSection() {
			if (!this.fireworksCredential) {
				return this.refuse(this.t('hermiq', 'Pick a Fireworks AI credential first.'))
			}
			return {
				baseUrl: apiPathOf(this.form.fireworksConfig.baseUrl),
				chatModel: this.form.fireworksConfig.chatModel,
				credentialId: this.fireworksCredential.value,
			}
		},

		/**
		 * The Anthropic section of the patch, or null for OAuth or without a credential.
		 *
		 * Claude Max (OAuth) is personal-only per the Anthropic ToS — it may never be
		 * configured as an organisation credential here (admin settings). The server
		 * rejects it too; this guard gives an immediate, clear message.
		 *
		 * @return {object|null} The section.
		 *
		 * @spec openspec/changes/archive/2026-07-12-llm-keys-via-broker/tasks.md#task-5-admin-ui
		 */
		anthropicSection() {
			if (this.anthropicAuthModeValue === 'oauth') {
				return this.refuse(this.t(
					'hermiq',
					'A Claude Max subscription (OAuth) may only be set as a personal token in your personal settings, per the Anthropic Terms of Service. Use an API key here.',
				))
			}
			if (!this.anthropicCredential) {
				return this.refuse(this.t('hermiq', 'Pick an Anthropic credential first.'))
			}
			return {
				chatModel: this.form.anthropicConfig.chatModel,
				credentialId: this.anthropicCredential.value,
				authMode: this.anthropicAuthModeValue,
				// Admin settings are always organisation scope.
				scope: 'organisation',
			}
		},

		/**
		 * Show why the patch cannot be built.
		 *
		 * @param {string} message The translated reason.
		 * @return {null} Always null, the missing section.
		 *
		 * @spec openspec/changes/taskprocessing-consume-ui/tasks.md#task-2-2
		 */
		refuse(message) {
			this.error = message
			return null
		},

		/**
		 * The ticks for the model being saved travel with it; an untouched model is
		 * sent as declared "neither", which is what the boxes show.
		 *
		 * @param {object} payload The patch.
		 * @return {void}
		 *
		 * @spec openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#requirement-a-model-that-reads-images-or-pdfs-natively-gets-them-natively-req-catt-004
		 */
		attachModelCapabilities(payload) {
			if (this.capabilityKey === '') {
				return
			}
			payload.modelCapabilities = {
				[this.capabilityKey]: this.modelCapabilities[this.capabilityKey] || [],
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
			const payload = this.providerPayload()
			if (payload === null) {
				this.saving = false
				return
			}
			try {
				this.attachModelCapabilities(payload)
				await patchLlmSettings(payload)
				// The residency and data-use statements are saved with the provider.
				await this.$refs.declarations?.save()
				this.$emit('saved', this.providerValue)
				this.$emit('close')
			} catch (e) {
				this.error = this.t(
					'hermiq',
					'Could not save the LLM configuration.',
				)
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

.llm-provider__capabilities {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 8px 12px;
	margin: 0;
}

.llm-provider__capabilities legend {
	padding: 0 4px;
	font-weight: bold;
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
