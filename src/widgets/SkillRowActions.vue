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

  @spec openspec/changes/manifest-driven-pages/specs/manifest-driven-pages/spec.md#req-010-agenttemplategallery-renders-as-an-index-type-list-page-with-write-actions-kept-behind-their-existing-guarded-endpoints
  @spec openspec/changes/skills-catalog/specs/skills-catalog/spec.md
  @spec openspec/changes/skills-marketplace/tasks.md
-->
<template>
	<div class="skill-row-actions">
		<NcNoteCard v-if="error" type="error">
			{{ error }}
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
				:aria-label="t('hermiq', 'Install on agent')"
				@click="openInstallModal">
				{{ t('hermiq', 'Install') }}
			</NcButton>
		</div>

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
	</div>
</template>

<script>
import { NcButton, NcModal, NcNoteCard, NcSelect } from '@nextcloud/vue'
import { emit } from '@nextcloud/event-bus'
import { approveSkill, exportSkill, installSkill, publishSkill } from '../api/skills.js'
import { useAgentStore } from '../store/store.js'

export default {
	name: 'SkillRowActions',

	components: {
		NcButton,
		NcModal,
		NcNoteCard,
		NcSelect,
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
			exportedPackage: '',
			showExport: false,
			showInstall: false,
			installing: false,
			installError: '',
			selectedAgent: null,
			agents: [],
			loadingAgents: false,
		}
	},

	computed: {
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
.skill-row-actions__buttons {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
	justify-content: flex-end;
}

.skill-row-actions__export-modal,
.skill-row-actions__install-modal {
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
