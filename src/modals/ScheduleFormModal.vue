<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  ScheduleFormModal — attach or edit a Schedule for an agent (agent-management-ui).

  Own file per ADR-004 modal-isolation. Persists a `hermiq`/`schedule` OpenRegister
  object through the createObjectStore in src/store/store.js (no bespoke store, no
  bespoke CRUD backend). Every NcSelect carries an `inputLabel` (nc-input-labels
  accessibility gate; WCAG 2.1 AA). Fields mirror the Schedule schema: kind,
  cronExpr, intervalMinutes, runAt, prompt, deliver, deliverTarget, enabled, repeat.

  @spec openspec/changes/agent-management-ui/tasks.md#task-5-2
  @spec openspec/changes/agent-management-ui/specs/agent-management-ui/spec.md
-->
<template>
	<NcModal
		:show="show"
		size="normal"
		:name="heading"
		@close="$emit('close')">
		<div class="schedule-form">
			<h2 class="schedule-form__title">
				{{ heading }}
			</h2>

			<NcNoteCard v-if="error" type="error" :heading="t('hermiq', 'Could not save schedule')">
				{{ error }}
			</NcNoteCard>

			<NcTextField
				:value.sync="form.name"
				:label="t('hermiq', 'Name')"
				:placeholder="t('hermiq', 'Daily briefing')"
				required />

			<div class="schedule-form__field">
				<NcSelect
					v-model="kindOption"
					:input-label="t('hermiq', 'Trigger')"
					:options="kindOptions"
					:clearable="false"
					label="label"
					track-by="value" />
			</div>

			<NcTextField
				v-if="form.kind === 'cron'"
				:value.sync="form.cronExpr"
				:label="t('hermiq', 'Cron expression')"
				placeholder="0 8 * * *" />

			<NcTextField
				v-if="form.kind === 'interval'"
				type="number"
				:value.sync="form.intervalMinutes"
				:label="t('hermiq', 'Interval (minutes)')"
				placeholder="1440" />

			<NcTextField
				v-if="form.kind === 'once'"
				type="datetime-local"
				:value.sync="form.runAt"
				:label="t('hermiq', 'Run at')" />

			<NcTextArea
				:value.sync="form.prompt"
				:label="t('hermiq', 'Prompt')"
				:placeholder="t('hermiq', 'Task text passed to the agent run')"
				resize="vertical" />

			<div class="schedule-form__field">
				<NcSelect
					v-model="deliverOption"
					:input-label="t('hermiq', 'Deliver output to')"
					:options="deliverOptions"
					:clearable="false"
					label="label"
					track-by="value" />
			</div>

			<NcTextField
				v-if="form.deliver === 'talk'"
				:value.sync="form.deliverTarget"
				:label="t('hermiq', 'Talk room token')"
				:placeholder="t('hermiq', 'Leave empty for Note-to-self')" />

			<NcTextField
				type="number"
				:value.sync="form.repeatTimes"
				:label="t('hermiq', 'Repeat times (empty = forever)')"
				placeholder="" />

			<NcCheckboxRadioSwitch :checked.sync="form.enabled" type="switch">
				{{ t('hermiq', 'Enabled') }}
			</NcCheckboxRadioSwitch>

			<div class="schedule-form__actions">
				<NcButton :disabled="saving" @click="$emit('close')">
					{{ t('hermiq', 'Cancel') }}
				</NcButton>
				<NcButton
					type="primary"
					:disabled="saving || !form.name"
					@click="save">
					<template v-if="saving" #icon>
						<NcLoadingIcon :size="20" />
					</template>
					{{ t('hermiq', 'Save') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { NcButton, NcCheckboxRadioSwitch, NcLoadingIcon, NcModal, NcNoteCard, NcSelect, NcTextArea, NcTextField } from '@nextcloud/vue'
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
			kindOptions: [
				{ label: this.t('hermiq', 'Once'), value: 'once' },
				{ label: this.t('hermiq', 'Interval'), value: 'interval' },
				{ label: this.t('hermiq', 'Cron'), value: 'cron' },
			],
			deliverOptions: [
				{ label: this.t('hermiq', 'Nextcloud Talk'), value: 'talk' },
				{ label: this.t('hermiq', 'Notification'), value: 'notification' },
				{ label: this.t('hermiq', 'None'), value: 'none' },
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
			return this.schedule ? this.t('hermiq', 'Edit schedule') : this.t('hermiq', 'Attach schedule')
		},

		/**
		 * Two-way bridge between the `kind` string and the NcSelect option object.
		 */
		kindOption: {
			get() {
				return this.kindOptions.find((option) => option.value === this.form.kind) || this.kindOptions[0]
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
				return this.deliverOptions.find((option) => option.value === this.form.deliver) || this.deliverOptions[2]
			},
			set(option) {
				this.form.deliver = option ? option.value : 'none'
			},
		},
	},

	watch: {
		show(open) {
			if (open) {
				this.resetForm()
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
				repeatTimes: source.repeat && source.repeat.times ? source.repeat.times : '',
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
			if (this.form.deliver === 'talk' && this.form.deliverTarget) {
				payload.deliverTarget = this.form.deliverTarget
			}
			const times = Number(this.form.repeatTimes)
			if (times >= 1) {
				payload.repeat = { times, completed: 0 }
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
				const saved = await this.store.saveObject('schedule', this.buildPayload())
				if (saved === null) {
					this.error = this.store.errors?.schedule?.message
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
</style>
