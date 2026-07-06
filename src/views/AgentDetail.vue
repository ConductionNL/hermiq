<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  AgentDetail — one agent's detail page (agent-management-ui).

  Shows the agent config, lets the user attach/edit a Schedule (ScheduleFormModal,
  persisted via createObjectStore), trigger a Run now (thin Hermiq endpoint
  POST /apps/hermiq/api/schedules/{id}/run), and review Run history
  (GET /apps/hermiq/api/schedules/{id}/runs).

  Because OpenRegister's agent execution is a work-in-progress that can return an
  error, Run now renders a graceful, dismissible error state (kept in run history)
  rather than breaking the view. All modals are isolated files (ADR-004).

  @spec openspec/changes/agent-management-ui/tasks.md#task-5-1
  @spec openspec/changes/agent-management-ui/specs/agent-management-ui/spec.md
-->
<template>
	<div class="agent-detail">
		<NcButton type="tertiary" class="agent-detail__back" @click="goBack">
			<template #icon>
				<ArrowLeft :size="20" />
			</template>
			{{ t('hermiq', 'Back to agents') }}
		</NcButton>

		<div v-if="loading" class="agent-detail__loading">
			<NcLoadingIcon :size="32" />
		</div>

		<NcEmptyContent
			v-else-if="!agent"
			:name="t('hermiq', 'Agent not found')"
			:description="t('hermiq', 'This agent does not exist or you may not have access to it.')">
			<template #icon>
				<Robot :size="20" />
			</template>
		</NcEmptyContent>

		<template v-else>
			<div class="agent-detail__header">
				<h2 class="agent-detail__title">
					{{ agent.name || t('hermiq', 'Untitled agent') }}
				</h2>
				<NcButton @click="showEditAgent = true">
					<template #icon>
						<Pencil :size="20" />
					</template>
					{{ t('hermiq', 'Edit agent') }}
				</NcButton>
			</div>

			<dl class="agent-detail__meta">
				<div>
					<dt>{{ t('hermiq', 'Provider') }}</dt>
					<dd>{{ agent.provider || '—' }}</dd>
				</div>
				<div>
					<dt>{{ t('hermiq', 'Model') }}</dt>
					<dd>{{ agent.model || '—' }}</dd>
				</div>
				<div class="agent-detail__meta-wide">
					<dt>{{ t('hermiq', 'System prompt') }}</dt>
					<dd>{{ agent.prompt || '—' }}</dd>
				</div>
				<div class="agent-detail__meta-wide">
					<dt>{{ t('hermiq', 'Enabled tools') }}</dt>
					<dd>{{ toolsLabel }}</dd>
				</div>
			</dl>

			<!-- Schedule section -->
			<section class="agent-detail__section">
				<div class="agent-detail__section-head">
					<h3>{{ t('hermiq', 'Schedule') }}</h3>
					<div class="agent-detail__section-actions">
						<NcButton @click="showScheduleForm = true">
							{{ schedule ? t('hermiq', 'Edit schedule') : t('hermiq', 'Attach schedule') }}
						</NcButton>
						<NcButton
							type="primary"
							:disabled="!schedule || running"
							@click="runNow">
							<template #icon>
								<NcLoadingIcon v-if="running" :size="20" />
								<Play v-else :size="20" />
							</template>
							{{ t('hermiq', 'Run now') }}
						</NcButton>
					</div>
				</div>

				<NcNoteCard
					v-if="runError"
					type="error"
					:heading="t('hermiq', 'The last run reported an error')">
					{{ runError }}
					<template #action>
						<NcButton type="tertiary" @click="runError = ''">
							{{ t('hermiq', 'Dismiss') }}
						</NcButton>
					</template>
				</NcNoteCard>

				<p v-if="!schedule" class="agent-detail__empty-hint">
					{{ t('hermiq', 'No schedule attached yet. Attach one to run this agent unattended, or use Run now once attached.') }}
				</p>
				<dl v-else class="agent-detail__meta">
					<div>
						<dt>{{ t('hermiq', 'Trigger') }}</dt>
						<dd>{{ triggerLabel }}</dd>
					</div>
					<div>
						<dt>{{ t('hermiq', 'Deliver output to') }}</dt>
						<dd>{{ schedule.deliver || 'none' }}</dd>
					</div>
					<div>
						<dt>{{ t('hermiq', 'Enabled') }}</dt>
						<dd>{{ schedule.enabled === false ? t('hermiq', 'No') : t('hermiq', 'Yes') }}</dd>
					</div>
					<div>
						<dt>{{ t('hermiq', 'Next run') }}</dt>
						<dd>{{ formatDate(schedule.nextRun) }}</dd>
					</div>
				</dl>
			</section>

			<!-- Run history section -->
			<section class="agent-detail__section">
				<h3>{{ t('hermiq', 'Run history') }}</h3>

				<NcNoteCard v-if="runsError" type="warning">
					{{ t('hermiq', 'Could not load run history.') }}
				</NcNoteCard>

				<p v-else-if="!schedule" class="agent-detail__empty-hint">
					{{ t('hermiq', 'Attach a schedule to start recording run history.') }}
				</p>
				<p v-else-if="runs.length === 0" class="agent-detail__empty-hint">
					{{ t('hermiq', 'No runs yet.') }}
				</p>
				<table v-else class="agent-detail__table">
					<thead>
						<tr>
							<th scope="col">
								{{ t('hermiq', 'Status') }}
							</th>
							<th scope="col">
								{{ t('hermiq', 'Started') }}
							</th>
							<th scope="col">
								{{ t('hermiq', 'Duration') }}
							</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="run in runs" :key="run.id">
							<td>
								<span :class="run.status === 'error' ? 'agent-detail__badge--error' : 'agent-detail__badge--ok'">
									{{ run.status || '—' }}
								</span>
							</td>
							<td>{{ formatDate(run.startedAt || run.created) }}</td>
							<td>{{ durationLabel(run.durationMs) }}</td>
						</tr>
					</tbody>
				</table>
			</section>

			<AgentFormModal
				:show="showEditAgent"
				:agent="agent"
				@close="showEditAgent = false"
				@saved="onAgentSaved" />

			<ScheduleFormModal
				:show="showScheduleForm"
				:agent-id="agentUuid"
				:schedule="schedule"
				@close="showScheduleForm = false"
				@saved="onScheduleSaved" />
		</template>
	</div>
