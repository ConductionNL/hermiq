<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  AgentMemory — the Hermiq "Memory" nav page (agent-memory).

  Pick an agent, then view + curate its long-term Memory: the durable entries, the
  character-budget usage bar, the needsConsolidation nudge with a manual "Consolidate"
  action. All reads/writes go
  through the tenant-scoped MemoryController endpoints (src/api/memory.js) — no new
  write path, no custom Pinia store.

  A standard nav page — NOT a dashboard (dashboard-antipattern gate). The agent run loop
  that populates memory/turns during a run is an OpenRegister seam (ADR-001 Option C+);
  this page is the operator management surface. Every NcSelect carries an inputLabel
  (ADR-004, WCAG 2.1 AA).

  @spec openspec/changes/agent-memory/tasks.md#task-4-1
  @spec openspec/changes/agent-memory/specs/agent-memory/spec.md
-->
<template>
	<div class="agent-memory">
		<div class="agent-memory__header">
			<h2 class="agent-memory__heading">
				{{ t('hermiq', 'Agent memory') }}
			</h2>
			<div class="agent-memory__picker">
				<NcSelect
					v-model="selectedAgent"
					:input-label="t('hermiq', 'Agent')"
					:options="agentOptions"
					:clearable="false"
					:placeholder="t('hermiq', 'Select an agent')"
					label="label"
					track-by="value"
					@input="onAgentChange" />
			</div>
		</div>

		<NcNoteCard v-if="error" type="error" :heading="t('hermiq', 'Memory error')">
			{{ error }}
		</NcNoteCard>

		<div v-if="loading" class="agent-memory__loading">
			<NcLoadingIcon :size="32" />
		</div>

		<NcEmptyContent
			v-else-if="!selectedAgent"
			:name="t('hermiq', 'Select an agent')"
			:description="t('hermiq', 'Choose an agent to view and curate its long-term memory.')">
			<template #icon>
				<BrainIcon :size="20" />
			</template>
		</NcEmptyContent>

		<template v-else>
			<!-- Char-budget bar + consolidation nudge -->
			<section class="agent-memory__budget">
				<div class="agent-memory__budget-head">
					<span class="agent-memory__budget-label">{{ t('hermiq', 'Memory budget') }}</span>
					<span class="agent-memory__budget-count">{{ charCount }} / {{ charBudget }} {{ t('hermiq', 'characters') }}</span>
				</div>
				<div class="agent-memory__budget-bar" :class="{ 'agent-memory__budget-bar--over': memory.needsConsolidation }">
					<div class="agent-memory__budget-fill" :style="{ width: budgetPct + '%' }" />
				</div>
				<div v-if="memory.needsConsolidation" class="agent-memory__nudge">
					<AlertIcon :size="18" class="agent-memory__nudge-icon" />
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
			<section class="agent-memory__add">
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
			<section class="agent-memory__section">
				<h3 class="agent-memory__subhead">
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
				<ul v-else class="agent-memory__entries">
					<li v-for="(entry, i) in entries" :key="i" class="agent-memory__entry">
						<span class="agent-memory__entry-text">{{ entry.text }}</span>
						<span class="agent-memory__entry-date">{{ formatDate(entry.createdAt) }}</span>
					</li>
				</ul>
			</section>

		</template>
	</div>
</template>

<script>
import { NcButton, NcEmptyContent, NcLoadingIcon, NcNoteCard, NcSelect, NcTextField } from '@nextcloud/vue'
import AlertIcon from 'vue-material-design-icons/AlertOutline.vue'
import BrainIcon from 'vue-material-design-icons/Brain.vue'
import { listAgents } from '../api/agents.js'
import { addMemory, consolidateMemory, getMemory } from '../api/memory.js'

