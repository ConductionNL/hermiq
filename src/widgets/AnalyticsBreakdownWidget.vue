<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  AnalyticsBreakdownWidget — run status breakdown + per-agent table.

  The detail companion to AnalyticsKpiWidget on the RunAnalytics dashboard: a
  status→count breakdown and a per-agent runs/success table, read from the
  computed /api/analytics endpoint. Agent names are resolved from the agents
  resource (cosmetic; metrics still render if it fails).
-->
<template>
	<div class="analytics-breakdown">
		<div v-if="loading" class="analytics-breakdown__loading">
			<NcLoadingIcon :size="28" />
		</div>
		<NcNoteCard v-else-if="error" type="error">
			{{ error }}
		</NcNoteCard>
		<template v-else>
			<section class="analytics-breakdown__section">
				<h3 class="analytics-breakdown__subhead">
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
				<ul v-else class="analytics-breakdown__list">
					<li v-for="row in statusRows" :key="row.status" class="analytics-breakdown__row">
						<span class="analytics-breakdown__status" :class="`analytics-breakdown__status--${row.status}`">{{ row.status }}</span>
						<span class="analytics-breakdown__count">{{ row.count }}</span>
					</li>
				</ul>
			</section>

			<section v-if="metrics.perAgent && metrics.perAgent.length > 0" class="analytics-breakdown__section">
				<h3 class="analytics-breakdown__subhead">
					{{ t('hermiq', 'Per agent') }}
				</h3>
				<table class="analytics-breakdown__table">
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
							<td>{{ agentName(row) }}</td>
							<td>{{ row.runs }}</td>
							<td>{{ row.success }}</td>
						</tr>
					</tbody>
				</table>
			</section>

			<p v-if="metrics.tokens && metrics.tokens.available" class="analytics-breakdown__seam">
				{{ t('hermiq', 'Tokens: {prompt} prompt + {completion} completion, recorded from OpenRegister run-cost.', { prompt: metrics.tokens.prompt, completion: metrics.tokens.completion }) }}
			</p>
			<p v-else class="analytics-breakdown__seam">
				{{ t('hermiq', 'Token usage will appear here once an agent run records it (OpenRegister run-cost).') }}
			</p>
		</template>
	</div>
</template>

<script>
import { NcEmptyContent, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import ChartIcon from 'vue-material-design-icons/ChartBar.vue'
import { getAnalytics } from '../api/analytics.js'
import { useAgentStore } from '../store/store.js'

export default {
	name: 'AnalyticsBreakdownWidget',

	components: {
		ChartIcon,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
	},

	data() {
		return {
			metrics: { statusBreakdown: {}, perAgent: [], tokens: {} },
			agents: [],
			loading: true,
			error: '',
		}
	},

	computed: {
		/**
		 * The status breakdown as sorted rows.
		 *
		 * @return {Array<object>} The { status, count } rows.
		 */
		statusRows() {
			const b = this.metrics.statusBreakdown || {}
			return Object.keys(b).map((status) => ({ status, count: b[status] })).sort((a, x) => x.count - a.count)
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * Load the analytics + agents (for per-agent names).
		 *
		 * @return {Promise<void>}
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				const agentStore = useAgentStore()
				agentStore.registerObjectType('agent', 'agent', 'hermiq')
				const [metrics, agents] = await Promise.all([
					getAnalytics(''),
					agentStore.fetchCollection('agent').catch(() => []),
				])
				this.metrics = metrics
				this.agents = Array.isArray(agents) ? agents : []
			} catch (e) {
				this.error = e?.response?.data?.error || e?.message || this.t('hermiq', 'Unknown error')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Resolve an agent's display name for one `perAgent` row.
		 *
		 * Prefers the name the SERVER now sends on the row (AnalyticsService
		 * enriches `perAgent` with it, so the sibling "Runs by agent" chart can
		 * label its bars). That is strictly better than the client-side store
		 * lookup below, which only resolves agents this session happens to have
		 * loaded — an agent that has since been deleted, or simply is not in the
		 * fetched page, fell through to a raw UUID in the user's face. The store
		 * lookup stays as the fallback so the widget keeps working against an
		 * older server that does not send `name` yet.
		 *
		 * @param {object} row The perAgent row ({ agentId, name?, runs, success }).
		 * @return {string} The agent name, or the UUID.
		 */
		agentName(row) {
			if (row && typeof row.name === 'string' && row.name !== '') {
				return row.name
			}

			const agentId = (row && row.agentId) || ''
			const agent = this.agents.find((a) => (a.uuid || a.id) === agentId)
			return (agent && agent.name) || agentId || '—'
		},
	},
}
</script>

<style scoped>
.analytics-breakdown__section {
	margin-bottom: 16px;
}

.analytics-breakdown__subhead {
	font-size: 15px;
	font-weight: 600;
	margin: 0 0 8px;
}

.analytics-breakdown__list {
	list-style: none;
	margin: 0;
	padding: 0;
}

.analytics-breakdown__row {
	display: flex;
	justify-content: space-between;
	padding: 6px 4px;
	border-bottom: 1px solid var(--color-border);
}

.analytics-breakdown__status {
	text-transform: capitalize;
}

.analytics-breakdown__count {
	font-weight: 600;
}

.analytics-breakdown__table {
	width: 100%;
	border-collapse: collapse;
}

.analytics-breakdown__table th,
.analytics-breakdown__table td {
	text-align: left;
	padding: 6px 4px;
	border-bottom: 1px solid var(--color-border);
}

.analytics-breakdown__seam {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	margin: 8px 0 0;
}

.analytics-breakdown__loading {
	display: flex;
	justify-content: center;
	padding: 16px;
}
</style>
