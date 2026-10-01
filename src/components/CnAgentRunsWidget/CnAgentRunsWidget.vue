<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  -
  - CnAgentRunsWidget — the agent render leaf's per-object RUN HISTORY + a
  - user-initiated "run agent" affordance (agent-object-leaf).
  -
  - History is read from OpenRegister's audit trail for the object
  - (`GET /api/objects/{register}/{schema}/{id}/audit-trails`), filtered to the
  - `agent-run` action, surfacing each run's status among ok / error /
  - skipped_killswitch / skipped_budget / awaiting_approval. The widget is
  - RENDER-ONLY for this data — it reads the audit trail and writes no run record.
  -
  - The "Run agent" button POSTs to `/api/agents/{id}/run-on-object`, which
  - dispatches the GOVERNED AgentRunRequestedEvent recipe and returns 202. The run
  - is asynchronous: the widget refreshes history for the outcome rather than
  - showing a synchronous result.
  -
  - The "Summary" section above the run list shows an AI-written summary of
  - the object (agents-bound-to-their-app task 5): `GET /api/assistant/summary`
  - says what may show without a model call, `POST /api/assistant/summarise`
  - writes one with the assistant of the object's app.
  -
  - @spec openspec/changes/hermiq-agent-leaf/specs/agent-object-leaf/spec.md#requirement-per-object-agent-run-history-and-status
  - @spec openspec/changes/agents-bound-to-their-app/specs/agent-object-leaf/spec.md#requirement-a-record-page-can-show-an-ai-written-summary-of-the-record-req-appag-004
-->
<template>
	<div class="cn-agent-runs-widget" data-testid="cn-agent-runs-widget">
		<section
			v-if="summaryShown"
			class="cn-agent-runs-widget__summary"
			data-testid="cn-agent-runs-widget-summary">
			<h4>{{ t('hermiq', 'Summary') }}</h4>
			<template v-if="summaryStatus.summary">
				<p class="cn-agent-runs-widget__summary-text">
					{{ summaryStatus.summary.summary }}
				</p>
				<p class="cn-agent-runs-widget__summary-label">
					{{
						t(
							'hermiq',
							'Written by AI on {date}. Check it before you rely on it.',
							{ date: summaryWrittenOn },
						)
					}}
				</p>
			</template>
			<NcButton
				v-if="summaryButton"
				:disabled="summaryWriting"
				data-testid="cn-agent-runs-widget-summarise"
				@click="writeSummary">
				<template v-if="summaryWriting" #icon>
					<NcLoadingIcon :size="20" />
				</template>
				{{ t('hermiq', 'Write a summary') }}
			</NcButton>
			<NcNoteCard v-if="summaryError" type="error">
				{{ summaryError }}
			</NcNoteCard>
		</section>

		<div class="cn-agent-runs-widget__run">
			<NcSelect
				v-model="selectedAgent"
				:options="agents"
				:loading="loadingAgents"
				:disabled="dispatching"
				label="label"
				:inputLabel="t('hermiq', 'Agent')"
				:placeholder="t('hermiq', 'Select an agent')"
				data-testid="cn-agent-runs-widget-agent" />
			<NcButton
				type="primary"
				:disabled="!selectedAgent || dispatching"
				data-testid="cn-agent-runs-widget-run"
				@click="runAgent">
				<template v-if="dispatching" #icon>
					<NcLoadingIcon :size="20" />
				</template>
				{{ t('hermiq', 'Run agent') }}
			</NcButton>
		</div>

		<NcNoteCard v-if="notice" :type="noticeType">
			{{ notice }}
		</NcNoteCard>

		<div class="cn-agent-runs-widget__history">
			<h4>{{ t('hermiq', 'Run history') }}</h4>
			<NcLoadingIcon v-if="loadingHistory" :size="24" />
			<NcEmptyContent
				v-else-if="runs.length === 0"
				:name="t('hermiq', 'No runs yet')"
				:description="
					t('hermiq', 'Runs triggered on this object will appear here.')
				" />
			<ul v-else class="cn-agent-runs-widget__list">
				<li
					v-for="(run, idx) in runs"
					:key="idx"
					class="cn-agent-runs-widget__item">
					<span
						class="cn-agent-runs-widget__status"
						:class="`cn-agent-runs-widget__status--${run.status}`">
						{{ statusLabel(run.status) }}
					</span>
					<span class="cn-agent-runs-widget__meta">
						{{ run.when }}
						<template v-if="run.summary"> — {{ run.summary }}</template>
					</span>
				</li>
			</ul>
		</div>
	</div>
