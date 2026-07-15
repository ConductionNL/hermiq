<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  AgentTemplateGallery — the Hermiq "Agent templates" nav page (agent-template-gallery).

  Browse portable, versionable agent definitions (name, category, description, state),
  "Use this template" to instantiate a real Agent (model coerced into the caller's
  TenantModelPolicy — any substitution is surfaced in a note-card before navigating to the
  new agent), export a template to a shareable JSON package, import a package (paste +
  choose source), and approve a quarantined import (the review gate). All reads/writes go
  through the tenant-scoped AgentTemplateController endpoints (src/api/agentTemplates.js) —
  no new write path, no custom Pinia store. Gives a fresh install something better to click
  than AgentCatalog's blank AgentFormModal.

  Renders through the shared CnDataTable, mirroring SkillsCatalog.vue's index-page shape.
  A standard nav page — NOT a dashboard (dashboard-antipattern gate). Every NcSelect carries
  an inputLabel (ADR-004, WCAG 2.1 AA) — this page uses none, but the sibling
  TemplateImportModal is audited the same way.

  @spec openspec/changes/agent-template-gallery/tasks.md#task-7-srcapiagenttemplatesjs-agenttemplategalleryvue
  @spec openspec/changes/agent-template-gallery/specs/agent-template-gallery/spec.md
-->
<template>
	<div class="agent-template-gallery" data-testid="agent-template-gallery">
		<div class="agent-template-gallery__header">
			<h2 class="agent-template-gallery__heading" data-testid="agent-template-gallery-heading">
				{{ t('hermiq', 'Agent templates') }}
			</h2>
			<NcButton type="primary" @click="showImport = true">
				{{ t('hermiq', 'Import template') }}
			</NcButton>
		</div>

		<NcNoteCard v-if="error" type="error" :heading="t('hermiq', 'Agent templates error')">
			{{ error }}
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

		<section class="agent-template-gallery__section">
			<h3 class="agent-template-gallery__subhead">
				{{ t('hermiq', 'Templates') }} ({{ templates.length }})
			</h3>

			<NcEmptyContent
				v-if="!loading && templates.length === 0"
				:name="t('hermiq', 'No templates yet')"
				:description="t('hermiq', 'Import a template package to add one.')">
				<template #icon>
					<PackageVariantIcon :size="20" />
				</template>
			</NcEmptyContent>

			<CnDataTable
				v-else
				:columns="columns"
				:rows="rows"
				:loading="loading"
				row-key="id"
				:empty-text="t('hermiq', 'No templates yet')">
				<template #column-state="{ row }">
					<span class="agent-template-gallery__state" :class="`agent-template-gallery__state--${row.state}`">
						{{ row.state }}
					</span>
				</template>
				<template #row-actions="{ row }">
					<div class="agent-template-gallery__actions">
						<NcButton
							v-if="row.state === 'active'"
							type="primary"
							:disabled="busy"
							:aria-label="t('hermiq', 'Use this template')"
							@click="doInstantiate(row.template)">
							{{ t('hermiq', 'Use this template') }}
						</NcButton>
						<NcButton
							v-if="row.state === 'quarantined'"
							type="secondary"
							:disabled="busy"
							:aria-label="t('hermiq', 'Approve quarantined template')"
							@click="doApprove(row.template)">
							{{ t('hermiq', 'Approve') }}
						</NcButton>
						<NcButton
							type="tertiary"
							:aria-label="t('hermiq', 'Export template')"
							@click="doExport(row.template)">
							{{ t('hermiq', 'Export') }}
						</NcButton>
					</div>
				</template>
			</CnDataTable>
		</section>

		<!-- Export result -->
		<NcModal v-if="showExport" @close="showExport = false">
			<div class="agent-template-gallery__export-modal">
				<h3>{{ t('hermiq', 'Exported package') }}</h3>
				<textarea class="agent-template-gallery__textarea" readonly :value="exportedPackage" />
			</div>
		</NcModal>

		<TemplateImportModal
			v-if="showImport"
			@imported="load"
			@close="showImport = false" />
	</div>
</template>

<script>
import { NcButton, NcEmptyContent, NcModal, NcNoteCard } from '@nextcloud/vue'
import { CnDataTable } from '@conduction/nextcloud-vue'
import PackageVariantIcon from 'vue-material-design-icons/PackageVariantClosed.vue'
import {
	approveAgentTemplate,
	exportAgentTemplate,
	instantiateAgentTemplate,
	listAgentTemplates,
} from '../api/agentTemplates.js'
import TemplateImportModal from '../modals/TemplateImportModal.vue'

