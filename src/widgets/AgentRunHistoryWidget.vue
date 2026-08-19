<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  AgentRunHistoryWidget — this agent's schedule run history as a
  type:"detail" custom widget (manifest-driven-pages).

  `object-list`'s static columns[]/rowRoute shape has no per-row expand-in-place,
  no per-row conditional action set (re-run only on `dead_letter`), and no trace
  fetch-and-cache — genuinely bespoke interaction (design.md Decision 3).
  Self-fetches the agent id from `$route.params.id` and its own copy of the
  attached schedule (to resolve the schedule id runs/trace/replay are scoped
  to) — a schedule-collection fetch also performed independently by the
  sibling AgentRunOperationsWidget, since the manifest grid has no
  cross-widget data-sharing channel (design.md).

  Subscribes to the shared `cn:page:refresh` event-bus signal so a Run now /
  schedule save on the sibling AgentRunOperationsWidget refreshes this list
  without a bespoke sibling channel. "Replay" renders its own inline preview
  here (rather than in the sibling's dry-run preview panel) — each widget
  renders the result of its OWN triggered action.

  @spec openspec/specs/manifest-driven-pages/spec.md#requirement-a-run-history-custom-widget-must-show-run-history-with-per-row-trace-expand-re-run-and-replay
-->
<template>
	<div class="agent-run-history-widget">
		<NcNoteCard v-if="runsError" type="warning">
			{{ t('hermiq', 'Could not load run history.') }}
		</NcNoteCard>

		<p v-else-if="!schedule" class="agent-run-history-widget__empty-hint">
			{{ t('hermiq', 'Attach a schedule to start recording run history.') }}
		</p>
		<p
			v-else-if="runs.length === 0"
			class="agent-run-history-widget__empty-hint">
			{{ t('hermiq', 'No runs yet.') }}
		</p>
		<!--
			Run history grows with every run, and a row expands inline to show its
			trace — so it scrolls inside the widget rather than growing past its
			grid cell and over the widgets below (ADR-062).
		-->
		<div v-else class="agent-run-history-widget__table-wrap">
			<table class="agent-run-history-widget__table">
				<thead>
					<tr>
						<th scope="col">
							{{ t('hermiq', 'Status') }}
						</th>
						<th scope="col">
							{{ t('hermiq', 'Started') }}
						</th>
						<th scope="col">
							{{ t('hermiq', 'Duration') }}
						</th>
						<th scope="col">
							{{ t('hermiq', 'Attempt') }}
						</th>
						<th scope="col">
							{{ t('hermiq', 'Agent version') }}
						</th>
						<th scope="col">
							<span class="hidden-visually">{{
								t('hermiq', 'Actions')
							}}</span>
						</th>
					</tr>
				</thead>
				<tbody>
					<template v-for="run in runs" :key="run.id">
						<tr>
							<td>
								<span
									class="agent-run-history-widget__badge"
									:class="[statusBadgeClass(run.status)]">
									{{ statusLabel(run.status) }}
								</span>
							</td>
							<td>{{ formatDate(run.startedAt || run.created) }}</td>
							<td>{{ durationLabel(run.durationMs) }}</td>
							<td>{{ run.attempt || '—' }}</td>
							<td>{{ shortVersionLabel(run.agentVersion) }}</td>
							<td class="agent-run-history-widget__row-actions">
								<NcButton
									type="tertiary"
									:aria-label="
										t('hermiq', 'View this run\'s step timeline')
									"
									@click="toggleRunTrace(run)">
									{{
										expandedRunId === run.id
											? t('hermiq', 'Hide details')
											: t('hermiq', 'Details')
									}}
								</NcButton>
								<NcButton
									v-if="run.status === 'dead_letter'"
									type="tertiary"
									:disabled="running"
									:aria-label="
										t(
											'hermiq',
											'Re-run this dead-lettered schedule',
										)
									"
									@click="reRun">
									{{ t('hermiq', 'Re-run') }}
								</NcButton>
								<NcButton
									type="tertiary"
									:disabled="replayingRunId === run.id"
									:aria-label="
										t(
											'hermiq',
											'Replay this run as a dry run and compare',
										)
									"
									@click="replay(run)">
									<template #icon>
										<NcLoadingIcon
											v-if="replayingRunId === run.id"
											:size="20" />
										<Replay v-else :size="20" />
									</template>
									{{ t('hermiq', 'Replay') }}
								</NcButton>
							</td>
						</tr>
						<tr v-if="expandedRunId === run.id">
							<td
								colspan="6"
								class="agent-run-history-widget__trace-cell">
								<NcLoadingIcon v-if="traceLoading" :size="24" />
								<NcNoteCard v-else-if="traceError" type="warning">
									{{
										t(
											'hermiq',
											"Could not load this run's trace.",
										)
									}}
								</NcNoteCard>
								<div
									v-else-if="runTraces[run.id]"
									class="agent-run-history-widget__trace">
									<p
										v-if="
											runTraces[run.id].toolStepsAvailable
											=== false
										"
										class="agent-run-history-widget__trace-hint">
										{{
											t(
												'hermiq',
												"Tool-level detail is unavailable for this run's execution path.",
											)
										}}
									</p>
									<p
										v-if="
											!runTraces[run.id].steps
											|| runTraces[run.id].steps.length === 0
										"
										class="agent-run-history-widget__empty-hint">
										{{
											t(
												'hermiq',
												'No step detail recorded for this run.',
											)
										}}
									</p>
									<ol
										v-else
										class="agent-run-history-widget__trace-steps">
										<li
											v-for="step in runTraces[run.id].steps"
											:key="step.seq"
											class="agent-run-history-widget__trace-step">
											<span
												class="agent-run-history-widget__trace-step-type"
												>{{ stepTypeLabel(step.type) }}</span
											>
											<span
												class="agent-run-history-widget__trace-step-name"
												>{{ step.name }}</span
											>
											<span
												class="agent-run-history-widget__trace-step-duration"
												>{{
													/**
													 * @spec openspec/specs/manifest-driven-pages/spec.md#requirement-a-run-history-custom-widget-must-show-run-history-with-per-row-trace-expand-re-run-and-replay
													 */
													stepDurationLabel(
														step.durationMs,
													)
												}}</span
											>
											<span
												class="agent-run-history-widget__badge"
												:class="[
													step.outcome === 'error'
														? 'agent-run-history-widget__badge--error'
														: 'agent-run-history-widget__badge--ok',
												]">
												{{ step.outcome }}
											</span>
										</li>
									</ol>
									<NcButton
										type="tertiary"
										@click="downloadTrace(run)">
										{{ t('hermiq', 'Download trace (JSON)') }}
									</NcButton>
								</div>
							</td>
						</tr>
						<tr v-if="replayResultRunId === run.id">
							<td
								colspan="6"
								class="agent-run-history-widget__trace-cell">
								<div class="agent-run-history-widget__replay-head">
									<strong>{{
										t('hermiq', 'Replay preview')
									}}</strong>
									<NcButton type="tertiary" @click="dismissReplay">
										{{ t('hermiq', 'Dismiss') }}
									</NcButton>
								</div>
								<NcNoteCard type="info">
									{{
										t(
											'hermiq',
											'Nothing was changed — side-effecting tools were reported, not executed.',
										)
									}}
								</NcNoteCard>
								<p
									v-if="replayResult && replayResult.diff"
									class="agent-run-history-widget__empty-hint">
									{{
										replayResult.diff.changed
											? t(
													'hermiq',
													'The replay produced a DIFFERENT outcome than the original run.',
												)
											: t(
													'hermiq',
													'The replay produced the same outcome as the original run.',
												)
									}}
								</p>
								<ol
									v-if="replaySteps.length > 0"
									class="agent-run-history-widget__trace-steps">
									<li
										v-for="step in replaySteps"
										:key="step.seq"
										class="agent-run-history-widget__trace-step">
										<span
											class="agent-run-history-widget__trace-step-type"
											>{{ stepTypeLabel(step.type) }}</span
										>
										<span
											class="agent-run-history-widget__trace-step-name"
											>{{ step.name }}</span
										>
										<span
											class="agent-run-history-widget__badge"
											:class="[
												step.outcome === 'would-have-called'
													? 'agent-run-history-widget__badge--warn'
													: step.outcome === 'error'
														? 'agent-run-history-widget__badge--error'
														: 'agent-run-history-widget__badge--ok',
											]">
											{{
												step.outcome === 'would-have-called'
													? t(
															'hermiq',
															'would have called',
														)
													: step.outcome
											}}
										</span>
									</li>
								</ol>
								<p
									v-else
									class="agent-run-history-widget__empty-hint">
									{{
										t(
											'hermiq',
											'No step detail recorded for this preview.',
										)
									}}
								</p>
							</td>
						</tr>
					</template>
				</tbody>
			</table>
		</div>
	</div>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { emit, subscribe, unsubscribe } from '@nextcloud/event-bus'
