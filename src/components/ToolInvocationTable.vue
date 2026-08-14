<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  ToolInvocationTable — the per-agent EU AI Act art.12/14 oversight view
  (agent-tool-governance-and-disclosure).

  Lists the agent's recorded MCP tool invocations (newest first) read from OpenRegister's
  hash-chained AuditTrail: when, which tool, who acted, the arguments DIGEST (the audit
  entry never stores raw argument values — showing the SHA-256 digest is honest; inventing
  decoded values would not be), the structured result summary, and which objects were
  touched. Carries the retention note and a CSV/JSON export.

  Two honesty guarantees the spec is explicit about:
  - An agent with NO recorded invocations renders an empty state — never a fabricated row.
  - When OpenRegister has not yet written the richer per-invocation MCP audit shape, the
    endpoint degrades to the coarser run-audit entries and sets available/source; this view
    then shows a REDUCED-DETAIL indicator rather than implying the detail is simply absent.

  @spec openspec/changes/agent-tool-governance-and-disclosure/tasks.md#task-7
  @spec openspec/changes/agent-tool-governance-and-disclosure/specs/agent-tool-governance/spec.md#requirement-per-agent-tool-invocation-oversight-surface-ai-act-art1214
-->
<template>
	<section class="tool-oversight">
		<h3 class="tool-oversight__title">
			{{ t('hermiq', 'Tool activity (oversight)') }}
		</h3>

		<NcNoteCard
			v-if="error"
			type="error"
			:heading="t('hermiq', 'Could not load tool activity')">
			{{ error }}
		</NcNoteCard>

		<div v-if="loading" class="tool-oversight__loading">
			<NcLoadingIcon :size="32" />
		</div>

		<template v-else-if="data">
			<NcNoteCard v-if="!data.available" type="warning">
				{{
					t(
						'hermiq',
						'Reduced detail: OpenRegister has not recorded per-invocation MCP audit entries on this instance yet, so this view falls back to the coarser run audit log. Tool ids, argument digests and touched objects are not available for these rows.',
					)
				}}
			</NcNoteCard>

			<div class="tool-oversight__meta">
				<p class="tool-oversight__retention">
					{{ retentionLabel }}
				</p>
				<div class="tool-oversight__exports">
					<NcButton
						variant="tertiary"
						:disabled="!data.rows.length"
						@click="exportAs('csv')">
						<template #icon>
							<Download :size="20" />
						</template>
						{{ t('hermiq', 'Export CSV') }}
					</NcButton>
					<NcButton
						variant="tertiary"
						:disabled="!data.rows.length"
						@click="exportAs('json')">
						<template #icon>
							<Download :size="20" />
						</template>
						{{ t('hermiq', 'Export JSON') }}
					</NcButton>
				</div>
			</div>

			<NcEmptyContent
				v-if="!data.rows.length"
				:name="t('hermiq', 'No invocations recorded yet')"
				:description="
					t(
						'hermiq',
						'This agent has not invoked any tool yet. Rows appear here once it does.',
					)
				">
				<template #icon>
					<History :size="20" />
				</template>
			</NcEmptyContent>

			<!--
				An audit trail grows without bound, so its height cannot be a
				function of any gridHeight: a busy agent's rows pushed this widget
				past its cell and over the widgets below it. Same treatment as the
				grant catalogue in ToolGrantEditor — bounded scroll region, sticky
				header, all rows still present.
			-->
			<div v-else class="tool-oversight__table-wrap">
				<table class="tool-oversight__table">
					<thead>
						<tr>
							<th scope="col">
								{{ t('hermiq', 'When') }}
							</th>
							<th scope="col">
								{{ t('hermiq', 'Tool') }}
							</th>
							<th scope="col">
								{{ t('hermiq', 'Acting identity') }}
							</th>
							<th scope="col">
								{{ t('hermiq', 'Arguments (digest)') }}
							</th>
							<th scope="col">
								{{ t('hermiq', 'Result') }}
							</th>
							<th scope="col">
								{{ t('hermiq', 'Data touched') }}
							</th>
						</tr>
					</thead>
					<tbody>
						<tr
							v-for="(row, index) in data.rows"
							:key="`${row.at}-${index}`">
							<td>{{ formatDate(row.at) }}</td>
							<td>
								<span v-if="row.toolId" class="tool-oversight__id">{{
									row.toolId
								}}</span>
								<span v-else class="tool-oversight__unavailable">{{
									t('hermiq', 'not recorded')
								}}</span>
							</td>
							<td>{{ row.actingUser || '—' }}</td>
							<td>
								<code
									v-if="row.paramsDigest"
									class="tool-oversight__digest"
									>{{ shortDigest(row.paramsDigest) }}</code
								>
								<span v-else class="tool-oversight__unavailable">{{
									t('hermiq', 'not recorded')
								}}</span>
							</td>
							<td>
								<span :class="resultClass(row)">{{
									resultLabel(row)
								}}</span>
							</td>
							<td>
								<span
									v-if="row.dataTouched && row.dataTouched.length"
									>{{ row.dataTouched.join(', ') }}</span
								>
								<span v-else>—</span>
							</td>
						</tr>
					</tbody>
				</table>
			</div>
		</template>
	</section>
</template>

