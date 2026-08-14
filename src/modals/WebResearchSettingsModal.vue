<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  WebResearchSettingsModal — admin configures the pluggable web-search backend
  (SearXNG native, or a generic JSON API with a field mapping) and the web.fetch
  egress-governance knobs (allowlist, denylist, insecure-HTTP opt-in, size cap,
  timeout) that hermiq.webSearch/webFetch run behind.

  Own file per the modal-isolation gate. Reads GET /api/settings/web-research (the
  search credential arrives masked as a `searchCredentialConfigured` boolean — the
  raw id is never returned) and PATCHes the change.
-->
<template>
	<NcModal
		:show="show"
		size="normal"
		:name="t('hermiq', 'Web research')"
		@close="$emit('close')">
		<div class="web-research">
			<h2 class="web-research__title">
				{{ t('hermiq', 'Search backend') }}
			</h2>
			<p class="web-research__description">
				{{
					t(
						'hermiq',
						'Choose the web-search backend hermiq.webSearch queries — a self-hosted SearXNG instance, or any JSON search API via a field mapping. Leave unconfigured and the tool reports itself unavailable rather than fabricating results.',
					)
				}}
			</p>

			<div v-if="loading" class="web-research__loading">
				<NcLoadingIcon :size="32" />
			</div>

			<template v-else>
				<div class="web-research__field">
					<NcSelect
						v-model="selectedProvider"
						:inputLabel="t('hermiq', 'Search provider')"
						:options="providerOptions"
						:clearable="false"
						label="label"
						trackBy="value" />
				</div>

				<template v-if="providerValue">
					<NcTextField
						v-model="form.searchEndpoint"
						:label="t('hermiq', 'Search endpoint')"
						placeholder="https://searxng.internal:8080" />

					<template v-if="providerValue === 'generic-json'">
						<NcTextField
							v-model="form.searchFieldMapping.resultsPath"
							:label="t('hermiq', 'Results path (dot-separated)')"
							placeholder="results" />
						<NcTextField
							v-model="form.searchFieldMapping.titleField"
							:label="t('hermiq', 'Title field')"
							placeholder="title" />
						<NcTextField
							v-model="form.searchFieldMapping.urlField"
							:label="t('hermiq', 'URL field')"
							placeholder="url" />
						<NcTextField
							v-model="form.searchFieldMapping.snippetField"
							:label="t('hermiq', 'Snippet field')"
							placeholder="content" />
					</template>

					<NcSelect
						v-model="searchCredential"
						:options="credentialOptions"
						:inputLabel="t('hermiq', 'API credential (optional)')"
						:loading="loadingCredentials"
						:placeholder="t('hermiq', 'None — no authentication')"
						label="label" />
					<p class="web-research__hint">
						{{
							t(
								'hermiq',
								'Only needed for a paid search API. The key stays in your credential vault — Hermiq only ever holds a reference to it.',
							)
						}}
					</p>
				</template>

				<h2 class="web-research__title">
					{{ t('hermiq', 'web.fetch egress governance') }}
				</h2>
				<p class="web-research__description">
					{{
						t(
							'hermiq',
							'Every hermiq.webFetch target is checked against its resolved IP address — private, loopback, link-local and cloud-metadata addresses are always blocked. These lists narrow which hosts an agent may reach further.',
						)
					}}
				</p>

				<NcTextArea
					v-model="allowlistText"
					:label="
						t(
							'hermiq',
							'Allowlist (one host per line — empty means any public host is reachable)',
						)
					"
					:placeholder="'en.wikipedia.org\nwww.rijksoverheid.nl'" />
				<NcTextArea
					v-model="denylistText"
					:label="t('hermiq', 'Denylist (one host per line)')" />
				<NcCheckboxRadioSwitch
					v-model="form.allowInsecureHttp"
					type="switch">
					{{
						t(
							'hermiq',
							'Allow plain http:// (an explicit opt-in — https:// is required by default)',
						)
					}}
				</NcCheckboxRadioSwitch>
				<NcTextField
					v-model="maxResponseBytesText"
					:label="t('hermiq', 'Max response size (bytes)')"
					placeholder="500000" />
				<NcTextField
					v-model="timeoutSecondsText"
					:label="t('hermiq', 'Timeout (seconds)')"
					placeholder="10" />

				<div class="web-research__actions">
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

				<p v-if="error" class="web-research__error">
					{{ error }}
				</p>
			</template>
		</div>
	</NcModal>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcLoadingIcon,
	NcModal,
	NcSelect,
	NcTextArea,
	NcTextField,
} from '@nextcloud/vue'
import {
	getWebResearchSettings,
	patchWebResearchSettings,
} from '../api/webResearch.js'

