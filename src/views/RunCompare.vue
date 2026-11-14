<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  RunCompare — two agent runs side by side (observability-compare-two-runs).

  Opened from the Runs page with `?left=<id>&right=<id>`. The server compares only
  runs of agents the caller may see in the run list; a run it may not see reads as
  "not found", the same as a run that does not exist.

  @spec openspec/specs/run-replay-and-dry-run/spec.md#requirement-a-person-can-compare-any-two-runs-they-may-see-req-rcmp-001
-->
<template>
	<div class="hermiq-run-compare">
		<div class="hermiq-run-compare__header">
			<h2>{{ t('hermiq', 'Compare runs') }}</h2>
			<NcButton @click="back">
				{{ t('hermiq', 'Back to runs') }}
			</NcButton>
		</div>

		<div v-if="loading" class="hermiq-run-compare__loading">
			<NcLoadingIcon :size="32" />
		</div>

		<NcNoteCard
			v-else-if="error"
			type="error"
			:heading="t('hermiq', 'Could not compare these runs')">
			{{ error }}
		</NcNoteCard>

		<template v-else-if="result">
			<p class="hermiq-run-compare__summary">
				{{ summary }}
			</p>
			<NcNoteCard v-if="!result.sameAgent" type="info">
				{{ t('hermiq', 'These runs are of different agents.') }}
			</NcNoteCard>
			<p class="hermiq-run-compare__hint">
				{{ t('hermiq', 'The comparison uses the redacted summaries.') }}
			</p>

			<div class="hermiq-run-compare__columns">
				<section
					v-for="side in sides"
					:key="side.key"
					class="hermiq-run-compare__run"
					:aria-label="side.label">
					<h3>{{ side.label }}</h3>
					<dl>
						<dt>{{ t('hermiq', 'Agent') }}</dt>
						<dd>{{ side.run.agentName }}</dd>
						<dt>{{ t('hermiq', 'Started') }}</dt>
						<dd>
							{{ recorded(side.run.startedAt || side.run.created) }}
						</dd>
						<dt>{{ t('hermiq', 'Duration') }}</dt>
						<dd>{{ duration(side.run.durationMs) }}</dd>
						<dt>{{ t('hermiq', 'Status') }}</dt>
						<dd>{{ side.run.status }}</dd>
						<dt>{{ t('hermiq', 'Trigger') }}</dt>
						<dd>
							{{
								side.run.trigger === 'schedule'
									? t('hermiq', 'Schedule')
									: t('hermiq', 'Flow')
							}}
						</dd>
						<dt>{{ t('hermiq', 'Agent version') }}</dt>
						<dd>{{ recorded(side.run.agentVersion) }}</dd>
						<dt>{{ t('hermiq', 'Model') }}</dt>
						<dd>
							{{
								recorded(
									side.run.provider && side.run.model
										? `${side.run.provider} · ${side.run.model}`
										: side.run.model,
								)
							}}
						</dd>
					</dl>
				</section>
			</div>

			<table class="hermiq-run-compare__steps">
				<caption>
					{{
						t('hermiq', 'Tool steps, lined up')
					}}
				</caption>
				<thead>
					<tr>
						<th scope="col">
							{{ t('hermiq', 'Run A') }}
						</th>
						<th scope="col">
							{{ t('hermiq', 'Run B') }}
						</th>
						<th scope="col">
							{{ t('hermiq', 'Difference') }}
						</th>
					</tr>
				</thead>
				<tbody>
					<tr
						v-for="(row, index) in result.comparison.steps"
						:key="index"
						:class="{
							'hermiq-run-compare__row--differs': row.mark !== 'same',
						}">
						<td>{{ stepText(row.left) }}</td>
						<td>{{ stepText(row.right) }}</td>
						<td>{{ markLabel(row.mark) }}</td>
					</tr>
				</tbody>
			</table>
		</template>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import { compareRuns } from '../api/analytics.js'
import { summaryLine } from '../utils/runCompare.js'