</template>

<script>
import { getRequestToken } from '@nextcloud/auth'
import { getCanonicalLocale, translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcEmptyContent,
	NcLoadingIcon,
	NcNoteCard,
	NcSelect,
} from '@nextcloud/vue'
import {
	offersSummaryButton,
	showsSummarySection,
	summaryDate,
	summaryRefusal,
} from '../../utils/recordSummary.js'

/**
 * The sentence a reader sees for a refused summary.
 *
 * @param {string} reason The refusal reason (summaryRefusal()).
 * @return {string} The sentence.
 * @spec openspec/changes/agents-bound-to-their-app/specs/agent-object-leaf/spec.md#scenario-no-summary-for-a-record-the-user-cannot-read
 */
function refusalSentence(reason) {
	switch (reason) {
		case 'not-found':
			return t('hermiq', 'This record could not be found.')
		case 'switched-off':
			return t(
				'hermiq',
				'Your organisation has switched off AI summaries of records.',
			)
		case 'no-agent':
			return t('hermiq', 'No agent answers in this app.')
		case 'blocked':
			return t(
				'hermiq',
				"Your organisation's guardrail policy stopped this summary.",
			)
		default:
			return t('hermiq', 'The summary could not be written. Try again later.')
	}
}

export default {
	name: 'CnAgentRunsWidget',
	components: { NcButton, NcEmptyContent, NcLoadingIcon, NcNoteCard, NcSelect },
	props: {
		/** OpenRegister register id (slug or uuid). */
		register: { type: String, default: '' },
		/** OpenRegister schema id (slug or uuid). */
		schema: { type: String, default: '' },
		/** The object id (sidebar context). */
		objectId: { type: String, default: '' },
	},

	data() {
		return {
			agents: [],
			selectedAgent: null,
			loadingAgents: false,
			dispatching: false,
			runs: [],
			loadingHistory: false,
			notice: '',
			noticeType: 'success',
			summaryStatus: null,
			summaryWriting: false,
			summaryError: '',
		}
	},

	computed: {
		/**
		 * @spec openspec/changes/agents-bound-to-their-app/specs/agent-object-leaf/spec.md#requirement-a-record-page-can-show-an-ai-written-summary-of-the-record-req-appag-004
		 */
		summaryShown() {
			return showsSummarySection(this.summaryStatus)
		},

		/**
		 * @spec openspec/changes/agents-bound-to-their-app/specs/agent-object-leaf/spec.md#requirement-a-record-page-can-show-an-ai-written-summary-of-the-record-req-appag-004
		 */
		summaryButton() {
			return offersSummaryButton(this.summaryStatus)
		},

		/**
		 * @spec openspec/changes/agents-bound-to-their-app/specs/agent-object-leaf/spec.md#requirement-a-record-page-can-show-an-ai-written-summary-of-the-record-req-appag-004
		 */
		summaryWrittenOn() {
			return summaryDate(
				this.summaryStatus?.summary?.generatedAt || '',
				getCanonicalLocale(),
			)
		},
	},

	watch: {
		objectId: {
			immediate: true,
			/**
			 * @spec openspec/changes/hermiq-agent-leaf/specs/agent-object-leaf/spec.md#requirement-per-object-agent-run-history-and-status
			 */
			handler() {
				this.refresh()
			},
		},
	},

	methods: {
		t,
		/**
		 * @spec openspec/changes/hermiq-agent-leaf/specs/agent-object-leaf/spec.md#requirement-per-object-agent-run-history-and-status
		 */
		statusLabel(status) {
			const map = {
				ok: t('hermiq', 'Completed'),
				error: t('hermiq', 'Failed'),
				skipped_killswitch: t('hermiq', 'Blocked (kill-switch)'),
				skipped_budget: t('hermiq', 'Blocked (budget)'),
				skipped_agent_off: t('hermiq', 'Blocked (agent switched off)'),
				awaiting_approval: t('hermiq', 'Awaiting approval'),
			}
			return map[status] || status
		},

		/**
		 * @spec openspec/changes/hermiq-agent-leaf/specs/agent-object-leaf/spec.md#requirement-per-object-agent-run-history-and-status
		 */
		async refresh() {
			if (this.objectId === '') {
				return
			}
			await Promise.all([
				this.loadAgents(),
				this.loadHistory(),
				this.loadSummary(),
			])
		},

		/**
		 * What the summary section may show, without a model call. Any refusal
		 * (an unreadable object included) leaves the section hidden.
		 *
		 * @spec openspec/changes/agents-bound-to-their-app/specs/agent-object-leaf/spec.md#requirement-a-record-page-can-show-an-ai-written-summary-of-the-record-req-appag-004
		 */
		async loadSummary() {
			this.summaryError = ''
			try {
				const url = generateUrl(
					'/apps/hermiq/api/assistant/summary?register={register}&schema={schema}&objectId={id}',
					{
						register: this.register,
						schema: this.schema,
						id: this.objectId,
					},
				)
				const res = await fetch(url, {
					headers: { requesttoken: getRequestToken() },
				})
				this.summaryStatus = res.ok ? await res.json() : null
			} catch {
				this.summaryStatus = null
			}
		},

		/**
		 * Ask the app's assistant for a summary of this object.
		 *
		 * @spec openspec/changes/agents-bound-to-their-app/specs/agent-object-leaf/spec.md#scenario-a-case-handler-reads-a-summary-of-a-long-application
		 */
		async writeSummary() {
			if (this.summaryWriting) {
				return
			}
			this.summaryWriting = true
			this.summaryError = ''
			try {
				const res = await fetch(
					generateUrl('/apps/hermiq/api/assistant/summarise'),
					{
						method: 'POST',
						headers: {
							'Content-Type': 'application/json',
							requesttoken: getRequestToken(),
						},
						body: JSON.stringify({
							register: this.register,
							schema: this.schema,
							objectId: this.objectId,
						}),
					},
				)
				const body = await res.json().catch(() => ({}))
				if (res.ok) {
					this.summaryStatus = { ...this.summaryStatus, summary: body }
				} else {
					this.summaryError = this.refusalText(summaryRefusal(res.status))
				}
			} catch {
				this.summaryError = this.refusalText('failed')
			} finally {
				this.summaryWriting = false
			}
		},

		/**
		 * @param {string} reason The refusal reason (summaryRefusal()).
		 * @return {string} The sentence the reader sees.
		 * @spec openspec/changes/agents-bound-to-their-app/specs/agent-object-leaf/spec.md#scenario-no-summary-for-a-record-the-user-cannot-read
		 */
		refusalText(reason) {
			return refusalSentence(reason)
		},

		/**
		 * @spec openspec/changes/hermiq-agent-leaf/specs/agent-object-leaf/spec.md#requirement-per-object-agent-run-history-and-status
		 */
		async loadAgents() {
			if (this.agents.length > 0) {
				return
			}
			this.loadingAgents = true
			try {
				const res = await fetch(generateUrl('/apps/hermiq/api/agents'), {
					headers: { requesttoken: getRequestToken() },
				})
				if (!res.ok) {
					return
				}
				const body = await res.json()
				const list = Array.isArray(body)
					? body
					: body?.results || body?.agents || []
				this.agents = list
					.map((a) => ({
						id: a.uuid || a.id,
						label: a.name || a.title || a.uuid || a.id,
					}))
					.filter((a) => a.id)
			} catch {
				// Non-fatal: the run affordance simply has no agents to offer.
			} finally {
				this.loadingAgents = false
			}
		},

		/**
		 * @spec openspec/changes/hermiq-agent-leaf/specs/agent-object-leaf/spec.md#requirement-per-object-agent-run-history-and-status
		 */
		async loadHistory() {
			this.loadingHistory = true
			try {
				const url = generateUrl(
					'/apps/openregister/api/objects/{register}/{schema}/{id}/audit-trails',
					{
						register: this.register,
						schema: this.schema,
						id: this.objectId,
					},
				)
				const res = await fetch(url, {
					headers: { requesttoken: getRequestToken() },
				})
				if (!res.ok) {
					this.runs = []
					return
				}
				const body = await res.json()
				const entries = Array.isArray(body) ? body : body?.results || []
				this.runs = entries
					.filter(
						(e) =>
							e.action === 'agent-run'
							|| (e?.context?.status !== undefined
								&& e?.action === 'agent-run'),
					)
					.map((e) => this.toRun(e))
			} catch {
				this.runs = []
			} finally {
				this.loadingHistory = false
			}
		},

		/**
		 * @spec openspec/changes/hermiq-agent-leaf/specs/agent-object-leaf/spec.md#requirement-per-object-agent-run-history-and-status
		 */
		toRun(entry) {
			const ctx = entry.context || entry.changed || {}
			const when = ctx.endedAt || entry.created || entry.timestamp || ''
			return {
				status: ctx.status || 'ok',
				summary: ctx.summary || '',
				when: when ? new Date(when).toLocaleString() : '',
			}
		},

		/**
		 * @spec openspec/changes/hermiq-agent-leaf/specs/agent-object-leaf/spec.md#requirement-per-object-agent-run-history-and-status
		 */
		async runAgent() {
			if (!this.selectedAgent || this.dispatching) {
				return
			}
			this.dispatching = true
			this.notice = ''
			try {
				const url = generateUrl(
					'/apps/hermiq/api/agents/{id}/run-on-object',
					{ id: this.selectedAgent.id },
				)
				const res = await fetch(url, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: getRequestToken(),
					},
					body: JSON.stringify({
						register: this.register,
						schema: this.schema,
						objectId: this.objectId,
					}),
				})
				const body = await res.json().catch(() => ({}))
				if (res.status === 202) {
					this.noticeType = 'success'
					this.notice = t(
						'hermiq',
						'Run queued. It will appear in the history shortly.',
					)
					await this.loadHistory()
				} else if (res.status === 404) {
					this.noticeType = 'error'
					this.notice = t('hermiq', 'Object or agent not found.')
				} else {
					this.noticeType = 'error'
					this.notice =
						body?.error || t('hermiq', 'Could not start the run.')
				}
			} catch {
				this.noticeType = 'error'
				this.notice = t('hermiq', 'The agent service is unreachable.')
			} finally {
				this.dispatching = false
			}
		},
	},
}
</script>