export default {
	name: 'WebResearchSettingsModal',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcModal,
		NcSelect,
		NcTextArea,
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
			selectedProvider: null,
			credentials: [],
			loadingCredentials: false,
			searchCredential: null,
			allowlistText: '',
			denylistText: '',
			maxResponseBytesText: '500000',
			timeoutSecondsText: '10',
			providerOptions: [
				{
					value: '',
					label: this.t('hermiq', 'None (web.search reports unavailable)'),
				},
				{ value: 'searxng', label: 'SearXNG (self-hosted)' },
				{
					value: 'generic-json',
					label: this.t('hermiq', 'Generic JSON search API'),
				},
			],

			form: {
				searchEndpoint: '',
				allowInsecureHttp: false,
				searchFieldMapping: {
					resultsPath: 'results',
					titleField: 'title',
					urlField: 'url',
					snippetField: 'content',
				},
			},
		}
	},

	computed: {
		/**
		 * The bare provider string of the currently-selected NcSelect option.
		 *
		 * @return {string|null} The provider value, or null/'' when none is selected.
		 *
		 * @spec exclude Trivial computed display helper; no behavioural spec.
		 */
		providerValue() {
			return this.selectedProvider ? this.selectedProvider.value : null
		},

		/**
		 * The broker credentials pickable for a search backend.
		 *
		 * @return {Array} NcSelect options.
		 *
		 * @spec exclude Trivial computed display helper; no behavioural spec.
		 */
		credentialOptions() {
			return this.credentials.map((c) => ({
				label: c.name || c.id,
				value: c.id,
			}))
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
		 * @spec openspec/changes/web-research-tool/tasks.md#task-8-admin-settings-ui-for-the-web-research-backend
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				await this.fetchCredentials()

				const config = await getWebResearchSettings()
				this.form.searchEndpoint = config.searchEndpoint || ''
				this.form.allowInsecureHttp = config.allowInsecureHttp === true
				this.form.searchFieldMapping = {
					resultsPath:
						(config.searchFieldMapping
							&& config.searchFieldMapping.resultsPath)
						|| 'results',

					titleField:
						(config.searchFieldMapping
							&& config.searchFieldMapping.titleField)
						|| 'title',

					urlField:
						(config.searchFieldMapping
							&& config.searchFieldMapping.urlField)
						|| 'url',

					snippetField:
						(config.searchFieldMapping
							&& config.searchFieldMapping.snippetField)
						|| 'content',
				}
				this.allowlistText = (config.fetchAllowlist || []).join('\n')
				this.denylistText = (config.fetchDenylist || []).join('\n')
				this.maxResponseBytesText = String(config.maxResponseBytes || 500000)
				this.timeoutSecondsText = String(config.timeoutSeconds || 10)

				this.selectedProvider =
					this.providerOptions.find(
						(option) => option.value === (config.searchProvider || ''),
					) || this.providerOptions[0]

				if (config.searchCredentialConfigured === true) {
					// The raw id is never returned — only a "configured" flag. The picker
					// cannot pre-select a specific credential without it, so it starts
					// blank; a hint below explains that saving without reselecting leaves
					// the existing credential untouched.
					this.searchCredential = null
				}
			} catch (e) {
				this.error = this.t(
					'hermiq',
					'Could not load the current web-research configuration.',
				)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Load the user's broker credentials.
		 *
		 * @return {Promise<void>}
		 */
		async fetchCredentials() {
			this.loadingCredentials = true
			try {
				const { data } = await axios.get(
					generateUrl('/apps/openregister/api/credentials'),
				)
				this.credentials = data.results || []
			} catch (e) {
				this.credentials = []
			} finally {
				this.loadingCredentials = false
			}
		},

		/**
		 * Parse a newline-separated textarea into a clean list of hostnames.
		 *
		 * @param {string} text The raw textarea value.
		 * @return {Array<string>} The non-empty, trimmed lines.
		 *
		 * @spec exclude Trivial text-to-list parsing helper; no behavioural spec.
		 */
		parseHostList(text) {
			return text
				.split('\n')
				.map((line) => line.trim())
				.filter((line) => line !== '')
		},

		/**
		 * Build the patch payload and PATCH it.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/web-research-tool/tasks.md#task-8-admin-settings-ui-for-the-web-research-backend
		 */
		async save() {
			this.saving = true
			this.error = ''

			const payload = {
				searchProvider: this.providerValue || '',
				searchEndpoint: this.form.searchEndpoint,
				searchFieldMapping: this.form.searchFieldMapping,
				fetchAllowlist: this.parseHostList(this.allowlistText),
				fetchDenylist: this.parseHostList(this.denylistText),
				allowInsecureHttp: this.form.allowInsecureHttp,
				maxResponseBytes: parseInt(this.maxResponseBytesText, 10) || 500000,
				timeoutSeconds: parseInt(this.timeoutSecondsText, 10) || 10,
			}
			// Only a credential REFERENCE is ever sent — never a key. An unselected
			// picker omits the field entirely so an existing credential is preserved.
			if (this.searchCredential) {
				payload.searchCredentialId = this.searchCredential.value
			}

			try {
				await patchWebResearchSettings(payload)
				this.$emit('saved', payload.searchProvider)
				this.$emit('close')
			} catch (e) {
				this.error = this.t(
					'hermiq',
					'Could not save the web-research configuration.',
				)
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.web-research {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 20px;
	max-height: 70vh;
	overflow-y: auto;
}

.web-research__title {
	margin: 12px 0 0;
	font-size: 1.25rem;
}

.web-research__title:first-child {
	margin-top: 0;
}

.web-research__description {
	margin: 0;
	color: var(--color-text-maxcontrast);
	line-height: 1.5;
}

.web-research__field {
	margin-top: 4px;
}

.web-research__loading {
	display: flex;
	justify-content: center;
	padding: 24px 0;
}

.web-research__hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: 0.85rem;
}

.web-research__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 12px;
}

.web-research__error {
	margin: 0;
	color: var(--color-error);
}
</style>