<script>
import { NcButton, NcEmptyContent, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import Download from 'vue-material-design-icons/Download.vue'
import History from 'vue-material-design-icons/History.vue'
import {
	getToolInvocations,
	toolInvocationsExportUrl,
} from '../api/toolOversight.js'

export default {
	name: 'ToolInvocationTable',

	components: {
		Download,
		History,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
	},

	props: {
		/** The agent UUID whose invocations are shown. */
		agentId: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			data: null,
			loading: true,
			error: '',
		}
	},

	computed: {
		/**
		 * The retention note, straight from the endpoint (never invented client-side —
		 * retention is inherited from OpenRegister's AuditTrail policy).
		 *
		 * `escape: false` because this string is rendered through Vue's `{{ }}` text
		 * interpolation, which already escapes. Without it the value is escaped TWICE
		 * and the user reads the raw entity — the endpoint's "OpenRegister's AuditTrail
		 * policy" rendered literally as "OpenRegister&#39;s AuditTrail policy".
		 *
		 * @return {string} The retention label.
		 */
		retentionLabel() {
			return this.t(
				'hermiq',
				'Retention: {retention}',
				{ retention: this.data.retention },
				undefined,
				{ escape: false },
			)
		},
	},

	watch: {
		agentId: {
			handler() {
				this.load()
			},

			immediate: true,
		},
	},

	methods: {
		/**
		 * Load this agent's oversight rows.
		 *
		 * @return {Promise<void>}
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				this.data = await getToolInvocations(this.agentId)
			} catch (error) {
				this.error =
					(error.response
						&& error.response.data
						&& error.response.data.error)
					|| error.message
			} finally {
				this.loading = false
			}
		},

		/**
		 * Download the rows in the given format via the export endpoint.
		 *
		 * @param {string} format Either 'csv' or 'json'.
		 * @return {void}
		 */
		exportAs(format) {
			window.location.href = toolInvocationsExportUrl(this.agentId, format)
		},

		/**
		 * Format an ISO 8601 timestamp for display.
		 *
		 * @param {string} value The ISO timestamp.
		 * @return {string} The formatted date, or an em dash.
		 */
		formatDate(value) {
			if (!value) {
				return '—'
			}
			return new Date(value).toLocaleString()
		},

		/**
		 * Shorten a SHA-256 hex digest for display (the full value is in the export).
		 *
		 * @param {string} digest The full digest.
		 * @return {string} The shortened digest.
		 */
		shortDigest(digest) {
			return digest.length > 12 ? `${digest.slice(0, 12)}…` : digest
		},

		/**
		 * A row's result label (never colour-only — the text carries the meaning).
		 *
		 * @param {object} row The oversight row.
		 * @return {string} The result label.
		 */
		resultLabel(row) {
			const summary = row.resultSummary
			if (!summary) {
				return '—'
			}
			if (summary.isError) {
				return this.t('hermiq', 'Error')
			}
			if (summary.status) {
				return String(summary.status)
			}
			if (typeof summary.count === 'number') {
				return this.t('hermiq', '{count} result(s)', {
					count: summary.count,
				})
			}
			if (summary.id) {
				return this.t('hermiq', 'Object {id}', { id: summary.id })
			}
			return this.t('hermiq', 'OK')
		},

		/**
		 * The result cell's class.
		 *
		 * @param {object} row The oversight row.
		 * @return {string} The class.
		 */
		resultClass(row) {
			const isError = !!(row.resultSummary && row.resultSummary.isError)
			return isError
				? 'tool-oversight__badge tool-oversight__badge--error'
				: 'tool-oversight__badge tool-oversight__badge--ok'
		},
	},
}
</script>

<style scoped>
.tool-oversight {
	margin-block: 24px;
}

.tool-oversight__title {
	margin-block-end: 8px;
}

.tool-oversight__meta {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
	margin-block-end: 12px;
}

.tool-oversight__retention {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.tool-oversight__exports {
	display: flex;
	gap: 8px;
}

.tool-oversight__loading {
	display: flex;
	justify-content: center;
	padding: 24px;
}

/* The audit trail is unbounded — it scrolls inside the widget (ADR-062). */
.tool-oversight__table-wrap {
	/* Paired with ToolGrantEditor's 300px cap — see the note there. */
	max-height: 260px;
	overflow-y: auto;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
}

.tool-oversight__table {
	width: 100%;
	border-collapse: collapse;
}

.tool-oversight__table th,
.tool-oversight__table td {
	text-align: start;
	padding: 8px;
	border-block-end: 1px solid var(--color-border);
	vertical-align: top;
}

/* Sticky header — the column meaning must survive scrolling the rows. */
.tool-oversight__table thead th {
	position: sticky;
	inset-block-start: 0;
	z-index: 1;
	background-color: var(--color-main-background);
}

.tool-oversight__id,
.tool-oversight__digest {
	font-family: monospace;
}

.tool-oversight__unavailable {
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.tool-oversight__badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: var(--border-radius-pill);
	font-size: 0.85em;
	white-space: nowrap;
}

.tool-oversight__badge--ok {
	background-color: var(--color-background-dark);
	color: var(--color-main-text);
}

.tool-oversight__badge--error {
	background-color: var(--color-error);
	color: var(--color-primary-text);
}
</style>