export default {
	name: 'AgentMemory',

	components: {
		AlertIcon,
		BrainIcon,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
		NcTextField,
	},

	data() {
		return {
			agents: [],
			selectedAgent: null,
			memory: { entries: [], charBudget: 8000, needsConsolidation: false },
			newEntry: '',
			loading: true,
			busy: false,
			error: '',
		}
	},

	computed: {
		/**
		 * The agents as NcSelect options.
		 *
		 * @return {Array<object>} The { label, value } options.
		 */
		agentOptions() {
			return this.agents.map((agent) => ({
				label: agent.name || agent.uuid || agent.id,
				value: agent.uuid || agent.id,
			}))
		},

		/**
		 * The current memory entries.
		 *
		 * @return {Array<object>} The entry list.
		 */
		entries() {
			return Array.isArray(this.memory.entries) ? this.memory.entries : []
		},

		/**
		 * The total character count across entry texts.
		 *
		 * @return {number} The summed length.
		 */
		charCount() {
			return this.entries.reduce((sum, e) => sum + String(e.text || '').length, 0)
		},

		/**
		 * The configured character budget.
		 *
		 * @return {number} The budget.
		 */
		charBudget() {
			return Number(this.memory.charBudget) || 8000
		},

		/**
		 * The budget-bar fill percentage (capped at 100).
		 *
		 * @return {number} The percentage.
		 */
		budgetPct() {
			if (this.charBudget <= 0) {
				return 0
			}
			return Math.min(100, Math.round((this.charCount / this.charBudget) * 100))
		},
	},

	created() {
		this.loadAgents()
	},

	methods: {
		/**
		 * Load the agents the user may see and select the first one.
		 *
		 * @return {Promise<void>}
		 */
		async loadAgents() {
			this.loading = true
			this.error = ''
			try {
				this.agents = await listAgents()
				if (this.agentOptions.length > 0) {
					this.selectedAgent = this.agentOptions[0]
					await this.loadAgent()
				}
			} catch (e) {
				this.error = e?.response?.data?.error || e?.message || this.t('hermiq', 'Unknown error')
			} finally {
				this.loading = false
			}
		},

		/**
		 * React to the agent picker changing.
		 *
		 * @return {Promise<void>}
		 */
		async onAgentChange() {
			await this.loadAgent()
		},

		/**
		 * Load the selected agent's memory.
		 *
		 * @return {Promise<void>}
		 */
		async loadAgent() {
			if (!this.selectedAgent) {
				return
			}
			this.loading = true
			this.error = ''
			try {
				const memory = await getMemory(this.selectedAgent.value)
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
			if (!text || !this.selectedAgent) {
				return
			}
			this.busy = true
			this.error = ''
			try {
				this.memory = await addMemory(this.selectedAgent.value, text)
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
			if (!this.selectedAgent) {
				return
			}
			this.busy = true
			this.error = ''
			try {
				this.memory = await consolidateMemory(this.selectedAgent.value)
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
	},
}
</script>

<style scoped>
.agent-memory {
	padding: 20px;
	max-width: 900px;
	margin: 0 auto;
}

.agent-memory__header {
	display: flex;
	align-items: flex-start;
	justify-content: space-between;
	gap: 12px;
	margin-bottom: 16px;
	flex-wrap: wrap;
}

.agent-memory__heading {
	margin: 0;
	font-size: 22px;
	font-weight: 600;
}

.agent-memory__picker {
	min-width: 260px;
}

.agent-memory__loading {
	display: flex;
	justify-content: center;
	padding: 48px 0;
}

.agent-memory__budget {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	padding: 12px 16px;
	margin-bottom: 16px;
}

.agent-memory__budget-head {
	display: flex;
	justify-content: space-between;
	margin-bottom: 8px;
	font-weight: 600;
}

.agent-memory__budget-count {
	color: var(--color-text-maxcontrast);
	font-weight: normal;
}

.agent-memory__budget-bar {
	height: 8px;
	background: var(--color-background-dark);
	border-radius: 4px;
	overflow: hidden;
}

.agent-memory__budget-fill {
	height: 100%;
	background: var(--color-primary-element);
	transition: width 0.3s ease;
}

.agent-memory__budget-bar--over .agent-memory__budget-fill {
	background: var(--color-error);
}

.agent-memory__nudge {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-top: 10px;
	color: var(--color-error);
}

.agent-memory__nudge-icon {
	flex: 0 0 auto;
}

.agent-memory__nudge .button-vue {
	margin-inline-start: auto;
}

.agent-memory__add,
.agent-memory__recall {
	display: flex;
	align-items: flex-end;
	gap: 8px;
	margin-bottom: 20px;
}

.agent-memory__add .input-field,
.agent-memory__recall .input-field {
	flex: 1 1 auto;
}

.agent-memory__section {
	margin-bottom: 24px;
}

.agent-memory__subhead {
	font-size: 16px;
	font-weight: 600;
	margin: 0 0 8px;
}

.agent-memory__entries {
	list-style: none;
	margin: 0;
	padding: 0;
}

.agent-memory__entry {
	display: flex;
	align-items: baseline;
	gap: 12px;
	padding: 8px 12px;
	border-bottom: 1px solid var(--color-border);
}

.agent-memory__entry-text {
	flex: 1 1 auto;
}

.agent-memory__entry-role {
	font-size: 12px;
	text-transform: uppercase;
	color: var(--color-text-maxcontrast);
	flex: 0 0 auto;
	min-width: 64px;
}

.agent-memory__entry-date {
	color: var(--color-text-maxcontrast);
	font-size: 12px;
	white-space: nowrap;
}

.agent-memory__muted {
	color: var(--color-text-maxcontrast);
}
</style>
