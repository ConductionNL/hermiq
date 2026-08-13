<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  SkillEvalEvidence — the SkillDetail page's L5 eval-evidence card
  (skill-evals): the skill's `levelEvidence.l5` (pass rate, baseline delta
  with its attribution-mode label, last validated), a pass-rate trend across
  the paired EvalRuns of datasets whose `skillRefs` reference this skill, and
  an owner-guarded "Run paired eval" action (linked-dataset picker + agent
  picker + the mode-dependent cost note → the trigger endpoint,
  404-never-403 server-side).

  Honesty rules (ADR-060): with no l5 evidence the card shows an EMPTY STATE
  pointing at linking a dataset — never a fabricated or placeholder metric;
  the trend renders only real executed runs; a `mode: joint` delta is
  labelled as the joint contribution of the linked set so it never reads as a
  per-skill marginal; the delta is sign+text, never color-only. The card
  writes NO maturity field — evidence arrives only via a paired run's
  completion. Self-fetches the skill id from `$route.params.id`.

  @spec openspec/specs/skill-maturity/spec.md#requirement-skilldetail-surfaces-eval-evidence-and-a-run-paired-eval-action
-->
<template>
	<div class="skill-eval-evidence">
		<NcNoteCard
			v-if="error"
			type="error"
			:heading="t('hermiq', 'Eval evidence error')">
			{{ error }}
		</NcNoteCard>

		<NcLoadingIcon
			v-if="loading"
			:size="24"
			class="skill-eval-evidence__loading" />

		<template v-else>
			<template v-if="hasEvidence">
				<dl class="skill-eval-evidence__facts">
					<div class="skill-eval-evidence__fact">
						<dt>{{ t('hermiq', 'Pass rate') }}</dt>
						<dd>{{ passRateLabel(l5.passRate) }}</dd>
					</div>
					<div class="skill-eval-evidence__fact">
						<dt>{{ deltaTermLabel }}</dt>
						<dd>{{ deltaLabel(l5.baselineDelta) }}</dd>
					</div>
					<div class="skill-eval-evidence__fact">
						<dt>{{ t('hermiq', 'Last validated') }}</dt>
						<dd>{{ formatDate(l5.lastValidated) }}</dd>
					</div>
				</dl>
				<p v-if="l5.mode === 'joint'" class="skill-eval-evidence__hint">
					{{
						t(
							'hermiq',
							"Joint attribution: this delta is the joint contribution of all skills linked to the dataset, not this skill's individual marginal.",
						)
					}}
				</p>

				<div v-if="trend.length > 0" class="skill-eval-evidence__trend">
					<h4 class="skill-eval-evidence__subtitle">
						{{ t('hermiq', 'Pass-rate trend (paired runs)') }}
					</h4>
					<ol class="skill-eval-evidence__trend-list">
						<li v-for="point in trend" :key="point.id">
							{{ formatDate(point.startedAt) }} —
							{{ passRateLabel(point.passRateWith) }}
						</li>
					</ol>
				</div>
			</template>

			<p v-else class="skill-eval-evidence__empty">
				{{ emptyStateText }}
			</p>

			<div class="skill-eval-evidence__run">
				<h4 class="skill-eval-evidence__subtitle">
					{{ t('hermiq', 'Run paired eval') }}
				</h4>
				<p
					v-if="datasetOptions.length === 0"
					class="skill-eval-evidence__hint">
					{{
						t(
							'hermiq',
							'No dataset links this skill yet. Link it from an evaluation dataset first.',
						)
					}}
				</p>
				<template v-else>
					<div class="skill-eval-evidence__run-form">
						<NcSelect
							v-model="selectedDataset"
							:input-label="t('hermiq', 'Dataset')"
							:options="datasetOptions"
							label="label"
							track-by="value"
							:placeholder="t('hermiq', 'Select a linked dataset')" />
						<NcSelect
							v-model="selectedAgent"
							:input-label="t('hermiq', 'Agent')"
							:options="agentOptions"
							label="label"
							track-by="value"
							:placeholder="t('hermiq', 'Select an agent')" />
						<NcButton
							type="primary"
							:disabled="running || !selectedDataset || !selectedAgent"
							@click="runPaired">
							<template v-if="running" #icon>
								<NcLoadingIcon :size="20" />
							</template>
							{{ t('hermiq', 'Run paired eval') }}
						</NcButton>
					</div>
					<p class="skill-eval-evidence__hint">
						{{ costNote }}
					</p>
				</template>
			</div>
		</template>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon, NcNoteCard, NcSelect } from '@nextcloud/vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { runEval } from '../api/evals.js'