</template>

<script>
import { NcButton, NcEmptyContent, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import ArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Play from 'vue-material-design-icons/Play.vue'
import Robot from 'vue-material-design-icons/Robot.vue'
import { listRuns, runScheduleNow } from '../api/agents.js'
import { useAgentStore, useScheduleStore } from '../store/store.js'
import AgentFormModal from '../modals/AgentFormModal.vue'
import ScheduleFormModal from '../modals/ScheduleFormModal.vue'

export default {
	name: 'AgentDetail',

	components: {
		AgentFormModal,
		ArrowLeft,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		Pencil,
		Play,
		Robot,
		ScheduleFormModal,
	},

	data() {
		return {
			agent: null,
			schedule: null,
			runs: [],
			loading: true,
			running: false,
			runError: '',
			runsError: false,
			showEditAgent: false,
			showScheduleForm: false,
		}
	},

	computed: {
		/**
		 * The agent uuid from the route param.
		 *
		 * @return {string} The agent uuid.
		 */
		agentUuid() {
			return this.$route.params.id
		},

		/**
		 * Comma-joined list of the agent's enabled tools.
		 *
		 * @return {string} The tools label.
		 */
		toolsLabel() {
			const tools = Array.isArray(this.agent && this.agent.tools) ? this.agent.tools : []
			return tools.length ? tools.join(', ') : '—'
		},

		/**
		 * Human label for the schedule trigger.
		 *
		 * @return {string} The trigger label.
		 */
		triggerLabel() {
			if (!this.schedule) {
				return '—'
			}
			if (this.schedule.kind === 'cron') {
				return `${this.t('hermiq', 'Cron')}: ${this.schedule.cronExpr || ''}`
			}
			if (this.schedule.kind === 'interval') {
				return `${this.t('hermiq', 'Every')} ${this.schedule.intervalMinutes} ${this.t('hermiq', 'minutes')}`
			}
			return `${this.t('hermiq', 'Once')}: ${this.formatDate(this.schedule.runAt)}`
		},
	},

	created() {
		this.store = useScheduleStore()
		this.store.registerObjectType('schedule', 'schedule', 'hermiq')
		this.agentStore = useAgentStore()
		this.agentStore.registerObjectType('agent', 'agent', 'hermiq')
		this.load()
	},

	methods: {
		/**
		 * Load the agent (createObjectStore, hermiq register), its attached
		 * schedule, and its run history.
		 *
		 * @return {Promise<void>}
		 */
		async load() {
			this.loading = true
			try {
				const [agent, schedules] = await Promise.all([
					this.agentStore.fetchObject('agent', this.agentUuid),
					this.store.fetchCollection('schedule'),
				])
				this.agent = agent || null
				this.schedule = (Array.isArray(schedules) ? schedules : [])
					.find((candidate) => candidate.agentId === this.agentUuid) || null
				await this.loadRuns()
			} catch (e) {
				showError(this.t('hermiq', 'Could not load the agent.'))
			} finally {
				this.loading = false
			}
		},

		/**
		 * Load the run history for the attached schedule (non-blocking on error).
		 *
		 * @return {Promise<void>}
		 */
		async loadRuns() {
			this.runsError = false
			if (!this.schedule || !this.schedule.id) {
				this.runs = []
				return
			}
			try {
				this.runs = await listRuns(this.schedule.id)
			} catch (e) {
				this.runsError = true
				this.runs = []
			}
		},

		/**
		 * Trigger an immediate run and surface the result or a graceful error state.
		 *
		 * @return {Promise<void>}
		 */
		async runNow() {
			if (!this.schedule || !this.schedule.id) {
				return
			}
			this.running = true
			this.runError = ''
			try {
				const result = await runScheduleNow(this.schedule.id)
				if (result && result.status === 'error') {
					// OpenRegister agent-execution error (recorded + kept in run history).
					this.runError = result.error || this.t('hermiq', 'The agent run failed.')
					showError(this.t('hermiq', 'The agent run reported an error.'))
				} else {
					showSuccess(this.t('hermiq', 'Agent run started.'))
				}
			} catch (e) {
				this.runError = e?.response?.data?.message
					|| e?.response?.data?.error
					|| e?.message
					|| this.t('hermiq', 'The agent run failed.')
				showError(this.t('hermiq', 'The agent run failed.'))
			} finally {
				this.running = false
				// Refresh schedule status + run history regardless of outcome.
				await this.load()
			}
		},

		/**
		 * Reload after the agent is edited.
		 *
		 * @return {Promise<void>}
		 */
		async onAgentSaved() {
			await this.load()
		},

		/**
		 * Reload after the schedule is attached/edited.
		 *
		 * @return {Promise<void>}
		 */
		async onScheduleSaved() {
			await this.load()
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
		 * Human label for a run duration in milliseconds.
		 *
		 * @param {number} ms The duration in milliseconds.
		 * @return {string} The duration label.
		 */
		durationLabel(ms) {
			if (ms === null || ms === undefined) {
				return '—'
			}
			const seconds = Math.round(Number(ms) / 1000)
			return `${seconds}s`
		},

		/**
		 * Navigate back to the agent catalog.
		 *
		 * @return {void}
		 */
		goBack() {
			this.$router.push('/')
		},
	},
}
</script>

<style scoped>
.agent-detail {
	padding: 20px;
	max-width: 960px;
	margin: 0 auto;
}

.agent-detail__back {
	margin-bottom: 8px;
}

.agent-detail__loading {
	display: flex;
	justify-content: center;
	padding: 48px 0;
}

.agent-detail__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	margin-bottom: 12px;
}

.agent-detail__title {
	margin: 0;
	font-size: 22px;
	font-weight: 600;
}

.agent-detail__meta {
	display: grid;
	grid-template-columns: repeat(2, minmax(0, 1fr));
	gap: 12px 24px;
	margin: 0 0 8px;
}

.agent-detail__meta-wide {
	grid-column: 1 / -1;
}

.agent-detail__meta dt {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
	margin-bottom: 2px;
}

.agent-detail__meta dd {
	margin: 0;
	white-space: pre-wrap;
}

.agent-detail__section {
	margin-top: 24px;
	padding-top: 16px;
	border-top: 1px solid var(--color-border);
}

.agent-detail__section-head {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	margin-bottom: 12px;
}

.agent-detail__section-head h3,
.agent-detail__section h3 {
	margin: 0 0 12px;
	font-size: 18px;
	font-weight: 600;
}

.agent-detail__section-actions {
	display: flex;
	gap: 8px;
}

.agent-detail__empty-hint {
	color: var(--color-text-maxcontrast);
	margin: 4px 0;
}

.agent-detail__table {
	width: 100%;
	border-collapse: collapse;
}

.agent-detail__table th,
.agent-detail__table td {
	text-align: left;
	padding: 8px 12px;
	border-bottom: 1px solid var(--color-border);
}

.agent-detail__badge--ok {
	color: var(--color-success-text, var(--color-success));
	font-weight: 600;
}

.agent-detail__badge--error {
	color: var(--color-error-text, var(--color-error));
	font-weight: 600;
}
</style>
