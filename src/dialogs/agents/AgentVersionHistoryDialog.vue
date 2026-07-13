<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  AgentVersionHistoryDialog — an agent's version timeline, compare, and rollback
  (agent-versioning).

  Own file per ADR-004 modal-isolation. Reads OpenRegister's AuditTrail via
  GET /api/agents/{id}/versions (AgentVersionController::index — no new
  storage). Selecting exactly two versions and clicking "Compare" emits
  `compare` so the parent (AgentDetail.vue) can open AgentVersionDiffDialog.
  "Roll back" is owner-only (the `can-rollback` prop, mirroring every other
  owner-gated action already on AgentDetail.vue) and calls the rollback
  endpoint itself, then refreshes its own list and emits `rolled-back` so the
  parent re-fetches the agent's live config.

  @spec openspec/changes/agent-versioning/tasks.md#task-4-frontend-version-history-diff-and-one-click-rollback-on-agentdetail
  @spec openspec/changes/agent-versioning/specs/agent-versioning/spec.md#requirement-list-an-agents-version-history
-->
<template>
	<NcDialog
		:name="t('hermiq', 'Version history')"
		:open="show"
		size="normal"
		@update:open="onUpdateOpen">
		<div class="agent-version-history-dialog">
			<NcNoteCard v-if="error" type="error" :heading="t('hermiq', 'Could not load version history')">
				{{ error }}
			</NcNoteCard>

			<NcLoadingIcon v-if="loading" :size="32" />

			<p v-else-if="versions.length === 0" class="agent-version-history-dialog__empty">
				{{ t('hermiq', 'No versions recorded yet.') }}
			</p>

			<ul v-else class="agent-version-history-dialog__list">
				<li
					v-for="version in versions"
					:key="version.id"
					class="agent-version-history-dialog__row">
					<NcCheckboxRadioSwitch
						:checked="isSelected(version.id)"
						:aria-label="t('hermiq', 'Select version from {date} to compare', { date: formatDate(version.timestamp) })"
						@update:checked="toggleSelected(version.id)">
						<span class="agent-version-history-dialog__meta">
							<span class="agent-version-history-dialog__date">{{ formatDate(version.timestamp) }}</span>
							<span class="agent-version-history-dialog__user">{{ version.user || t('hermiq', 'Unknown user') }}</span>
							<span class="agent-version-history-dialog__action">{{ actionLabel(version.action) }}</span>
						</span>
					</NcCheckboxRadioSwitch>
					<span v-if="version.changedFields && version.changedFields.length" class="agent-version-history-dialog__fields">
						{{ t('hermiq', 'Changed: {fields}', { fields: version.changedFields.join(', ') }) }}
					</span>
					<template v-if="canRollback">
						<span v-if="confirmingId === version.id" class="agent-version-history-dialog__confirm">
							<span class="agent-version-history-dialog__confirm-text">
								{{ t('hermiq', 'Roll back to this version? This creates a new version — nothing is deleted.') }}
							</span>
							<NcButton type="tertiary" :disabled="rollingBackId !== null" @click="confirmingId = null">
								{{ t('hermiq', 'Cancel') }}
							</NcButton>
							<NcButton
								type="error"
								:disabled="rollingBackId !== null"
								@click="performRollback(version)">
								<template v-if="rollingBackId === version.id" #icon>
									<NcLoadingIcon :size="20" />
								</template>
								{{ t('hermiq', 'Confirm rollback') }}
							</NcButton>
						</span>
						<NcButton
							v-else
							type="tertiary"
							:disabled="rollingBackId !== null"
							:aria-label="t('hermiq', 'Roll back to this version')"
							@click="confirmingId = version.id">
							{{ t('hermiq', 'Roll back') }}
						</NcButton>
					</template>
				</li>
			</ul>
		</div>

		<template #actions>
			<NcButton @click="$emit('close')">
				{{ t('hermiq', 'Close') }}
			</NcButton>
			<NcButton
				type="primary"
				:disabled="selected.length !== 2"
				@click="compare">
				{{ t('hermiq', 'Compare selected') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcCheckboxRadioSwitch, NcDialog, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { listAgentVersions, rollbackAgentVersion } from '../../api/agents.js'

export default {
	name: 'AgentVersionHistoryDialog',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcDialog,
		NcLoadingIcon,
		NcNoteCard,
	},

	props: {
		/** Whether the dialog is visible. */
		show: {
			type: Boolean,
			default: false,
		},
		/** The Agent UUID whose version history is shown. */
		agentId: {
			type: String,
			required: true,
		},
		/** Whether the current user may roll back this agent (owner-only). */
		canRollback: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['close', 'compare', 'rolled-back'],

	data() {
		return {
			versions: [],
			selected: [],
			loading: false,
			error: '',
			confirmingId: null,
			rollingBackId: null,
		}
	},

	watch: {
		show(open) {
			if (open) {
				this.selected = []
				this.load()
			}
		},
	},

	methods: {
		/**
		 * Load the agent's version history.
		 *
		 * @return {Promise<void>}
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				this.versions = await listAgentVersions(this.agentId)
			} catch (e) {
				this.error = e?.response?.data?.error || e?.message || this.t('hermiq', 'Unknown error')
				this.versions = []
			} finally {
				this.loading = false
			}
		},

		/**
		 * Whether a version id is currently selected for comparison.
		 *
		 * @param {string} id The version id.
		 * @return {boolean} True when selected.
		 */
		isSelected(id) {
			return this.selected.includes(id)
		},

		/**
		 * Toggle a version's selection. Selecting a third version drops the
		 * oldest selection (FIFO) so at most two stay selected at once.
		 *
		 * @param {string} id The version id.
		 * @return {void}
		 */
		toggleSelected(id) {
			if (this.selected.includes(id)) {
				this.selected = this.selected.filter((existing) => existing !== id)
				return
			}
			const next = [...this.selected, id]
			this.selected = next.length > 2 ? next.slice(next.length - 2) : next
		},

		/**
		 * Emit `compare` with the two selected version ids (oldest as `from`,
		 * relying on the list's newest-first order to pick a stable direction).
		 *
		 * @return {void}
		 */
		compare() {
			if (this.selected.length !== 2) {
				return
			}
			const indices = this.selected
				.map((id) => this.versions.findIndex((version) => version.id === id))
				.sort((a, b) => a - b)
			const olderId = this.versions[indices[1]].id
			const newerId = this.versions[indices[0]].id
			this.$emit('compare', { from: olderId, to: newerId })
		},

		/**
		 * Perform a rollback to the given version, after the inline confirm step.
		 *
		 * @param {object} version The version record to roll back to.
		 * @return {Promise<void>}
		 */
		async performRollback(version) {
			this.rollingBackId = version.id
			try {
				await rollbackAgentVersion(this.agentId, version.id)
				showSuccess(this.t('hermiq', 'Agent rolled back to the selected version.'))
				this.selected = []
				this.confirmingId = null
				await this.load()
				this.$emit('rolled-back')
			} catch (e) {
				showError(this.t('hermiq', 'Could not roll back the agent.'))
			} finally {
				this.rollingBackId = null
			}
		},

		/**
		 * Human label for a version's action.
		 *
		 * @param {string} action The audit action (create|update).
		 * @return {string} The localised label.
		 */
		actionLabel(action) {
			if (action === 'create') {
				return this.t('hermiq', 'Created')
			}
			return this.t('hermiq', 'Updated')
		},

		/**
		 * Format an ISO date for display, or a dash when absent/invalid.
		 *
		 * @param {string} value The ISO timestamp.
		 * @return {string} The localised date, or '—'.
		 */
		formatDate(value) {
			if (!value) {
				return '—'
			}
			const date = new Date(value)
			return Number.isNaN(date.getTime()) ? '—' : date.toLocaleString()
		},

		/**
		 * Close the dialog when NcDialog reports its open state changed to false.
		 *
		 * @param {boolean} open The new open state.
		 * @return {void}
		 */
		onUpdateOpen(open) {
			if (!open) {
				this.$emit('close')
			}
		},
	},
}
</script>

<style scoped>
.agent-version-history-dialog {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.agent-version-history-dialog__empty {
	color: var(--color-text-maxcontrast);
}

.agent-version-history-dialog__list {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
}

.agent-version-history-dialog__row {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 8px 0;
	border-bottom: 1px solid var(--color-border);
	flex-wrap: wrap;
}

.agent-version-history-dialog__meta {
	display: flex;
	gap: 12px;
	align-items: baseline;
}

.agent-version-history-dialog__date {
	font-weight: 600;
}

.agent-version-history-dialog__user,
.agent-version-history-dialog__action {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.agent-version-history-dialog__fields {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	flex: 1 1 100%;
}

.agent-version-history-dialog__confirm {
	display: flex;
	align-items: center;
	gap: 8px;
	flex: 1 1 100%;
}

.agent-version-history-dialog__confirm-text {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	flex: 1 1 auto;
}
</style>
