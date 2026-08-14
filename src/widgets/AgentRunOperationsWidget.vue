<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  AgentRunOperationsWidget — schedule attach/edit, dry-run, run-now, budget and
  webhook trigger as ONE type:"detail" custom widget (manifest-driven-pages).

  These pieces all read or write the SAME `schedule` object and share
  `previewResult`/`runError` state across dry-run and run-now — splitting them
  across independent grid widgets would require a cross-widget state channel
  the manifest grid doesn't have (widgets don't share props/events with
  siblings), so they stay one widget (design.md Decision 3). Self-fetches the
  agent id from `$route.params.id`.

  The dry-run/replay preview panel here shows THIS widget's own dry-run
  result. A replay triggered from the sibling AgentRunHistoryWidget's per-row
  "Replay" action renders its own inline preview there instead of here (no
  cross-widget render channel is invented for it) — both use the identical
  would-have-called/error/ok badge language. After a successful Run now, this
  widget emits the shared `cn:page:refresh` event-bus signal (the SAME channel
  the manifest's `type:"refresh"` action already uses) so
  AgentRunHistoryWidget reloads its run list without a bespoke sibling
  channel.

  @spec openspec/specs/manifest-driven-pages/spec.md#requirement-a-run-operations-custom-widget-combines-schedule-dry-run-run-now-budget-and-webhook
-->
<template>
	<div class="agent-run-ops-widget">
		<div class="agent-run-ops-widget__section">
			<div class="agent-run-ops-widget__section-head">
				<h4>{{ t('hermiq', 'Schedule') }}</h4>
				<div class="agent-run-ops-widget__actions">
					<NcButton @click="showScheduleForm = true">
						{{
							schedule
								? t('hermiq', 'Edit schedule')
								: t('hermiq', 'Attach schedule')
						}}
					</NcButton>
					<NcButton
						:disabled="!schedule || running || dryRunning"
						:aria-label="
							t(
								'hermiq',
								'Preview this run without letting it change anything',
							)
						"
						@click="dryRun">
						<template #icon>
							<NcLoadingIcon v-if="dryRunning" :size="20" />
							<BeakerOutline v-else :size="20" />
						</template>
						{{ t('hermiq', 'Dry run') }}
					</NcButton>
					<NcButton
						type="primary"
						:disabled="!schedule || running || dryRunning"
						@click="runNow">
						<template #icon>
							<NcLoadingIcon v-if="running" :size="20" />
							<Play v-else :size="20" />
						</template>
						{{ t('hermiq', 'Run now') }}
					</NcButton>
				</div>
			</div>

			<p
				v-if="estimate && estimate.available"
				class="agent-run-ops-widget__estimate">
				{{
					t(
						'hermiq',
						'Estimate: ~{tokens} tokens per run (average of the last {count} runs)',
						{
							tokens: estimate.avgTotalTokens,
							count: estimate.sampleSize,
						},
					)
				}}
			</p>
			<p
				v-else-if="estimate"
				class="agent-run-ops-widget__estimate agent-run-ops-widget__estimate--empty">
				{{ t('hermiq', 'Not enough run history yet for a cost estimate.') }}
			</p>

			<NcNoteCard
				v-if="runError"
				type="error"
				:heading="t('hermiq', 'The last run reported an error')">
				{{ runError }}
				<template #action>
					<NcButton type="tertiary" @click="runError = ''">
						{{ t('hermiq', 'Dismiss') }}
					</NcButton>
				</template>
			</NcNoteCard>

			<p v-if="!schedule" class="agent-run-ops-widget__empty-hint">
				{{
					t(
						'hermiq',
						'No schedule attached yet. Attach one to run this agent unattended, or use Run now once attached.',
					)
				}}
			</p>
			<dl v-else class="agent-run-ops-widget__meta">
				<div>
					<dt>{{ t('hermiq', 'Trigger') }}</dt>
					<dd>{{ triggerLabel }}</dd>
				</div>
				<div>
					<dt>{{ t('hermiq', 'Deliver output to') }}</dt>
					<dd>{{ schedule.deliver || 'none' }}</dd>
				</div>
				<div>
					<dt>{{ t('hermiq', 'Enabled') }}</dt>
					<dd>
						{{
							schedule.enabled === false
								? t('hermiq', 'No')
								: t('hermiq', 'Yes')
						}}
					</dd>
				</div>
				<div>
					<dt>{{ t('hermiq', 'Next run') }}</dt>
					<dd>{{ formatDate(schedule.nextRun) }}</dd>
				</div>
			</dl>
		</div>

		<section v-if="previewResult" class="agent-run-ops-widget__section">
			<div class="agent-run-ops-widget__section-head">
				<h4>{{ t('hermiq', 'Dry-run preview') }}</h4>
				<NcButton type="tertiary" @click="previewResult = null">
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
			<ol
				v-if="previewSteps.length > 0"
				class="agent-run-ops-widget__trace-steps">
				<li
					v-for="step in previewSteps"
					:key="step.seq"
					class="agent-run-ops-widget__trace-step">
					<span class="agent-run-ops-widget__trace-step-type">{{
						stepTypeLabel(step.type)
					}}</span>
					<span class="agent-run-ops-widget__trace-step-name">{{
						step.name
					}}</span>
					<span
						class="agent-run-ops-widget__badge"
						:class="[
							step.outcome === 'would-have-called'
								? 'agent-run-ops-widget__badge--warn'
								: step.outcome === 'error'
									? 'agent-run-ops-widget__badge--error'
									: 'agent-run-ops-widget__badge--ok',
						]">
						{{
							step.outcome === 'would-have-called'
								? t('hermiq', 'would have called')
								: step.outcome
						}}
					</span>
				</li>
			</ol>
			<p v-else class="agent-run-ops-widget__empty-hint">
				{{ t('hermiq', 'No step detail recorded for this preview.') }}
			</p>
		</section>

		<div class="agent-run-ops-widget__section">
			<div class="agent-run-ops-widget__section-head">
				<h4>{{ t('hermiq', 'Webhook trigger') }}</h4>
				<div class="agent-run-ops-widget__actions">
					<NcButton
						v-if="!webhookStatus || !webhookStatus.configured"
						:disabled="webhookBusy"
						@click="createWebhook">
						<template v-if="webhookBusy" #icon>
							<NcLoadingIcon :size="20" />
						</template>
						{{ t('hermiq', 'Create webhook') }}
					</NcButton>
					<template v-else>
						<NcButton :disabled="webhookBusy" @click="rotateWebhook">
							{{ t('hermiq', 'Rotate secret') }}
						</NcButton>
						<NcButton
							v-if="webhookStatus.enabled"
							type="error"
							:disabled="webhookBusy"
							@click="revokeWebhook">
							{{ t('hermiq', 'Revoke') }}
						</NcButton>
					</template>
				</div>
			</div>

			<p
				v-if="!webhookStatus || !webhookStatus.configured"
				class="agent-run-ops-widget__empty-hint">
				{{
					t(
						'hermiq',
						'No webhook configured yet. Create one to let an external system (n8n, a CI pipeline, a third-party event) trigger this agent.',
					)
				}}
			</p>
			<dl v-else class="agent-run-ops-widget__meta">
				<div>
					<dt>{{ t('hermiq', 'Status') }}</dt>
					<dd>
						<span
							class="agent-run-ops-widget__badge"
							:class="[
								webhookStatus.enabled
									? 'agent-run-ops-widget__badge--ok'
									: 'agent-run-ops-widget__badge--error',
							]">
							{{
								webhookStatus.enabled
									? t('hermiq', 'Enabled')
									: t('hermiq', 'Disabled')
							}}
						</span>
					</dd>
				</div>
				<div>
					<dt>{{ t('hermiq', 'Secret') }}</dt>
					<dd>{{ webhookStatus.secretPrefix }}…</dd>
				</div>
				<div>
					<dt>{{ t('hermiq', 'Last used') }}</dt>
					<dd>{{ formatDate(webhookStatus.lastUsedAt) }}</dd>
				</div>
				<div>
					<dt>{{ t('hermiq', 'Rotated') }}</dt>
					<dd>{{ formatDate(webhookStatus.rotatedAt) }}</dd>
				</div>
			</dl>
		</div>

		<section
			v-if="budgetStatus && budgetStatus.configured"
			class="agent-run-ops-widget__section">
			<h4>{{ t('hermiq', 'Budget') }}</h4>
			<NcNoteCard v-if="budgetStatus.hardCapReached" type="error">
				{{
					t(
						'hermiq',
						'Hard cap reached — new runs are blocked until the next period.',
					)
				}}
			</NcNoteCard>
			<NcNoteCard v-else-if="budgetStatus.softThresholdReached" type="warning">
				{{ t('hermiq', 'Soft threshold crossed for the current period.') }}
			</NcNoteCard>
			<dl class="agent-run-ops-widget__meta">
				<div v-if="budgetStatus.tokens && budgetStatus.tokens.limit">
					<dt>{{ t('hermiq', 'Tokens this period') }}</dt>
					<dd>
						{{ budgetStatus.tokens.used }} /
						{{ budgetStatus.tokens.limit }} ({{
							budgetStatus.tokens.percent
						}}%)
					</dd>
				</div>
				<div v-if="budgetStatus.eur && budgetStatus.eur.limit">
					<dt>{{ t('hermiq', 'Spend this period') }}</dt>
					<dd>
						€{{ budgetStatus.eur.used }} / €{{ budgetStatus.eur.limit }}
					</dd>
				</div>
			</dl>
		</section>

		<ScheduleFormModal
			:show="showScheduleForm"
			:agentId="agentId"
			:schedule="schedule"
			@close="showScheduleForm = false"
			@saved="onScheduleSaved" />

		<WebhookSecretDialog
			:show="showWebhookSecretDialog"
			:secret="revealedSecret"
			@close="closeWebhookSecretDialog" />
	</div>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { emit } from '@nextcloud/event-bus'
