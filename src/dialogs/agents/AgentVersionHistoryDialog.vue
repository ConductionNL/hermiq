<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  AgentVersionHistoryDialog — an agent's version timeline, compare, and rollback
  (agent-versioning).

  Own file per ADR-004 modal-isolation. Reads OpenRegister's AuditTrail via
  GET /api/agents/{id}/versions (AgentVersionController::index — no new
  storage). Selecting exactly two versions and clicking "Compare" mounts
  AgentVersionDiffDialog INTERNALLY (manifest-driven-pages task 3) — the
  registry resolves this dialog as one self-contained `kind:"modal"` entry, so
  no parent page needs to also mount a sibling diff dialog. "Roll back" is
  owner-only (the `can-rollback` prop, mirroring every other owner-gated
  action already on the agent detail page) and calls the rollback endpoint
  itself, then refreshes its own list and emits `rolled-back` so the host
  re-fetches the agent's live config.

  @spec openspec/changes/agent-versioning/tasks.md#task-4-frontend-version-history-diff-and-one-click-rollback-on-agentdetail
  @spec openspec/changes/agent-versioning/specs/agent-versioning/spec.md#requirement-list-an-agents-version-history
  @spec openspec/specs/manifest-driven-pages/spec.md#requirement-header-actions-open-their-modal-via-a-registry-resolved-open-modal-action
