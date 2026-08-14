<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  ScheduleFormModal — attach or edit a Schedule for an agent (agent-management-ui).

  Own file per ADR-004 modal-isolation. Persists a `hermiq`/`schedule` OpenRegister
  object through the createObjectStore in src/store/store.js (no bespoke store, no
  bespoke CRUD backend). Every NcSelect carries an `inputLabel` (nc-input-labels
  accessibility gate; WCAG 2.1 AA). Fields mirror the Schedule schema: kind,
  cronExpr, intervalMinutes, runAt, prompt, deliver, deliverTarget, enabled, repeat.

  Retry policy (run-reliability): an opt-in "Retry enabled" switch reveals three
  bounded number inputs (max attempts, backoff base, circuit-breaker threshold).
  Off by default so the save payload is unchanged from before this change.

  Delivery channels (delivery-channels): `deliver` gains `email` and `webhook`
  alongside talk/notification/none. `deliverTarget` keeps its single field but
  its meaning follows the selected channel (Talk room token / email recipient /
  webhook URL) — Slack/Matrix/Telegram/WhatsApp/Teams are OpenConnector's job,
  reached THROUGH the webhook channel, never grown here. The webhook signing
  secret is managed in the separate ScheduleWebhookSecretDialog (own file per
  ADR-004 modal-isolation) — only reachable once the schedule has been saved
  (it needs a persisted schedule id).

  @spec openspec/changes/agent-management-ui/tasks.md#task-5-2
  @spec openspec/changes/agent-management-ui/specs/agent-management-ui/spec.md
  @spec openspec/changes/run-reliability/specs/agent-schedule/spec.md#requirement-per-schedule-opt-in-bounded-retry-with-exponential-backoff-mvp
  @spec openspec/changes/delivery-channels/tasks.md#task-7-frontend-scheduleformmodalvue-new-channels-schedulewebhooksecretdialogvue
