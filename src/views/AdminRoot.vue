<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Nextcloud admin app-settings panel.

 Mounted into `#hermiq-settings` by `src/settings.js` (which
 itself is loaded from `templates/settings/admin.php` via
 `Util::addScript`). This is the panel users reach via Nextcloud's
 "Administration settings" → "Hermiq".

 It carries the "before the app boots" wiring that the manifest-driven
 SPA cannot. The AI provider section explains that Hermiq uses Nextcloud's
 own AI settings (use-nextcloud-ai-settings), links there, and shows per
 task type whether a Nextcloud provider is ready. Choosing a direct
 provider instead stays possible; that picker lives in
 src/modals/LlmProviderModal.vue (modal-isolation gate).

 Also hosts the web-research backend configuration (web-research-tool):
 the pluggable search endpoint/provider shape and the web.fetch
 egress-governance knobs. That picker lives in
 src/modals/WebResearchSettingsModal.vue (modal-isolation gate).

 Also hosts the AI-feature governance register (ai-features-to-admin): the
 EU AI Act risk-classification + DPO-acknowledgement gate that must be
 satisfied before a feature may be enabled instance-wide. Relocated here
 from the in-app nav (`/ai-features`) because it is design-time,
 instance-level governance, not a day-to-day operator page.
 AiFeatureRegister.vue itself is unmodified — only its mount point moves;
 it still reads `is_admin` / `opencatalogi_available` via
 `loadState('hermiq', …)`, now provided by this class's `getForm()`
 instead of DashboardController.

 Also hosts organisation-scope credential management (agent-credentials): the
 shared, already-tested `CnCredentials` component mounted `scope="organisation"`.
 An admin manages the FULL allowed-app list for each org-wide broker
 credential. Personal-scope credentials stay exactly where they already are
 (the app's own in-app "Credentials" settings section, src/App.vue) — this
 admin panel never reintroduces a personal-scope surface.
-->
<template>
	<div class="hermiq-admin-settings">
		<NcSettingsSection
			id="section-ai-provider"
			:name="t('hermiq', 'AI provider')"
			:description="
				t(
					'hermiq',
					'Hermiq uses the AI provider set up for the whole Nextcloud instance. You enter the API key once, under Administration settings > Artificial intelligence.',
				)
			">
			<p class="hermiq-admin-settings__lead">
				{{
					t(
						'hermiq',
						'Hermiq sends chats, agent tool calls and summaries there, as the person it works for.',
					)
				}}
			</p>

			<ul
				v-if="usesNextcloudAi"
				class="hermiq-admin-settings__task-types"
				data-testid="hermiq-nextcloud-ai-status">
				<li
					v-for="row in taskTypeRows"
					:key="row.key"
					class="hermiq-admin-settings__task-type">
					<span class="hermiq-admin-settings__provider-label"
						>{{ row.label }}:</span
					>
					<strong v-if="row.available">{{
						t('hermiq', 'Ready: {provider}', {
							provider: row.provider,
						})
					}}</strong>
					<strong v-else>{{ t('hermiq', 'No provider') }}</strong>
				</li>
			</ul>

			<NcNoteCard v-if="nextcloudAiMissing" type="warning">
				{{ nextcloudAi.adminMessage }}
			</NcNoteCard>

			<div class="hermiq-admin-settings__actions">
				<NcButton
					v-if="nextcloudAi.settingsUrl"
					variant="primary"
					:href="nextcloudAi.settingsUrl">
					<template #icon>
						<OpenInNew :size="20" />
					</template>
					{{ t('hermiq', 'Open Artificial intelligence settings') }}
				</NcButton>
			</div>

			<div class="hermiq-admin-settings__provider">
				<span class="hermiq-admin-settings__provider-label"
					>{{ t('hermiq', 'Current provider') }}:</span
				>
				<strong>{{ currentProviderLabel }}</strong>
			</div>
			<p v-if="!usesNextcloudAi" class="hermiq-admin-settings__lead">
				{{
					t('hermiq', 'Hermiq uses this provider instead of Nextcloud AI.')
				}}
			</p>
			<NcButton variant="secondary" @click="showModal = true">
				<template #icon>
					<Cog :size="20" />
				</template>
				{{ t('hermiq', 'Use another provider') }}
			</NcButton>

			<LlmProviderModal
				:show="showModal"
				@close="showModal = false"
				@saved="onSaved" />
		</NcSettingsSection>

		<NcSettingsSection
			id="section-web-research"
			:name="t('hermiq', 'Web research')"
			:description="
				t(
					'hermiq',
					'Configure the web-search backend and the web.fetch allowlist/denylist that hermiq.webSearch/hermiq.webFetch run behind.',
				)
			">
			<div class="hermiq-admin-settings__provider">
				<span class="hermiq-admin-settings__provider-label"
					>{{ t('hermiq', 'Search backend') }}:</span
				>
				<strong>{{ currentSearchProviderLabel }}</strong>
			</div>
			<NcButton variant="primary" @click="showWebResearchModal = true">
				<template #icon>
					<Cog :size="20" />
				</template>
				{{ t('hermiq', 'Configure web research') }}
			</NcButton>

			<WebResearchSettingsModal
				:show="showWebResearchModal"
				@close="showWebResearchModal = false"
				@saved="onWebResearchSaved" />
		</NcSettingsSection>

		<NcSettingsSection
			:name="t('hermiq', 'AI features')"
			:description="
				t(
					'hermiq',
					'Review the EU AI Act risk classification of each AI feature and acknowledge it as DPO before it may be enabled instance-wide.',
				)
			">
			<AiFeatureRegister />
		</NcSettingsSection>

		<NcSettingsSection
			:name="t('hermiq', 'Conversational intake')"
			:description="
				t(
					'hermiq',
					'How certain the intake has to be before it files a request on somebody’s behalf. Under this, the conversation goes to a person instead, carrying everything that was said.',
				)
			">
			<IntakeSettings />
		</NcSettingsSection>

		<NcSettingsSection
			:name="t('hermiq', 'Report grouping')"
			:description="
				t(
					'hermiq',
					'Where the line falls between one event and two. Two hundred meldingen about one street-wide power cut should read as one item with a count, and a report that only looks alike should not disappear into it.',
				)
			">
			<ReportGroupingSettings />
		</NcSettingsSection>

		<NcSettingsSection
			:name="t('hermiq', 'Assistant prompts')"
			:description="
				t(
					'hermiq',
					'The prompts the assistant offers on a record, with the exact text that is sent to the model. Read them here when you need to explain an answer, and switch them off in one act when you need to stop giving any.',
				)
			">
			<AssistantPromptLibrary />
		</NcSettingsSection>

		<NcSettingsSection
			:name="t('hermiq', 'AI run retention')"
			:description="
				t(
					'hermiq',
					'How long the record of an AI run is kept. Each run is written with the retention that applied at the time, so changing this does not move a promise already made. A daily job removes what a run recorded once it expires, and leaves the audit entry itself in place.',
				)
			">
			<RunRetentionSettings />
		</NcSettingsSection>

		<NcSettingsSection
			:name="t('hermiq', 'Talk chat bridge')"
			:description="
				t(
					'hermiq',
					'Let people converse with an agent from a Nextcloud Talk conversation, including the Talk mobile apps. A conversation only becomes active when a Talk moderator enables the Hermiq bot in it AND an opted-in agent is bound to it here.',
				)
			">
			<TalkBridgeSettings />
		</NcSettingsSection>

		<!-- CnCredentials renders its own heading and description, so no
		     settings-section title wraps it (that duplicated both). -->
		<div
			id="section-organisation-credentials"
			class="hermiq-admin-settings__credentials">
			<CnCredentials
				scope="organisation"
				appId="hermiq"
				:appName="t('hermiq', 'Hermiq')"
				:appCredentials="(manifest && manifest.credentials) || []" />
		</div>
	</div>
</template>

<script>
import { CnCredentials } from '@conduction/nextcloud-vue'
import { NcButton, NcNoteCard, NcSettingsSection } from '@nextcloud/vue'
import Cog from 'vue-material-design-icons/Cog.vue'
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'
import AssistantPromptLibrary from '../components/settings/AssistantPromptLibrary.vue'
import IntakeSettings from '../components/settings/IntakeSettings.vue'
import ReportGroupingSettings from '../components/settings/ReportGroupingSettings.vue'
import RunRetentionSettings from '../components/settings/RunRetentionSettings.vue'
import TalkBridgeSettings from '../components/settings/TalkBridgeSettings.vue'
import LlmProviderModal from '../modals/LlmProviderModal.vue'
import WebResearchSettingsModal from '../modals/WebResearchSettingsModal.vue'
import AiFeatureRegister from './AiFeatureRegister.vue'
import { getLlmSettings } from '../api/llm.js'
import { getWebResearchSettings } from '../api/webResearch.js'
import manifest from '../manifest.json'

const PROVIDER_LABELS = {
	openai: 'OpenAI',
	ollama: 'Ollama (local)',
	fireworks: 'Fireworks AI',
	nextcloud: 'Nextcloud AI',
}

const SEARCH_PROVIDER_LABELS = {
	searxng: 'SearXNG (self-hosted)',
	'generic-json': 'Generic JSON search API',
}

export default {
	name: 'AdminRoot',
	components: {
		AiFeatureRegister,
		AssistantPromptLibrary,
		CnCredentials,
		Cog,
		LlmProviderModal,
		NcButton,
		NcNoteCard,
		OpenInNew,
		IntakeSettings,
		NcSettingsSection,
		ReportGroupingSettings,
		RunRetentionSettings,
		TalkBridgeSettings,
		WebResearchSettingsModal,
	},

	data() {
		return {
			showModal: false,
			chatProvider: null,
			// Nextcloud AI readiness per task type hermiq consumes, the admin
			// message and the link to Nextcloud's AI settings
			// (use-nextcloud-ai-settings).
			nextcloudAi: { taskTypes: {}, adminMessage: '', settingsUrl: '' },
			showWebResearchModal: false,
			searchProvider: null,
			// The app's own manifest.json — read-only, for CnCredentials'
			// "what {app} uses" informational list (agent-credentials).
			manifest,
		}
	},

	computed: {
		/**
		 * Human-readable name of the current provider, or a "not configured" hint.
		 *
		 * @return {string} The label.
		 *
		 * @spec exclude Trivial computed display helper; no behavioural spec.
		 */
		currentProviderLabel() {
			if (this.usesNextcloudAi) {
				return this.t('hermiq', 'Nextcloud AI')
			}
			return PROVIDER_LABELS[this.chatProvider] || this.chatProvider
		},

		/**
		 * Whether hermiq runs on Nextcloud AI: no hermiq-specific provider is
		 * saved, or `nextcloud` is.
		 *
		 * @return {boolean} True on Nextcloud AI.
		 *
		 * @spec openspec/changes/use-nextcloud-ai-settings/tasks.md#task-5-1
		 */
		usesNextcloudAi() {
			return !this.chatProvider || this.chatProvider === 'nextcloud'
		},

		/**
		 * One row per task type hermiq consumes, with its readiness.
		 *
		 * @return {Array<object>} Rows of { key, label, available, provider }.
		 *
		 * @spec openspec/changes/use-nextcloud-ai-settings/tasks.md#task-5-1
		 */
		taskTypeRows() {
			const labels = {
				chat: this.t('hermiq', 'Chat'),
				chatWithTools: this.t('hermiq', 'Chat with tools'),
				text: this.t('hermiq', 'Plain text'),
			}
			const types = this.nextcloudAi.taskTypes || {}
			return Object.keys(labels).map((key) => ({
				key,
				label: labels[key],
				available: Boolean(types[key] && types[key].available),
				provider: (types[key] && types[key].provider) || '',
			}))
		},

		/**
		 * Whether a task type hermiq needs has no Nextcloud provider.
		 *
		 * @return {boolean} True when the admin message should show.
		 *
		 * @spec openspec/changes/use-nextcloud-ai-settings/tasks.md#task-5-1
		 */
		nextcloudAiMissing() {
			return (
				this.usesNextcloudAi
				&& this.taskTypeRows.some((row) => !row.available)
			)
		},

		/**
		 * Human-readable name of the current search backend, or a "not configured" hint.
		 *
		 * @return {string} The label.
		 *
		 * @spec exclude Trivial computed display helper; no behavioural spec.
		 */
		currentSearchProviderLabel() {
			if (!this.searchProvider) {
				return this.t('hermiq', 'Not configured')
			}
			return SEARCH_PROVIDER_LABELS[this.searchProvider] || this.searchProvider
		},
	},

	/**
	 * @spec openspec/changes/taskprocessing-consume-ui/tasks.md#task-2-3
	 */
	mounted() {
		this.refresh()
		this.refreshWebResearch()
	},

	methods: {
		/**
		 * Fetch the current provider selection for display.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/taskprocessing-consume-ui/tasks.md#task-2-3
		 */
		async refresh() {
			try {
				const config = await getLlmSettings()
				this.chatProvider = config.chatProvider || null
				if (config.nextcloudAi) {
					this.nextcloudAi = config.nextcloudAi
				}
			} catch (e) {
				this.chatProvider = null
			}
		},

		/**
		 * Handle a save from the modal: reflect the new provider immediately.
		 *
		 * @param {string} provider The newly-selected provider.
		 * @return {void}
		 *
		 * @spec openspec/changes/taskprocessing-consume-ui/tasks.md#task-2-3
		 */
		onSaved(provider) {
			this.chatProvider = provider
		},

		/**
		 * Fetch the current search-backend selection for display.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/web-research-tool/tasks.md#task-8-admin-settings-ui-for-the-web-research-backend
		 */
		async refreshWebResearch() {
			try {
				const config = await getWebResearchSettings()
				this.searchProvider = config.searchProvider || null
			} catch (e) {
				this.searchProvider = null
			}
		},

		/**
		 * Handle a save from the web-research modal: reflect the new provider immediately.
		 *
		 * @param {string} provider The newly-selected search provider.
		 * @return {void}
		 *
		 * @spec openspec/changes/web-research-tool/tasks.md#task-8-admin-settings-ui-for-the-web-research-backend
		 */
		onWebResearchSaved(provider) {
			this.searchProvider = provider || null
		},
	},
}
</script>

<style scoped>
.hermiq-admin-settings {
	max-width: 720px;
}

.hermiq-admin-settings__provider {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-bottom: 12px;
}

.hermiq-admin-settings__provider-label {
	color: var(--color-text-maxcontrast);
}

.hermiq-admin-settings__lead {
	margin-bottom: 12px;
}

.hermiq-admin-settings__task-types {
	margin-bottom: 12px;
}

.hermiq-admin-settings__task-type {
	display: flex;
	gap: 8px;
}

.hermiq-admin-settings__actions {
	margin: 12px 0;
}

.hermiq-admin-settings__credentials {
	padding: calc(var(--default-grid-baseline) * 7);
}
</style>
