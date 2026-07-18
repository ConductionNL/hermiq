<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  AgentMemoryPanel — one agent's long-term Memory management surface
  (agent-capability-detail-surface).

  Extracted from AgentMemory.vue so the same panel renders both on the standalone
  Memory nav page and in-place on the agent detail page — one implementation, no
  duplicated markup. Given an `agentId`, it loads the agent's Memory and offers the
  char-budget meter (shared BudgetMeter component — hermiq's budget-shaped
  quantities are unified in presentation only, never merged into one number),
  the needsConsolidation nudge with a manual Consolidate action, an add-a-fact
  input, and the entry list. All reads/writes go through the tenant-scoped
  MemoryController endpoints (src/api/memory.js).

  @spec openspec/changes/agent-capability-detail-surface/specs/agent-management-ui/spec.md#requirement-agent-detail-manages-memory-in-place-mvp
-->
<template>
	<div class="agent-memory-panel">
		<NcNoteCard v-if="error" type="error" :heading="t('hermiq', 'Memory error')">
			{{ error }}
		</NcNoteCard>

		<div v-if="loading" class="agent-memory-panel__loading">
			<NcLoadingIcon :size="32" />
		</div>

		<template v-else-if="agentId">
			<!-- Char-budget meter + consolidation nudge -->
			<section class="agent-memory-panel__budget">
				<BudgetMeter
					:label="t('hermiq', 'Memory')"
					:used="charCount"
					:limit="charBudget"
					:unit="t('hermiq', 'characters')"
					:over="memory.needsConsolidation" />
				<div v-if="memory.needsConsolidation" class="agent-memory-panel__nudge">
					<AlertIcon :size="18" class="agent-memory-panel__nudge-icon" />
					<span>{{ t('hermiq', 'Over budget — consolidation suggested. No entries were dropped.') }}</span>
					<NcButton
						type="secondary"
						:disabled="busy"
						:aria-label="t('hermiq', 'Consolidate memory')"
						@click="consolidate">
						<template v-if="busy" #icon>
							<NcLoadingIcon :size="18" />
						</template>
						{{ t('hermiq', 'Consolidate') }}
					</NcButton>
				</div>
			</section>

			<!-- Add a fact -->
			<section class="agent-memory-panel__add">
				<NcTextField
					:value.sync="newEntry"
					:label="t('hermiq', 'Add a fact the agent should remember')"
					:disabled="busy"
					@keydown.enter="addFact" />
				<NcButton
					type="primary"
					:disabled="busy || !newEntry.trim()"
					:aria-label="t('hermiq', 'Add fact')"
					@click="addFact">
					{{ t('hermiq', 'Remember') }}
				</NcButton>
			</section>

			<!-- Memory entries -->
			<section class="agent-memory-panel__section">
				<h3 class="agent-memory-panel__subhead">
					{{ t('hermiq', 'Memory entries') }} ({{ entries.length }})
				</h3>
				<NcEmptyContent
					v-if="entries.length === 0"
					:name="t('hermiq', 'No memory yet')"
					:description="t('hermiq', 'Facts the agent remembers will appear here.')">
					<template #icon>
						<BrainIcon :size="20" />
					</template>
				</NcEmptyContent>
				<ul v-else class="agent-memory-panel__entries">
					<li
						v-for="(entry, i) in entries"
						:key="i"
						class="agent-memory-panel__entry"
						:class="{ 'agent-memory-panel__entry--forgotten': isForgotten(entry) }">
						<span class="agent-memory-panel__entry-text">{{ entry.text }}</span>
						<span
							v-if="isForgotten(entry)"
							class="agent-memory-panel__entry-forgotten"
							:title="t('hermiq', 'The agent retracted this fact — it is excluded from recall but kept for audit history.')">
							<EyeOffIcon :size="14" />
							{{ t('hermiq', 'Forgotten') }}
						</span>
						<span class="agent-memory-panel__entry-date">{{ formatDate(entry.createdAt) }}</span>
						<NcButton
							v-if="entry.id && !isForgotten(entry)"
							type="tertiary"
							:disabled="busy"
							:aria-label="t('hermiq', 'Remove memory entry')"
							:title="t('hermiq', 'Remove')"
							@click="removeEntry(entry.id)">
							<template #icon>
								<TrashCanOutlineIcon :size="18" />
							</template>
						</NcButton>
						<span
							v-else-if="!isForgotten(entry)"
							class="agent-memory-panel__entry-noremove"
							:title="t('hermiq', 'This entry predates removal support and has no id — it cannot be removed individually.')">
							<InformationOutlineIcon :size="14" />
						</span>
					</li>
				</ul>
			</section>
		</template>
	</div>