-->
<template>
	<NcModal :show="show" size="normal" :name="heading" @close="$emit('close')">
		<div class="schedule-form">
			<h2 class="schedule-form__title">
				{{ heading }}
			</h2>

			<NcNoteCard
				v-if="error"
				type="error"
				:heading="t('hermiq', 'Could not save schedule')">
				{{ error }}
			</NcNoteCard>

			<!-- Pre-run cost estimate (cost-guardrails): trailing average, clearly
			     labelled an estimate — never a fabricated figure without history. -->
			<p v-if="estimate && estimate.available" class="schedule-form__estimate">
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
				class="schedule-form__estimate schedule-form__estimate--empty">
				{{ t('hermiq', 'Not enough run history yet for a cost estimate.') }}
			</p>

			<NcTextField
				v-model="form.name"
				:label="t('hermiq', 'Name')"
				:placeholder="t('hermiq', 'Daily briefing')"
				required />

			<div class="schedule-form__field">
				<NcSelect
					v-model="kindOption"
					:inputLabel="t('hermiq', 'Trigger')"
					:options="kindOptions"
					:clearable="false"
					label="label"
					trackBy="value" />
			</div>

			<NcTextField
				v-if="form.kind === 'cron'"
				v-model="form.cronExpr"
				:label="t('hermiq', 'Cron expression')"
				placeholder="0 8 * * *" />

			<NcTextField
				v-if="form.kind === 'interval'"
				v-model="form.intervalMinutes"
				type="number"
				:label="t('hermiq', 'Interval (minutes)')"
				placeholder="1440" />

			<NcTextField
				v-if="form.kind === 'once'"
				v-model="form.runAt"
				type="datetime-local"
				:label="t('hermiq', 'Run at')" />

			<NcTextArea
				v-model="form.prompt"
				:label="t('hermiq', 'Prompt')"
				:placeholder="t('hermiq', 'Task text passed to the agent run')"
				resize="vertical" />

			<div class="schedule-form__field">
				<NcSelect
					v-model="deliverOption"
					:inputLabel="t('hermiq', 'Deliver output to')"
					:options="deliverOptions"
					:clearable="false"
					label="label"
					trackBy="value" />
			</div>

			<NcTextField
				v-if="form.deliver === 'talk'"
				v-model="form.deliverTarget"
				:label="t('hermiq', 'Talk room token')"
				:placeholder="t('hermiq', 'Leave empty for Note-to-self')" />

			<NcTextField
				v-if="form.deliver === 'email'"
				v-model="form.deliverTarget"
				type="email"
				:label="t('hermiq', 'Email recipient')"
				:placeholder="
					t('hermiq', 'Leave empty to use your own account email')
				" />

			<template v-if="form.deliver === 'webhook'">
				<NcTextField
					v-model="form.deliverTarget"
					:label="t('hermiq', 'Webhook URL')"
					placeholder="https://example.com/hook" />

				<NcButton
					v-if="schedule && schedule.id"
					@click="showWebhookSecretDialog = true">
					{{ t('hermiq', 'Manage webhook signing secret') }}
				</NcButton>
				<NcNoteCard v-else type="info">
					{{
						t(
							'hermiq',
							'Save this schedule first, then reopen it to mint a webhook signing secret.',
						)
					}}
				</NcNoteCard>
			</template>

			<NcTextField
				v-model="form.repeatTimes"
				type="number"
				:label="t('hermiq', 'Repeat times (empty = forever)')"
				placeholder="" />

			<NcCheckboxRadioSwitch v-model="form.enabled" type="switch">
				{{ t('hermiq', 'Enabled') }}
			</NcCheckboxRadioSwitch>

			<NcCheckboxRadioSwitch v-model="form.requiresApproval" type="switch">
				{{ t('hermiq', 'Requires approval') }}
			</NcCheckboxRadioSwitch>

			<template v-if="form.requiresApproval">
				<div class="schedule-form__field">
					<NcSelect
						v-model="reviewerTypeOption"
						:inputLabel="t('hermiq', 'Reviewer type')"
						:options="reviewerTypeOptions"
						:clearable="false"
						label="label"
						trackBy="value" />
				</div>

				<NcTextField
					v-model="form.reviewer"
					:label="
						form.reviewerType === 'group'
							? t('hermiq', 'Reviewer group id')
							: t('hermiq', 'Reviewer user id')
					"
					:placeholder="
						t('hermiq', 'Leave empty to use the schedule owner')
					" />
			</template>

			<!-- Retry policy (run-reliability): opt-in bounded retry with exponential
			     backoff + a circuit breaker. Off by default (backward compatible). -->
			<NcCheckboxRadioSwitch v-model="form.retryEnabled" type="switch">
				{{ t('hermiq', 'Retry on failure') }}
			</NcCheckboxRadioSwitch>

			<template v-if="form.retryEnabled">
				<NcTextField
					v-model="form.retryMaxAttempts"
					type="number"
					:label="t('hermiq', 'Retry max attempts')"
					min="1"
					max="10" />

				<NcTextField
					v-model="form.retryBackoffBaseSeconds"
					type="number"
					:label="t('hermiq', 'Retry backoff base (seconds)')"
					min="1" />

				<NcTextField
					v-model="form.circuitBreakerThreshold"
					type="number"
					:label="t('hermiq', 'Circuit breaker threshold')"
					min="1" />
			</template>

			<div class="schedule-form__actions">
				<NcButton :disabled="saving" @click="$emit('close')">
					{{ t('hermiq', 'Cancel') }}
				</NcButton>
				<NcButton
					variant="primary"
					:disabled="saving || !form.name"
					@click="save">
					<template v-if="saving" #icon>
						<NcLoadingIcon :size="20" />
					</template>
					{{ t('hermiq', 'Save') }}
				</NcButton>
			</div>
		</div>

		<ScheduleWebhookSecretDialog
			v-if="schedule && schedule.id"
			:show="showWebhookSecretDialog"
			:scheduleId="schedule.id"
			@close="showWebhookSecretDialog = false" />
	</NcModal>
</template>

<script>
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcLoadingIcon,
	NcModal,
	NcNoteCard,
	NcSelect,
	NcTextArea,
	NcTextField,
} from '@nextcloud/vue'
import ScheduleWebhookSecretDialog from './ScheduleWebhookSecretDialog.vue'
import { getBudgetEstimate } from '../api/budgets.js'
import { useScheduleStore } from '../store/store.js'

