<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  SkillRowActions — SkillsCatalog's per-row write actions as a
  `page.slots.row-actions` custom widget over the generic `type:"index"`
  SkillsCatalog page (manifest-driven-pages), mirroring
  AgentTemplateRowActions.vue exactly.

  "Approve" (quarantined only), "Export" and "Install onto agent" all call
  the existing tenant-scoped SkillController endpoints unchanged
  (src/api/skills.js) — no new write path, no declarative `object-op` patch.
  "Publish" hits a structured seam (publishSkill) that may return `{ error }`
  when no hub is configured; that is surfaced as an error note, not thrown.

  Receives `{ row }` from CnIndexPage's `#row-actions` scoped slot — `row` is
  the raw Skill OpenRegister object (register:"hermiq" schema:"agentskill").

  Install needs an agent picker: agents are loaded lazily (only when the
  install modal opens) via `useAgentStore()` +
  `registerObjectType('agent', 'agent', 'hermiq')` + `fetchCollection('agent')`
  — the same store the retired SkillsCatalog.vue used — mirroring how
  AgentTemplateRowActions lazily loads GitHub credentials in
  `fetchCredentials()`.

  hermiq-github-store adds a fifth action here, "Publish to GitHub" — a form
  (owner/repo/visibility/credential) that calls the new guarded
  `github/publish` endpoint, the new PRIMARY skill-publish path (the existing
  "Publish" button above stays as the secondary OpenConnector hub route). A
  broker `credentialId` is required; the GitHub token never reaches Hermiq —
  mirrors AgentTemplateRowActions' publish-to-GitHub form exactly.

  @spec openspec/changes/manifest-driven-pages/specs/manifest-driven-pages/spec.md#req-010-agenttemplategallery-renders-as-an-index-type-list-page-with-write-actions-kept-behind-their-existing-guarded-endpoints
  @spec openspec/changes/skills-catalog/specs/skills-catalog/spec.md
  @spec openspec/changes/skills-marketplace/tasks.md
  @spec openspec/changes/hermiq-github-store/specs/skills-marketplace/spec.md#requirement-a-skill-can-be-published-to-a-tagged-github-repository-as-the-primary-path
