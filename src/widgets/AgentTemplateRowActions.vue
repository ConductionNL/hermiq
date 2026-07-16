<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  AgentTemplateRowActions — the unified Store page's (formerly
  AgentTemplateGallery, hermiq-github-store) per-row write actions as a
  `page.slots.row-actions` custom widget over the generic `type:"index"`
  Store page (manifest-driven-pages).

  "Use this template", "Approve" (quarantined only) and "Export" all call the
  existing guarded AgentTemplateController endpoints unchanged
  (src/api/agentTemplates.js) — NEVER a declarative `object-op` patch, since
  e.g. approving a quarantined template gates through
  `ActionAuthService::requireAction('agenttemplate.approve-quarantined')`
  server-side, a check the generic OpenRegister object-patch path does not
  express (design.md Decision 6).

  Receives `{ row }` from CnIndexPage's `#row-actions` scoped slot — `row` is
  the raw AgentTemplate OpenRegister object (this page's generic index read
  path is register:"hermiq" schema:"agenttemplate", verified read-equivalent
  to the removed bespoke AgentTemplateGallery.vue's own read in design.md
  Decision 5).

  agent-template-github-store adds a fourth action here, "Publish to GitHub"
  (active templates only) — a form (owner/repo/visibility/credential) that
  calls the new guarded `publish-github` endpoint. A broker `credentialId` is
  required; the GitHub token never reaches Hermiq.

  @spec openspec/changes/manifest-driven-pages/specs/manifest-driven-pages/spec.md#req-010-agenttemplategallery-renders-as-an-index-type-list-page-with-write-actions-kept-behind-their-existing-guarded-endpoints
  @spec openspec/changes/agent-template-github-store/specs/agent-template-github-store/spec.md#requirement-the-system-must-let-a-template-owner-publish-it-to-a-new-tagged-github-repository
-->
<template>
	<div class="agent-template-row-actions">
		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<NcNoteCard v-if="publishNotice" type="success">
			{{ publishNotice }}
		</NcNoteCard>

		<NcNoteCard v-if="instantiateNote" type="info" :heading="t('hermiq', 'Agent created')">
			<p v-if="instantiateNote.modelCoerced">
				{{ t('hermiq', 'The suggested model ({requested}) is outside your organisation\'s model policy. The agent was created with {resolved} instead.', { requested: instantiateNote.requestedProvider + '/' + instantiateNote.requestedModel, resolved: instantiateNote.resolvedProvider + '/' + instantiateNote.resolvedModel }) }}
			</p>
			<p v-if="instantiateNote.unresolvedSkillRefs && instantiateNote.unresolvedSkillRefs.length > 0">
				{{ n('hermiq', '%n suggested skill could not be found in your organisation and was skipped.', '%n suggested skills could not be found in your organisation and were skipped.', instantiateNote.unresolvedSkillRefs.length) }}
			</p>
			<NcButton type="primary" @click="openInstantiatedAgent">
				{{ t('hermiq', 'Open agent') }}
			</NcButton>
		</NcNoteCard>

		<div class="agent-template-row-actions__buttons">
			<NcButton
				v-if="row.state === 'active'"
				type="primary"
				:disabled="busy"
				:aria-label="t('hermiq', 'Use this template')"
				@click="doInstantiate">
				{{ t('hermiq', 'Use this template') }}
			</NcButton>
			<NcButton
				v-if="row.state === 'quarantined'"
				type="secondary"
				:disabled="busy"
				:aria-label="t('hermiq', 'Approve quarantined template')"
				@click="doApprove">
				{{ t('hermiq', 'Approve') }}
			</NcButton>
			<NcButton
				type="tertiary"
				:aria-label="t('hermiq', 'Export template')"
				@click="doExport">
				{{ t('hermiq', 'Export') }}
			</NcButton>
			<NcButton
				v-if="row.state === 'active'"
				type="tertiary"
				:aria-label="t('hermiq', 'Publish to GitHub')"
				@click="openPublishModal">
				{{ t('hermiq', 'Publish to GitHub') }}
			</NcButton>
		</div>

		<NcModal v-if="showExport" @close="showExport = false">
			<div class="agent-template-row-actions__export-modal">
				<h3>{{ t('hermiq', 'Exported package') }}</h3>
				<textarea class="agent-template-row-actions__textarea" readonly :value="exportedPackage" />
			</div>
		</NcModal>

		<NcModal v-if="showPublish" @close="closePublishModal">
			<div class="agent-template-row-actions__publish-modal">
				<h3>{{ t('hermiq', 'Publish to GitHub') }}</h3>
				<NcNoteCard v-if="publishError" type="error">
					{{ publishError }}
				</NcNoteCard>
				<NcTextField :value.sync="publishForm.owner"
					:label="t('hermiq', 'Owner')"
					:placeholder="t('hermiq', 'e.g. acme-council')" />
				<NcTextField :value.sync="publishForm.repo"
					:label="t('hermiq', 'Repository name')"
					:placeholder="t('hermiq', 'e.g. morning-briefing-template')" />
				<NcSelect v-model="publishVisibility"
					:options="visibilityOptions"
					:input-label="t('hermiq', 'Visibility')"
					:clearable="false"
					label="label"
					track-by="value" />
				<NcSelect v-model="publishCredential"
					:options="githubCredentials"
					:input-label="t('hermiq', 'GitHub credential')"
					:loading="loadingCredentials"
					:placeholder="t('hermiq', 'Select a credential')"
					label="label" />
				<p v-if="!loadingCredentials && githubCredentials.length === 0" class="agent-template-row-actions__hint">
					{{ t('hermiq', 'No GitHub credential yet. Add a personal one under your Personal settings, or ask an organisation admin to add one under the Hermiq admin settings, then reopen this dialog.') }}
				</p>
				<NcButton
					type="primary"
					:disabled="publishing || !canPublish"
					@click="doPublish">
					{{ publishing ? t('hermiq', 'Publishing…') : t('hermiq', 'Publish') }}
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
import {
	approveAgentTemplate,
	exportAgentTemplate,
	instantiateAgentTemplate,
	publishAgentTemplateToGithub,
} from '../api/agentTemplates.js'

