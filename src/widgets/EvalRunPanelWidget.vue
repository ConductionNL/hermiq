<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  EvalRunPanelWidget — one eval dataset's agent-picker + Run action + run
  history as a `type:"custom"` content widget on the `EvalDatasetDetail` page
  (manifest-driven-pages).

  `EvalDatasets.vue` today renders one card per dataset with an embedded runs
  sub-table and an inline agent-picker + Run button per card — a nested, not
  flat, shape that `type:"index"` cannot express directly (design.md
  Decision 8). The outer list becomes `type:"index"` (name/description
  columns only — no per-row run controls); this widget hosts the actual
  per-dataset run management on the new detail page. "Run" has no OR-object
  equivalent (the one bespoke `src/api/evals.js` action; every other eval
  path is object CRUD), so it stays a custom widget rather than a declarative
  `object-op`. Self-fetches the dataset id from `$route.params.id`.

  skill-evals adds the PAIRED BASELINE toggle — enabled only when the dataset
  has linked skills (`skillRefs`), with the mode-dependent cost note (~2x in
  joint mode, (N+1)x in per-skill mode, per the SELECTED agent's
  evalBaselineMode) — and the paired-run rendering: with vs without pass
  rates side by side, per-skill baseline delta (sign + text, never
  color-only), and expandable per-case results for both halves so failing
  cases are distinguishable in each.

  @spec openspec/specs/manifest-driven-pages/spec.md#requirement-evaldatasets-renders-as-an-index-type-list-page-with-per-dataset-run-management-on-a-new-evaldatasetdetail-page
  @spec openspec/specs/agent-evals/spec.md#requirement-the-evaldataset-detail-surface-manages-skill-links-and-paired-runs
-->
<template>
	<div class="eval-run-panel-widget">
		<NcNoteCard
			v-if="error"
			type="error"
			:heading="t('hermiq', 'Evaluations error')">
			{{ error }}
		</NcNoteCard>

		<div class="eval-run-panel-widget__controls">
			<NcSelect
				v-model="selectedAgent"
				class="eval-run-panel-widget__agent-picker"
				:inputLabel="t('hermiq', 'Agent')"
				:options="agentOptions"
				label="label"
				trackBy="value"
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

		<div class="eval-run-panel-widget__baseline">
			<NcCheckboxRadioSwitch
				v-model="baseline"
				type="switch"
				:disabled="!hasLinkedSkills">
				{{ t('hermiq', 'Paired baseline (with vs without skills)') }}
			</NcCheckboxRadioSwitch>
			<p v-if="!hasLinkedSkills" class="eval-run-panel-widget__hint">
				{{
					t(
						'hermiq',
						'Link a skill to this dataset to enable paired baseline runs.',
					)
				}}
			</p>
			<p v-else-if="baseline" class="eval-run-panel-widget__hint">
				{{ costNote }}
			</p>
		</div>

		<div v-if="runs.length > 0" class="eval-run-panel-widget__runs">
			<table class="eval-run-panel-widget__table">
				<thead>
					<tr>
						<th scope="col">{{ t('hermiq', 'When') }}</th>
						<th scope="col">{{ t('hermiq', 'Pass rate') }}</th>
						<th scope="col">{{ t('hermiq', 'Baseline') }}</th>
						<th scope="col">{{ t('hermiq', 'Regression gate') }}</th>
						<th scope="col">{{ t('hermiq', 'Status') }}</th>
						<th scope="col">
							<span class="hidden-visually">{{
								t('hermiq', 'Details')
							}}</span>
						</th>
					</tr>
				</thead>
				<tbody>
					<template v-for="runRow in runs" :key="runRow.id || runRow.uuid">
						<tr>
							<td>{{ formatDate(runRow.startedAt) }}</td>
							<td>{{ passRateLabel(runRow.passRate) }}</td>
							<td>
								<span v-if="runRow.baselineMode">
									{{ baselineCellLabel(runRow) }}
								</span>
								<span v-else>—</span>
							</td>
							<td>
								<span
									class="eval-run-panel-widget__badge"
									:class="[
										regressionBadgeClass(
											runRow.regressionGateResult,
										),
									]">
									{{
										regressionLabel(runRow.regressionGateResult)
									}}
								</span>
							</td>
							<td>{{ statusLabel(runRow.status) }}</td>
							<td>
								<NcButton
									v-if="runRow.baselineMode"
									type="tertiary"
									:aria-label="
										t('hermiq', 'Toggle paired run details')
									"
									@click="
										toggleExpanded(runRow.id || runRow.uuid)
									">
									{{
										expandedRunId === (runRow.id || runRow.uuid)
											? t('hermiq', 'Hide details')
											: t('hermiq', 'Details')
									}}
								</NcButton>
							</td>
						</tr>
						<tr v-if="expandedRunId === (runRow.id || runRow.uuid)">
							<td colspan="6" class="eval-run-panel-widget__details">
								<h4 class="eval-run-panel-widget__details-title">
									{{ pairedModeLabel(runRow.attributionMode) }}
								</h4>
								<table
									class="eval-run-panel-widget__table eval-run-panel-widget__skill-table">
									<thead>
										<tr>
											<th scope="col">
												{{ t('hermiq', 'Skill') }}
											</th>
											<th scope="col">
												{{ t('hermiq', 'With skill') }}
											</th>
											<th scope="col">
												{{ t('hermiq', 'Without skill') }}
											</th>
											<th scope="col">
												{{ t('hermiq', 'Baseline delta') }}
											</th>
										</tr>
									</thead>
									<tbody>
										<tr
											v-for="entry in runRow.skillResults
											|| []"
											:key="entry.skillId">
											<td>{{ skillName(entry.skillId) }}</td>
											<td>
												{{
													passRateLabel(entry.passRateWith)
												}}
											</td>
											<td>
												{{
													passRateLabel(
														entry.passRateWithout,
													)
												}}
											</td>
											<td>
												{{ deltaLabel(entry.baselineDelta) }}
											</td>
										</tr>
									</tbody>
								</table>
								<div class="eval-run-panel-widget__halves">
									<div class="eval-run-panel-widget__half">
										<h5>
											{{
												t('hermiq', 'With skills — per case')
											}}
										</h5>
										<ol class="eval-run-panel-widget__case-list">
											<li
												v-for="caseResult in runRow.results
												|| []"
												:key="'w' + caseResult.caseIndex">
												{{ caseOutcomeLabel(caseResult) }}
											</li>
										</ol>
									</div>
									<div
										v-for="half in withoutHalves(runRow)"
										:key="half.key"
										class="eval-run-panel-widget__half">
										<h5>{{ half.title }}</h5>
										<ol class="eval-run-panel-widget__case-list">
											<li
												v-for="caseResult in half.results"
												:key="
													half.key + caseResult.caseIndex
												">
												{{ caseOutcomeLabel(caseResult) }}
											</li>
										</ol>
									</div>
								</div>
							</td>
						</tr>
					</template>
				</tbody>
			</table>
		</div>
		<p v-else class="eval-run-panel-widget__empty-hint">
			{{ t('hermiq', 'No runs yet — pick an agent and run this dataset.') }}
		</p>
	</div>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcLoadingIcon,
	NcNoteCard,
	NcSelect,
} from '@nextcloud/vue'
import { runEval } from '../api/evals.js'
import { listSkills } from '../api/skills.js'
import {
	useAgentStore,
	useEvalDatasetStore,
	useEvalRunStore,
} from '../store/store.js'