-->
<template>
	<div class="skill-row-actions">
		<!-- skill-self-improvement: the catalog behind-badge — a pure client-side
		     publishedAt < lastAcceptedVersionAt comparison (no per-row history
		     query); text, never icon-only. -->
		<span
			v-if="publishedCopyBehind"
			class="skill-row-actions__behind-badge"
			role="status">
			{{ t('hermiq', 'Published copy is behind') }}
		</span>

		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<NcNoteCard v-if="githubPublishNotice" type="success">
			{{ githubPublishNotice }}
		</NcNoteCard>

		<div class="skill-row-actions__buttons">
			<NcButton
				v-if="row.state === 'quarantined'"
				type="secondary"
				:disabled="busy"
				:aria-label="t('hermiq', 'Approve quarantined skill')"
				@click="doApprove">
				{{ t('hermiq', 'Approve') }}
			</NcButton>
			<NcButton
				type="tertiary"
				:disabled="busy"
				:aria-label="t('hermiq', 'Qualify skill maturity')"
				@click="doQualify">
				{{ t('hermiq', 'Qualify') }}
			</NcButton>
			<NcButton
				type="tertiary"
				:disabled="busy"
				:aria-label="t('hermiq', 'Export skill')"
				@click="doExport">
				{{ t('hermiq', 'Export') }}
			</NcButton>
			<NcButton
				type="tertiary"
				:disabled="busy"
				:aria-label="t('hermiq', 'Publish skill to a hub')"
				@click="doPublish">
				{{ t('hermiq', 'Publish') }}
			</NcButton>
			<NcButton
				type="tertiary"
				:disabled="busy"
				:aria-label="t('hermiq', 'Publish skill to GitHub')"
				@click="openGithubPublishModal">
				{{ t('hermiq', 'Publish to GitHub') }}
			</NcButton>
			<NcButton
				type="tertiary"
				:disabled="busy"
				:aria-label="t('hermiq', 'Install on agent')"
				@click="openInstallModal">
				{{ t('hermiq', 'Install') }}
			</NcButton>
		</div>

		<SkillScorecardModal
			v-if="scorecardResult"
			:result="scorecardResult"
			:skill-name="row.name || ''"
			@close="scorecardResult = null" />

		<NcModal v-if="showExport" @close="showExport = false">
			<div class="skill-row-actions__export-modal">
				<h3>{{ t('hermiq', 'Exported package') }}</h3>
				<textarea class="skill-row-actions__textarea" readonly :value="exportedPackage" />
			</div>
		</NcModal>

		<NcModal v-if="showInstall" @close="closeInstallModal">
			<div class="skill-row-actions__install-modal">
				<h3>{{ t('hermiq', 'Install on agent') }}</h3>
				<NcNoteCard v-if="installError" type="error">
					{{ installError }}
				</NcNoteCard>
				<NcSelect v-model="selectedAgent"
					:options="agentOptions"
					:input-label="t('hermiq', 'Agent')"
					:loading="loadingAgents"
					:placeholder="t('hermiq', 'Select agent')"
					label="label" />
				<p v-if="!loadingAgents && agentOptions.length === 0" class="skill-row-actions__hint">
					{{ t('hermiq', 'No agents yet. Create one under Agents, then reopen this dialog.') }}
				</p>
				<NcButton
					type="primary"
					:disabled="installing || !canInstall"
					@click="doInstall">
					{{ installing ? t('hermiq', 'Installing…') : t('hermiq', 'Install') }}
				</NcButton>
			</div>
		</NcModal>

		<NcModal v-if="showGithubPublish" @close="closeGithubPublishModal">
			<div class="skill-row-actions__publish-modal">
				<h3>{{ t('hermiq', 'Publish skill to GitHub') }}</h3>
				<NcNoteCard v-if="githubPublishError" type="error">
					{{ githubPublishError }}
				</NcNoteCard>
				<NcTextField :value.sync="githubPublishForm.owner"
					:label="t('hermiq', 'Owner')"
					:placeholder="t('hermiq', 'e.g. acme-council')" />
				<NcTextField :value.sync="githubPublishForm.repo"
					:label="t('hermiq', 'Repository name')"
					:placeholder="t('hermiq', 'e.g. demo-skill')" />
				<NcSelect v-model="githubPublishVisibility"
					:options="visibilityOptions"
					:input-label="t('hermiq', 'Visibility')"
					:clearable="false"
					label="label"
					track-by="value" />
				<NcSelect v-model="githubPublishCredential"
					:options="githubCredentials"
					:input-label="t('hermiq', 'GitHub credential')"
					:loading="loadingGithubCredentials"
					:placeholder="t('hermiq', 'Select a credential')"
					label="label" />
				<p v-if="!loadingGithubCredentials && githubCredentials.length === 0" class="skill-row-actions__hint">
					{{ t('hermiq', 'No GitHub credential yet. Add a personal one under your Personal settings, or ask an organisation admin to add one under the Hermiq admin settings, then reopen this dialog.') }}
				</p>
				<NcButton
					type="primary"
					:disabled="githubPublishing || !canGithubPublish"
					@click="doGithubPublish">
					{{ githubPublishing ? t('hermiq', 'Publishing…') : t('hermiq', 'Publish') }}
				</NcButton>
			</div>
		</NcModal>
	</div>
</template>

<script>
import { NcButton, NcModal, NcNoteCard, NcSelect, NcTextField } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { emit } from '@nextcloud/event-bus'
import { approveSkill, exportSkill, installSkill, publishSkill, publishSkillToGithub, qualifySkill } from '../api/skills.js'
import { useAgentStore } from '../store/store.js'
// skill-maturity: the post-qualify scorecard, its own file per the
// modal-isolation rule.
import SkillScorecardModal from '../modals/SkillScorecardModal.vue'

