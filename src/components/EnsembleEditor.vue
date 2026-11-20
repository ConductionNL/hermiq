<!--
  SPDX-License-Identifier: EUPL-1.2
  Copyright (C) 2026 Conduction B.V.

  EnsembleEditor (models-several-models-per-turn): on the agent form, the
  switch "Answer with several models" and its two or three reference models,
  each a provider and model. The reference models answer first, without any
  tool; the agent's own model then merges their answers into the one reply.
  The server refuses a reference model the organisation's policy does not
  allow; the form shows that refusal.

  @spec openspec/changes/models-several-models-per-turn/specs/model-ensemble/spec.md#requirement-an-agent-owner-can-switch-on-ensemble-answers-req-mens-001
-->
<template>
	<fieldset class="ensemble-editor" data-testid="agent-form-ensemble">
		<legend class="ensemble-editor__legend">
			{{ t('hermiq', 'Several models') }}
		</legend>
		<NcCheckboxRadioSwitch
			:modelValue="modelValue.enabled"
			type="switch"
			data-testid="agent-form-ensemble-switch"
			@update:modelValue="emitChange(switchEnsemble(modelValue, $event))">
			{{ t('hermiq', 'Answer with several models') }}
		</NcCheckboxRadioSwitch>
		<template v-if="modelValue.enabled">
			<p class="ensemble-editor__hint">
				{{
					t(
						'hermiq',
						'The reference models answer first, without tools. This agent\'s own model then merges their answers into one reply, with its tools.',
					)
				}}
			</p>
			<p class="ensemble-editor__cost" data-testid="agent-form-ensemble-cost">
				{{ t('hermiq', 'Each answer costs up to four model calls.') }}
			</p>
			<div
				v-for="(row, index) in modelValue.referenceModels"
				:key="index"
				class="ensemble-editor__row"
				:data-testid="`agent-form-ensemble-reference-${index}`">
				<NcSelect
					:modelValue="providerOption(row.provider)"
					:inputLabel="t('hermiq', 'Reference provider {number}', { number: index + 1 })"
					:options="providerOptions"
					:clearable="false"
					label="label"
					trackBy="value"
					@update:modelValue="emitChange(setReference(modelValue, index, 'provider', $event?.value || ''))" />
				<NcSelect
					:modelValue="modelOption(row.model)"
					:inputLabel="t('hermiq', 'Reference model {number}', { number: index + 1 })"
					:options="modelOptionsFor(row.provider)"
					:clearable="false"
					:taggable="true"
					:createOption="(value) => ({ label: value, value })"
					label="label"
					trackBy="value"
					@update:modelValue="emitChange(setReference(modelValue, index, 'model', $event?.value || ''))" />
				<NcButton
					variant="tertiary"
					:aria-label="t('hermiq', 'Remove reference model {number}', { number: index + 1 })"
					@click="emitChange(removeReference(modelValue, index))">
					{{ t('hermiq', 'Remove') }}
				</NcButton>
			</div>
			<NcButton
				variant="secondary"
				:disabled="modelValue.referenceModels.length >= maxReferences"
				@click="emitChange(addReference(modelValue))">
				{{ t('hermiq', 'Add reference model') }}
			</NcButton>
			<p
				v-if="modelValue.referenceModels.length < minReferences"
				class="ensemble-editor__hint"
				data-testid="agent-form-ensemble-too-few">
				{{ t('hermiq', 'Pick at least two reference models.') }}
			</p>
		</template>
	</fieldset>
</template>

<script>
import { NcButton, NcCheckboxRadioSwitch, NcSelect } from '@nextcloud/vue'
import {
	addReference,
	MAX_REFERENCES,
	MIN_REFERENCES,
	removeReference,
	setReference,
	switchEnsemble,
} from '../utils/agentEnsemble.js'

export default {
	name: 'EnsembleEditor',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcSelect,
	},

	props: {
		/** The ensemble being edited: `{enabled, referenceModels}`. */
		modelValue: {
			type: Object,
			required: true,
		},

		/** The providers the policy allows, as `{label, value}` options. */
		providerOptions: {
			type: Array,
			default: () => [],
		},

		/** The models offered for a provider: `(provider) => Array<string>`. */
		modelsFor: {
			type: Function,
			default: () => [],
		},
	},

	emits: ['update:modelValue'],

	computed: {
		/**
		 * @return {number} The most reference models an ensemble names.
		 *
		 * @spec openspec/changes/models-several-models-per-turn/specs/model-ensemble/spec.md#requirement-an-agent-owner-can-switch-on-ensemble-answers-req-mens-001
		 */
		maxReferences() {
			return MAX_REFERENCES
		},

		/**
		 * @return {number} The fewest reference models an enabled ensemble names.
		 *
		 * @spec openspec/changes/models-several-models-per-turn/specs/model-ensemble/spec.md#requirement-an-agent-owner-can-switch-on-ensemble-answers-req-mens-001
		 */
		minReferences() {
			return MIN_REFERENCES
		},
	},

	methods: {
		addReference,
		removeReference,
		setReference,
		switchEnsemble,

		/**
		 * Hand the changed ensemble to the form.
		 *
		 * @param {object} ensemble The new ensemble.
		 * @return {void}
		 * @spec openspec/changes/models-several-models-per-turn/specs/model-ensemble/spec.md#requirement-an-agent-owner-can-switch-on-ensemble-answers-req-mens-001
		 */
		emitChange(ensemble) {
			this.$emit('update:modelValue', ensemble)
		},

		/**
		 * The option of a provider, or null when none is chosen.
		 *
		 * @param {string} provider The provider.
		 * @return {object|null}
		 * @spec openspec/changes/models-several-models-per-turn/specs/model-ensemble/spec.md#requirement-an-agent-owner-can-switch-on-ensemble-answers-req-mens-001
		 */
		providerOption(provider) {
			if (!provider) {
				return null
			}
			return (
				this.providerOptions.find((option) => option.value === provider)
				|| { label: provider, value: provider }
			)
		},

		/**
		 * The option of a model, or null when none is chosen.
		 *
		 * @param {string} model The model.
		 * @return {object|null}
		 * @spec openspec/changes/models-several-models-per-turn/specs/model-ensemble/spec.md#requirement-an-agent-owner-can-switch-on-ensemble-answers-req-mens-001
		 */
		modelOption(model) {
			return model ? { label: model, value: model } : null
		},

		/**
		 * The models offered for a provider, as options.
		 *
		 * @param {string} provider The provider.
		 * @return {Array<{label: string, value: string}>}
		 * @spec openspec/changes/models-several-models-per-turn/specs/model-ensemble/spec.md#requirement-an-agent-owner-can-switch-on-ensemble-answers-req-mens-001
		 */
		modelOptionsFor(provider) {
			return (this.modelsFor(provider) || []).map((model) => ({ label: model, value: model }))
		},
	},
}
</script>

<style scoped>
.ensemble-editor {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 8px 12px;
	margin: 8px 0;
}

.ensemble-editor__legend {
	font-weight: bold;
}

.ensemble-editor__hint,
.ensemble-editor__cost {
	color: var(--color-text-maxcontrast);
	margin: 4px 0 8px;
}

.ensemble-editor__row {
	display: flex;
	gap: 8px;
	align-items: flex-end;
	flex-wrap: wrap;
	margin-bottom: 8px;
}
</style>