</template>

<script>
import { NcButton, NcEmptyContent, NcLoadingIcon, NcNoteCard, NcTextField } from '@nextcloud/vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import AlertIcon from 'vue-material-design-icons/AlertOutline.vue'
import BrainIcon from 'vue-material-design-icons/Brain.vue'
import EyeOffIcon from 'vue-material-design-icons/EyeOffOutline.vue'
import InformationOutlineIcon from 'vue-material-design-icons/InformationOutline.vue'
import TrashCanOutlineIcon from 'vue-material-design-icons/TrashCanOutline.vue'
import { addMemory, consolidateMemory, forgetMemory, getMemory } from '../api/memory.js'
import BudgetMeter from './BudgetMeter.vue'

export default {
	name: 'AgentMemoryPanel',

	components: {
		AlertIcon,
		BrainIcon,
		BudgetMeter,
		EyeOffIcon,
		InformationOutlineIcon,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		NcTextField,
		TrashCanOutlineIcon,
	},

	props: {
		/** The agent UUID whose memory this panel manages. */
		agentId: {
			type: String,
			default: '',
		},
	},

	data() {
		return {
			memory: { entries: [], charBudget: 8000, needsConsolidation: false },
			newEntry: '',
			loading: false,
			busy: false,
			error: '',
		}
	},

	computed: {
		/**
		 * The current memory entries.
		 *
		 * @return {Array<object>} The entry list.
		 */
		entries() {
			return Array.isArray(this.memory.entries) ? this.memory.entries : []
		},

		/**
		 * The total character count across NON-FORGOTTEN entry texts — mirrors the
		 * backend's needsConsolidation calculation (agent-memory-tools), which
		 * excludes soft-deleted entries from the budget.
		 *
		 * @return {number} The summed length.
		 */
		charCount() {
			return this.entries
				.filter(e => !this.isForgotten(e))
				.reduce((sum, e) => sum + String(e.text || '').length, 0)
		},

		/**
		 * The configured character budget.
		 *
		 * @return {number} The budget.
		 */
		charBudget() {
			return Number(this.memory.charBudget) || 8000
		},
	},

	watch: {
		agentId: {
			immediate: true,
			handler() {
				this.load()
			},
		},
	},

	methods: {
		/**
		 * Load the agent's memory.
		 *
		 * @return {Promise<void>}
		 */
		async load() {
			if (!this.agentId) {
				this.memory = { entries: [], charBudget: 8000, needsConsolidation: false }
				return
			}
			this.loading = true
			this.error = ''
			try {
				const memory = await getMemory(this.agentId)
				this.memory = memory || { entries: [], charBudget: 8000, needsConsolidation: false }
			} catch (e) {
				this.error = e?.response?.data?.error || e?.message || this.t('hermiq', 'Unknown error')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Append a fact to the agent's memory.
		 *
		 * @return {Promise<void>}
		 */
		async addFact() {
			const text = this.newEntry.trim()
			if (!text || !this.agentId) {
				return
			}
			this.busy = true
			this.error = ''
			try {
				this.memory = await addMemory(this.agentId, text)
				this.newEntry = ''
			} catch (e) {
				this.error = e?.response?.data?.error || e?.message || this.t('hermiq', 'Unknown error')
			} finally {
				this.busy = false
			}
		},

		/**
		 * Consolidate the agent's memory (server de-duplicates when no strategy supplied).
		 *
		 * @return {Promise<void>}
		 */
		async consolidate() {
			if (!this.agentId) {
				return
			}
			this.busy = true
			this.error = ''
			try {
				this.memory = await consolidateMemory(this.agentId)
			} catch (e) {
				this.error = e?.response?.data?.error || e?.message || this.t('hermiq', 'Unknown error')
			} finally {
				this.busy = false
			}
		},

		/**
		 * Remove (soft-delete) one memory entry, then reload. Only offered for entries
		 * that carry an `id` — entries appended before agent-memory-tools may not.
		 *
		 * @param {string} entryId The entry id to forget.
		 * @return {Promise<void>}
		 */
		async removeEntry(entryId) {
			if (!entryId || !this.agentId) {
				return
			}
			this.busy = true
			this.error = ''
			try {
				const result = await forgetMemory(this.agentId, entryId)
				if (result && result.found === false) {
					showError(this.t('hermiq', 'That memory entry could not be found.'))
				} else {
					showSuccess(this.t('hermiq', 'Memory entry removed.'))
				}
				await this.load()
			} catch (e) {
				this.error = e?.response?.data?.error || e?.message || this.t('hermiq', 'Unknown error')
			} finally {
				this.busy = false
			}
		},

		/**
		 * Human-friendly timestamp.
		 *
		 * @param {string} value The ISO timestamp.
		 * @return {string} The formatted date, or a dash.
		 */
		formatDate(value) {
			if (!value) {
				return '—'
			}
			const date = new Date(value)
			if (Number.isNaN(date.getTime())) {
				return String(value)
			}
			return date.toLocaleString()
		},

		/**
		 * Whether an entry was soft-deleted by hermiq.forgetMemory (agent-memory-tools)
		 * — entries appended before that change carry no `deletedAt` at all.
		 *
		 * @param {object} entry The memory/profile entry.
		 * @return {boolean} True when the entry has been forgotten.
		 */
		isForgotten(entry) {
			return Boolean(entry && entry.deletedAt)
		},
	},
}
</script>

<style scoped>
.agent-memory-panel__loading {
	display: flex;
	justify-content: center;
	padding: 48px 0;
}

.agent-memory-panel__budget {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	padding: 12px 16px;
	margin-bottom: 16px;
}

.agent-memory-panel__nudge {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-top: 10px;
	color: var(--color-error);
}

.agent-memory-panel__nudge-icon {
	flex: 0 0 auto;
}

.agent-memory-panel__nudge .button-vue {
	margin-inline-start: auto;
}

.agent-memory-panel__add {
	display: flex;
	align-items: flex-end;
	gap: 8px;
	margin-bottom: 20px;
}

.agent-memory-panel__add .input-field {
	flex: 1 1 auto;
}

.agent-memory-panel__section {
	margin-bottom: 8px;
}

.agent-memory-panel__subhead {
	font-size: 16px;
	font-weight: 600;
	margin: 0 0 8px;
}

.agent-memory-panel__entries {
	list-style: none;
	margin: 0;
	padding: 0;
}

.agent-memory-panel__entry {
	display: flex;
	align-items: baseline;
	gap: 12px;
	padding: 8px 12px;
	border-bottom: 1px solid var(--color-border);
}

.agent-memory-panel__entry-text {
	flex: 1 1 auto;
}

.agent-memory-panel__entry--forgotten .agent-memory-panel__entry-text {
	text-decoration: line-through;
	color: var(--color-text-maxcontrast);
}

.agent-memory-panel__entry-forgotten {
	display: flex;
	align-items: center;
	gap: 4px;
	font-size: 12px;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
}

.agent-memory-panel__entry-date {
	color: var(--color-text-maxcontrast);
	font-size: 12px;
	white-space: nowrap;
}

.agent-memory-panel__entry-noremove {
	display: flex;
	align-items: center;
	color: var(--color-text-maxcontrast);
	flex: 0 0 auto;
}
</style>