<style scoped>
.cn-agent-runs-widget {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 8px 4px;
}

.cn-agent-runs-widget__summary {
	display: flex;
	flex-direction: column;
	gap: 6px;
	align-items: flex-start;
}

.cn-agent-runs-widget__summary-text {
	margin: 0;
	white-space: pre-line;
}

.cn-agent-runs-widget__summary-label {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.cn-agent-runs-widget__run {
	display: flex;
	gap: 8px;
	align-items: flex-end;
	flex-wrap: wrap;
}

.cn-agent-runs-widget__list {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.cn-agent-runs-widget__item {
	display: flex;
	gap: 8px;
	align-items: baseline;
}

.cn-agent-runs-widget__status {
	font-weight: 600;
	font-size: 0.85em;
	padding: 2px 6px;
	border-radius: var(--border-radius, 4px);
	background: var(--color-background-hover);
	white-space: nowrap;
}

.cn-agent-runs-widget__status--ok {
	color: var(--color-success);
}

.cn-agent-runs-widget__status--error {
	color: var(--color-error);
}

.cn-agent-runs-widget__status--skipped_killswitch,
.cn-agent-runs-widget__status--skipped_budget,
.cn-agent-runs-widget__status--skipped_agent_off {
	color: var(--color-warning);
}

.cn-agent-runs-widget__status--awaiting_approval {
	color: var(--color-primary-element);
}

.cn-agent-runs-widget__meta {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}
</style>
