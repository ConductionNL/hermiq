<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  Runs — the cross-agent run list.

  🔴 WHY THIS PAGE EXISTS. Hermiq's headline promise is that an agent runs on a
  schedule, and until now the failures that promise produces had nowhere to be read.
  The dashboard showed a success rate and a runs-by-agent chart; the agent detail page
  showed one agent's history. Neither could answer the question an operator actually
  asks at nine in the morning: what ran last night, and what went wrong.

  It also makes flow-triggered runs visible for the first time. `agent-run` audit
  entries hang on the object that triggered the flow, which routinely lives in another
  register, so no schedule-scoped list could ever match one. The dashboard KPIs counted
  them (same service) while every list denied they existed.

  A plain nav page, NOT a dashboard (dashboard-antipattern gate). The `?schedule=`
  query narrows the list to one schedule, which is where run-history and Talk delivery
  links now point: both used to build `/apps/hermiq/schedules/<uuid>`, a route the
  manifest never declared, so they answered 200 with the app shell and dropped the
  reader on the dashboard.

  Every NcSelect carries an inputLabel (ADR-004, WCAG 2.1 AA).

  @spec openspec/specs/run-analytics/spec.md#requirement-a-cross-agent-run-list-on-the-same-tenant-boundary-as-the-metrics
-->
<template>
	<div class="hermiq-runs">
		<div class="hermiq-runs__header">
			<h2 class="hermiq-runs__heading">
				{{ t('hermiq', 'Runs') }}
			</h2>

			<div class="hermiq-runs__filters">
				<NcSelect
					v-model="agentFilter"
					class="hermiq-runs__filter"
					:inputLabel="t('hermiq', 'Agent')"
					:options="agentOptions"
					:placeholder="t('hermiq', 'Every agent')"
					label="label"
					trackBy="value" />
				<NcSelect
					v-model="statusFilter"
					class="hermiq-runs__filter"
					:inputLabel="t('hermiq', 'Status')"
					:options="statusOptions"
					:placeholder="t('hermiq', 'Every status')"
					label="label"
					trackBy="value" />
				<NcButton :disabled="loading" @click="reload">
					{{ t('hermiq', 'Refresh') }}
				</NcButton>
			</div>
		</div>

		<NcNoteCard
			v-if="scheduleFilter"
			type="info"
			:heading="t('hermiq', 'Showing one schedule')">
			{{
				t(
					'hermiq',
					'You followed a link to a single schedule. Clear the filter to see every run.',
				)
			}}
			<NcButton class="hermiq-runs__clear" @click="clearScheduleFilter">
				{{ t('hermiq', 'Show every run') }}
			</NcButton>
		</NcNoteCard>

		<NcNoteCard
			v-if="error"
			type="error"
			:heading="t('hermiq', 'Could not load runs')">
			{{ error }}
		</NcNoteCard>

		<div v-if="loading" class="hermiq-runs__loading">
			<NcLoadingIcon :size="32" />
		</div>

		<NcEmptyContent
			v-else-if="visibleRuns.length === 0"
			:name="t('hermiq', 'No runs yet')"
			:description="
				t(
					'hermiq',
					'Runs appear here once an agent has run, on a schedule or from a flow.',
				)
			">
			<template #icon>
				<HistoryIcon :size="20" />
			</template>
		</NcEmptyContent>

		<template v-else>
			<table class="hermiq-runs__table">
				<thead>
					<tr>
						<th scope="col">{{ t('hermiq', 'When') }}</th>
						<th scope="col">{{ t('hermiq', 'Agent') }}</th>
						<th scope="col">{{ t('hermiq', 'Status') }}</th>
						<th scope="col">{{ t('hermiq', 'Trigger') }}</th>
						<th scope="col">{{ t('hermiq', 'Duration') }}</th>
						<th scope="col">{{ t('hermiq', 'Summary') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="run in visibleRuns" :key="run.id">
						<td>{{ formatWhen(run.created) }}</td>
						<td>
							<router-link
								v-if="run.agentId"
								:to="`/agents/${run.agentId}`">
								{{ run.agentName }}
							</router-link>
							<span v-else>{{ run.agentName }}</span>
						</td>
						<td>
							<span
								class="hermiq-runs__status"
								:class="`hermiq-runs__status--${statusTone(run.status)}`">
								{{ run.status }}
							</span>
						</td>
						<td>{{ triggerLabel(run.trigger) }}</td>
						<td>{{ formatDuration(run.durationMs) }}</td>
						<td class="hermiq-runs__summary">
							{{ run.summary || '—' }}
						</td>
					</tr>
				</tbody>
			</table>

			<div class="hermiq-runs__pager">
				<NcButton :disabled="offset === 0 || loading" @click="previousPage">
					{{ t('hermiq', 'Previous') }}
				</NcButton>
				<span class="hermiq-runs__range">{{ rangeLabel }}</span>
				<NcButton :disabled="!hasNextPage || loading" @click="nextPage">
					{{ t('hermiq', 'Next') }}
				</NcButton>
			</div>
		</template>
	</div>
</template>

<script>
import {
	NcButton,
	NcEmptyContent,
	NcLoadingIcon,
	NcNoteCard,
	NcSelect,
} from '@nextcloud/vue'
import HistoryIcon from 'vue-material-design-icons/History.vue'
import { listRuns } from '../api/analytics.js'
import { useAgentStore } from '../store/store.js'

/** Page size. The server clamps anything above 200. */
const PAGE_SIZE = 50

export default {
	name: 'RunList',

	components: {
		HistoryIcon,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
	},

	data() {
		return {
			runs: [],
			total: 0,
			offset: 0,
			loading: true,
			error: '',
			agents: [],
			agentFilter: null,
			statusFilter: null,
			// Set from `?schedule=` when a run-history or Talk delivery link is
			// followed. Filtered client-side: the endpoint is agent-scoped by
			// design (it must also cover flow runs, which have no schedule), so
			// narrowing to one schedule is a view concern, not a query one.
			scheduleFilter: '',
		}
	},

	computed: {
		/**
		 * The agents as NcSelect options.
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
		 * The statuses present in the loaded page, offered as filters.
		 *
		 * Derived from the data rather than hard-coded: the run writers record
		 * their own status strings, and a hard-coded list silently omits any
		 * status added later.
		 *
		 * @return {Array<object>} The { label, value } options.
		 */
		statusOptions() {
			const seen = [...new Set(this.runs.map((run) => run.status))]
			return seen.filter(Boolean).map((status) => ({
				label: status,
				value: status,
			}))
		},

		/**
		 * The rows to render, after the client-side schedule narrowing.
		 *
		 * @return {Array<object>} The visible runs.
		 */
		visibleRuns() {
			if (!this.scheduleFilter) {
				return this.runs
			}
			return this.runs.filter((run) => run.objectId === this.scheduleFilter)
		},

		/**
		 * Whether a further page exists on the server.
		 *
		 * @return {boolean} True when more rows follow this page.
		 */
		hasNextPage() {
			return this.offset + PAGE_SIZE < this.total
		},

		/**
		 * "1 to 50 of 340", so the pager says what it is showing.
		 *
		 * @return {string} The range label.
		 */
		rangeLabel() {
			if (this.total === 0) {
				return ''
			}
			const first = this.offset + 1
			const last = Math.min(this.offset + this.runs.length, this.total)
			return t('hermiq', '{first} to {last} of {total}', {
				first,
				last,
				total: this.total,
			})
		},
	},

	watch: {
		agentFilter() {
			this.offset = 0
			this.load()
		},

		statusFilter() {
			this.offset = 0
			this.load()
		},
	},

	created() {
		this.agentStore = useAgentStore()
		this.agentStore.registerObjectType('agent', 'agent', 'hermiq')
		this.scheduleFilter = String(this.$route?.query?.schedule || '')
		this.loadAgents()
		this.load()
	},

	methods: {
		/**
		 * Load one page of runs.
		 *
		 * @return {Promise<void>}
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				const payload = await listRuns({
					agentId: this.agentFilter?.value || '',
					status: this.statusFilter?.value || '',
					limit: PAGE_SIZE,
					offset: this.offset,
				})
				this.runs = payload.results || []
				this.total = payload.total || 0
			} catch (e) {
				this.error = e?.response?.data?.error || e.message
				this.runs = []
				this.total = 0
			} finally {
				this.loading = false
			}
		},

		/**
		 * Load the agent list for the filter.
		 *
		 * A failure here leaves the filter empty and is not surfaced: the run list
		 * is the page, and it renders without the filter.
		 *
		 * @return {Promise<void>}
		 */
		async loadAgents() {
			try {
				this.agents = await this.agentStore.fetchCollection('agent')
			} catch {
				this.agents = []
			}
		},

		/**
		 * Reload the current page.
		 *
		 * @return {void}
		 */
		reload() {
			this.load()
		},

		/**
		 * Drop the schedule narrowing and show every run.
		 *
		 * @return {void}
		 */
		clearScheduleFilter() {
			this.scheduleFilter = ''
			this.$router.replace({ query: {} }).catch(() => {})
		},

		/**
		 * Go to the previous page.
		 *
		 * @return {void}
		 */
		previousPage() {
			this.offset = Math.max(0, this.offset - PAGE_SIZE)
			this.load()
		},

		/**
		 * Go to the next page.
		 *
		 * @return {void}
		 */
		nextPage() {
			this.offset += PAGE_SIZE
			this.load()
		},

		/**
		 * Format a run's timestamp for the list.
		 *
		 * @param {string|null} iso The ISO timestamp.
		 * @return {string} The formatted time, or a dash.
		 */
		formatWhen(iso) {
			if (!iso) {
				return '—'
			}
			const date = new Date(iso)
			if (Number.isNaN(date.getTime())) {
				return '—'
			}
			return date.toLocaleString()
		},

		/**
		 * Format a duration in ms as seconds.
		 *
		 * @param {number|null} ms The duration in milliseconds.
		 * @return {string} The formatted duration, or a dash.
		 */
		formatDuration(ms) {
			if (ms === null || ms === undefined || Number.isNaN(Number(ms))) {
				return '—'
			}
			return t('hermiq', '{seconds}s', {
				seconds: (Number(ms) / 1000).toFixed(1),
			})
		},

		/**
		 * The human label for a run's channel.
		 *
		 * @param {string} trigger Either 'schedule' or 'flow'.
		 * @return {string} The label.
		 */
		triggerLabel(trigger) {
			return trigger === 'flow' ? t('hermiq', 'Flow') : t('hermiq', 'Schedule')
		},

		/**
		 * Map a run status onto one of three tones.
		 *
		 * Anything not recognised is neutral rather than an error: a status this
		 * page has not been taught is not the same as a failed run.
		 *
		 * @param {string} status The recorded status.
		 * @return {string} One of 'ok', 'error' or 'neutral'.
		 */
		statusTone(status) {
			if (status === 'ok') {
				return 'ok'
			}
			if (['error', 'failed', 'dead_letter'].includes(status)) {
				return 'error'
			}
			return 'neutral'
		},
	},
}
</script>

<style scoped>
.hermiq-runs {
	padding: 16px;
}

.hermiq-runs__header {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-end;
	justify-content: space-between;
	gap: 12px;
	margin-bottom: 12px;
}

.hermiq-runs__heading {
	margin: 0;
}

.hermiq-runs__filters {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-end;
	gap: 8px;
}

.hermiq-runs__filter {
	min-width: 200px;
}

.hermiq-runs__clear {
	margin-top: 8px;
}

.hermiq-runs__loading {
	display: flex;
	justify-content: center;
	padding: 32px;
}

/* The table scrolls inside its own container so the page body never scrolls
   sideways on a narrow window. */
.hermiq-runs__table {
	display: block;
	overflow-x: auto;
	width: 100%;
	border-collapse: collapse;
}

.hermiq-runs__table th,
.hermiq-runs__table td {
	text-align: start;
	padding: 8px 12px;
	border-bottom: 1px solid var(--color-border);
	white-space: nowrap;
}

.hermiq-runs__summary {
	white-space: normal;
	max-width: 40ch;
}

.hermiq-runs__status--ok {
	color: var(--color-success);
}

.hermiq-runs__status--error {
	color: var(--color-error);
}

.hermiq-runs__status--neutral {
	color: var(--color-text-maxcontrast);
}

.hermiq-runs__pager {
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 12px;
	margin-top: 16px;
}

.hermiq-runs__range {
	color: var(--color-text-maxcontrast);
}
</style>
