<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  EvalDatasets — the Hermiq "Evaluations" nav page (agent-evals).

  Lists the caller's eval datasets (createObjectStore over the hermiq/evaldataset
  schema), lets them create/edit one (EvalDatasetFormModal), run a dataset against an
  agent (the one bespoke src/api/evals.js action — every other path is object CRUD),
  and shows the per-dataset EvalRun history: pass rate, regression-gate result, and
  status. Runs are written server-side by EvalRunService (governed, non-delivering), so
  the evalrun store is read-only here.

  @spec openspec/changes/agent-evals/tasks.md#task-10-evalrunsvue--l10n-strings
-->
<template>
	<div class="evals" data-testid="evals">
		<div class="evals__header">
			<h2 class="evals__heading" data-testid="evals-heading">
				{{ t('hermiq', 'Evaluations') }}
			</h2>
			<NcButton type="primary" @click="openCreate">
				{{ t('hermiq', 'New dataset') }}
			</NcButton>
		</div>

		<NcNoteCard v-if="error" type="error" :heading="t('hermiq', 'Evaluations error')">
			{{ error }}
		</NcNoteCard>

		<div v-if="loading" class="evals__loading">
			<NcLoadingIcon :size="32" />
		</div>

		<NcEmptyContent
			v-else-if="datasets.length === 0"
			:name="t('hermiq', 'No eval datasets yet')"
			:description="t('hermiq', 'Create a dataset of prompt + expectation cases, then run it against an agent to measure and track its quality.')">
			<template #icon>
				<BeakerIcon :size="20" />
			</template>
		</NcEmptyContent>

		<div v-else class="evals__list">
			<section v-for="dataset in datasets" :key="dataset.id" class="evals__dataset">
				<div class="evals__dataset-head">
					<div>
						<strong>{{ dataset.name || t('hermiq', 'Untitled dataset') }}</strong>
						<span class="evals__dataset-sub">
							{{ n('hermiq', '%n case', '%n cases', caseCount(dataset)) }}
						</span>
					</div>
					<div class="evals__dataset-actions">
						<NcSelect
							v-model="selectedAgent[dataset.id]"
							class="evals__agent-picker"
							:input-label="t('hermiq', 'Agent')"
							:options="agentOptions"
							label="label"
							track-by="value"
							:placeholder="t('hermiq', 'Select an agent')" />
						<NcButton
							type="secondary"
							:disabled="runningDatasetId === dataset.id || !selectedAgent[dataset.id]"
							@click="run(dataset)">
							<template v-if="runningDatasetId === dataset.id" #icon>
								<NcLoadingIcon :size="20" />
							</template>
							{{ t('hermiq', 'Run') }}
						</NcButton>
						<NcButton type="tertiary" @click="openEdit(dataset)">
							{{ t('hermiq', 'Edit') }}
						</NcButton>
					</div>
				</div>

				<div v-if="runsFor(dataset.id).length > 0" class="evals__runs">
					<table class="evals__runs-table">
						<thead>
							<tr>
								<th>{{ t('hermiq', 'When') }}</th>
								<th>{{ t('hermiq', 'Pass rate') }}</th>
								<th>{{ t('hermiq', 'Regression gate') }}</th>
								<th>{{ t('hermiq', 'Status') }}</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="runRow in runsFor(dataset.id)" :key="runRow.id">
								<td>{{ formatDate(runRow.startedAt) }}</td>
								<td>{{ passRateLabel(runRow.passRate) }}</td>
								<td>
									<span :class="['evals__badge', regressionBadgeClass(runRow.regressionGateResult)]">
										{{ regressionLabel(runRow.regressionGateResult) }}
									</span>
								</td>
								<td>{{ statusLabel(runRow.status) }}</td>
							</tr>
						</tbody>
					</table>
				</div>
				<p v-else class="evals__empty-hint">
					{{ t('hermiq', 'No runs yet — pick an agent and run this dataset.') }}
				</p>
			</section>
		</div>

		<EvalDatasetFormModal
			:show="showForm"
			:dataset="editingDataset"
			@close="showForm = false"
			@saved="onSaved" />
	</div>
</template>

<script>
import { NcButton, NcEmptyContent, NcLoadingIcon, NcNoteCard, NcSelect } from '@nextcloud/vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import BeakerIcon from 'vue-material-design-icons/BeakerOutline.vue'
import { runEval } from '../api/evals.js'
import { useAgentStore, useEvalDatasetStore, useEvalRunStore } from '../store/store.js'
import EvalDatasetFormModal from '../modals/EvalDatasetFormModal.vue'

