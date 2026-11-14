<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  EvalScheduleFormModal — "Run on a schedule" for an eval dataset: which of the
  owner's agents, how often, and how far the pass rate may drop before the owner
  is told. Own file per the modal-isolation rule; opened by EvalRunPanelWidget,
  which saves through src/api/evals.js. The server checks that the caller owns
  the dataset and the agent; this form only shapes the request.

  @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-dataset-can-run-against-an-agent-on-a-schedule-req-biaseval-003
-->
<template>
	<NcModal :name="t('hermiq', 'Run on a schedule')" @close="$emit('close')">
		<form class="eval-schedule-form-modal" @submit.prevent="submit">
			<h3>{{ t('hermiq', 'Run on a schedule') }}</h3>
			<p class="eval-schedule-form-modal__hint">
				{{
					t(
						'hermiq',
						'The dataset runs as you, through the same budget and stop controls as a run you start yourself. Every run is kept.',
					)
				}}
			</p>
			<NcSelect
				v-model="agent"
				:options="agentOptions"
				:inputLabel="t('hermiq', 'Agent')"
				:clearable="false"
				label="label"
				trackBy="value"
				:placeholder="t('hermiq', 'Select an agent')" />
			<NcSelect
				v-model="frequency"
				:options="frequencies"
				:inputLabel="t('hermiq', 'How often')"
				:clearable="false"
				label="label"
				trackBy="value" />
			<NcTextField
				v-model="threshold"
				type="number"
				min="0"
				max="100"
				:label="t('hermiq', 'Tell me when the pass rate drops by more than (percentage points)')" />
			<NcCheckboxRadioSwitch v-model="enabled" type="switch">
				{{ t('hermiq', 'Schedule is on') }}
			</NcCheckboxRadioSwitch>
			<div class="eval-schedule-form-modal__actions">
				<NcButton :disabled="saving" @click="$emit('close')">
					{{ t('hermiq', 'Cancel') }}
				</NcButton>
				<NcButton type="submit" variant="primary" :disabled="saving || !agent">
					{{ t('hermiq', 'Save') }}
				</NcButton>
			</div>
		</form>
	</NcModal>
</template>

<script>
import { NcButton, NcCheckboxRadioSwitch, NcModal, NcSelect, NcTextField } from '@nextcloud/vue'
import { frequencyOptions } from '../widgets/evalSchedules.js'

/**
 * The request a filled-in form sends.
 *
 * @param {object} form { id, agent, frequency, threshold, enabled }.
 * @return {object} { id?, agentId, cronExpr, enabled, regressionThresholdPercent }.
 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-dataset-can-run-against-an-agent-on-a-schedule-req-biaseval-003
 */
export function schedulePayload(form) {
	const threshold = String(form.threshold ?? '').trim()
	return {
		...(form.id ? { id: form.id } : {}),
		agentId: form.agent?.value || '',
		cronExpr: form.frequency?.value || '0 6 1 * *',
		enabled: form.enabled !== false,
		regressionThresholdPercent: threshold === '' ? null : Number(threshold),
	}
}

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
		/** The owner's agents as { label, value } options. */
		agentOptions: {
			type: Array,
			required: true,
		},

		/** The schedule to change, or null for a new one. */
		schedule: {
			type: Object,
			default: null,
		},

		/** Whether the parent is saving. */
		saving: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['close', 'save'],

	data() {
		const frequencies = frequencyOptions()
		const schedule = this.schedule || {}
		return {
			frequencies,
			agent: this.agentOptions.find((option) => option.value === schedule.agentId) || null,
			frequency: frequencies.find((option) => option.value === schedule.cronExpr) || frequencies[0],
			threshold: schedule.regressionThresholdPercent ?? 10,
			enabled: schedule.enabled !== false,
		}
	},

	methods: {
		/**
		 * Hand the request to the parent.
		 *
		 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-dataset-can-run-against-an-agent-on-a-schedule-req-biaseval-003
		 * @return {void}
		 */
		submit() {
			if (!this.agent) {
				return
			}
			this.$emit('save', schedulePayload({ ...this.$data, id: this.schedule?.id }))
		},
	},
}
</script>

<style scoped>
.eval-schedule-form-modal {
	padding: 20px;
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.eval-schedule-form-modal__hint {
	color: var(--color-text-maxcontrast);
}

.eval-schedule-form-modal__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
}
</style>
