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

 Also hosts the remote agents (flows-hand-off-and-wait D5): agents on other
 servers reached through an integriq source, in
 src/components/settings/RemoteAgents.vue.

 Also hosts the validated tag (memory-retrieval-scope D2): which restricted
 Nextcloud system tag marks a document as validated, in
 src/components/settings/ValidatedTagSettings.vue.

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
			<div
				v-if="retrievalTier"
				class="hermiq-admin-settings__provider"
				data-testid="retrieval-tier">
				<span class="hermiq-admin-settings__provider-label"
					>{{ t('hermiq', 'Semantic search') }}:</span
				>
				<strong>{{ retrievalTierLabel }}</strong>
			</div>
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
			id="section-validated-documents"
			:name="t('hermiq', 'Validated documents')"
			:description="
				t(
					'hermiq',
					'The Nextcloud tag that marks a document as validated. An agent set to answer only from validated documents searches only files carrying it, and declines without asking the model when none of them matches.',
				)
			">
			<ValidatedTagSettings />
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
			:name="t('hermiq', 'Scheduled runs in parallel')"
			:description="
				t(
					'hermiq',
					'How many scheduled agent runs go at once. The schedule tick hands each due run to a background worker, and each run still runs once. The limit per organisation keeps one organisation\'s nightly agents from holding every worker.',
				)
			">
			<RunConcurrencySettings />
		</NcSettingsSection>

		<NcSettingsSection
			id="section-backups"
			:name="t('hermiq', 'Backups')"
			:description="
				t(
					'hermiq',
					'Back up every organisation\'s hermiq data into a folder in your Files every night: agents, skills, memories, schedules, policies and the other hermiq objects, one file per organisation. Only an administrator sees this section.',
				)
			">
			<p class="hermiq-admin-settings__provider" data-testid="backup-summary">
				{{ backupSummary }}
			</p>
			<NcButton variant="primary" @click="showBackupModal = true">
				<template #icon>
					<Cog :size="20" />
				</template>
				{{ t('hermiq', 'Configure backups') }}
			</NcButton>
			<NcButton :disabled="!backup || !backup.ownerUid || backupQueued" @click="onBackupNow">
				{{ backupQueued ? t('hermiq', 'Backup queued') : t('hermiq', 'Back up now') }}
			</NcButton>
			<NcButton data-testid="restore-open" @click="showRestoreDialog = true">
				{{ t('hermiq', 'Restore') }}
			</NcButton>

			<BackupSettingsModal
				:show="showBackupModal"
				:settings="backup"
				@close="showBackupModal = false"
				@saved="backup = $event" />
			<RestoreDialog
				:show="showRestoreDialog"
				@close="showRestoreDialog = false" />
		</NcSettingsSection>

		<NcSettingsSection
			:name="t('hermiq', 'Code sandbox')"
			:description="
				t(
					'hermiq',
					'Where the code an agent or a flow runs is executed: in the sandbox app on this server, or at a hosted sandbox provider reached through integriq.',
				)
			">
			<CodeSandboxSettings />
		</NcSettingsSection>

		<NcSettingsSection
			:name="t('hermiq', 'Voices for translated recordings')"
			:description="
				t(
					'hermiq',
					'The voice that speaks a translated recording in each language. A language without a voice gets a written translation only.',
				)
			">
			<SpeechVoiceSettings />
		</NcSettingsSection>

		<NcSettingsSection
			:name="t('hermiq', 'Computer targets')"
			:description="
				t(
					'hermiq',
					'The machines an agent may operate, each reached only through an integriq source whose key stays in the credential broker. A machine is off until you switch it on, and every action on it still needs a person\'s approval.',
				)
			">
			<ComputerTargets />
		</NcSettingsSection>

		<NcSettingsSection
			id="section-trace-export"
			:name="t('hermiq', 'Trace export')"
			:description="
				t(
					'hermiq',
					'Send run and chat traces over OpenTelemetry (OTLP) to a tool such as Langfuse, Grafana Tempo or Jaeger. The export runs in the background and never slows a run.',
				)
			">
			<TraceExportSettings />
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

		<NcSettingsSection
			id="section-remote-agents"
			:name="t('hermiq', 'Remote agents')"
			:description="
				t(
					'hermiq',
					'Agents on other servers that a local agent may hand work to and wait for. Each is reached through an integriq source, which holds the address and the credential; Hermiq keeps only the agent card it fetched.',
				)
			">
			<RemoteAgents />
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
import CodeSandboxSettings from '../components/settings/CodeSandboxSettings.vue'
import ComputerTargets from '../components/settings/ComputerTargets.vue'
import IntakeSettings from '../components/settings/IntakeSettings.vue'
import RemoteAgents from '../components/settings/RemoteAgents.vue'
import ReportGroupingSettings from '../components/settings/ReportGroupingSettings.vue'
import RunConcurrencySettings from '../components/settings/RunConcurrencySettings.vue'
import RunRetentionSettings from '../components/settings/RunRetentionSettings.vue'
import SpeechVoiceSettings from '../components/settings/SpeechVoiceSettings.vue'
import TalkBridgeSettings from '../components/settings/TalkBridgeSettings.vue'
import TraceExportSettings from '../components/settings/TraceExportSettings.vue'
import ValidatedTagSettings from '../components/settings/ValidatedTagSettings.vue'
import BackupSettingsModal from '../modals/BackupSettingsModal.vue'
import LlmProviderModal from '../modals/LlmProviderModal.vue'
import RestoreDialog from '../modals/RestoreDialog.vue'
import WebResearchSettingsModal from '../modals/WebResearchSettingsModal.vue'
import AiFeatureRegister from './AiFeatureRegister.vue'
import { backupNow, getBackupSettings } from '../api/backup.js'
import { getLlmSettings } from '../api/llm.js'
import { getRetrievalTier } from '../api/retrievalTier.js'
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