export default {
	name: 'EvalRunPanelWidget',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
	},

	data() {
		return {
			agents: [],
			runs: [],
			skills: [],
			dataset: null,
			selectedAgent: null,
			baseline: false,
			running: false,
			expandedRunId: null,
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
				label: agent.name || agent.uuid || agent.id,
				value: agent.uuid || agent.id,
			}))
		},

		/**
		 * The dataset's linked skill uuids (skillRefs).
		 *
		 * @spec openspec/specs/agent-evals/spec.md#requirement-the-evaldataset-detail-surface-manages-skill-links-and-paired-runs
		 * @return {Array<string>} The linked skill uuids.
		 */
		linkedSkillIds() {
			const refs = this.dataset?.skillRefs
			return Array.isArray(refs)
				? refs.filter((ref) => typeof ref === 'string' && ref !== '')
				: []
		},

		/**
		 * Whether the dataset has linked skills (the paired toggle's enable gate).
		 *
		 * @return {boolean} True when skillRefs is non-empty.
		 */
		hasLinkedSkills() {
			return this.linkedSkillIds.length > 0
		},

		/**
		 * The selected agent's evalBaselineMode ('joint' unless explicitly 'per-skill').
		 *
		 * @spec openspec/specs/agent-evals/spec.md#requirement-every-half-of-a-paired-run-counts-toward-the-same-budgets-and-gates
		 * @return {string} joint|per-skill.
		 */
		selectedAgentMode() {
			const agent = this.agents.find(
				(entry) => (entry.uuid || entry.id) === this.selectedAgent?.value,
			)
			return agent?.evalBaselineMode === 'per-skill' ? 'per-skill' : 'joint'
		},

		/**
		 * The mode-dependent cost note for the paired toggle (~2x joint, (N+1)x
		 * per-skill for N linked skills, per the selected agent's evalBaselineMode).
		 *
		 * @spec openspec/specs/agent-evals/spec.md#requirement-every-half-of-a-paired-run-counts-toward-the-same-budgets-and-gates
		 * @return {string} The translated cost note.
		 */
		costNote() {
			if (this.selectedAgentMode === 'per-skill') {
				const count = this.linkedSkillIds.length
				return this.t(
					'hermiq',
					'Per-skill baseline: every case runs {times} times (one without-half per linked skill) — about {times}x the token cost of a normal run, counted against the same budgets.',
					{ times: count + 1 },
				)
			}
			return this.t(
				'hermiq',
				'Joint baseline: every case runs twice (all linked skills detached together) — about 2x the token cost of a normal run, counted against the same budgets. Link one skill per dataset for the cleanest attribution.',
			)
		},
	},

	/**
	 * Register the object stores and kick off the initial load.
	 *
	 * @spec exclude framework lifecycle hook — only wires stores and delegates to load()
	 */
	created() {
		this.agentStore = useAgentStore()
		this.agentStore.registerObjectType('agent', 'agent', 'hermiq')
		this.runStore = useEvalRunStore()
		this.runStore.registerObjectType('evalrun', 'evalrun', 'hermiq')
		this.datasetStore = useEvalDatasetStore()
		this.datasetStore.registerObjectType('evaldataset', 'evaldataset', 'hermiq')
		this.load()
	},

	methods: {
		/**
		 * Load the caller's agents, this dataset (for its skillRefs), the skill
		 * catalogue (for names), and this dataset's prior runs.
		 *
		 * @spec openspec/specs/agent-evals/spec.md#requirement-the-evaldataset-detail-surface-manages-skill-links-and-paired-runs
		 * @return {Promise<void>}
		 */
		async load() {
			this.error = ''
			try {
				const [agents, runs, dataset, skills] = await Promise.all([
					this.agentStore.fetchCollection('agent').catch(() => []),
					this.runStore.fetchCollection('evalrun').catch(() => []),
					this.datasetStore
						.fetchObject('evaldataset', this.datasetId)
						.catch(() => null),
					listSkills().catch(() => []),
				])
				this.agents = Array.isArray(agents) ? agents : []
				this.dataset = dataset || null
				this.skills = Array.isArray(skills) ? skills : []
				this.runs = (Array.isArray(runs) ? runs : [])
					.filter((runRow) => runRow.datasetId === this.datasetId)
					.sort((a, b) =>
						String(b.startedAt || '').localeCompare(
							String(a.startedAt || ''),
						),
					)
					.slice(0, 10)
			} catch (e) {
				this.error =
					e?.response?.data?.error
					|| e?.message
					|| this.t('hermiq', 'Unknown error')
			}
		},

		/**
		 * Run this dataset against the selected agent (paired when toggled).
		 *
		 * @spec openspec/specs/agent-evals/spec.md#requirement-the-evaldataset-detail-surface-manages-skill-links-and-paired-runs
		 * @return {Promise<void>}
		 */
		async run() {
			if (!this.selectedAgent) {
				return
			}
			this.running = true
			try {
				const outcome = await runEval(
					this.datasetId,
					this.selectedAgent.value,
					{
						baseline: this.baseline && this.hasLinkedSkills,
					},
				)
				showSuccess(
					this.t('hermiq', 'Eval run complete: {rate} passed.', {
						rate: this.passRateLabel(outcome.passRate),
					}),
				)
				await this.load()
			} catch (e) {
				showError(
					e?.response?.data?.error
						|| this.t('hermiq', 'The eval run failed.'),
				)
			} finally {
				this.running = false
			}
		},

		/**
		 * Toggle the expanded paired-run details row.
		 *
		 * @spec openspec/specs/agent-evals/spec.md#requirement-the-evaldataset-detail-surface-manages-skill-links-and-paired-runs
		 * @param {string} runId The run's object id.
		 * @return {void}
		 */
		toggleExpanded(runId) {
			this.expandedRunId = this.expandedRunId === runId ? null : runId
		},

		/**
		 * The without-half blocks to render for a paired run: the shared joint
		 * without-half, or (per-skill mode) one block per skillResults entry.
		 *
		 * @spec openspec/specs/agent-evals/spec.md#requirement-the-evaldataset-detail-surface-manages-skill-links-and-paired-runs
		 * @param {object} runRow The paired EvalRun.
		 * @return {Array<object>} { key, title, results } blocks.
		 */
		withoutHalves(runRow) {
			if (runRow.attributionMode === 'per-skill') {
				return (runRow.skillResults || []).map((entry) => ({
					key: 'wo-' + entry.skillId,
					title: this.t('hermiq', 'Without {skill} — per case', {
						skill: this.skillName(entry.skillId),
					}),
					results: entry.baselineResults || [],
				}))
			}
			return [
				{
					key: 'wo-joint',
					title: this.t('hermiq', 'Without skills — per case'),
					results: runRow.baselineResults || [],
				},
			]
		},

		/**
		 * Resolve a skill uuid to its catalogue name (falls back to the uuid).
		 *
		 * @spec exclude pure display lookup — maps a uuid to a name with uuid fallback, no behaviour of its own
		 * @param {string} skillId The skill uuid.
		 * @return {string} The display name.
		 */
		skillName(skillId) {
			const match = this.skills.find(
				(skill) => (skill.uuid || skill.id) === skillId,
			)
			return (match && match.name) || skillId
		},

		/**
		 * The baseline column label for a paired run row (joint pass rate, or the
		 * per-skill marker when there is no single without-half).
		 *
		 * @spec openspec/specs/agent-evals/spec.md#requirement-the-evaldataset-detail-surface-manages-skill-links-and-paired-runs
		 * @param {object} runRow The paired EvalRun.
		 * @return {string} The label.
		 */
		baselineCellLabel(runRow) {
			if (runRow.attributionMode === 'per-skill') {
				return this.t('hermiq', 'per-skill')
			}
			return this.passRateLabel(runRow.baselinePassRate)
		},

		/**
		 * The paired-details heading for an attribution mode.
		 *
		 * @spec exclude presentational heading text for the paired-details block, no logic beyond label choice
		 * @param {string} mode joint|per-skill.
		 * @return {string} The heading.
		 */
		pairedModeLabel(mode) {
			if (mode === 'per-skill') {
				return this.t('hermiq', 'Paired baseline — per-skill marginals')
			}
			return this.t(
				'hermiq',
				'Paired baseline — joint contribution of the linked set',
			)
		},

		/**
		 * One case's outcome line — pass/fail as TEXT (never color-only).
		 *
		 * @spec openspec/specs/agent-evals/spec.md#requirement-ui-surfaces-per-case-results-and-pass-rate-trend
		 * @param {object} caseResult The per-case result.
		 * @return {string} The line.
		 */
		caseOutcomeLabel(caseResult) {
			const outcome = caseResult.passed
				? this.t('hermiq', 'passed')
				: this.t('hermiq', 'failed')
			const prompt = String(caseResult.prompt || '')
			const short = prompt.length > 60 ? prompt.slice(0, 60) + '…' : prompt
			return `${outcome} — ${short}`
		},

		/**
		 * A signed delta label (sign + value, never color-only).
		 *
		 * @spec openspec/specs/agent-evals/spec.md#requirement-the-evaldataset-detail-surface-manages-skill-links-and-paired-runs
		 * @param {number} delta The baseline delta (-1..1).
		 * @return {string} The label, e.g. "+30 pp" / "−10 pp" / "±0 pp".
		 */
		deltaLabel(delta) {
			if (typeof delta !== 'number') {
				return '—'
			}
			const points = Math.round(delta * 100)
			if (points > 0) {
				return `+${points} pp`
			}
			if (points < 0) {
				return `−${Math.abs(points)} pp`
			}
			return '±0 pp'
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
		 * @spec exclude pure date-formatting display helper, no domain behaviour
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
	margin-bottom: 8px;
}

.eval-run-panel-widget__agent-picker {
	min-width: 240px;
}

.eval-run-panel-widget__baseline {
	margin-bottom: 16px;
}

.eval-run-panel-widget__table {
	width: 100%;
	border-collapse: collapse;
}

.eval-run-panel-widget__table th,
.eval-run-panel-widget__table td {
	text-align: start;
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

.eval-run-panel-widget__details {
	background: var(--color-background-hover);
}

.eval-run-panel-widget__details-title {
	margin: 8px 0 4px;
	font-size: 14px;
	font-weight: 600;
}

.eval-run-panel-widget__skill-table {
	margin-bottom: 8px;
}

.eval-run-panel-widget__halves {
	display: flex;
	flex-wrap: wrap;
	gap: 16px;
	margin-bottom: 8px;
}

.eval-run-panel-widget__half {
	flex: 1 1 260px;
	min-width: 240px;
}

.eval-run-panel-widget__half h5 {
	margin: 4px 0;
	font-weight: 600;
}

.eval-run-panel-widget__case-list {
	margin: 0;
	padding-inline-start: 20px;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.eval-run-panel-widget__hint,
.eval-run-panel-widget__empty-hint {
	margin: 4px 0 0;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}
</style>