export default {
	name: 'ScheduleFormModal',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcModal,
		NcNoteCard,
		NcSelect,
		NcTextArea,
		ScheduleWebhookSecretDialog,
		NcTextField,
	},

	props: {
		/** Whether the modal is visible. */
		show: {
			type: Boolean,
			default: false,
		},

		/** The agent UUID this schedule runs. */
		agentId: {
			type: String,
			required: true,
		},

		/** The schedule being edited, or null when attaching a new one. */
		schedule: {
			type: Object,
			default: null,
		},
	},

	emits: ['close', 'saved'],

	data() {
		return {
			form: this.blankForm(),
			saving: false,
			error: '',
			// Pre-run cost estimate (cost-guardrails); null keeps the line hidden.
			estimate: null,
			// delivery-channels: webhook signing-secret dialog visibility.
			showWebhookSecretDialog: false,
			kindOptions: [
				{ label: this.t('hermiq', 'Once'), value: 'once' },
				{ label: this.t('hermiq', 'Interval'), value: 'interval' },
				{ label: this.t('hermiq', 'Cron'), value: 'cron' },
			],

			deliverOptions: [
				{ label: this.t('hermiq', 'Nextcloud Talk'), value: 'talk' },
				{ label: this.t('hermiq', 'Notification'), value: 'notification' },
				{ label: this.t('hermiq', 'None'), value: 'none' },
				// delivery-channels: reached THROUGH the outbound webhook, Hermiq
				// itself never grows a Slack/Matrix/Telegram/WhatsApp/Teams option.
				{ label: this.t('hermiq', 'Email'), value: 'email' },
				{ label: this.t('hermiq', 'Webhook'), value: 'webhook' },
			],

			reviewerTypeOptions: [
				{ label: this.t('hermiq', 'User'), value: 'user' },
				{ label: this.t('hermiq', 'Group'), value: 'group' },
			],
		}
	},

	computed: {
		/**
		 * Modal heading — differs for attach vs edit.
		 *
		 * @return {string} The localised heading.
		 */
		heading() {
			return this.schedule
				? this.t('hermiq', 'Edit schedule')
				: this.t('hermiq', 'Attach schedule')
		},

		/**
		 * Two-way bridge between the `kind` string and the NcSelect option object.
		 */
		kindOption: {
			get() {
				return (
					this.kindOptions.find(
						(option) => option.value === this.form.kind,
					) || this.kindOptions[0]
				)
			},

			set(option) {
				this.form.kind = option ? option.value : 'once'
			},
		},

		/**
		 * Two-way bridge between the `deliver` string and the NcSelect option object.
		 */
		deliverOption: {
			get() {
				return (
					this.deliverOptions.find(
						(option) => option.value === this.form.deliver,
					) || this.deliverOptions[2]
				)
			},

			set(option) {
				this.form.deliver = option ? option.value : 'none'
			},
		},

		/**
		 * Two-way bridge between the `reviewerType` string and the NcSelect option.
		 */
		reviewerTypeOption: {
			get() {
				return (
					this.reviewerTypeOptions.find(
						(option) => option.value === this.form.reviewerType,
					) || this.reviewerTypeOptions[0]
				)
			},

			set(option) {
				this.form.reviewerType = option ? option.value : 'user'
			},
		},
	},

	watch: {
		show(open) {
			if (open) {
				this.resetForm()
				this.loadEstimate()
			}
		},
	},

	created() {
		// Register the 'schedule' OpenRegister object type once (idempotent):
		// register 'hermiq' + schema 'schedule' → CRUD at
		// /apps/openregister/api/objects/hermiq/schedule.
		this.store = useScheduleStore()
		this.store.registerObjectType('schedule', 'schedule', 'hermiq')
	},

	methods: {
		/**
		 * Load the agent's pre-run cost estimate (cost-guardrails). Non-fatal:
		 * the estimate line simply stays hidden when the request fails.
		 *
		 * @return {Promise<void>}
		 */
		async loadEstimate() {
			this.estimate = await getBudgetEstimate(this.agentId).catch(() => null)
		},

		/**
		 * An empty schedule form.
		 *
		 * @return {object} The blank form model.
		 */
		blankForm() {
			return {
				name: '',
				kind: 'cron',
				cronExpr: '0 8 * * *',
				intervalMinutes: 1440,
				runAt: '',
				prompt: '',
				deliver: 'none',
				deliverTarget: '',
				enabled: true,
				repeatTimes: '',
				requiresApproval: false,
				reviewer: '',
				reviewerType: 'user',
				retryEnabled: false,
				retryMaxAttempts: 3,
				retryBackoffBaseSeconds: 60,
				circuitBreakerThreshold: 3,
			}
		},

		/**
		 * Seed the form from the `schedule` prop (edit) or blank (attach).
		 *
		 * @return {void}
		 */
		resetForm() {
			this.error = ''
			if (!this.schedule) {
				this.form = this.blankForm()
				return
			}
			const source = this.schedule
			this.form = {
				name: source.name || '',
				kind: source.kind || 'cron',
				cronExpr: source.cronExpr || '0 8 * * *',
				intervalMinutes: source.intervalMinutes || 1440,
				runAt: source.runAt || '',
				prompt: source.prompt || '',
				deliver: source.deliver || 'none',
				deliverTarget: source.deliverTarget || '',
				enabled: source.enabled !== false,
				repeatTimes:
					source.repeat && source.repeat.times ? source.repeat.times : '',

				requiresApproval: source.requiresApproval === true,
				reviewer: source.reviewer || '',
				reviewerType: source.reviewerType || 'user',
				retryEnabled: source.retryEnabled === true,
				retryMaxAttempts: source.retryMaxAttempts || 3,
				retryBackoffBaseSeconds: source.retryBackoffBaseSeconds || 60,
				circuitBreakerThreshold: source.circuitBreakerThreshold || 3,
			}
		},

		/**
		 * Build the OpenRegister schedule payload from the form model.
		 *
		 * @return {object} The schedule object body.
		 */
		buildPayload() {
			const payload = {
				name: this.form.name,
				agentId: this.agentId,
				kind: this.form.kind,
				prompt: this.form.prompt,
				deliver: this.form.deliver,
				enabled: this.form.enabled,
			}
			if (this.form.kind === 'cron') {
				payload.cronExpr = this.form.cronExpr
			} else if (this.form.kind === 'interval') {
				payload.intervalMinutes = Number(this.form.intervalMinutes) || 1
			} else if (this.form.kind === 'once' && this.form.runAt) {
				payload.runAt = new Date(this.form.runAt).toISOString()
			}
			// delivery-channels: deliverTarget's meaning follows the selected
			// channel (Talk room token / email recipient / webhook URL) — an
			// empty value is meaningful for talk/email (owner-scoped fallback)
			// but omitted rather than sent as ''.
			if (
				['talk', 'email', 'webhook'].includes(this.form.deliver)
				&& this.form.deliverTarget
			) {
				payload.deliverTarget = this.form.deliverTarget
			}
			const times = Number(this.form.repeatTimes)
			if (times >= 1) {
				payload.repeat = { times, completed: 0 }
			}
			// Human-approval gate (human-approval-gate-ui). An empty reviewer means
			// the dispatcher defaults the reviewer to the schedule owner.
			payload.requiresApproval = this.form.requiresApproval
			if (this.form.requiresApproval) {
				payload.reviewer = this.form.reviewer || ''
				payload.reviewerType = this.form.reviewerType || 'user'
			}
			// Retry policy (run-reliability). Off by default: when retryEnabled is
			// false the payload carries none of these keys, so it stays byte-for-byte
			// identical to the pre-run-reliability payload (backward compatible).
			if (this.form.retryEnabled) {
				payload.retryEnabled = true
				payload.retryMaxAttempts = Number(this.form.retryMaxAttempts) || 3
				payload.retryBackoffBaseSeconds =
					Number(this.form.retryBackoffBaseSeconds) || 60
				payload.circuitBreakerThreshold =
					Number(this.form.circuitBreakerThreshold) || 3
			}
			// Preserve the object id on edit so saveObject issues a PUT.
			if (this.schedule && this.schedule.id) {
				payload.id = this.schedule.id
			}
			return payload
		},

		/**
		 * Persist the schedule via the createObjectStore and notify the parent.
		 *
		 * @return {Promise<void>}
		 */
		async save() {
			this.saving = true
			this.error = ''
			try {
				const saved = await this.store.saveObject(
					'schedule',
					this.buildPayload(),
				)
				if (saved === null) {
					this.error =
						this.store.errors?.schedule?.message
						|| this.t('hermiq', 'Could not save schedule')
					return
				}
				this.$emit('saved', saved)
				this.$emit('close')
			} catch (e) {
				this.error = e?.message || this.t('hermiq', 'Unknown error')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.schedule-form {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 20px;
}

.schedule-form__title {
	margin: 0 0 4px;
	font-size: 20px;
	font-weight: 600;
}

.schedule-form__field {
	display: flex;
	flex-direction: column;
}

.schedule-form__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 8px;
}

.schedule-form__estimate {
	margin: 0 0 8px;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.schedule-form__estimate--empty {
	font-style: italic;
}
</style>