/**
 * What the admin reads about the retrieval tier.
 *
 * @param {{tier: string, reason: string}|null} tier The tier from the settings read-out.
 * @param {(app: string, text: string) => string} t The translate function.
 * @return {string} The label.
 * @spec openspec/changes/vector-rag/tasks.md#task-4-1
 */
export function retrievalTierLabel(tier, t) {
	if (tier && tier.tier === 'vector-live') {
		return t('hermiq', 'Vector search through Open Register')
	}
	if (tier && tier.reason === 'no-embedding-backend') {
		return t('hermiq', 'Keyword search: Open Register has no embedding provider configured')
	}
	return t('hermiq', 'Keyword search: this Open Register has no vector search')
}

export default {
	name: 'AdminRoot',
	components: {
		AiFeatureRegister,
		AssistantPromptLibrary,
		BackupSettingsModal,
		CnCredentials,
		Cog,
		LlmProviderModal,
		RestoreDialog,
		NcButton,
		NcNoteCard,
		OpenInNew,
		CodeSandboxSettings,
		IntakeSettings,
		NcSettingsSection,
		RemoteAgents,
		ReportGroupingSettings,
		RunRetentionSettings,
		SpeechVoiceSettings,
		RunConcurrencySettings,
		ComputerTargets,
		TalkBridgeSettings,
		TraceExportSettings,
		ValidatedTagSettings,
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
			// operations-backup-and-restore: the stored backup settings, null until read.
			backup: null,
			showBackupModal: false,
			showRestoreDialog: false,
			backupQueued: false,
			// Which tier semantic and hybrid RAG run on (vector-rag), read-only.
			retrievalTier: null,
			// The app's own manifest.json — read-only, for CnCredentials'
			// "what {app} uses" informational list (agent-credentials).
			manifest,
		}
	},

	computed: {
		/**
		 * One line on the backup: off, or when, where and how many are kept.
		 *
		 * @return {string} The line.
		 * @spec openspec/changes/operations-backup-and-restore/specs/backup-restore/spec.md#requirement-an-administrator-can-back-up-an-organisations-hermiq-data-to-files-on-a-schedule-req-bkup-001
		 */
		backupSummary() {
			if (!this.backup || !this.backup.enabled) {
				return this.t('hermiq', 'Nightly backups are off.')
			}
			return this.t('hermiq', 'Every night at {hour}:00 into "{folder}", keeping {keep} per organisation.', {
				hour: String(this.backup.hour).padStart(2, '0'),
				folder: this.backup.folder,
				keep: this.backup.keep,
			})
		},

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

		/**
		 * The retrieval tier as the admin reads it.
		 *
		 * @return {string} The label.
		 * @spec openspec/changes/vector-rag/tasks.md#task-4-1
		 */
		retrievalTierLabel() {
			return retrievalTierLabel(this.retrievalTier, this.t)
		},
	},

	/**
	 * @spec openspec/changes/taskprocessing-consume-ui/tasks.md#task-2-3
	 */
	mounted() {
		this.refresh()
		this.refreshWebResearch()
		this.refreshBackup()
		this.refreshRetrievalTier()
	},

	methods: {
		/**
		 * Read the backup settings; a failure leaves the section saying backups are off.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/operations-backup-and-restore/specs/backup-restore/spec.md#requirement-an-administrator-can-back-up-an-organisations-hermiq-data-to-files-on-a-schedule-req-bkup-001
		 */
		async refreshBackup() {
			try {
				this.backup = await getBackupSettings()
			} catch {
				this.backup = null
			}
		},

		/**
		 * Queue a backup now.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/operations-backup-and-restore/specs/backup-restore/spec.md#requirement-an-administrator-can-back-up-an-organisations-hermiq-data-to-files-on-a-schedule-req-bkup-001
		 */
		async onBackupNow() {
			try {
				await backupNow()
				this.backupQueued = true
			} catch {
				this.backupQueued = false
			}
		},

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
		 * @spec openspec/changes/archive/2026-07-14-web-research-tool/tasks.md#task-8-admin-settings-ui-for-the-web-research-backend
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
		 * Load which retrieval tier is live; a failed read shows nothing.
		 *
		 * @spec openspec/changes/vector-rag/tasks.md#task-4-1
		 */
		async refreshRetrievalTier() {
			this.retrievalTier = await getRetrievalTier()
		},

		/**
		 * Handle a save from the web-research modal: reflect the new provider immediately.
		 *
		 * @param {string} provider The newly-selected search provider.
		 * @return {void}
		 *
		 * @spec openspec/changes/archive/2026-07-14-web-research-tool/tasks.md#task-8-admin-settings-ui-for-the-web-research-backend
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