export default {
	name: 'RunCompare',

	components: { NcButton, NcLoadingIcon, NcNoteCard },

	data() {
		return { loading: true, error: '', result: null }
	},

	computed: {
		/**
		 * The two runs as labelled columns.
		 *
		 * @return {Array<object>} The sides.
		 *
		 * @spec openspec/specs/run-replay-and-dry-run/spec.md#requirement-a-person-can-compare-any-two-runs-they-may-see-req-rcmp-001
		 */
		sides() {
			return [
				{ key: 'left', label: t('hermiq', 'Run A'), run: this.result.left },
				{
					key: 'right',
					label: t('hermiq', 'Run B'),
					run: this.result.right,
				},
			]
		},

		/**
		 * The one-line summary of the difference.
		 *
		 * @return {string} The summary.
		 *
		 * @spec openspec/specs/run-replay-and-dry-run/spec.md#requirement-a-person-can-compare-any-two-runs-they-may-see-req-rcmp-001
		 */
		summary() {
			return summaryLine(
				this.result.left,
				this.result.right,
				this.result.comparison,
				t,
			)
		},
	},

	/**
	 * Load the comparison named in the query.
	 *
	 * @return {Promise<void>}
	 * @spec openspec/specs/run-replay-and-dry-run/spec.md#requirement-a-person-can-compare-any-two-runs-they-may-see-req-rcmp-001
	 */
	async created() {
		const { left = '', right = '' } = this.$route?.query || {}
		try {
			this.result = await compareRuns(String(left), String(right))
		} catch (e) {
			this.error =
				e?.response?.status === 404
					? t(
							'hermiq',
							'One of these runs was not found, or you may not see it.',
						)
					: e?.response?.data?.error || e.message
		} finally {
			this.loading = false
		}
	},

	methods: {
		/**
		 * Back to the run list.
		 *
		 * @return {void}
		 * @spec openspec/specs/run-replay-and-dry-run/spec.md#requirement-a-person-can-compare-any-two-runs-they-may-see-req-rcmp-001
		 */
		back() {
			this.$router.push('/runs').catch(() => {})
		},

		/**
		 * A value, or "Not recorded for this run".
		 *
		 * @param {string|number|null|undefined} value The value.
		 * @return {string} The text.
		 * @spec openspec/specs/run-replay-and-dry-run/spec.md#requirement-a-person-can-compare-any-two-runs-they-may-see-req-rcmp-001
		 */
		recorded(value) {
			return value ? String(value) : t('hermiq', 'Not recorded for this run')
		},

		/**
		 * A duration in seconds.
		 *
		 * @param {number|null} ms The duration in milliseconds.
		 * @return {string} The text.
		 * @spec openspec/specs/run-replay-and-dry-run/spec.md#requirement-a-person-can-compare-any-two-runs-they-may-see-req-rcmp-001
		 */
		duration(ms) {
			return Number.isFinite(ms)
				? `${(ms / 1000).toFixed(1)} s`
				: t('hermiq', 'Not recorded for this run')
		},

		/**
		 * One step as "name (outcome)".
		 *
		 * @param {object|null} step The step.
		 * @return {string} The text, or a dash when the run has no step here.
		 * @spec openspec/specs/run-replay-and-dry-run/spec.md#requirement-steps-are-aligned-so-an-extra-step-shows-as-one-difference-req-rcmp-002
		 */
		stepText(step) {
			if (!step) {
				return '-'
			}
			return step.outcome ? `${step.name} (${step.outcome})` : step.name
		},

		/**
		 * The difference mark in words.
		 *
		 * @param {string} mark The mark.
		 * @return {string} The label.
		 * @spec openspec/specs/run-replay-and-dry-run/spec.md#requirement-steps-are-aligned-so-an-extra-step-shows-as-one-difference-req-rcmp-002
		 */
		markLabel(mark) {
			return (
				{
					same: t('hermiq', 'Same'),
					'only-left': t('hermiq', 'Only in run A'),
					'only-right': t('hermiq', 'Only in run B'),
					'different-outcome': t('hermiq', 'Different outcome'),
				}[mark] || mark
			)
		},
	},
}
</script>

<style scoped>
.hermiq-run-compare {
	padding: 16px;
}

.hermiq-run-compare__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
}

.hermiq-run-compare__summary {
	font-weight: bold;
	margin: 12px 0;
}

.hermiq-run-compare__hint {
	color: var(--color-text-maxcontrast);
}

.hermiq-run-compare__columns {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
	gap: 16px;
	margin: 16px 0;
}

.hermiq-run-compare__run dl {
	display: grid;
	grid-template-columns: max-content 1fr;
	gap: 4px 12px;
}

.hermiq-run-compare__run dt {
	color: var(--color-text-maxcontrast);
}

.hermiq-run-compare__steps {
	width: 100%;
	border-collapse: collapse;
}

.hermiq-run-compare__steps th,
.hermiq-run-compare__steps td {
	text-align: start;
	padding: 6px 8px;
	border-bottom: 1px solid var(--color-border);
}

.hermiq-run-compare__row--differs {
	background: var(--color-warning-hover, var(--color-background-hover));
}
</style>
