<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  EvalRunPanelWidget — one eval dataset's agent-picker + Run action + run
  history as the sole `type:"custom"` content widget on the new
  `EvalDatasetDetail` page (manifest-driven-pages).

  `EvalDatasets.vue` today renders one card per dataset with an embedded runs
  sub-table and an inline agent-picker + Run button per card — a nested, not
  flat, shape that `type:"index"` cannot express directly (design.md
  Decision 8). The outer list becomes `type:"index"` (name/description
  columns only — no per-row run controls); this widget hosts the actual
  per-dataset run management on the new detail page. "Run" has no OR-object
  equivalent (the one bespoke `src/api/evals.js` action; every other eval
  path is object CRUD), so it stays a custom widget rather than a declarative
  `object-op`. Self-fetches the dataset id from `$route.params.id`.

  @spec openspec/changes/manifest-driven-pages/specs/manifest-driven-pages/spec.md#req-011-evaldatasets-renders-as-an-index-type-list-page-with-per-dataset-run-management-on-a-new-evaldatasetdetail-page
-->
<template>
	<div class="eval-run-panel-widget">
		<NcNoteCard v-if="error" type="error" :heading="t('hermiq', 'Evaluations error')">
			{{ error }}
		</NcNoteCard>

		<div class="eval-run-panel-widget__controls">
			<NcSelect
				v-model="selectedAgent"
				class="eval-run-panel-widget__agent-picker"
				:input-label="t('hermiq', 'Agent')"
				:options="agentOptions"
				label="label"
				track-by="value"
				:placeholder="t('hermiq', 'Select an agent')" />
			<NcButton
				type="primary"
				:disabled="running || !selectedAgent"
				@click="run">
				<template v-if="running" #icon>
					<NcLoadingIcon :size="20" />
				</template>
				{{ t('hermiq', 'Run') }}
			</NcButton>
		</div>

		<div v-if="runs.length > 0" class="eval-run-panel-widget__runs">
			<table class="eval-run-panel-widget__table">
				<thead>
					<tr>
						<th>{{ t('hermiq', 'When') }}</th>
						<th>{{ t('hermiq', 'Pass rate') }}</th>
						<th>{{ t('hermiq', 'Regression gate') }}</th>
						<th>{{ t('hermiq', 'Status') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="runRow in runs" :key="runRow.id">
						<td>{{ formatDate(runRow.startedAt) }}</td>
						<td>{{ passRateLabel(runRow.passRate) }}</td>
						<td>
							<span :class="['eval-run-panel-widget__badge', regressionBadgeClass(runRow.regressionGateResult)]">
								{{ regressionLabel(runRow.regressionGateResult) }}
							</span>
						</td>
						<td>{{ statusLabel(runRow.status) }}</td>
					</tr>
				</tbody>
			</table>
		</div>
		<p v-else class="eval-run-panel-widget__empty-hint">
			{{ t('hermiq', 'No runs yet — pick an agent and run this dataset.') }}
		</p>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon, NcNoteCard, NcSelect } from '@nextcloud/vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { runEval } from '../api/evals.js'
import { useAgentStore, useEvalRunStore } from '../store/store.js'

export default {
	name: 'EvalRunPanelWidget',

	components: {
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
	},

	data() {
		return {
			agents: [],
			runs: [],
			selectedAgent: null,
			running: false,
			error: '',
		}
	},

	computed: {
		/**
		 * This dataset's uuid from the route param.
		 *
		 * @return {string} The dataset uuid.
		 */
		datasetId() {
			return this.$route.params.id
		},

		/**
		 * The caller's agents as NcSelect options.
		 *
		 * @return {Array<object>} The { label, value } options.
		 */
		agentOptions() {
			return this.agents.map((agent) => ({
				label: agent.name || (agent.uuid || agent.id),
				value: agent.uuid || agent.id,
			}))
		},
	},

	created() {
		this.agentStore = useAgentStore()
		this.agentStore.registerObjectType('agent', 'agent', 'hermiq')
		this.runStore = useEvalRunStore()
		this.runStore.registerObjectType('evalrun', 'evalrun', 'hermiq')
		this.load()
	},

	methods: {
		/**
		 * Load the caller's agents and this dataset's prior runs.
		 *
		 * @return {Promise<void>}
		 */
		async load() {
			this.error = ''
			try {
				const [agents, runs] = await Promise.all([
					this.agentStore.fetchCollection('agent').catch(() => []),
					this.runStore.fetchCollection('evalrun').catch(() => []),
				])
				this.agents = Array.isArray(agents) ? agents : []
				this.runs = (Array.isArray(runs) ? runs : [])
					.filter((runRow) => runRow.datasetId === this.datasetId)
					.sort((a, b) => String(b.startedAt || '').localeCompare(String(a.startedAt || '')))
					.slice(0, 10)
			} catch (e) {
				this.error = e?.response?.data?.error || e?.message || this.t('hermiq', 'Unknown error')
			}
		},

		/**
		 * Run this dataset against the selected agent.
		 *
		 * @return {Promise<void>}
		 */
		async run() {
			if (!this.selectedAgent) {
				return
			}
			this.running = true
			try {
				const outcome = await runEval(this.datasetId, this.selectedAgent.value)
				showSuccess(this.t('hermiq', 'Eval run complete: {rate} passed.', { rate: this.passRateLabel(outcome.passRate) }))
				await this.load()
			} catch (e) {
				showError(e?.response?.data?.error || this.t('hermiq', 'The eval run failed.'))
			} finally {
				this.running = false
			}
		},

		/**
		 * Human pass-rate percentage (the stored value is a 0..1 fraction).
		 *
		 * @param {number} passRate The 0..1 pass rate.
		 * @return {string} A percentage label.
		 */
		passRateLabel(passRate) {
			if (typeof passRate !== 'number') {
				return '—'
			}
			return `${Math.round(passRate * 100)}%`
		},

		/**
		 * Localised regression-gate label.
		 *
		 * @param {string} result The gate result.
		 * @return {string} The label.
		 */
		regressionLabel(result) {
			if (result === 'failed') {
				return this.t('hermiq', 'Regressed')
			}
			if (result === 'passed') {
				return this.t('hermiq', 'No regression')
			}
			return this.t('hermiq', 'First run')
		},

		/**
		 * Badge class for a regression-gate result (never colour-only — text
		 * distinct too).
		 *
		 * @param {string} result The gate result.
		 * @return {string} The badge modifier class.
		 */
		regressionBadgeClass(result) {
			if (result === 'failed') {
				return 'eval-run-panel-widget__badge--error'
			}
			if (result === 'passed') {
				return 'eval-run-panel-widget__badge--ok'
			}
			return 'eval-run-panel-widget__badge--neutral'
		},

		/**
		 * Localised run-status label.
		 *
		 * @param {string} status The run status.
		 * @return {string} The label.
		 */
		statusLabel(status) {
			const labels = {
				completed: this.t('hermiq', 'Completed'),
				failed: this.t('hermiq', 'Failed'),
				blocked_killswitch: this.t('hermiq', 'Blocked (kill switch)'),
				blocked_budget: this.t('hermiq', 'Blocked (budget)'),
			}
			return labels[status] || status
		},

		/**
		 * Format an ISO timestamp for display.
		 *
		 * @param {string} value The ISO timestamp.
		 * @return {string} The formatted date, or an em dash.
		 */
		formatDate(value) {
			if (!value) {
				return '—'
			}
			const date = new Date(value)
			return Number.isNaN(date.getTime()) ? value : date.toLocaleString()
		},
	},
}
</script>

<style scoped>
.eval-run-panel-widget__controls {
	display: flex;
	align-items: flex-end;
	gap: 8px;
	margin-bottom: 16px;
}

.eval-run-panel-widget__agent-picker {
	min-width: 240px;
}

.eval-run-panel-widget__table {
	width: 100%;
	border-collapse: collapse;
}

.eval-run-panel-widget__table th,
.eval-run-panel-widget__table td {
	text-align: left;
	padding: 6px 8px;
	border-bottom: 1px solid var(--color-border);
	font-size: 13px;
}

.eval-run-panel-widget__badge {
	display: inline-block;
	padding: 1px 8px;
	border-radius: var(--border-radius-pill);
	font-size: 12px;
}

.eval-run-panel-widget__badge--ok {
	background: var(--color-success);
	color: var(--color-primary-text);
}

.eval-run-panel-widget__badge--error {
	background: var(--color-error);
	color: var(--color-primary-text);
}

.eval-run-panel-widget__badge--neutral {
	background: var(--color-background-dark);
}

.eval-run-panel-widget__empty-hint {
	margin: 8px 0 0;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}
</style>
