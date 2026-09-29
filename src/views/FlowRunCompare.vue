<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  FlowRunCompare — two runs of one flow, node by node (observability-compare-two-runs).

  Flows and their runs are read from OpenRegister in the browser, with the person's
  own session, so OpenRegister's organisation scoping decides what can be read.
  Hermiq neither copies nor stores flow runs. `?flow=<uuid>` preselects a flow.

  The design placed this on the flow's own detail page; that page is the shared
  `flow` page type from @conduction/nextcloud-vue, which hermiq cannot extend, so the
  comparison is its own page, reached from the Runs page.

  @spec openspec/changes/observability-compare-two-runs/specs/run-replay-and-dry-run/spec.md#requirement-a-person-can-compare-two-runs-of-a-flow-node-by-node-req-rcmp-003
-->
<template>
	<div class="hermiq-flow-compare">
		<h2>{{ t('hermiq', 'Compare flow runs') }}</h2>

		<NcSelect
			v-model="flow"
			class="hermiq-flow-compare__flow"
			:inputLabel="t('hermiq', 'Flow')"
			:options="flowOptions"
			:placeholder="t('hermiq', 'Choose a flow')"
			label="label"
			trackBy="value" />

		<NcNoteCard
			v-if="error"
			type="error"
			:heading="t('hermiq', 'Could not load the flow runs')">
			{{ error }}
		</NcNoteCard>

		<NcNoteCard v-if="selectionRefused" type="warning">
			{{ t('hermiq', 'Choose two runs to compare.') }}
		</NcNoteCard>

		<div v-if="loading" class="hermiq-flow-compare__loading">
			<NcLoadingIcon :size="32" />
		</div>

		<template v-else-if="flow">
			<p v-if="runs.length === 0">
				{{ t('hermiq', 'This flow has no runs yet.') }}
			</p>
			<table v-else class="hermiq-flow-compare__table">
				<caption>
					{{
						t('hermiq', 'Tick two runs to compare them.')
					}}
				</caption>
				<thead>
					<tr>
						<th scope="col">
							<span class="hidden-visually">{{
								t('hermiq', 'Compare')
							}}</span>
						</th>
						<th scope="col">
							{{ t('hermiq', 'Started') }}
						</th>
						<th scope="col">
							{{ t('hermiq', 'Status') }}
						</th>
						<th scope="col">
							{{ t('hermiq', 'Flow version') }}
						</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="run in runs" :key="run.uuid">
						<td>
							<input
								type="checkbox"
								:checked="selected.includes(run.uuid)"
								:aria-label="t('hermiq', 'Compare this run')"
								@change="toggle(run.uuid)" />
						</td>
						<td>{{ run.created || '-' }}</td>
						<td>{{ run.status }}</td>
						<td>{{ run.flowVersion ?? '-' }}</td>
					</tr>
				</tbody>
			</table>

			<NcButton
				v-if="selected.length === 2"
				variant="primary"
				@click="compare">
				{{ t('hermiq', 'Compare') }}
			</NcButton>
		</template>

		<section
			v-if="result"
			class="hermiq-flow-compare__result"
			:aria-label="t('hermiq', 'Comparison')">
			<NcNoteCard v-if="result.versions" type="info">
				{{
					t(
						'hermiq',
						'These runs used different versions of the flow ({left} and {right}).',
						{ left: result.versions[0], right: result.versions[1] },
					)
				}}
			</NcNoteCard>
			<NcNoteCard v-if="result.unreadable.length" type="warning">
				{{ t('hermiq', 'This flow run could not be read.') }}
			</NcNoteCard>
			<table v-else class="hermiq-flow-compare__table">
				<caption>
					{{
						t('hermiq', 'Nodes, lined up')
					}}
				</caption>
				<thead>
					<tr>
						<th scope="col">
							{{ t('hermiq', 'Node') }}
						</th>
						<th scope="col">
							{{ t('hermiq', 'Run A') }}
						</th>
						<th scope="col">
							{{ t('hermiq', 'Run B') }}
						</th>
					</tr>
				</thead>
				<tbody>
					<tr
						v-for="row in result.rows"
						:key="row.node"
						:class="{
							'hermiq-flow-compare__row--differs': row.differs,
						}">
						<td>{{ row.node }}</td>
						<td>{{ nodeText(row.left) }}</td>
						<td>{{ nodeText(row.right) }}</td>
					</tr>
				</tbody>
			</table>
		</section>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon, NcNoteCard, NcSelect } from '@nextcloud/vue'
import { getFlowRun, listFlowRuns, listFlows } from '../api/flowRuns.js'
import { compareFlowRuns, toggleSelection } from '../utils/runCompare.js'

