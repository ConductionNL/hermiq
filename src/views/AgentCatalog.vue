<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  AgentCatalog — the Hermiq "Agents" nav page (agent-management-ui).

  Lists the agents the user may see (RBAC-filtered by OpenRegister's
  AgentsController), showing name, model, whether a schedule is attached, and the
  last-run status. Agents come from the plain agents API helper; the
  schedule-attached + last-run columns are derived by matching Schedule.agentId
  against each agent's uuid from the createObjectStore schedule collection.

  Renders through the shared CnDataTable (the same widget the manifest `type: index`
  pages use via `object-table`) so the list matches the standard index-page design,
  while keeping the agent-specific create/open actions (which the register-bound
  index kind cannot express — agents are an OpenRegister resource, not
  register/schema objects). Create/edit uses the isolated AgentFormModal (ADR-004).

  @spec openspec/changes/agent-management-ui/tasks.md#task-3-1
  @spec openspec/changes/agent-management-ui/specs/agent-management-ui/spec.md
-->
<template>
	<div class="agent-catalog">
		<div class="agent-catalog__header">
			<h2 class="agent-catalog__heading">
				{{ t('hermiq', 'Agents') }}
			</h2>
			<NcButton type="primary" @click="openCreate">
				<template #icon>
					<Plus :size="20" />
				</template>
				{{ t('hermiq', 'Create agent') }}
			</NcButton>
		</div>

		<NcNoteCard v-if="error" type="error" :heading="t('hermiq', 'Could not load agents')">
			{{ error }}
		</NcNoteCard>

		<NcEmptyContent
			v-if="!loading && agents.length === 0 && !error"
			:name="t('hermiq', 'No agents yet')"
			:description="t('hermiq', 'Create your first agent to run it on a schedule.')">
			<template #icon>
				<Robot :size="20" />
			</template>
			<template #action>
				<NcButton type="primary" @click="openCreate">
					{{ t('hermiq', 'Create agent') }}
				</NcButton>
			</template>
		</NcEmptyContent>

		<CnDataTable
			v-else
			:columns="columns"
			:rows="rows"
			:loading="loading"
			row-key="id"
			:empty-text="t('hermiq', 'No agents yet')">
			<template #column-schedule="{ row }">
				<span :class="row.scheduleAttached ? 'agent-catalog__badge--on' : 'agent-catalog__badge--off'">
					{{ row.schedule }}
				</span>
			</template>
			<template #row-actions="{ row }">
				<NcButton
					type="tertiary"
					:aria-label="t('hermiq', 'Open agent')"
					@click="openAgent(row.agent)">
					{{ t('hermiq', 'Open') }}
				</NcButton>
			</template>
		</CnDataTable>

		<AgentFormModal
			:show="showCreate"
			:agent="null"
			@close="showCreate = false"
			@saved="onAgentSaved" />
	</div>
</template>

<script>
import { NcButton, NcEmptyContent, NcNoteCard } from '@nextcloud/vue'
import { CnDataTable } from '@conduction/nextcloud-vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Robot from 'vue-material-design-icons/Robot.vue'
import { listAgents } from '../api/agents.js'
import { useScheduleStore } from '../store/store.js'
import AgentFormModal from '../modals/AgentFormModal.vue'

export default {
	name: 'AgentCatalog',

	components: {
		AgentFormModal,
		CnDataTable,
		NcButton,
		NcEmptyContent,
		NcNoteCard,
		Plus,
		Robot,
	},

	data() {
		return {
			agents: [],
			schedules: [],
			loading: true,
			error: '',
			showCreate: false,
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
				{ key: 'model', label: this.t('hermiq', 'Model') },
				{ key: 'schedule', label: this.t('hermiq', 'Schedule') },
				{ key: 'lastRun', label: this.t('hermiq', 'Last run') },
			]
		},

		/**
		 * Agents projected onto flat rows for the index table. Keeps the original
		 * agent object under `agent` so row actions can navigate to its detail.
		 *
		 * @return {Array<object>} The table rows.
		 */
		rows() {
			return this.agents.map((agent) => {
				const schedule = this.scheduleFor(agent)
				return {
					id: agent.uuid || agent.id,
					name: agent.name || this.t('hermiq', 'Untitled agent'),
					model: agent.model || '—',
					schedule: schedule ? this.t('hermiq', 'Attached') : this.t('hermiq', 'None'),
					scheduleAttached: !!schedule,
					lastRun: this.lastRunLabel(agent),
					agent,
				}
			})
		},
	},

	created() {
		this.store = useScheduleStore()
		this.store.registerObjectType('schedule', 'schedule', 'hermiq')
		this.load()
	},

	methods: {
		/**
		 * Load agents (RBAC-scoped) and the schedule collection in parallel.
		 *
		 * @return {Promise<void>}
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				const [agents, schedules] = await Promise.all([
					listAgents(),
					this.store.fetchCollection('schedule'),
				])
				this.agents = agents
				this.schedules = Array.isArray(schedules) ? schedules : []
			} catch (e) {
				this.error = e?.response?.data?.error || e?.message || this.t('hermiq', 'Unknown error')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Find the schedule attached to an agent (by uuid), if any.
		 *
		 * @param {object} agent The agent object.
		 * @return {object|null} The attached schedule, or null.
		 */
		scheduleFor(agent) {
			const uuid = agent.uuid || agent.id
			return this.schedules.find((schedule) => schedule.agentId === uuid) || null
		},

		/**
		 * Human label for an agent's last-run status.
		 *
		 * @param {object} agent The agent object.
		 * @return {string} The last-run label.
		 */
		lastRunLabel(agent) {
			const schedule = this.scheduleFor(agent)
			if (!schedule || !schedule.lastStatus) {
				return '—'
			}
			if (schedule.lastStatus === 'ok') {
				return this.t('hermiq', 'OK')
			}
			if (schedule.lastStatus === 'error') {
				return this.t('hermiq', 'Error')
			}
			if (schedule.lastStatus === 'running') {
				return this.t('hermiq', 'Running')
			}
			return schedule.lastStatus
		},

		/**
		 * Open the create-agent modal.
		 *
		 * @return {void}
		 */
		openCreate() {
			this.showCreate = true
		},

		/**
		 * Reload after an agent is created/edited.
		 *
		 * @return {Promise<void>}
		 */
		async onAgentSaved() {
			await this.load()
		},

		/**
		 * Navigate to an agent's detail page (route param = agent uuid).
		 *
		 * @param {object} agent The agent object.
		 * @return {void}
		 */
		openAgent(agent) {
			this.$router.push(`/agents/${agent.uuid || agent.id}`)
		},
	},
}
</script>

<style scoped>
.agent-catalog {
	padding: 20px;
	max-width: 960px;
	margin: 0 auto;
}

.agent-catalog__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	margin-bottom: 16px;
}

.agent-catalog__heading {
	margin: 0;
	font-size: 22px;
	font-weight: 600;
}

.agent-catalog__badge--on {
	color: var(--color-success-text, var(--color-success));
	font-weight: 600;
}

.agent-catalog__badge--off {
	color: var(--color-text-maxcontrast);
}
</style>
