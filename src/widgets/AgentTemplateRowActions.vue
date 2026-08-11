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

  @spec openspec/specs/manifest-driven-pages/spec.md#requirement-agenttemplategallery-renders-as-an-index-type-list-page-with-write-actions-kept-behind-their-existing-guarded-endpoints
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

		<AgentTemplateExportModal
			v-if="showExport"
			:exported-package="exportedPackage"
			@close="showExport = false" />

		<AgentTemplatePublishModal
			v-if="showPublish"
			:template-id="templateId()"
			@close="showPublish = false"
			@published="onPublished" />
	</div>
</template>

<script>
import { NcButton, NcNoteCard } from '@nextcloud/vue'
import { emit } from '@nextcloud/event-bus'
import AgentTemplateExportModal from '../modals/AgentTemplateExportModal.vue'
import AgentTemplatePublishModal from '../modals/AgentTemplatePublishModal.vue'
import {
	approveAgentTemplate,
	exportAgentTemplate,
	instantiateAgentTemplate,
} from '../api/agentTemplates.js'

export default {
	name: 'AgentTemplateRowActions',

	components: {
		AgentTemplateExportModal,
		AgentTemplatePublishModal,
		NcButton,
		NcNoteCard,
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
			publishNotice: '',
		}
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
		 * Open the "Publish to GitHub" form. The modal loads the caller's broker
		 * credentials itself on mount.
		 *
		 * @return {void}
		 */
		openPublishModal() {
			this.publishNotice = ''
			this.showPublish = true
		},

		/**
		 * The publish modal reported success: close it, show the notice and
		 * refresh the list. Both outlive the modal, which is why the parent owns
		 * them.
		 *
		 * @param {string} repoUrl The created repository URL.
		 *
		 * @return {void}
		 */
		onPublished(repoUrl) {
			this.publishNotice = this.t('hermiq', 'Published to {repoUrl}', { repoUrl })
			this.showPublish = false
			emit('cn:page:refresh', {})
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

/*
 * The export/publish modal styles moved with their markup into
 * src/modals/AgentTemplateExportModal.vue and
 * src/modals/AgentTemplatePublishModal.vue (gate-13 modal-isolation).
 */
</style>
