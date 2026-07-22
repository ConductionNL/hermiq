<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  AnalyticsKpiWidget — the headline run-analytics KPIs as a dashboard widget.

  Total runs, success rate, average latency and LLM tokens, read from the
  computed /api/analytics endpoint (runs are not OR-schema objects, so this can't
  be a declarative stats-block dataSource). Rendered on the RunAnalytics
  dashboard alongside AnalyticsBreakdownWidget.
-->
<template>
	<div class="analytics-kpi">
		<div class="analytics-kpi__header">
			<h2 class="analytics-kpi__title">
				{{ t('hermiq', 'Analytics') }}
			</h2>
			<p class="analytics-kpi__desc">
				{{ t('hermiq', 'Run metrics across your agents — success rate, latency and token usage.') }}
			</p>
		</div>
		<div v-if="loading" class="analytics-kpi__loading">
			<NcLoadingIcon :size="28" />
		</div>
		<NcNoteCard v-else-if="error" type="error">
			{{ error }}
		</NcNoteCard>
		<div v-else class="analytics-kpi__cards">
			<div class="analytics-kpi__card">
				<span class="analytics-kpi__value">{{ metrics.totalRuns }}</span>
				<span class="analytics-kpi__label">{{ t('hermiq', 'Total runs') }}</span>
			</div>
			<div class="analytics-kpi__card">
				<span class="analytics-kpi__value">{{ metrics.successRate }}%</span>
				<span class="analytics-kpi__label">{{ t('hermiq', 'Success rate') }}</span>
			</div>
			<div class="analytics-kpi__card">
				<span class="analytics-kpi__value">{{ avgLatency }}</span>
				<span class="analytics-kpi__label">{{ t('hermiq', 'Avg latency') }}</span>
			</div>
			<div class="analytics-kpi__card">
				<span class="analytics-kpi__value">{{ totalTokens }}</span>
				<span class="analytics-kpi__label">{{ t('hermiq', 'Tokens') }}</span>
			</div>
		</div>
	</div>
</template>

<script>
import { NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import { getAnalytics } from '../api/analytics.js'

export default {
	name: 'AnalyticsKpiWidget',

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
		 * Total LLM tokens across the runs, or a dash when none recorded.
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
		 * Load the run analytics (all agents).
		 *
		 * @return {Promise<void>}
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				this.metrics = await getAnalytics('')
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
.analytics-kpi__header {
	padding-top: 8px;
	margin-bottom: 16px;
}

.analytics-kpi__title {
	margin: 0;
	font-size: 22px;
	font-weight: 600;
}

.analytics-kpi__desc {
	color: var(--color-text-maxcontrast);
	margin: 4px 0 0;
}

.analytics-kpi__cards {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
	gap: 12px;
}

.analytics-kpi__card {
	display: flex;
	flex-direction: column;
	gap: 4px;
	padding: 12px;
	background: var(--color-background-hover);
	border-radius: var(--border-radius-large, 8px);
}

.analytics-kpi__value {
	font-size: 24px;
	font-weight: 600;
	color: var(--color-main-text);
}

.analytics-kpi__label {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.analytics-kpi__loading {
	display: flex;
	justify-content: center;
	padding: 16px;
}
</style>
