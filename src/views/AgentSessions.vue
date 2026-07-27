<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  AgentSessions — the Hermiq "Sessions" nav page (agent chats).

  Sessions are the conversation transcripts an agent records across its runs.
  They are a distinct concern from long-term Memory (durable facts), so they get
  their own page: pick an agent, browse its recorded sessions, and search prior
  conversation turns (recall). Reads go through the tenant-scoped Memory endpoints
  (src/api/memory.js), which serve Session/SessionTurn objects agent-scoped.

  @spec openspec/changes/agent-memory/specs/agent-memory/spec.md
-->
<template>
	<div class="agent-sessions">
		<div class="agent-sessions__header">
			<h2 class="agent-sessions__heading">
				{{ t('hermiq', 'Sessions') }}
			</h2>
			<div class="agent-sessions__picker">
				<NcSelect
					:input-label="t('hermiq', 'Agent')"
					:options="agentOptions"
					:model-value="selectedAgent"
					:placeholder="t('hermiq', 'Select an agent')"
					label="label"
					@update:modelValue="onAgentChange" />
			</div>
		</div>

		<NcNoteCard v-if="error" type="error" :heading="t('hermiq', 'Sessions error')">
			{{ error }}
		</NcNoteCard>

		<div v-if="loading" class="agent-sessions__loading">
			<NcLoadingIcon :size="32" />
		</div>

		<NcEmptyContent
			v-else-if="!selectedAgent"
			:name="t('hermiq', 'Select an agent')"
			:description="t('hermiq', 'Choose an agent to browse its conversation sessions.')">
			<template #icon>
				<ChatIcon :size="20" />
			</template>
		</NcEmptyContent>

		<template v-else>
			<!-- Recall: search prior conversation turns -->
			<section class="agent-sessions__section">
				<h3 class="agent-sessions__subhead">
					{{ t('hermiq', 'Search conversations') }}
				</h3>
				<div class="agent-sessions__recall">
					<NcTextField
						v-model="recallQuery"
						:label="t('hermiq', 'Search prior conversation turns')"
						:disabled="busy"
						@keydown.enter="runRecall" />
					<NcButton type="secondary" :disabled="busy" @click="runRecall">
						{{ t('hermiq', 'Search') }}
					</NcButton>
				</div>
				<ul v-if="recallResults.length > 0" class="agent-sessions__turns">
					<li v-for="turn in recallResults" :key="turn.uuid" class="agent-sessions__turn">
						<span class="agent-sessions__turn-role">{{ turn.role }}</span>
						<span class="agent-sessions__turn-text">{{ turn.content }}</span>
					</li>
				</ul>
				<p v-else-if="recallRan" class="agent-sessions__muted">
					{{ t('hermiq', 'No matching turns.') }}
				</p>
			</section>

			<!-- Sessions -->
			<section class="agent-sessions__section">
				<h3 class="agent-sessions__subhead">
					{{ t('hermiq', 'Recorded sessions') }} ({{ sessions.length }})
				</h3>
				<NcEmptyContent
					v-if="sessions.length === 0"
					:name="t('hermiq', 'No sessions yet')"
					:description="t('hermiq', 'Conversation sessions recorded by agent runs will appear here.')">
					<template #icon>
						<ChatIcon :size="20" />
					</template>
				</NcEmptyContent>
				<ul v-else class="agent-sessions__list">
					<li v-for="session in sessions" :key="session.uuid" class="agent-sessions__item">
						<ChatIcon :size="18" class="agent-sessions__item-icon" />
						<span class="agent-sessions__item-title">{{ session.title || t('hermiq', 'Untitled session') }}</span>
						<span class="agent-sessions__item-date">{{ formatDate(session.lastActivityAt) }}</span>
					</li>
				</ul>
			</section>
		</template>
	</div>
</template>

<script>
import { NcButton, NcEmptyContent, NcLoadingIcon, NcNoteCard, NcSelect, NcTextField } from '@nextcloud/vue'
import ChatIcon from 'vue-material-design-icons/ChatOutline.vue'
import { listSessions, recall } from '../api/memory.js'
import { useAgentStore } from '../store/store.js'