import { NcButton, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import BeakerOutline from 'vue-material-design-icons/BeakerOutline.vue'
import Play from 'vue-material-design-icons/Play.vue'
import ScheduleFormModal from '../modals/ScheduleFormModal.vue'
import WebhookSecretDialog from '../modals/WebhookSecretDialog.vue'
import { dryRunSchedule, runScheduleNow } from '../api/agents.js'
import { getBudgetEstimate, getBudgetStatus } from '../api/budgets.js'
import {
	createWebhookSecret,
	getWebhookStatus,
	revokeWebhookSecret,
	rotateWebhookSecret,
} from '../api/webhooks.js'
import { useScheduleStore } from '../store/store.js'

export default {
	name: 'AgentRunOperationsWidget',

	components: {
		BeakerOutline,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		Play,
		ScheduleFormModal,
		WebhookSecretDialog,
	},

	data() {
		return {
			schedule: null,
			running: false,
			dryRunning: false,
			runError: '',
			previewResult: null,
			showScheduleForm: false,
			estimate: null,
			budgetStatus: null,
			webhookStatus: null,
			webhookBusy: false,
			showWebhookSecretDialog: false,
			revealedSecret: '',
		}
	},

	computed: {
		/**
		 * This agent's uuid from the route param.
		 *
		 * @return {string} The agent uuid.
		 */
		agentId() {
			return this.$route.params.id
		},

		/**
		 * The steps of the last dry-run preview.
		 *
		 * @return {Array<object>} The preview's ordered steps.
		 */
		previewSteps() {
			if (!this.previewResult) {
				return []
			}
			return Array.isArray(this.previewResult.steps)
				? this.previewResult.steps
				: []
		},

		/**
		 * Human label for the schedule trigger.
		 *
		 * @return {string} The trigger label.
		 */
		triggerLabel() {
			if (!this.schedule) {
				return '—'
			}
			if (this.schedule.kind === 'cron') {
				return `${this.t('hermiq', 'Cron')}: ${this.schedule.cronExpr || ''}`
			}
			if (this.schedule.kind === 'interval') {
				return `${this.t('hermiq', 'Every')} ${this.schedule.intervalMinutes} ${this.t('hermiq', 'minutes')}`
			}
			return `${this.t('hermiq', 'Once')}: ${this.formatDate(this.schedule.runAt)}`
		},
	},

	created() {
		this.scheduleStore = useScheduleStore()
		this.scheduleStore.registerObjectType('schedule', 'schedule', 'hermiq')
		this.load()
	},

	methods: {
		/**
		 * Load the attached schedule, cost estimate, budget status and webhook status.
		 *
		 * @return {Promise<void>}
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
			await Promise.all([this.loadBudgetInfo(), this.loadWebhookStatus()])
		},

		/**
		 * Load the pre-run cost estimate + agent-scoped budget status
		 * (non-fatal: both surfaces simply stay hidden when the requests fail).
		 *
		 * @return {Promise<void>}
		 */
		async loadBudgetInfo() {
			const [estimate, budgetStatus] = await Promise.all([
				getBudgetEstimate(this.agentId).catch(() => null),
				getBudgetStatus('', this.agentId).catch(() => null),
			])
			this.estimate = estimate
			this.budgetStatus = budgetStatus
		},

		/**
		 * Load the webhook trigger status (non-fatal on error).
		 *
		 * @return {Promise<void>}
		 */
		async loadWebhookStatus() {
			try {
				this.webhookStatus = await getWebhookStatus(this.agentId)
			} catch (e) {
				this.webhookStatus = null
			}
		},

		/**
		 * Create a webhook secret for this agent and reveal it once.
		 *
		 * @return {Promise<void>}
		 */
		async createWebhook() {
			this.webhookBusy = true
			try {
				const result = await createWebhookSecret(this.agentId)
				this.revealedSecret = result.secret || ''
				this.showWebhookSecretDialog = true
				await this.loadWebhookStatus()
				showSuccess(this.t('hermiq', 'Webhook created.'))
			} catch (e) {
				showError(this.t('hermiq', 'Could not create the webhook.'))
			} finally {
				this.webhookBusy = false
			}
		},

		/**
		 * Rotate this agent's webhook secret and reveal the new one once.
		 *
		 * @return {Promise<void>}
		 */
		async rotateWebhook() {
			this.webhookBusy = true
			try {
				const result = await rotateWebhookSecret(this.agentId)
				this.revealedSecret = result.secret || ''
				this.showWebhookSecretDialog = true
				await this.loadWebhookStatus()
				showSuccess(this.t('hermiq', 'Webhook secret rotated.'))
			} catch (e) {
				showError(this.t('hermiq', 'Could not rotate the webhook secret.'))
			} finally {
				this.webhookBusy = false
			}
		},

		/**
		 * Revoke this agent's webhook — disables it without deleting its configuration.
		 *
		 * @return {Promise<void>}
		 */
		async revokeWebhook() {
			this.webhookBusy = true
			try {
				await revokeWebhookSecret(this.agentId)
				await this.loadWebhookStatus()
				showSuccess(this.t('hermiq', 'Webhook revoked.'))
			} catch (e) {
				showError(this.t('hermiq', 'Could not revoke the webhook.'))
			} finally {
				this.webhookBusy = false
			}
		},

		/**
		 * Clear the transiently-held plaintext secret once the copy-once dialog
		 * is dismissed.
		 *
		 * @return {void}
		 */
		closeWebhookSecretDialog() {
			this.showWebhookSecretDialog = false
			this.revealedSecret = ''
		},

		/**
		 * Preview this schedule's run WITHOUT letting it change anything:
		 * side-effecting tools are neutralised and reported as
		 * `would-have-called` rather than invoked.
		 *
		 * @return {Promise<void>}
		 */
		async dryRun() {
			if (!this.schedule || !this.schedule.id) {
				return
			}
			this.dryRunning = true
			this.runError = ''
			this.previewResult = null
			try {
				this.previewResult = await dryRunSchedule(this.schedule.id)
				showSuccess(
					this.t('hermiq', 'Dry run complete — nothing was changed.'),
				)
			} catch (e) {
				this.runError =
					e?.response?.data?.error
					|| e?.message
					|| this.t('hermiq', 'The dry run failed.')
				showError(this.t('hermiq', 'The dry run failed.'))
			} finally {
				this.dryRunning = false
			}
		},

		/**
		 * Trigger an immediate run and surface the result or a graceful error
		 * state. Emits the shared `cn:page:refresh` signal afterward so the
		 * sibling AgentRunHistoryWidget reloads its run list.
		 *
		 * @return {Promise<void>}
		 */
		async runNow() {
			if (!this.schedule || !this.schedule.id) {
				return
			}
			this.running = true
			this.runError = ''
			try {
				const result = await runScheduleNow(this.schedule.id)
				if (result && result.status === 'error') {
					this.runError =
						result.error || this.t('hermiq', 'The agent run failed.')
					showError(this.t('hermiq', 'The agent run reported an error.'))
				} else {
					showSuccess(this.t('hermiq', 'Agent run started.'))
				}
			} catch (e) {
				this.runError =
					e?.response?.data?.message
					|| e?.response?.data?.error
					|| e?.message
					|| this.t('hermiq', 'The agent run failed.')
				showError(this.t('hermiq', 'The agent run failed.'))
			} finally {
				this.running = false
				await this.load()
				emit('cn:page:refresh', {})
			}
		},

		/**
		 * Reload after the schedule is attached/edited.
		 *
		 * @return {Promise<void>}
		 */
		async onScheduleSaved() {
			await this.load()
			emit('cn:page:refresh', {})
		},

		/**
		 * Human label for a trace step's type.
		 *
		 * @param {string} type The step type (gate_wait|context|history|llm|tool|delivery).
		 * @return {string} The localised label.
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
		 * Format an ISO date for display, or a dash when absent/invalid.
		 *
		 * @param {string} value The ISO timestamp.
		 * @return {string} The localised date, or '—'.
		 */
		formatDate(value) {
			if (!value) {
				return '—'
			}
			const date = new Date(value)
			return Number.isNaN(date.getTime()) ? '—' : date.toLocaleString()
		},
	},
}
</script>

<style scoped>
.agent-run-ops-widget {
	display: flex;
	flex-direction: column;
	gap: 20px;
}

.agent-run-ops-widget__section-head {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	margin-bottom: 8px;
}

.agent-run-ops-widget__section-head h4 {
	margin: 0;
	font-size: 15px;
	font-weight: 600;
}

.agent-run-ops-widget__actions {
	display: flex;
	gap: 8px;
	flex-wrap: wrap;
}

.agent-run-ops-widget__estimate {
	margin: 0 0 8px;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.agent-run-ops-widget__estimate--empty {
	font-style: italic;
}

.agent-run-ops-widget__empty-hint {
	color: var(--color-text-maxcontrast);
	margin: 4px 0;
}

.agent-run-ops-widget__meta {
	display: grid;
	grid-template-columns: repeat(2, minmax(0, 1fr));
	gap: 12px 24px;
	margin: 0;
}

.agent-run-ops-widget__meta dt {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
	margin-bottom: 2px;
}

.agent-run-ops-widget__meta dd {
	margin: 0;
	white-space: pre-wrap;
}

.agent-run-ops-widget__trace-steps {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.agent-run-ops-widget__trace-step {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 4px 0;
	border-bottom: 1px solid var(--color-border);
}

.agent-run-ops-widget__trace-step-type {
	min-width: 90px;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

.agent-run-ops-widget__trace-step-name {
	flex: 1 1 auto;
}

.agent-run-ops-widget__badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: var(--border-radius-pill, 12px);
	font-size: 13px;
}

.agent-run-ops-widget__badge--ok {
	color: var(--color-success-text, var(--color-success));
	font-weight: 600;
}

.agent-run-ops-widget__badge--error {
	color: var(--color-error-text, var(--color-error));
	font-weight: 600;
}

.agent-run-ops-widget__badge--warn {
	color: var(--color-warning-text, var(--color-warning));
	font-weight: 600;
}
</style>
