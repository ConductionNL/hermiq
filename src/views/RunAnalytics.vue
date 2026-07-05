<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  RunAnalytics — the Hermiq "Analytics" nav page (run-analytics).

  Read-only run metrics computed from OpenRegister's run AuditTrail (no separate store):
  total runs, success rate, average latency, a status breakdown, and a per-agent table,
  scoped to the caller's tenant and optionally to one agent. Cost/token/tool-usage are
  shown as "not recorded yet" (an OpenRegister seam) rather than fabricated.

  A standard nav page — NOT a dashboard (dashboard-antipattern gate). Every NcSelect
  carries an inputLabel (ADR-004).

  @spec openspec/changes/run-analytics/tasks.md#task-3-2
  @spec openspec/changes/run-analytics/specs/run-analytics/spec.md
-->
<template>
	<div class="run-analytics">
		<div class="run-analytics__header">
			<h2 class="run-analytics__heading">
				{{ t('hermiq', 'Run analytics') }}
			</h2>
			<div class="run-analytics__picker">
				<NcSelect
					v-model="selectedAgent"
					:input-label="t('hermiq', 'Agent')"
					:options="agentOptions"
					:placeholder="t('hermiq', 'All agents')"
					label="label"
					track-by="value"
					@input="load" />
			</div>
		</div>

		<NcNoteCard v-if="error" type="error" :heading="t('hermiq', 'Analytics error')">
			{{ error }}
		</NcNoteCard>

		<div v-if="loading" class="run-analytics__loading">
			<NcLoadingIcon :size="32" />
		</div>

		<template v-else>
			<div class="run-analytics__cards">
				<div class="run-analytics__card">
					<span class="run-analytics__card-value">{{ metrics.totalRuns }}</span>
					<span class="run-analytics__card-label">{{ t('hermiq', 'Total runs') }}</span>
				</div>
				<div class="run-analytics__card">
					<span class="run-analytics__card-value">{{ metrics.successRate }}%</span>
					<span class="run-analytics__card-label">{{ t('hermiq', 'Success rate') }}</span>
				</div>
				<div class="run-analytics__card">
					<span class="run-analytics__card-value">{{ avgLatency }}</span>
					<span class="run-analytics__card-label">{{ t('hermiq', 'Avg latency') }}</span>
				</div>
			</div>

			<section class="run-analytics__section">
				<h3 class="run-analytics__subhead">
					{{ t('hermiq', 'Status breakdown') }}
				</h3>
				<NcEmptyContent
					v-if="statusRows.length === 0"
					:name="t('hermiq', 'No runs yet')"
					:description="t('hermiq', 'Run metrics will appear once agents have run.')">
					<template #icon>
						<ChartIcon :size="20" />
					</template>
				</NcEmptyContent>
				<ul v-else class="run-analytics__list">
					<li v-for="row in statusRows" :key="row.status" class="run-analytics__row">
						<span class="run-analytics__status" :class="`run-analytics__status--${row.status}`">{{ row.status }}</span>
						<span class="run-analytics__count">{{ row.count }}</span>
					</li>
				</ul>
			</section>

			<section v-if="metrics.perAgent && metrics.perAgent.length > 0" class="run-analytics__section">
				<h3 class="run-analytics__subhead">
					{{ t('hermiq', 'Per agent') }}
				</h3>
				<table class="run-analytics__table">
					<thead>
						<tr>
							<th scope="col">
								{{ t('hermiq', 'Agent') }}
							</th>
							<th scope="col">
								{{ t('hermiq', 'Runs') }}
							</th>
							<th scope="col">
								{{ t('hermiq', 'Success') }}
							</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="row in metrics.perAgent" :key="row.agentId">
							<td>{{ agentName(row.agentId) }}</td>
							<td>{{ row.runs }}</td>
							<td>{{ row.success }}</td>
						</tr>
					</tbody>
				</table>
			</section>

			<p class="run-analytics__seam">
				{{ t('hermiq', 'Cost, token and tool-usage metrics are not recorded yet — they await OpenRegister run-cost recording.') }}
			</p>
		</template>
	</div>
</template>

<script>
import { NcEmptyContent, NcLoadingIcon, NcNoteCard, NcSelect } from '@nextcloud/vue'
import ChartIcon from 'vue-material-design-icons/ChartBar.vue'
import { listAgents } from '../api/agents.js'
import { getAnalytics } from '../api/analytics.js'

