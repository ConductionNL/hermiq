<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  AgentKpiWidget — this agent's own run totals as a type:"detail" custom widget
  (manifest-driven-pages).

  Extracted from AgentDetail.vue's conversion to a type:"detail" grid. Reuses
  AnalyticsKpiWidget's computed /api/analytics endpoint, but scoped to the
  route's agent id (agentId query param) instead of tenant-wide — the same
  endpoint RunAnalytics' dashboard KPI widget calls, just scoped narrower.
  Self-fetches the agent id from `$route.params.id` (the manifest's
  `page.slots.widget-<id>` scoped slot only forwards `{ item, widget }`, not
  the loaded object — mirrors procest's InitiatorSection self-fetch pattern).

  Not a `stats-block` — the analytics endpoint is a computed aggregate, not an
  OR object-count query (same ADR-049 rationale already documented for
  `analytics-kpis` on the RunAnalytics dashboard).

  @spec openspec/changes/manifest-driven-pages/specs/manifest-driven-pages/spec.md#req-003-an-agent-scoped-run-kpi-custom-widget-shows-this-agents-run-totals
-->
<template>
	<div class="agent-kpi-widget">
		<div v-if="loading" class="agent-kpi-widget__loading">
			<NcLoadingIcon :size="28" />
		</div>
		<NcNoteCard v-else-if="error" type="error">
			{{ error }}
		</NcNoteCard>
		<div v-else class="agent-kpi-widget__cards">
			<div class="agent-kpi-widget__card">
				<span class="agent-kpi-widget__value">{{ metrics.totalRuns }}</span>
				<span class="agent-kpi-widget__label">{{ t('hermiq', 'Total runs') }}</span>
			</div>
			<div class="agent-kpi-widget__card">
				<span class="agent-kpi-widget__value">{{ metrics.successRate }}%</span>
				<span class="agent-kpi-widget__label">{{ t('hermiq', 'Success rate') }}</span>
			</div>
			<div class="agent-kpi-widget__card">
				<span class="agent-kpi-widget__value">{{ avgLatency }}</span>
				<span class="agent-kpi-widget__label">{{ t('hermiq', 'Avg latency') }}</span>
			</div>
			<div class="agent-kpi-widget__card">
				<span class="agent-kpi-widget__value">{{ totalTokens }}</span>
				<span class="agent-kpi-widget__label">{{ t('hermiq', 'Tokens') }}</span>
			</div>
		</div>
	</div>
</template>

<script>
import { NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import { getAnalytics } from '../api/analytics.js'

export default {
	name: 'AgentKpiWidget',

	components: {
		NcLoadingIcon,
		NcNoteCard,
	},

	data() {
		return {
			metrics: { totalRuns: 0, successRate: 0, latency: {}, tokens: {} },
			loading: true,
			error: '',
		}
	},

	computed: {
		/**
		 * This agent's uuid from the route param.
		 *
		 * @return {string} The agent uuid.
		 */
		agentId() {
			return this.$route.params.id
		},

		/**
		 * Average latency, human-formatted.
		 *
		 * @return {string} The formatted latency, or a dash.
		 */
		avgLatency() {
			const ms = this.metrics.latency && this.metrics.latency.avgMs
			if (ms === null || ms === undefined) {
				return '—'
			}
			return ms >= 1000 ? (ms / 1000).toFixed(1) + ' s' : ms + ' ms'
		},

		/**
		 * Total LLM tokens across this agent's runs, or a dash when none recorded.
		 *
		 * @return {string|number} The total tokens, or a dash.
		 */
		totalTokens() {
			if (this.metrics.tokens && this.metrics.tokens.available) {
				return this.metrics.tokens.total
			}
			return '—'
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * Load this agent's run analytics.
		 *
		 * @return {Promise<void>}
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				this.metrics = await getAnalytics(this.agentId)
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
.agent-kpi-widget__cards {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
	gap: 12px;
}

.agent-kpi-widget__card {
	display: flex;
	flex-direction: column;
	gap: 4px;
	padding: 12px;
	background: var(--color-background-hover);
	border-radius: var(--border-radius-large, 8px);
}

.agent-kpi-widget__value {
	font-size: 24px;
	font-weight: 600;
	color: var(--color-main-text);
}

.agent-kpi-widget__label {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.agent-kpi-widget__loading {
	display: flex;
	justify-content: center;
	padding: 16px;
}
</style>