-->
<template>
	<NcDialog
		:name="t('hermiq', 'Version history')"
		:open="show"
		size="normal"
		@update:open="onUpdateOpen">
		<div class="agent-version-history-dialog">
			<NcNoteCard
				v-if="error"
				type="error"
				:heading="t('hermiq', 'Could not load version history')">
				{{ error }}
			</NcNoteCard>

			<NcLoadingIcon v-if="loading" :size="32" />

			<p
				v-else-if="versions.length === 0"
				class="agent-version-history-dialog__empty">
				{{ t('hermiq', 'No versions recorded yet.') }}
			</p>

			<ul v-else class="agent-version-history-dialog__list">
				<li
					v-for="version in versions"
					:key="version.id"
					class="agent-version-history-dialog__row">
					<NcCheckboxRadioSwitch
						:modelValue="isSelected(version.id)"
						:aria-label="
							t('hermiq', 'Select version from {date} to compare', {
								date: formatDate(version.timestamp),
							})
						"
						@update:modelValue="toggleSelected(version.id)">
						<span class="agent-version-history-dialog__meta">
							<span class="agent-version-history-dialog__date">{{
								formatDate(version.timestamp)
							}}</span>
							<span class="agent-version-history-dialog__user">{{
								version.user || t('hermiq', 'Unknown user')
							}}</span>
							<span class="agent-version-history-dialog__action">{{
								actionLabel(version.action)
							}}</span>
						</span>
					</NcCheckboxRadioSwitch>
					<span
						v-if="version.changedFields && version.changedFields.length"
						class="agent-version-history-dialog__fields">
						{{
							t('hermiq', 'Changed: {fields}', {
								fields: version.changedFields.join(', '),
							})
						}}
					</span>
					<template v-if="resolvedCanRollback">
						<span
							v-if="confirmingId === version.id"
							class="agent-version-history-dialog__confirm">
							<span class="agent-version-history-dialog__confirm-text">
								{{
									t(
										'hermiq',
										'Roll back to this version? This creates a new version — nothing is deleted.',
									)
								}}
							</span>
							<NcButton
								variant="tertiary"
								:disabled="rollingBackId !== null"
								@click="confirmingId = null">
								{{ t('hermiq', 'Cancel') }}
							</NcButton>
							<NcButton
								variant="error"
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
							variant="tertiary"
							:disabled="rollingBackId !== null"
							:aria-label="t('hermiq', 'Roll back to this version')"
							@click="confirmingId = version.id">
							{{ t('hermiq', 'Roll back') }}
						</NcButton>
					</template>
				</li>
			</ul>
		</div>

		<AgentVersionDiffDialog
			:show="showDiff"
			:agentId="resolvedAgentId"
			:fromId="diffFromId"
			:toId="diffToId"
			@close="showDiff = false" />

		<template #actions>
			<NcButton @click="$emit('close')">
				{{ t('hermiq', 'Close') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="selected.length !== 2"
				@click="compare">
				{{ t('hermiq', 'Compare selected') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { getCurrentUser } from '@nextcloud/auth'
import { showError, showSuccess } from '@nextcloud/dialogs'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcDialog,
	NcLoadingIcon,
	NcNoteCard,
} from '@nextcloud/vue'
import AgentVersionDiffDialog from './AgentVersionDiffDialog.vue'
import { listAgentVersions, rollbackAgentVersion } from '../../api/agents.js'
import { useAgentStore } from '../../store/store.js'

export default {
	name: 'AgentVersionHistoryDialog',

	components: {
		AgentVersionDiffDialog,
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

		/**
		 * The Agent UUID whose version history is shown. Optional — when
		 * opened as the registry `agent-version-history` open-modal target
		 * (manifest-driven-pages), no prop is available (open-modal action
		 * props are static JSON, not resolved against the current object),
		 * so it self-resolves from the route's `:id` param instead (see
		 * `resolvedAgentId`).
		 */
		agentId: {
			type: String,
			default: '',
		},

		/**
		 * Whether the current user may roll back this agent (owner-only).
		 * `null` (default) means "auto-resolve": self-fetch the agent and
		 * compare its `owner` against the current user, the same check
		 * AgentDetail used to pass down explicitly. An explicit true/false
		 * still wins (e.g. tests).
		 */
		canRollback: {
			type: Boolean,
			default: null,
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
			agent: null,
		}
	},

	computed: {
		/**
		 * The agent uuid — the `agentId` prop when explicitly supplied, else
		 * the current route's `:id` param.
		 *
		 * @return {string} The resolved agent uuid.
		 */
		resolvedAgentId() {
			return this.agentId || this.$route?.params?.id || ''
		},

		/**
		 * Whether rollback is offered — the explicit `canRollback` prop when
		 * set, else the self-fetched agent's owner compared to the current user.
		 *
		 * @return {boolean} True when rollback should be offered.
		 */
		resolvedCanRollback() {
			if (this.canRollback !== null) {
				return this.canRollback
			}
			const user = getCurrentUser()
			return !!(user && this.agent && this.agent.owner === user.uid)
		},
	},

	watch: {
		// `immediate: true`: when opened via the registry
		// `agent-version-history` open-modal action, CnAppRoot mounts this
		// component FRESH with `show` already `true` — a plain watcher only
		// fires on a CHANGE, so it would never run for that mount path
		// without `immediate`.
		show: {
			immediate: true,
			handler(open) {
				if (open) {
					this.selected = []
					this.load()
					this.loadAgentForRollbackGate()
				}
			},
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
				this.versions = await listAgentVersions(this.resolvedAgentId)
			} catch (e) {
				this.error =
					e?.response?.data?.error
					|| e?.message
					|| this.t('hermiq', 'Unknown error')
				this.versions = []
			} finally {
				this.loading = false
			}
		},

		/**
		 * Self-fetch the agent for `resolvedCanRollback`'s owner check, only
		 * when the caller did not already supply an explicit `canRollback`.
		 *
		 * @return {Promise<void>}
		 */
		async loadAgentForRollbackGate() {
			if (this.canRollback !== null || !this.resolvedAgentId) {
				return
			}
			const store = useAgentStore()
			store.registerObjectType('agent', 'agent', 'hermiq')
			this.agent = await store
				.fetchObject('agent', this.resolvedAgentId)
				.catch(() => null)
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
				await rollbackAgentVersion(this.resolvedAgentId, version.id)
				showSuccess(
					this.t('hermiq', 'Agent rolled back to the selected version.'),
				)
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