export default {
	name: 'RunAnalytics',

	components: {
		ChartIcon,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
	},

	data() {
		return {
			agents: [],
			selectedAgent: null,
			metrics: { totalRuns: 0, successRate: 0, statusBreakdown: {}, perAgent: [], latency: {} },
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

		/**
		 * The status breakdown as sorted rows.
		 *
		 * @return {Array<object>} The { status, count } rows.
		 */
		statusRows() {
			const b = this.metrics.statusBreakdown || {}
			return Object.keys(b).map((status) => ({ status, count: b[status] })).sort((a, x) => x.count - a.count)
		},

		/**
		 * The average latency, human-formatted.
		 *
		 * @return {string} The formatted latency, or a dash.
		 */
		avgLatency() {
			const ms = this.metrics.latency && this.metrics.latency.avgMs
			if (ms === null || ms === undefined) {
				return '—'
			}
			if (ms >= 1000) {
				return (ms / 1000).toFixed(1) + ' s'
			}
			return ms + ' ms'
		},
	},

	created() {
		this.loadAgents()
	},

	methods: {
		/**
		 * Load the agents (for the filter + per-agent names), then the metrics.
		 *
		 * @return {Promise<void>}
		 */
		async loadAgents() {
			try {
				this.agents = await listAgents()
			} catch (e) {
				// Agent names are cosmetic; metrics still render.
				this.agents = []
			}
			await this.load()
		},

		/**
		 * Load the analytics for the current agent scope.
		 *
		 * @return {Promise<void>}
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				this.metrics = await getAnalytics(this.selectedAgent ? this.selectedAgent.value : '')
			} catch (e) {
				this.error = e?.response?.data?.error || e?.message || this.t('hermiq', 'Unknown error')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Resolve an agent's display name from its UUID.
		 *
		 * @param {string} agentId The agent UUID.
		 * @return {string} The agent name, or the UUID.
		 */
		agentName(agentId) {
			const agent = this.agents.find((a) => (a.uuid || a.id) === agentId)
			return (agent && agent.name) || agentId || '—'
		},
	},
}
</script>

<style scoped>
.run-analytics {
	padding: 20px;
	max-width: 900px;
	margin: 0 auto;
}

.run-analytics__header {
	display: flex;
	align-items: flex-start;
	justify-content: space-between;
	gap: 12px;
	margin-bottom: 16px;
	flex-wrap: wrap;
}

.run-analytics__heading {
	margin: 0;
	font-size: 22px;
	font-weight: 600;
}

.run-analytics__picker {
	min-width: 240px;
}

.run-analytics__loading {
	display: flex;
	justify-content: center;
	padding: 48px 0;
}

.run-analytics__cards {
	display: flex;
	gap: 12px;
	flex-wrap: wrap;
	margin-bottom: 24px;
}

.run-analytics__card {
	flex: 1 1 160px;
	display: flex;
	flex-direction: column;
	gap: 4px;
	padding: 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	background: var(--color-background-hover);
}

.run-analytics__card-value {
	font-size: 28px;
	font-weight: 700;
}

.run-analytics__card-label {
	color: var(--color-text-maxcontrast);
}

.run-analytics__section {
	margin-bottom: 24px;
}

.run-analytics__subhead {
	font-size: 16px;
	font-weight: 600;
	margin: 0 0 8px;
}

.run-analytics__list {
	list-style: none;
	margin: 0;
	padding: 0;
}

.run-analytics__row {
	display: flex;
	justify-content: space-between;
	padding: 8px 12px;
	border-bottom: 1px solid var(--color-border);
}

.run-analytics__status {
	font-weight: 600;
}

.run-analytics__status--ok {
	color: var(--color-success);
}

.run-analytics__status--error,
.run-analytics__status--failed {
	color: var(--color-error);
}

.run-analytics__table {
	width: 100%;
	border-collapse: collapse;
}

.run-analytics__table th,
.run-analytics__table td {
	text-align: left;
	padding: 8px 12px;
	border-bottom: 1px solid var(--color-border);
}

.run-analytics__seam {
	color: var(--color-text-maxcontrast);
	font-style: italic;
	margin-top: 8px;
}
</style>
