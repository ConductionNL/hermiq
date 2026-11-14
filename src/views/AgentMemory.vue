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

  @spec openspec/changes/archive/2026-09-06-agent-memory/tasks.md#task-4-1
  @spec openspec/specs/agent-memory/spec.md
  @spec openspec/specs/agent-management-ui/spec.md#requirement-agent-detail-manages-memory-in-place-mvp
  @spec openspec/changes/memory-project-scopes/specs/agent-memory/spec.md#requirement-an-owner-sees-and-moves-entries-between-layers-req-pmem-004

  memory-project-scopes: an agent with projects gets a tab per layer, "Shared"
  first; the panel below shows the chosen layer and moves entries between them.
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
					:inputLabel="t('hermiq', 'Agent')"
					:options="agentOptions"
					:clearable="false"
					:placeholder="t('hermiq', 'Select an agent')"
					label="label"
					trackBy="value" />
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
			:description="
				t(
					'hermiq',
					'Choose an agent to view and curate its long-term memory.',
				)
			">
			<template #icon>
				<BrainIcon :size="20" />
			</template>
		</NcEmptyContent>

		<template v-else>
			<div
				v-if="projects.length > 0"
				class="agent-memory__layers"
				role="tablist"
				:aria-label="t('hermiq', 'Memory layers')"
				data-testid="memory-layers">
				<NcButton
					v-for="layerTab in layerTabs"
					:key="layerTab.key || 'shared'"
					role="tab"
					:aria-selected="layerTab.key === layer ? 'true' : 'false'"
					:variant="layerTab.key === layer ? 'primary' : 'tertiary'"
					@click="layer = layerTab.key">
					{{ layerTab.label }}
				</NcButton>
			</div>
			<AgentMemoryPanel
				:agentId="selectedAgent.value"
				:project="layer"
				:projects="projects"
				@moved="loadProjects" />
		</template>
	</div>
</template>

<script>
import { NcButton, NcEmptyContent, NcLoadingIcon, NcNoteCard, NcSelect } from '@nextcloud/vue'
import BrainIcon from 'vue-material-design-icons/Brain.vue'
import AgentMemoryPanel from '../components/AgentMemoryPanel.vue'
import { listMemoryProjects } from '../api/memory.js'
import { useAgentStore } from '../store/store.js'
import { errorMessage } from '../utils/errorMessage.js'
import { agentPickerOptions } from '../widgets/evalRunLabels.js'

export default {
	name: 'AgentMemory',

	components: {
		AgentMemoryPanel,
		BrainIcon,
		NcButton,
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
			projects: [],
			layer: '',
		}
	},

	computed: {
		/**
		 * The agents as NcSelect options.
		 *
		 * @return {Array<object>} The { label, value } options.
		 * @spec openspec/changes/archive/2026-09-06-agent-memory/tasks.md#task-4-1
		 */
		agentOptions() {
			return agentPickerOptions(this.agents)
		},

		/**
		 * The tabs: "Shared", then one per project.
		 *
		 * @return {Array<{key: string, label: string}>} The tabs; key '' is the shared memory.
		 * @spec openspec/changes/memory-project-scopes/specs/agent-memory/spec.md#requirement-an-owner-sees-and-moves-entries-between-layers-req-pmem-004
		 */
		layerTabs() {
			return [
				{ key: '', label: this.t('hermiq', 'Shared') },
				...this.projects.map((project) => ({
					key: project.projectKey,
					label: project.projectName || project.projectKey,
				})),
			]
		},
	},

	watch: {
		selectedAgent() {
			this.layer = ''
			this.projects = []
			this.loadProjects()
		},
	},

	/**
	 * @spec openspec/changes/archive/2026-09-06-agent-memory/tasks.md#task-4-1
	 */
	created() {
		this.agentStore = useAgentStore()
		this.agentStore.registerObjectType('agent', 'agent', 'hermiq')
		this.loadAgents()
	},

	methods: {
		/**
		 * Load the selected agent's projects. Non-fatal: without them the page
		 * shows the shared memory only.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/memory-project-scopes/specs/agent-memory/spec.md#requirement-an-owner-sees-and-moves-entries-between-layers-req-pmem-004
		 */
		async loadProjects() {
			const agentId = this.selectedAgent?.value
			if (!agentId) {
				return
			}
			try {
				const projects = await listMemoryProjects(agentId)
				if (this.selectedAgent?.value === agentId) {
					this.projects = projects
				}
			} catch {
				this.projects = []
			}
		},

		/**
		 * Load the agents (createObjectStore, hermiq register) and select the
		 * first one.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/archive/2026-09-06-agent-memory/tasks.md#task-4-1
		 */
		async loadAgents() {
			this.loading = true
			this.error = ''
			try {
				const agents = await this.agentStore.fetchCollection('agent')
				this.agents = Array.isArray(agents) ? agents : []
				if (this.agentStore.errors?.agent) {
					this.error =
						this.agentStore.errors.agent.message
						|| this.t('hermiq', 'Unknown error')
				}
				if (this.agentOptions.length > 0) {
					this.selectedAgent = this.agentOptions[0]
				}
			} catch (e) {
				this.error = errorMessage(e, this.t('hermiq', 'Unknown error'))
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

.agent-memory__layers {
	display: flex;
	flex-wrap: wrap;
	gap: 4px;
	margin-bottom: 16px;
}

.agent-memory__loading {
	display: flex;
	justify-content: center;
	padding: 48px 0;
}
</style>