export default {
	name: 'FlowRunCompare',

	components: { NcButton, NcLoadingIcon, NcNoteCard, NcSelect },

	data() {
		return {
			flows: [],
			flow: null,
			runs: [],
			selected: [],
			selectionRefused: false,
			loading: false,
			error: '',
			result: null,
		}
	},

	computed: {
		/**
		 * The flows as select options.
		 *
		 * @return {Array<object>} The options.
		 * @spec openspec/changes/observability-compare-two-runs/specs/run-replay-and-dry-run/spec.md#requirement-a-person-can-compare-two-runs-of-a-flow-node-by-node-req-rcmp-003
		 */
		flowOptions() {
			return this.flows.map((flow) => ({
				label: flow.name || flow.uuid,
				value: flow.uuid,
			}))
		},
	},

	watch: {
		/**
		 * Load the chosen flow's runs.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/observability-compare-two-runs/specs/run-replay-and-dry-run/spec.md#requirement-a-person-can-compare-two-runs-of-a-flow-node-by-node-req-rcmp-003
		 */
		async flow() {
			this.selected = []
			this.result = null
			this.runs = []
			if (!this.flow) {
				return
			}
			await this.guard(async () => {
				this.runs = await listFlowRuns(this.flow.value)
			})
		},
	},

	/**
	 * Load the flows, and preselect `?flow=`.
	 *
	 * @return {Promise<void>}
	 * @spec openspec/changes/observability-compare-two-runs/specs/run-replay-and-dry-run/spec.md#requirement-a-person-can-compare-two-runs-of-a-flow-node-by-node-req-rcmp-003
	 */
	async created() {
		await this.guard(async () => {
			this.flows = await listFlows()
		})
		const wanted = String(this.$route?.query?.flow || '')
		this.flow =
			this.flowOptions.find((option) => option.value === wanted) || null
	},

	methods: {
		/**
		 * Run a read with the loading state and the error note.
		 *
		 * @param {function(): Promise<void>} read The read.
		 * @return {Promise<void>}
		 * @spec openspec/changes/observability-compare-two-runs/specs/run-replay-and-dry-run/spec.md#requirement-a-person-can-compare-two-runs-of-a-flow-node-by-node-req-rcmp-003
		 */
		async guard(read) {
			this.loading = true
			this.error = ''
			try {
				await read()
			} catch (e) {
				this.error = e?.response?.data?.error || e.message
			} finally {
				this.loading = false
			}
		},

		/**
		 * Tick or untick a run; a third tick is refused.
		 *
		 * @param {string} uuid The run.
		 * @return {void}
		 * @spec openspec/changes/observability-compare-two-runs/specs/run-replay-and-dry-run/spec.md#requirement-a-person-can-compare-two-runs-of-a-flow-node-by-node-req-rcmp-003
		 */
		toggle(uuid) {
			const next = toggleSelection(this.selected, uuid)
			this.selected = next.selected
			this.selectionRefused = next.refused
		},

		/**
		 * Read both runs and line their nodes up.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/observability-compare-two-runs/specs/run-replay-and-dry-run/spec.md#requirement-a-person-can-compare-two-runs-of-a-flow-node-by-node-req-rcmp-003
		 */
		async compare() {
			await this.guard(async () => {
				const [left, right] = await Promise.all(
					this.selected.map((uuid) => getFlowRun(uuid)),
				)
				this.result = compareFlowRuns(left, right)
			})
		},

		/**
		 * A node entry as "status, duration".
		 *
		 * @param {object|null} entry The node entry.
		 * @return {string} The text, or "Not reached" when the run never got there.
		 * @spec openspec/changes/observability-compare-two-runs/specs/run-replay-and-dry-run/spec.md#requirement-a-person-can-compare-two-runs-of-a-flow-node-by-node-req-rcmp-003
		 */
		nodeText(entry) {
			if (!entry) {
				return t('hermiq', 'Not reached')
			}
			return entry.durationMs === null
				? entry.status
				: `${entry.status}, ${entry.durationMs} ms`
		},
	},
}
</script>

<style scoped>
.hermiq-flow-compare {
	padding: 16px;
}

.hermiq-flow-compare__flow {
	max-width: 400px;
	margin-bottom: 12px;
}

.hermiq-flow-compare__table {
	width: 100%;
	border-collapse: collapse;
	margin: 12px 0;
}

.hermiq-flow-compare__table th,
.hermiq-flow-compare__table td {
	text-align: start;
	padding: 6px 8px;
	border-bottom: 1px solid var(--color-border);
}

.hermiq-flow-compare__row--differs {
	background: var(--color-warning-hover, var(--color-background-hover));
}
</style>
