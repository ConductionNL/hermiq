<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  EvalScheduleFormModal — "Run on a schedule" (reuse-scheduled-bias-evals,
  REQ-BIASEVAL-003): pick an agent, how often the dataset runs against it and how
  many points the pass rate may drop before the owner is told. Own file per
  ADR-004 modal-isolation; the panel saves through src/api/evals.js.

  @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-dataset-can-run-against-an-agent-on-a-schedule-req-biaseval-003
-->
<template>
	<NcModal
		:show="true"
		size="normal"
		:name="t('hermiq', 'Run on a schedule')"
		@close="$emit('close')">
		<form class="eval-schedule-form" @submit.prevent="submit">
			<h2 class="eval-schedule-form__title">
				{{ t('hermiq', 'Run on a schedule') }}
			</h2>

			<NcSelect
				v-model="agent"
				:inputLabel="t('hermiq', 'Agent')"
				:options="agentOptions"
				label="label"
				trackBy="value"
				:disabled="Boolean(schedule)"
				:placeholder="t('hermiq', 'Select an agent')" />

			<fieldset class="eval-schedule-form__frequency">
				<legend>{{ t('hermiq', 'How often') }}</legend>
				<NcCheckboxRadioSwitch
					v-for="option in frequencyOptions"
					:key="option.value"
					v-model="frequency"
					:value="option.value"
					name="eval-schedule-frequency"
					type="radio">
					{{ option.label }}
				</NcCheckboxRadioSwitch>
			</fieldset>

			<NcTextField
				v-model="threshold"
				type="number"
				min="0"
				max="100"
				:label="t('hermiq', 'Tell me when the pass rate drops more than this many points')"
				:placeholder="t('hermiq', 'Default')" />

			<NcCheckboxRadioSwitch v-model="enabled" type="switch">
				{{ t('hermiq', 'On') }}
			</NcCheckboxRadioSwitch>

			<div class="eval-schedule-form__actions">
				<NcButton
					variant="primary"
					type="submit"
					:disabled="busy || !agent">
					{{ t('hermiq', 'Save schedule') }}
				</NcButton>
				<NcButton variant="tertiary" :disabled="busy" @click="$emit('close')">
					{{ t('hermiq', 'Cancel') }}
				</NcButton>
			</div>
		</form>
	</NcModal>
</template>

<script>
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcModal,
	NcSelect,
	NcTextField,
} from '@nextcloud/vue'
import { frequencyOf, scheduleBody } from '../utils/evalSchedules.js'

export default {
	name: 'EvalScheduleFormModal',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcModal,
		NcSelect,
		NcTextField,
	},

	props: {
		/** The caller's agents as { label, value } options. */
		agentOptions: {
			type: Array,
			default: () => [],
		},

		/** The schedule being changed; null adds one. */
		schedule: {
			type: Object,
			default: null,
		},

		/** Whether a save is in flight. */
		busy: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['close', 'save'],

	data() {
		const schedule = this.schedule || {}
		return {
			agent: this.agentOptions.find((option) => option.value === schedule.agentId) || null,
			frequency: frequencyOf(schedule.cronExpr),
			threshold: schedule.regressionThresholdPercent ?? '',
			enabled: schedule.enabled !== false,
		}
	},

	computed: {
		/**
		 * The frequencies offered; a custom expression is offered only when the schedule has one.
		 *
		 * @return {Array<object>} { value, label } options.
		 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-dataset-can-run-against-an-agent-on-a-schedule-req-biaseval-003
		 */
		frequencyOptions() {
			const options = [
				{ value: 'monthly', label: this.t('hermiq', 'Monthly') },
				{ value: 'weekly', label: this.t('hermiq', 'Weekly') },
				{ value: 'daily', label: this.t('hermiq', 'Daily') },
			]
			if (frequencyOf(this.schedule?.cronExpr) === 'custom') {
				options.push({ value: 'custom', label: this.t('hermiq', 'Keep the current schedule ({cron})', { cron: this.schedule.cronExpr }) })
			}
			return options
		},
	},

	methods: {
		/**
		 * Hand the request body to the panel.
		 *
		 * @return {void}
		 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-dataset-can-run-against-an-agent-on-a-schedule-req-biaseval-003
		 */
		submit() {
			if (!this.agent) {
				return
			}
			this.$emit('save', scheduleBody({
				agentId: this.agent.value,
				frequency: this.frequency,
				enabled: this.enabled,
				threshold: this.threshold,
			}, this.schedule?.cronExpr || ''))
		},
	},
}
</script>

<style scoped>
.eval-schedule-form {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 16px;
}

.eval-schedule-form__title {
	margin: 0;
}

.eval-schedule-form__frequency {
	border: none;
	margin: 0;
	padding: 0;
}

.eval-schedule-form__actions {
	display: flex;
	gap: 8px;
}
</style>