import {
	useAgentStore,
	useEvalDatasetStore,
	useEvalRunStore,
	useSkillStore,
} from '../store/store.js'

export default {
	name: 'SkillEvalEvidence',

	components: {
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
	},

	data() {
		return {
			skill: null,
			datasets: [],
			agents: [],
			runs: [],
			selectedDataset: null,
			selectedAgent: null,
			loading: true,
			running: false,
			error: '',
		}
	},

	computed: {
		/**
		 * This skill's uuid from the route param.
		 *
		 * @spec exclude framework route-param accessor; behaviour owned by the widget's spec-tagged load/run methods
		 * @return {string} The skill uuid.
		 */
		skillId() {
			return this.$route.params.id
		},

		/**
		 * The stored l5 evidence map ({} when absent).
		 *
		 * @spec openspec/specs/skill-maturity/spec.md#requirement-skilldetail-surfaces-eval-evidence-and-a-run-paired-eval-action
		 * @return {object} The levelEvidence.l5 map.
		 */
		l5() {
			const evidence = this.skill?.levelEvidence
			return (evidence && typeof evidence.l5 === 'object' && evidence.l5) || {}
		},

		/**
		 * Whether real l5 evidence exists (a metric is only ever rendered from a
		 * completed paired run's write-back — ADR-060).
		 *
		 * @return {boolean} True when l5 carries a validated measurement.
		 */
		hasEvidence() {
			return typeof this.l5.passRate === 'number' && !!this.l5.lastValidated
		},

		/**
		 * The delta term, honest about attribution: a joint delta never reads as
		 * a per-skill marginal.
		 *
		 * @spec openspec/specs/skill-maturity/spec.md#requirement-skilldetail-surfaces-eval-evidence-and-a-run-paired-eval-action
		 * @return {string} The label.
		 */
		deltaTermLabel() {
			if (this.l5.mode === 'joint') {
				return this.t('hermiq', 'Baseline delta (joint, linked set)')
			}
			if (this.l5.mode === 'per-skill') {
				return this.t('hermiq', 'Baseline delta (per-skill marginal)')
			}
			return this.t('hermiq', 'Baseline delta')
		},

		/**
		 * The honest empty state: what is missing and how to obtain evidence.
		 *
		 * @spec openspec/specs/skill-maturity/spec.md#requirement-skilldetail-surfaces-eval-evidence-and-a-run-paired-eval-action
		 * @return {string} The empty-state text.
		 */
		emptyStateText() {
			if (this.datasetOptions.length === 0) {
				return this.t(
					'hermiq',
					'No eval evidence yet. Link this skill to an evaluation dataset and run a paired eval to measure its contribution.',
				)
			}
			return this.t(
				'hermiq',
				"No eval evidence yet. Run a paired eval below to measure this skill's contribution.",
			)
		},

		/**
		 * Datasets whose skillRefs reference THIS skill, as picker options.
		 *
		 * @spec openspec/specs/skill-maturity/spec.md#requirement-skilldetail-surfaces-eval-evidence-and-a-run-paired-eval-action
		 * @return {Array<object>} The { label, value } options.
		 */
		datasetOptions() {
			return this.datasets
				.filter(
					(dataset) =>
						Array.isArray(dataset.skillRefs)
						&& dataset.skillRefs.includes(this.skillId),
				)
				.map((dataset) => ({
					label: dataset.name || dataset.uuid || dataset.id,
					value: dataset.uuid || dataset.id,
				}))
		},

		/**
		 * The caller's agents as picker options.
		 *
		 * @spec openspec/specs/skill-maturity/spec.md#requirement-skilldetail-surfaces-eval-evidence-and-a-run-paired-eval-action
		 * @return {Array<object>} The { label, value } options.
		 */
		agentOptions() {
			return this.agents.map((agent) => ({
				label: agent.name || agent.uuid || agent.id,
				value: agent.uuid || agent.id,
			}))
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
		 * The linked-skill count of the selected dataset (for the (N+1)x note).
		 *
		 * @spec openspec/specs/agent-evals/spec.md#requirement-every-half-of-a-paired-run-counts-toward-the-same-budgets-and-gates
		 * @return {number} The count (at least 1).
		 */
		selectedDatasetSkillCount() {
			const dataset = this.datasets.find(
				(entry) => (entry.uuid || entry.id) === this.selectedDataset?.value,
			)
			const refs = Array.isArray(dataset?.skillRefs) ? dataset.skillRefs : []
			return Math.max(refs.length, 1)
		},

		/**
		 * The mode-dependent cost note (~2x joint, (N+1)x per-skill, per the
		 * selected agent's evalBaselineMode).
		 *
		 * @spec openspec/specs/agent-evals/spec.md#requirement-every-half-of-a-paired-run-counts-toward-the-same-budgets-and-gates
		 * @return {string} The translated cost note.
		 */
		costNote() {
			if (this.selectedAgentMode === 'per-skill') {
				return this.t(
					'hermiq',
					'Per-skill baseline: every case runs {times} times (one without-half per linked skill) — about {times}x the token cost of a normal run, counted against the same budgets.',
					{ times: this.selectedDatasetSkillCount + 1 },
				)
			}
			return this.t(
				'hermiq',
				'Joint baseline: every case runs twice (all linked skills detached together) — about 2x the token cost of a normal run, counted against the same budgets. Link one skill per dataset for the cleanest attribution.',
			)
		},

		/**
		 * The pass-rate trend across this skill's paired runs (oldest first, real
		 * executed runs only — ADR-060).
		 *
		 * @spec openspec/specs/skill-maturity/spec.md#requirement-skilldetail-surfaces-eval-evidence-and-a-run-paired-eval-action
		 * @return {Array<object>} { id, startedAt, passRateWith } points.
		 */
		trend() {
			return this.runs
				.filter(
					(runRow) =>
						runRow.baselineMode === true
						&& runRow.status === 'completed',
				)
				.filter((runRow) =>
					(runRow.skillResults || []).some(
						(entry) => entry.skillId === this.skillId,
					),
				)
				.sort((a, b) =>
					String(a.startedAt || '').localeCompare(
						String(b.startedAt || ''),
					),
				)
				.slice(-10)
				.map((runRow) => {
					const entry = (runRow.skillResults || []).find(
						(item) => item.skillId === this.skillId,
					)
					return {
						id: runRow.id || runRow.uuid,
						startedAt: runRow.startedAt,
						passRateWith: entry?.passRateWith,
					}
				})
		},
	},

	/**
	 * Wire the four stores and kick off the initial load.
	 *
	 * @spec exclude framework lifecycle hook; only registers stores and delegates to the spec-tagged load()
	 */
	created() {
		this.skillStore = useSkillStore()
		this.skillStore.registerObjectType('agentskill', 'agentskill', 'hermiq')
		this.datasetStore = useEvalDatasetStore()
		this.datasetStore.registerObjectType('evaldataset', 'evaldataset', 'hermiq')
		this.agentStore = useAgentStore()
		this.agentStore.registerObjectType('agent', 'agent', 'hermiq')
		this.runStore = useEvalRunStore()
		this.runStore.registerObjectType('evalrun', 'evalrun', 'hermiq')
		this.load()
	},

	methods: {
		/**
		 * Load the skill (for its l5 evidence), the datasets (for skillRefs
		 * matches), the agents (picker + mode), and the paired-run history.
		 *
		 * @spec openspec/specs/skill-maturity/spec.md#requirement-skilldetail-surfaces-eval-evidence-and-a-run-paired-eval-action
		 * @return {Promise<void>}
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				const [skill, datasets, agents, runs] = await Promise.all([
					this.skillStore.fetchObject('agentskill', this.skillId),
					this.datasetStore.fetchCollection('evaldataset').catch(() => []),
					this.agentStore.fetchCollection('agent').catch(() => []),
					this.runStore.fetchCollection('evalrun').catch(() => []),
				])
				this.skill = skill || null
				this.datasets = Array.isArray(datasets) ? datasets : []
				this.agents = Array.isArray(agents) ? agents : []
				this.runs = Array.isArray(runs) ? runs : []
			} catch (e) {
				this.error =
					e?.response?.data?.error
					|| e?.message
					|| this.t('hermiq', 'Could not load the skill.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Trigger the owner-guarded paired run endpoint, then refresh so the card
		 * reflects the freshly written l5 evidence.
		 *
		 * @spec openspec/specs/agent-evals/spec.md#requirement-the-paired-trigger-owner-guard-covers-dataset-agent-and-every-linked-skill
		 * @return {Promise<void>}
		 */
		async runPaired() {
			if (!this.selectedDataset || !this.selectedAgent) {
				return
			}
			this.running = true
			try {
				const outcome = await runEval(
					this.selectedDataset.value,
					this.selectedAgent.value,
					{ baseline: true },
				)
				showSuccess(
					this.t('hermiq', 'Paired eval complete: {rate} passed.', {
						rate: this.passRateLabel(outcome.passRate),
					}),
				)
				await this.load()
			} catch (e) {
				showError(
					e?.response?.data?.error
						|| this.t('hermiq', 'The paired eval failed.'),
				)
			} finally {
				this.running = false
			}
		},

		/**
		 * Human pass-rate percentage (the stored value is a 0..1 fraction).
		 *
		 * @spec exclude presentational percentage formatting for the eval-evidence card; behaviour owned by the widget's spec-tagged load/run methods
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
		 * A signed delta label (sign + value, never color-only).
		 *
		 * @spec openspec/specs/skill-maturity/spec.md#requirement-skilldetail-surfaces-eval-evidence-and-a-run-paired-eval-action
		 * @param {number} delta The baseline delta (-1..1).
		 * @return {string} The label, e.g. "+30 pp".
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
		 * Format an ISO timestamp for display.
		 *
		 * @spec exclude presentational date formatting for the eval-evidence card; behaviour owned by the widget's spec-tagged load/run methods
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
.skill-eval-evidence {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 4px;
	overflow-y: auto;
	height: 100%;
}

.skill-eval-evidence__loading {
	margin: 24px auto;
}

.skill-eval-evidence__facts {
	display: flex;
	flex-wrap: wrap;
	gap: 24px;
	margin: 0;
}

.skill-eval-evidence__fact dt {
	color: var(--color-text-maxcontrast);
	font-size: 12px;
}

.skill-eval-evidence__fact dd {
	margin: 0;
	font-size: 18px;
	font-weight: 600;
}

.skill-eval-evidence__subtitle {
	margin: 4px 0;
	font-size: 14px;
	font-weight: 600;
}

.skill-eval-evidence__trend-list {
	margin: 0;
	padding-inline-start: 20px;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.skill-eval-evidence__empty {
	color: var(--color-text-maxcontrast);
	margin: 4px 0;
}

.skill-eval-evidence__run {
	border-top: 1px solid var(--color-border);
	padding-top: 8px;
}

.skill-eval-evidence__run-form {
	display: flex;
	align-items: flex-end;
	gap: 8px;
	flex-wrap: wrap;
}

.skill-eval-evidence__run-form .v-select {
	min-width: 220px;
}

.skill-eval-evidence__hint {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	margin: 4px 0 0;
}
</style>