export default {
	name: 'AgentTemplateRowActions',

	components: {
		NcButton,
		NcModal,
		NcNoteCard,
		NcSelect,
		NcTextField,
	},

	props: {
		/** The AgentTemplate row object (raw OpenRegister object). */
		row: {
			type: Object,
			required: true,
		},
	},

	data() {
		return {
			busy: false,
			error: '',
			instantiateNote: null,
			exportedPackage: '',
			showExport: false,
			showPublish: false,
			publishing: false,
			publishError: '',
			publishNotice: '',
			publishForm: {
				owner: '',
				repo: '',
			},
			publishVisibility: { label: this.t('hermiq', 'Private'), value: 'private' },
			publishCredential: null,
			credentials: [],
			loadingCredentials: false,
		}
	},

	computed: {
		/**
		 * The publish visibility options (mirrors OpenBuild's `ExportsController`
		 * default: new repos default to `private`).
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
		 * The caller's broker credentials scoped to the `github` provider.
		 *
		 * @return {Array<object>} NcSelect options.
		 */
		githubCredentials() {
			return this.credentials
				.filter((c) => c.provider === 'github')
				.map((c) => ({ label: c.name || c.id, value: c.id }))
		},

		/**
		 * Whether the publish form has everything it needs to submit.
		 *
		 * @return {boolean}
		 */
		canPublish() {
			return this.publishForm.owner.trim() !== '' && this.publishForm.repo.trim() !== '' && !!this.publishCredential
		},
	},

	methods: {
		/**
		 * The row's uuid (OpenRegister objects expose both `uuid` and `id`
		 * depending on read path; prefer `uuid`).
		 *
		 * @return {string} The template uuid.
		 */
		templateId() {
			return this.row.uuid || this.row.id
		},

		/**
		 * "Use this template" — instantiate into a real Agent. Surfaces any
		 * model coercion / unresolved skill refs in a note-card before the
		 * user navigates on.
		 *
		 * @return {Promise<void>}
		 */
		async doInstantiate() {
			this.busy = true
			this.error = ''
			this.instantiateNote = null
			try {
				const result = await instantiateAgentTemplate(this.templateId())
				const hasNote = result.modelCoerced || (result.unresolvedSkillRefs && result.unresolvedSkillRefs.length > 0)
				if (hasNote) {
					this.instantiateNote = result
				} else {
					this.$router.push(`/agents/${result.agent?.uuid}`)
				}
			} catch (e) {
				this.error = e?.response?.data?.error || e?.message || this.t('hermiq', 'Unknown error')
			} finally {
				this.busy = false
			}
		},

		/**
		 * Navigate to the agent created by the last instantiate call.
		 *
		 * @return {void}
		 */
		openInstantiatedAgent() {
			const agentId = this.instantiateNote?.agent?.uuid
			this.instantiateNote = null
			if (agentId) {
				this.$router.push(`/agents/${agentId}`)
			}
		},

		/**
		 * Approve a quarantined template (the review gate → active). Refreshes
		 * the list via the shared `cn:page:refresh` signal so the row's state
		 * column updates.
		 *
		 * @return {Promise<void>}
		 */
		async doApprove() {
			this.busy = true
			this.error = ''
			try {
				await approveAgentTemplate(this.templateId())
				emit('cn:page:refresh', {})
			} catch (e) {
				this.error = e?.response?.data?.error || e?.message || this.t('hermiq', 'Unknown error')
			} finally {
				this.busy = false
			}
		},

		/**
		 * Export this template to a shareable JSON package and show it.
		 *
		 * @return {Promise<void>}
		 */
		async doExport() {
			this.error = ''
			try {
				this.exportedPackage = await exportAgentTemplate(this.templateId())
				this.showExport = true
			} catch (e) {
				this.error = e?.response?.data?.error || e?.message || this.t('hermiq', 'Unknown error')
			}
		},

		/**
		 * Open the "Publish to GitHub" form, loading the caller's broker
		 * credentials (for the required GitHub credential picker) the first time.
		 *
		 * @return {void}
		 */
		openPublishModal() {
			this.publishError = ''
			this.publishNotice = ''
			this.showPublish = true
			this.fetchCredentials()
		},

		/**
		 * Close the publish form, resetting its per-open state.
		 *
		 * @return {void}
		 */
		closePublishModal() {
			this.showPublish = false
			this.publishError = ''
		},

		/**
		 * Load the caller's broker credentials (for the GitHub credential picker).
		 *
		 * @return {Promise<void>}
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
		 * Publish this template to a new GitHub repository tagged
		 * `topic:hermiq-agent-template`. A broker `github` credential is
		 * required — the GitHub token never reaches Hermiq.
		 *
		 * @return {Promise<void>}
		 */
		async doPublish() {
			this.publishing = true
			this.publishError = ''
			try {
				const result = await publishAgentTemplateToGithub(this.templateId(), {
					owner: this.publishForm.owner.trim(),
					repo: this.publishForm.repo.trim(),
					visibility: this.publishVisibility?.value || 'private',
					credentialId: this.publishCredential?.value,
				})
				this.publishNotice = this.t('hermiq', 'Published to {repoUrl}', { repoUrl: result.repoUrl })
				this.showPublish = false
				emit('cn:page:refresh', {})
			} catch (e) {
				this.publishError = e?.response?.data?.error || e?.message || this.t('hermiq', 'Unknown error')
			} finally {
				this.publishing = false
			}
		},
	},
}
</script>

<style scoped>
.agent-template-row-actions__buttons {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
	justify-content: flex-end;
}

.agent-template-row-actions__export-modal {
	padding: 20px;
}

.agent-template-row-actions__textarea {
	width: 100%;
	min-height: 120px;
	font-family: monospace;
	font-size: 13px;
	margin-bottom: 8px;
	resize: vertical;
}

.agent-template-row-actions__publish-modal {
	padding: 20px;
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.agent-template-row-actions__hint {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	margin: -4px 0 0;
}
</style>
