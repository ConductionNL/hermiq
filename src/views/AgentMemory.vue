<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  AgentMemory — the Hermiq "Memory" nav page (agent-memory).

  Pick an agent, then view + curate its long-term Memory. The memory surface itself
  (budget bar, consolidation nudge, add-a-fact, entry list) lives in the shared
  AgentMemoryPanel component (agent-capability-detail-surface) so the same panel renders
  here and in-place on the agent detail page — one implementation, no duplicated markup.

  A standard nav page — NOT a dashboard (dashboard-antipattern gate). The agent run loop
  that populates memory/turns during a run is an OpenRegister seam (ADR-001 Option C+);
  this page is the operator management surface. Every NcSelect carries an inputLabel
  (ADR-004, WCAG 2.1 AA).

  @spec openspec/changes/agent-memory/tasks.md#task-4-1
  @spec openspec/changes/agent-memory/specs/agent-memory/spec.md
  @spec openspec/changes/agent-capability-detail-surface/specs/agent-management-ui/spec.md#requirement-agent-detail-manages-memory-in-place-mvp
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
					track-by="value" />
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

		<AgentMemoryPanel v-else :agent-id="selectedAgent.value" />
	</div>
</template>

<script>
import { NcEmptyContent, NcLoadingIcon, NcNoteCard, NcSelect } from '@nextcloud/vue'
import BrainIcon from 'vue-material-design-icons/Brain.vue'
import AgentMemoryPanel from '../components/AgentMemoryPanel.vue'
import { useAgentStore } from '../store/store.js'

export default {
	name: 'AgentMemory',

	components: {
		AgentMemoryPanel,
		BrainIcon,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
	},

	data() {
		return {
			agents: [],
			selectedAgent: null,
			loading: true,
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
		this.agentStore = useAgentStore()
		this.agentStore.registerObjectType('agent', 'agent', 'hermiq')
		this.loadAgents()
	},

	methods: {
		/**
		 * Load the agents (createObjectStore, hermiq register) and select the
		 * first one.
		 *
		 * @return {Promise<void>}
		 */
		async loadAgents() {
			this.loading = true
			this.error = ''
			try {
				const agents = await this.agentStore.fetchCollection('agent')
				this.agents = Array.isArray(agents) ? agents : []
				if (this.agentStore.errors?.agent) {
					this.error = this.agentStore.errors.agent.message || this.t('hermiq', 'Unknown error')
				}
				if (this.agentOptions.length > 0) {
					this.selectedAgent = this.agentOptions[0]
				}
			} catch (e) {
				this.error = e?.response?.data?.error || e?.message || this.t('hermiq', 'Unknown error')
			} finally {
				this.loading = false
			}
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
</style>