export default {
	name: 'AgentTemplateGallery',

	components: {
		CnDataTable,
		NcButton,
		NcEmptyContent,
		NcModal,
		NcNoteCard,
		PackageVariantIcon,
		TemplateImportModal,
	},

	data() {
		return {
			templates: [],
			exportedPackage: '',
			showExport: false,
			showImport: false,
			loading: true,
			busy: false,
			error: '',
			instantiateNote: null,
		}
	},

	computed: {
		/**
		 * Column definitions for the shared index table.
		 *
		 * @return {Array<object>} CnDataTable column descriptors.
		 */
		columns() {
			return [
				{ key: 'name', label: this.t('hermiq', 'Name') },
				{ key: 'category', label: this.t('hermiq', 'Category') },
				{ key: 'description', label: this.t('hermiq', 'Description') },
				{ key: 'state', label: this.t('hermiq', 'State') },
			]
		},

		/**
		 * Templates projected onto flat rows for the index table.
		 *
		 * @return {Array<object>} The table rows.
		 */
		rows() {
			return this.templates.map((template) => ({
				id: template.uuid,
				name: template.name,
				category: template.category || '—',
				description: template.description || '—',
				state: template.state,
				template,
			}))
		},
	},

	created() {
		this.load()
	},

	methods: {
		/**
		 * Load the tenant's agent templates.
		 *
		 * @return {Promise<void>}
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				this.templates = await listAgentTemplates()
			} catch (e) {
				this.error = e?.response?.data?.error || e?.message || this.t('hermiq', 'Unknown error')
			} finally {
				this.loading = false
			}
		},

		/**
		 * "Use this template" — instantiate into a real Agent. Surfaces any model
		 * coercion / unresolved skill refs in a note-card before the user navigates on.
		 *
		 * @param {object} template The template record.
		 * @return {Promise<void>}
		 */
		async doInstantiate(template) {
			this.busy = true
			this.error = ''
			this.instantiateNote = null
			try {
				const result = await instantiateAgentTemplate(template.uuid)
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
		 * Approve a quarantined template (the review gate → active).
		 *
		 * @param {object} template The template record.
		 * @return {Promise<void>}
		 */
		async doApprove(template) {
			this.busy = true
			this.error = ''
			try {
				await approveAgentTemplate(template.uuid)
				await this.load()
			} catch (e) {
				this.error = e?.response?.data?.error || e?.message || this.t('hermiq', 'Unknown error')
			} finally {
				this.busy = false
			}
		},

		/**
		 * Export a template to a shareable JSON package and show it.
		 *
		 * @param {object} template The template record.
		 * @return {Promise<void>}
		 */
		async doExport(template) {
			this.error = ''
			try {
				this.exportedPackage = await exportAgentTemplate(template.uuid)
				this.showExport = true
			} catch (e) {
				this.error = e?.response?.data?.error || e?.message || this.t('hermiq', 'Unknown error')
			}
		},
	},
}
</script>

<style scoped>
.agent-template-gallery {
	padding: 20px;
	max-width: 960px;
	margin: 0 auto;
}

.agent-template-gallery__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	margin-bottom: 16px;
}

.agent-template-gallery__heading {
	margin: 0;
	font-size: 22px;
	font-weight: 600;
}

.agent-template-gallery__subhead {
	font-size: 16px;
	font-weight: 600;
	margin: 0 0 8px;
}

.agent-template-gallery__section {
	margin-bottom: 24px;
}

.agent-template-gallery__state {
	font-size: 12px;
	text-transform: uppercase;
	width: fit-content;
	padding: 1px 6px;
	border-radius: 4px;
	background: var(--color-background-dark);
}

.agent-template-gallery__state--active {
	color: var(--color-success);
}

.agent-template-gallery__state--archived {
	color: var(--color-text-maxcontrast);
}

.agent-template-gallery__state--quarantined {
	color: var(--color-error);
	background: var(--color-error-hover, var(--color-background-dark));
}

.agent-template-gallery__actions {
	display: flex;
	align-items: flex-end;
	gap: 8px;
	flex-wrap: wrap;
}

.agent-template-gallery__export-modal {
	padding: 20px;
}

.agent-template-gallery__textarea {
	width: 100%;
	min-height: 120px;
	font-family: monospace;
	font-size: 13px;
	margin-bottom: 8px;
	resize: vertical;
}
</style>