import { NcButton, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import Replay from 'vue-material-design-icons/Replay.vue'
import { getRunTrace, listRuns, replayRun, runScheduleNow } from '../api/agents.js'
import { useScheduleStore } from '../store/store.js'

export default {
	name: 'AgentRunHistoryWidget',

	components: {
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		Replay,
	},

	data() {
		return {
			schedule: null,
			runs: [],
			runsError: false,
			running: false,
			expandedRunId: null,
			runTraces: {},
			traceLoading: false,
			traceError: false,
			replayingRunId: null,
			replayResultRunId: null,
			replayResult: null,
		}
	},

	computed: {
		/**
		 * This agent's uuid from the route param.
		 *
		 * @return {string} The agent uuid.
		 * @spec openspec/specs/manifest-driven-pages/spec.md#requirement-a-run-history-custom-widget-must-show-run-history-with-per-row-trace-expand-re-run-and-replay
		 */
		agentId() {
			return this.$route.params.id
		},

		/**
		 * The steps of the last replay preview.
		 *
		 * @return {Array<object>} The preview's ordered steps.
		 * @spec openspec/specs/manifest-driven-pages/spec.md#requirement-a-run-history-custom-widget-must-show-run-history-with-per-row-trace-expand-re-run-and-replay
		 */
		replaySteps() {
			if (!this.replayResult) {
				return []
			}
			const source = this.replayResult.replay || this.replayResult
			return Array.isArray(source.steps) ? source.steps : []
		},
	},

	/**
	 * @spec openspec/specs/manifest-driven-pages/spec.md#requirement-a-run-history-custom-widget-must-show-run-history-with-per-row-trace-expand-re-run-and-replay
	 */
	created() {
		this.scheduleStore = useScheduleStore()
		this.scheduleStore.registerObjectType('schedule', 'schedule', 'hermiq')
		this.load()
		this.onPageRefresh = () => this.load()
		subscribe('cn:page:refresh', this.onPageRefresh)
	},

	beforeUnmount() {
		unsubscribe('cn:page:refresh', this.onPageRefresh)
	},

	methods: {
		/**
		 * Dismiss the replay preview.
		 *
		 * Extracted from an inline
		 * `@click="replayResultRunId = null; replayResult = null"`. Vue's template
		 * compiler only treats a handler as raw STATEMENTS when the expression
		 * contains a `;`, and prettier's `semi: false` strips it — leaving two
		 * newline-separated statements the compiler then tries to parse as one
		 * expression and rejects. No behaviour change.
		 *
		 * @return {void}
		 *
		 * @spec exclude formatting-only extraction of an existing inline handler — no behaviour change
		 */
		dismissReplay() {
			this.replayResultRunId = null
			this.replayResult = null
		},

		/**
		 * Load the attached schedule and its run history.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/manifest-driven-pages/spec.md#requirement-a-run-history-custom-widget-must-show-run-history-with-per-row-trace-expand-re-run-and-replay
		 */
		async load() {
			try {
				const schedules =
					await this.scheduleStore.fetchCollection('schedule')
				this.schedule =
					(Array.isArray(schedules) ? schedules : []).find(
						(candidate) => candidate.agentId === this.agentId,
					) || null
			} catch (e) {
				this.schedule = null
			}
			await this.loadRuns()
		},

		/**
		 * Load the run history for the attached schedule (non-blocking on error).
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/manifest-driven-pages/spec.md#requirement-a-run-history-custom-widget-must-show-run-history-with-per-row-trace-expand-re-run-and-replay
		 */
		async loadRuns() {
			this.runsError = false
			this.expandedRunId = null
			this.runTraces = {}
			if (!this.schedule || !this.schedule.id) {
				this.runs = []
				return
			}
			try {
				this.runs = await listRuns(this.schedule.id)
			} catch (e) {
				this.runsError = true
				this.runs = []
			}
		},

		/**
		 * Expand/collapse a run's step-timeline row, fetching its trace on first
		 * expand and caching it by run id thereafter.
		 *
		 * @param {object} run The run record.
		 * @return {Promise<void>}
		 * @spec openspec/specs/manifest-driven-pages/spec.md#requirement-a-run-history-custom-widget-must-show-run-history-with-per-row-trace-expand-re-run-and-replay
		 */
		async toggleRunTrace(run) {
			if (this.expandedRunId === run.id) {
				this.expandedRunId = null
				return
			}
			this.expandedRunId = run.id
			if (this.runTraces[run.id] || !this.schedule || !this.schedule.id) {
				return
			}
			this.traceLoading = true
			this.traceError = false
			try {
				const trace = await getRunTrace(this.schedule.id, run.id)
				this.runTraces = { ...this.runTraces, [run.id]: trace }
			} catch (e) {
				this.traceError = true
			} finally {
				this.traceLoading = false
			}
		},

		/**
		 * Save the already-fetched, already-redacted trace for one run as a
		 * local JSON file.
		 *
		 * @param {object} run The run record whose trace is currently expanded.
		 * @return {void}
		 * @spec openspec/specs/manifest-driven-pages/spec.md#requirement-a-run-history-custom-widget-must-show-run-history-with-per-row-trace-expand-re-run-and-replay
		 */
		downloadTrace(run) {
			const trace = this.runTraces[run.id]
			if (!trace) {
				return
			}
			const blob = new Blob([JSON.stringify(trace, null, 2)], {
				type: 'application/json',
			})
			const url = URL.createObjectURL(blob)
			const link = document.createElement('a')
			link.href = url
			link.download = `run-trace-${run.id}.json`
			document.body.appendChild(link)
			link.click()
			document.body.removeChild(link)
			URL.revokeObjectURL(url)
		},

		/**
		 * Re-run a dead-lettered occurrence: a fresh, fully governed dispatch
		 * reusing the SAME runScheduleNow() endpoint as the sibling
		 * AgentRunOperationsWidget's page-level "Run now" (no new endpoint).
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/manifest-driven-pages/spec.md#requirement-a-run-history-custom-widget-must-show-run-history-with-per-row-trace-expand-re-run-and-replay
		 */
		async reRun() {
			if (!this.schedule || !this.schedule.id) {
				return
			}
			this.running = true
			try {
				const result = await runScheduleNow(this.schedule.id)
				if (result && result.status === 'error') {
					showError(
						result.error
							|| this.t('hermiq', 'The agent run reported an error.'),
					)
				} else {
					showSuccess(this.t('hermiq', 'Agent run started.'))
				}
			} catch (e) {
				showError(
					e?.response?.data?.message
						|| e?.response?.data?.error
						|| this.t('hermiq', 'The agent run failed.'),
				)
			} finally {
				this.running = false
				await this.loadRuns()
				emit('cn:page:refresh', {})
			}
		},

		/**
		 * Replay a past run's exact recorded prompt AS a dry run and diff the
		 * outcome against what actually happened. Never re-executes a
		 * side-effecting tool. Renders its own inline preview on this row.
		 *
		 * @param {object} run The run row to replay.
		 * @return {Promise<void>}
		 * @spec openspec/specs/manifest-driven-pages/spec.md#requirement-a-run-history-custom-widget-must-show-run-history-with-per-row-trace-expand-re-run-and-replay
		 */
		async replay(run) {
			if (!this.schedule || !this.schedule.id || !run || !run.id) {
				return
			}
			this.replayingRunId = run.id
			this.replayResult = null
			this.replayResultRunId = null
			try {
				this.replayResult = await replayRun(this.schedule.id, run.id)
				this.replayResultRunId = run.id
				showSuccess(
					this.t('hermiq', 'Replay complete — nothing was changed.'),
				)
			} catch (e) {
				showError(
					e?.response?.data?.error
						|| e?.message
						|| this.t('hermiq', 'The replay failed.'),
				)
			} finally {
				this.replayingRunId = null
			}
		},

		/**
		 * Human label for a trace step's type.
		 *
		 * @param {string} type The step type (gate_wait|context|history|llm|tool|delivery).
		 * @return {string} The localised label.
		 * @spec openspec/specs/manifest-driven-pages/spec.md#requirement-a-run-history-custom-widget-must-show-run-history-with-per-row-trace-expand-re-run-and-replay
		 */
		stepTypeLabel(type) {
			const labels = {
				gate_wait: this.t('hermiq', 'Awaiting approval'),
				context: this.t('hermiq', 'Context'),
				history: this.t('hermiq', 'History'),
				llm: this.t('hermiq', 'LLM'),
				tool: this.t('hermiq', 'Tool'),
				delivery: this.t('hermiq', 'Delivery'),
			}
			return labels[type] || type || '—'
		},

		/**
		 * Human label for a trace step's duration in milliseconds.
		 *
		 * @param {number} ms The duration in milliseconds.
		 * @return {string} The duration label.
		 * @spec openspec/specs/manifest-driven-pages/spec.md#requirement-a-run-history-custom-widget-must-show-run-history-with-per-row-trace-expand-re-run-and-replay
		 */
		stepDurationLabel(ms) {
			if (ms === null || ms === undefined) {
				return '—'
			}
			const value = Number(ms)
			if (Number.isNaN(value)) {
				return '—'
			}
			if (value < 1000) {
				return `${Math.round(value)}ms`
			}
			return `${(value / 1000).toFixed(1)}s`
		},

		/**
		 * A short, stable display form of a pinned agent-version id.
		 *
		 * @param {string} versionId The pinned agent version id.
		 * @return {string} The short label, or a dash when absent.
		 * @spec openspec/specs/manifest-driven-pages/spec.md#requirement-a-run-history-custom-widget-must-show-run-history-with-per-row-trace-expand-re-run-and-replay
		 */
		shortVersionLabel(versionId) {
			if (!versionId) {
				return '—'
			}
			return String(versionId).split('-')[0]
		},

		/**
		 * Format an ISO date for display, or a dash when absent/invalid.
		 *
		 * @param {string} value The ISO timestamp.
		 * @return {string} The localised date, or '—'.
		 * @spec openspec/specs/manifest-driven-pages/spec.md#requirement-a-run-history-custom-widget-must-show-run-history-with-per-row-trace-expand-re-run-and-replay
		 */
		formatDate(value) {
			if (!value) {
				return '—'
			}
			const date = new Date(value)
			return Number.isNaN(date.getTime()) ? '—' : date.toLocaleString()
		},

		/**
		 * Human label for a run duration in milliseconds.
		 *
		 * @param {number} ms The duration in milliseconds.
		 * @return {string} The duration label.
		 * @spec openspec/specs/manifest-driven-pages/spec.md#requirement-a-run-history-custom-widget-must-show-run-history-with-per-row-trace-expand-re-run-and-replay
		 */
		durationLabel(ms) {
			if (ms === null || ms === undefined) {
				return '—'
			}
			const seconds = Math.round(Number(ms) / 1000)
			return `${seconds}s`
		},

		/**
		 * Badge CSS modifier class for a run-history status.
		 *
		 * @param {string} status The run's status.
		 * @return {string} The badge modifier class.
		 * @spec openspec/specs/manifest-driven-pages/spec.md#requirement-a-run-history-custom-widget-must-show-run-history-with-per-row-trace-expand-re-run-and-replay
		 */
		statusBadgeClass(status) {
			if (
				status === 'error'
				|| status === 'dead_letter'
				|| status === 'paused_circuit_breaker'
			) {
				return 'agent-run-history-widget__badge--error'
			}
			if (
				status === 'retry_pending'
				|| status === 'awaiting_approval'
				|| status === 'skipped_killswitch'
				|| status === 'skipped_budget'
			) {
				return 'agent-run-history-widget__badge--warning'
			}
			return 'agent-run-history-widget__badge--ok'
		},

		/**
		 * Human-readable label for a run-history status.
		 *
		 * @param {string} status The run's status.
		 * @return {string} The localised status label.
		 * @spec openspec/specs/manifest-driven-pages/spec.md#requirement-a-run-history-custom-widget-must-show-run-history-with-per-row-trace-expand-re-run-and-replay
		 */
		statusLabel(status) {
			const labels = {
				ok: this.t('hermiq', 'OK'),
				error: this.t('hermiq', 'Error'),
				running: this.t('hermiq', 'Running'),
				skipped_killswitch: this.t('hermiq', 'Halted (kill-switch)'),
				skipped_budget: this.t('hermiq', 'Halted (budget)'),
				awaiting_approval: this.t('hermiq', 'Awaiting approval'),
				retry_pending: this.t('hermiq', 'Retrying…'),
				dead_letter: this.t('hermiq', 'Dead-letter'),
				paused_circuit_breaker: this.t('hermiq', 'Paused (circuit breaker)'),
			}
			return labels[status] || status || '—'
		},
	},
}
</script>

<style scoped>
.agent-run-history-widget__empty-hint {
	color: var(--color-text-maxcontrast);
	margin: 4px 0;
}

.agent-run-history-widget__table-wrap {
	max-height: 300px;
	overflow-y: auto;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
}

.agent-run-history-widget__table {
	width: 100%;
	border-collapse: collapse;
}

/* Sticky header — the column meaning must survive scrolling the rows. */
.agent-run-history-widget__table thead th {
	position: sticky;
	inset-block-start: 0;
	z-index: 1;
	background-color: var(--color-main-background);
}

.agent-run-history-widget__table th,
.agent-run-history-widget__table td {
	text-align: left;
	padding: 8px 12px;
	border-bottom: 1px solid var(--color-border);
}

.agent-run-history-widget__row-actions {
	display: flex;
	gap: 8px;
	justify-content: flex-end;
	flex-wrap: wrap;
}

.agent-run-history-widget__trace-cell {
	background-color: var(--color-background-hover);
}

.agent-run-history-widget__trace {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 8px 0;
}

.agent-run-history-widget__trace-hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.agent-run-history-widget__trace-steps {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.agent-run-history-widget__trace-step {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 4px 0;
	border-bottom: 1px solid var(--color-border);
}

.agent-run-history-widget__trace-step-type {
	min-width: 90px;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

.agent-run-history-widget__trace-step-name {
	flex: 1 1 auto;
}

.agent-run-history-widget__trace-step-duration {
	color: var(--color-text-maxcontrast);
	font-variant-numeric: tabular-nums;
}

.agent-run-history-widget__replay-head {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	margin-bottom: 8px;
}

.agent-run-history-widget__badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: var(--border-radius-pill, 12px);
	font-size: 13px;
}

.agent-run-history-widget__badge--ok {
	color: var(--color-success-text, var(--color-success));
	font-weight: 600;
}

.agent-run-history-widget__badge--error {
	color: var(--color-error-text, var(--color-error));
	font-weight: 600;
}

.agent-run-history-widget__badge--warning,
.agent-run-history-widget__badge--warn {
	color: var(--color-warning-text, var(--color-warning));
	font-weight: 600;
}

.hidden-visually {
	position: absolute;
	width: 1px;
	height: 1px;
	overflow: hidden;
	clip: rect(0 0 0 0);
}
</style>