export default {
	name: 'AgentSessions',

	components: {
		ChatIcon,
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
			sessions: [],
			recallQuery: '',
			recallResults: [],
			recallRan: false,
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
				const agentStore = useAgentStore()
				agentStore.registerObjectType('agent', 'agent', 'hermiq')
				const agents = await agentStore.fetchCollection('agent')
				this.agents = Array.isArray(agents) ? agents : []
				if (agentStore.errors?.agent) {
					this.error = agentStore.errors.agent.message || this.t('hermiq', 'Unknown error')
				}
				if (this.agentOptions.length > 0) {
					this.selectedAgent = this.agentOptions[0]
					await this.loadSessions()
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
		 * @param {object} option The chosen agent option.
		 * @return {Promise<void>}
		 */
		async onAgentChange(option) {
			this.selectedAgent = option
			this.recallResults = []
			this.recallRan = false
			await this.loadSessions()
		},

		/**
		 * Load the selected agent's recorded sessions.
		 *
		 * @return {Promise<void>}
		 */
		async loadSessions() {
			if (!this.selectedAgent) {
				return
			}
			this.loading = true
			this.error = ''
			try {
				this.sessions = await listSessions(this.selectedAgent.value)
			} catch (e) {
				this.error = e?.response?.data?.error || e?.message || this.t('hermiq', 'Unknown error')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Search the agent's prior conversation turns.
		 *
		 * @return {Promise<void>}
		 */
		async runRecall() {
			if (!this.selectedAgent) {
				return
			}
			this.busy = true
			this.error = ''
			try {
				this.recallResults = await recall(this.selectedAgent.value, this.recallQuery.trim())
				this.recallRan = true
			} catch (e) {
				this.error = e?.response?.data?.error || e?.message || this.t('hermiq', 'Unknown error')
			} finally {
				this.busy = false
			}
		},

		/**
		 * Format an ISO timestamp for display.
		 *
		 * @param {string} value The ISO date string.
		 * @return {string} The localised date, or '' when absent.
		 */
		formatDate(value) {
			if (!value) {
				return ''
			}
			const d = new Date(value)
			return Number.isNaN(d.getTime()) ? '' : d.toLocaleString()
		},
	},
}
</script>

<style scoped>
.agent-sessions {
	padding: 20px;
	max-width: 900px;
}

.agent-sessions__header {
	display: flex;
	align-items: flex-end;
	justify-content: space-between;
	gap: 16px;
	margin-bottom: 16px;
}

.agent-sessions__heading {
	margin: 0;
	font-size: 22px;
	font-weight: 600;
}

.agent-sessions__picker {
	min-width: 260px;
}

.agent-sessions__section {
	margin-top: 24px;
}

.agent-sessions__subhead {
	font-size: 16px;
	font-weight: 600;
	margin: 0 0 8px;
}

.agent-sessions__recall {
	display: flex;
	align-items: flex-end;
	gap: 8px;
}

.agent-sessions__list,
.agent-sessions__turns {
	list-style: none;
	margin: 8px 0 0;
	padding: 0;
}

.agent-sessions__item {
	display: flex;
	align-items: center;
	gap: 10px;
	padding: 8px 4px;
	border-bottom: 1px solid var(--color-border);
}

.agent-sessions__item-icon {
	color: var(--color-text-maxcontrast);
	flex: 0 0 auto;
}

.agent-sessions__item-title {
	flex: 1 1 auto;
}

.agent-sessions__item-date {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	flex: 0 0 auto;
}

.agent-sessions__turn {
	display: flex;
	gap: 10px;
	padding: 6px 4px;
	border-bottom: 1px solid var(--color-border);
}

.agent-sessions__turn-role {
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	flex: 0 0 auto;
	min-width: 72px;
}

.agent-sessions__muted {
	color: var(--color-text-maxcontrast);
	margin: 8px 0 0;
}
</style>