export default {
	name: 'SkillRowActions',

	components: {
		NcButton,
		NcModal,
		NcNoteCard,
		NcSelect,
		NcTextField,
		SkillScorecardModal,
	},

	props: {
		/** The Skill row object (raw OpenRegister object). */
		row: {
			type: Object,
			required: true,
		},
	},

	data() {
		return {
			busy: false,
			error: '',
			// skill-maturity: the qualify endpoint's scorecard payload; non-null
			// opens the SkillScorecardModal.
			scorecardResult: null,
			exportedPackage: '',
			showExport: false,
			showInstall: false,
			installing: false,
			installError: '',
			selectedAgent: null,
			agents: [],
			loadingAgents: false,
			// hermiq-github-store: "Publish to GitHub" form state, mirroring
			// AgentTemplateRowActions' publish-modal state exactly.
			showGithubPublish: false,
			githubPublishing: false,
			githubPublishError: '',
			githubPublishNotice: '',
			githubPublishForm: {
				owner: '',
				repo: '',
			},
			githubPublishVisibility: { label: this.t('hermiq', 'Private'), value: 'private' },
			githubPublishCredential: null,
			githubCredentialsList: [],
			loadingGithubCredentials: false,
		}
	},

	computed: {
		/**
		 * Whether the published GitHub copy is behind the locally accepted version
		 * (skill-self-improvement): githubRepo set AND publishedAt older than
		 * lastAcceptedVersionAt — computed client-side per row, no history query.
		 *
		 * @return {boolean}
		 */
		publishedCopyBehind() {
			const publishedAt = this.row?.publishedAt
			const acceptedAt = this.row?.lastAcceptedVersionAt
			if (!this.row?.githubRepo || !publishedAt || !acceptedAt) {
				return false
			}
			const published = new Date(publishedAt).getTime()
			const accepted = new Date(acceptedAt).getTime()
			return Number.isFinite(published) && Number.isFinite(accepted) && accepted > published
		},

		/**
		 * The available agents as NcSelect options.
		 *
		 * @return {Array<object>} NcSelect options.
		 */
		agentOptions() {
			return this.agents.map((agent) => ({
				label: agent.name || agent.uuid || agent.id,
				value: agent.uuid || agent.id,
			}))
		},

		/**
		 * Whether the install form has everything it needs to submit.
		 *
		 * @return {boolean}
		 */
		canInstall() {
			return !!this.selectedAgent
		},

		/**
		 * The publish visibility options (hermiq-github-store, mirrors
		 * AgentTemplateRowActions' `visibilityOptions`).
		 *
		 * @return {Array<object>} NcSelect options.
		 */
		visibilityOptions() {
			return [
				{ label: this.t('hermiq', 'Private'), value: 'private' },
				{ label: this.t('hermiq', 'Public'), value: 'public' },
			]
		},

		/**
		 * The caller's broker credentials scoped to the `github` provider
		 * (hermiq-github-store).
		 *
		 * @return {Array<object>} NcSelect options.
		 */
		githubCredentials() {
			return this.githubCredentialsList
				.filter((c) => c.provider === 'github')
				.map((c) => ({ label: c.name || c.id, value: c.id }))
		},

		/**
		 * Whether the "Publish to GitHub" form has everything it needs to submit.
		 *
		 * @return {boolean}
		 */
		canGithubPublish() {
			return this.githubPublishForm.owner.trim() !== '' && this.githubPublishForm.repo.trim() !== '' && !!this.githubPublishCredential
		},
	},

	methods: {
		/**
		 * The row's uuid (OpenRegister objects expose both `uuid` and `id`
		 * depending on read path; prefer `uuid`).
		 *
		 * @return {string} The skill uuid.
		 */
		skillId() {
			return this.row.uuid || this.row.id
		},

		/**
		 * Approve a quarantined skill (the review gate → active). Refreshes
		 * the list via the shared `cn:page:refresh` signal so the row's state
		 * column updates.
		 *
		 * @return {Promise<void>}
		 */
		async doApprove() {
			this.busy = true
			this.error = ''
			try {
				await approveSkill(this.skillId())
				emit('cn:page:refresh', {})
			} catch (e) {
				this.error = e?.response?.data?.error || e?.message || this.t('hermiq', 'Unknown error')
			} finally {
				this.busy = false
			}
		},

		/**
		 * Qualify this skill (skill-maturity): recompute + persist its maturity
		 * server-side, show the returned scorecard (with the failing levels'
		 * reasons), and refresh the list so the dots column updates.
		 *
		 * @return {Promise<void>}
		 */
		async doQualify() {
			this.busy = true
			this.error = ''
			try {
				this.scorecardResult = await qualifySkill(this.skillId())
				emit('cn:page:refresh', {})
			} catch (e) {
				this.error = e?.response?.data?.error || e?.message || this.t('hermiq', 'Unknown error')
			} finally {
				this.busy = false
			}
		},

		/**
		 * Export this skill to a shareable agentskills.io package and show it.
		 *
		 * @return {Promise<void>}
		 */
		async doExport() {
			this.error = ''
			try {
				this.exportedPackage = await exportSkill(this.skillId())
				this.showExport = true
			} catch (e) {
				this.error = e?.response?.data?.error || e?.message || this.t('hermiq', 'Unknown error')
			}
		},

		/**
		 * Publish this skill to an external hub (structured seam error when
		 * unavailable — surfaced as a note, not thrown).
		 *
		 * @return {Promise<void>}
		 */
		async doPublish() {
			this.busy = true
			this.error = ''
			try {
				const result = await publishSkill(this.skillId())
				if (result && result.error) {
					this.error = result.error.message || this.t('hermiq', 'Publishing is not available.')
				}
			} catch (e) {
				this.error = e?.response?.data?.error || e?.message || this.t('hermiq', 'Unknown error')
			} finally {
				this.busy = false
			}
		},

		/**
		 * Open the "Publish to GitHub" form, loading the caller's broker
		 * credentials (for the required GitHub credential picker) the first
		 * time (hermiq-github-store, mirrors AgentTemplateRowActions'
		 * `openPublishModal()`).
		 *
		 * @return {void}
		 */
		openGithubPublishModal() {
			this.githubPublishError = ''
			this.githubPublishNotice = ''
			this.showGithubPublish = true
			this.fetchGithubCredentials()
		},

		/**
		 * Close the "Publish to GitHub" form, resetting its per-open state.
		 *
		 * @return {void}
		 */
		closeGithubPublishModal() {
			this.showGithubPublish = false
			this.githubPublishError = ''
		},

		/**
		 * Load the caller's broker credentials (for the GitHub credential
		 * picker) (hermiq-github-store).
		 *
		 * @return {Promise<void>}
		 */
		async fetchGithubCredentials() {
			this.loadingGithubCredentials = true
			try {
				const { data } = await axios.get(generateUrl('/apps/openregister/api/credentials'))
				this.githubCredentialsList = data.results || []
			} catch (e) {
				this.githubCredentialsList = []
			} finally {
				this.loadingGithubCredentials = false
			}
		},

		/**
		 * Publish this skill to a new GitHub repository tagged
		 * `topic:hermiq-skill` (hermiq-github-store — the new primary publish
		 * path). A broker `github` credential is required — the GitHub token
		 * never reaches Hermiq.
		 *
		 * @return {Promise<void>}
		 */
		async doGithubPublish() {
			this.githubPublishing = true
			this.githubPublishError = ''
			try {
				const result = await publishSkillToGithub(this.skillId(), {
					owner: this.githubPublishForm.owner.trim(),
					repo: this.githubPublishForm.repo.trim(),
					visibility: this.githubPublishVisibility?.value || 'private',
					credentialId: this.githubPublishCredential?.value,
				})
				this.githubPublishNotice = this.t('hermiq', 'Published to {repoUrl}', { repoUrl: result.repoUrl })
				this.showGithubPublish = false
				emit('cn:page:refresh', {})
			} catch (e) {
				this.githubPublishError = e?.response?.data?.error || e?.message || this.t('hermiq', 'Unknown error')
			} finally {
				this.githubPublishing = false
			}
		},

		/**
		 * Open the "Install on agent" form, loading the tenant's agents (for
		 * the required agent picker) the first time.
		 *
		 * @return {void}
		 */
		openInstallModal() {
			this.installError = ''
			this.selectedAgent = null
			this.showInstall = true
			this.fetchAgents()
		},

		/**
		 * Close the install form, resetting its per-open state.
		 *
		 * @return {void}
		 */
		closeInstallModal() {
			this.showInstall = false
			this.installError = ''
		},

		/**
		 * Load the tenant's agents (for the agent picker), mirroring the
		 * retired SkillsCatalog.vue's own agent load.
		 *
		 * @return {Promise<void>}
		 */
		async fetchAgents() {
			this.loadingAgents = true
			try {
				const agentStore = useAgentStore()
				agentStore.registerObjectType('agent', 'agent', 'hermiq')
				const agents = await agentStore.fetchCollection('agent')
				this.agents = Array.isArray(agents) ? agents : []
			} catch (e) {
				this.agents = []
			} finally {
				this.loadingAgents = false
			}
		},

		/**
		 * Install this skill onto the selected agent (records the agent on
		 * `installedOn`). Refreshes the list via the shared `cn:page:refresh`
		 * signal so the row's installed count updates.
		 *
		 * @return {Promise<void>}
		 */
		async doInstall() {
			if (!this.selectedAgent) {
				return
			}
			this.installing = true
			this.installError = ''
			try {
				await installSkill(this.skillId(), this.selectedAgent.value)
				this.showInstall = false
				emit('cn:page:refresh', {})
			} catch (e) {
				this.installError = e?.response?.data?.error || e?.message || this.t('hermiq', 'Unknown error')
			} finally {
				this.installing = false
			}
		},
	},
}
</script>

<style scoped>
.skill-row-actions__behind-badge {
	display: inline-block;
	padding: 2px 8px;
	border: 1px solid var(--color-warning);
	border-radius: var(--border-radius-pill);
	color: var(--color-warning-text, var(--color-main-text));
	background-color: var(--color-warning-hover, transparent);
	font-size: 12px;
	white-space: nowrap;
}

.skill-row-actions__buttons {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
	justify-content: flex-end;
}

.skill-row-actions__export-modal,
.skill-row-actions__install-modal,
.skill-row-actions__publish-modal {
	padding: 20px;
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.skill-row-actions__textarea {
	width: 100%;
	min-height: 120px;
	font-family: monospace;
	font-size: 13px;
	margin-bottom: 8px;
	resize: vertical;
}

.skill-row-actions__hint {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	margin: -4px 0 0;
}
</style>