export default {
	name: 'EvalDatasets',

	components: {
		BeakerIcon,
		EvalDatasetFormModal,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
	},

	data() {
		return {
			datasets: [],
			runs: [],
			agents: [],
			loading: true,
			error: '',
			showForm: false,
			editingDataset: null,
			selectedAgent: {},
			runningDatasetId: null,
		}
	},

	computed: {
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
		this.datasetStore = useEvalDatasetStore()
		this.datasetStore.registerObjectType('evaldataset', 'evaldataset', 'hermiq')
		this.runStore = useEvalRunStore()
		this.runStore.registerObjectType('evalrun', 'evalrun', 'hermiq')
		this.agentStore = useAgentStore()
		this.agentStore.registerObjectType('agent', 'agent', 'hermiq')
		this.load()
	},

	methods: {
		/**
		 * Load datasets, prior runs and the caller's agents.
		 *
		 * @return {Promise<void>}
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				const [datasets, runs, agents] = await Promise.all([
					this.datasetStore.fetchCollection('evaldataset'),
					this.runStore.fetchCollection('evalrun').catch(() => []),
					this.agentStore.fetchCollection('agent').catch(() => []),
				])
				this.datasets = Array.isArray(datasets) ? datasets : []
				this.runs = Array.isArray(runs) ? runs : []
				this.agents = Array.isArray(agents) ? agents : []
			} catch (e) {
				this.error = e?.response?.data?.error || e?.message || this.t('hermiq', 'Unknown error')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Case count for a dataset.
		 *
		 * @param {object} dataset The dataset.
		 * @return {number} The number of cases.
		 */
		caseCount(dataset) {
			return Array.isArray(dataset.cases) ? dataset.cases.length : 0
		},

		/**
		 * The most-recent-first EvalRuns for one dataset (max 5 shown).
		 *
		 * @param {string} datasetId The dataset UUID.
		 * @return {Array<object>} The runs.
		 */
		runsFor(datasetId) {
			return this.runs
				.filter((runRow) => runRow.datasetId === datasetId)
				.sort((a, b) => String(b.startedAt || '').localeCompare(String(a.startedAt || '')))
				.slice(0, 5)
		},

		/**
		 * Open the create-dataset modal.
		 *
		 * @return {void}
		 */
		openCreate() {
			this.editingDataset = null
			this.showForm = true
		},

		/**
		 * Open the edit-dataset modal.
		 *
		 * @param {object} dataset The dataset to edit.
		 * @return {void}
		 */
		openEdit(dataset) {
			this.editingDataset = dataset
			this.showForm = true
		},

		/**
		 * Reload after a dataset is created/edited.
		 *
		 * @return {Promise<void>}
		 */
		async onSaved() {
			await this.load()
		},

		/**
		 * Run a dataset against the agent selected for it.
		 *
		 * @param {object} dataset The dataset to run.
		 * @return {Promise<void>}
		 */
		async run(dataset) {
			const agent = this.selectedAgent[dataset.id]
			if (!agent) {
				return
			}
			this.runningDatasetId = dataset.id
			try {
				const outcome = await runEval(dataset.id, agent.value || agent)
				showSuccess(this.t('hermiq', 'Eval run complete: {rate} passed.', { rate: this.passRateLabel(outcome.passRate) }))
				await this.load()
			} catch (e) {
				showError(e?.response?.data?.error || this.t('hermiq', 'The eval run failed.'))
			} finally {
				this.runningDatasetId = null
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
		 * Badge class for a regression-gate result (never colour-only — text distinct too).
		 *
		 * @param {string} result The gate result.
		 * @return {string} The badge modifier class.
		 */
		regressionBadgeClass(result) {
			if (result === 'failed') {
				return 'evals__badge--error'
			}
			if (result === 'passed') {
				return 'evals__badge--ok'
			}
			return 'evals__badge--neutral'
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
.evals {
	padding: 16px;
}

.evals__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-bottom: 12px;
}

.evals__heading {
	margin: 0;
	font-size: 22px;
	font-weight: 600;
}

.evals__list {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.evals__dataset {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 12px;
}

.evals__dataset-head {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	flex-wrap: wrap;
}

.evals__dataset-sub {
	margin-left: 8px;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.evals__dataset-actions {
	display: flex;
	align-items: flex-end;
	gap: 8px;
}

.evals__agent-picker {
	min-width: 200px;
}

.evals__runs-table {
	width: 100%;
	margin-top: 12px;
	border-collapse: collapse;
}

.evals__runs-table th,
.evals__runs-table td {
	text-align: left;
	padding: 4px 8px;
	border-bottom: 1px solid var(--color-border);
	font-size: 13px;
}

.evals__badge {
	display: inline-block;
	padding: 1px 8px;
	border-radius: var(--border-radius-pill);
	font-size: 12px;
}

.evals__badge--ok {
	background: var(--color-success);
	color: var(--color-primary-text);
}

.evals__badge--error {
	background: var(--color-error);
	color: var(--color-primary-text);
}

.evals__badge--neutral {
	background: var(--color-background-dark);
}

.evals__empty-hint {
	margin: 8px 0 0;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}
</style>
