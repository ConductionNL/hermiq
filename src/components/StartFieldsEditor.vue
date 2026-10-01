<!--
  SPDX-License-Identifier: EUPL-1.2
  Copyright (C) 2026 Conduction B.V.

  StartFieldsEditor (agents-instruction-variables): on the agent form, the
  fields a person fills in before a conversation starts. At most ten; each has
  a label, a key used as {{field.<key>}} in the instructions, a type, options
  for a choice, whether it is required and a default.

  @spec openspec/specs/agent-management-ui/spec.md#requirement-an-agent-can-ask-for-fields-before-a-conversation-starts-req-agvar-002
-->
<template>
	<fieldset class="start-fields-editor" data-testid="agent-form-start-fields">
		<legend class="start-fields-editor__legend">
			{{ t('hermiq', 'Questions before a conversation') }}
		</legend>
		<p class="start-fields-editor__hint">
			{{ hint }}
		</p>
		<div
			v-for="(field, index) in modelValue"
			:key="index"
			class="start-fields-editor__row"
			:data-testid="`agent-form-start-field-${index}`">
			<NcTextField
				:modelValue="field.label"
				:label="t('hermiq', 'Question')"
				@update:modelValue="change(index, { label: $event })" />
			<NcTextField
				:modelValue="field.key"
				:label="t('hermiq', 'Placeholder name')"
				:error="!keyIsValid(field.key)"
				:helperText="
					keyIsValid(field.key)
						? fieldToken(field.key)
						: t(
								'hermiq',
								'Lower case letters, digits and _, starting with a letter.',
							)
				"
				@update:modelValue="change(index, { key: $event })" />
			<NcSelect
				:modelValue="typeOption(field.type)"
				:inputLabel="t('hermiq', 'Answer type')"
				:options="typeOptions"
				:clearable="false"
				label="label"
				trackBy="value"
				@update:modelValue="
					change(index, { type: $event?.value || 'text' })
				" />
			<NcTextField
				v-if="field.type === 'select'"
				:modelValue="(field.options || []).join(', ')"
				:label="t('hermiq', 'Options, separated by commas')"
				@update:modelValue="
					change(index, { options: splitOptions($event) })
				" />
			<NcTextField
				:modelValue="field.default || ''"
				:label="t('hermiq', 'Default answer')"
				@update:modelValue="change(index, { default: $event })" />
			<div class="start-fields-editor__row-actions">
				<NcCheckboxRadioSwitch
					:modelValue="field.required === true"
					@update:modelValue="change(index, { required: $event })">
					{{ t('hermiq', 'Required') }}
				</NcCheckboxRadioSwitch>
				<NcButton variant="tertiary" @click="remove(index)">
					{{ t('hermiq', 'Remove question') }}
				</NcButton>
			</div>
		</div>
		<NcButton :disabled="modelValue.length >= maxFields" @click="add">
			{{ t('hermiq', 'Add a question') }}
		</NcButton>
	</fieldset>
</template>

<script>
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcSelect,
	NcTextField,
} from '@nextcloud/vue'
import { KEY_PATTERN, MAX_FIELDS } from '../utils/instructionVariables.js'

export default {
	name: 'StartFieldsEditor',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcSelect,
		NcTextField,
	},

	props: {
		/** The fields being edited. */
		modelValue: {
			type: Array,
			required: true,
		},
	},

	emits: ['update:modelValue'],

	computed: {
		/** @return {string} How an answer is used in the instructions. */
		hint() {
			return this.t(
				'hermiq',
				'A person answers these before their first message. Use an answer in the instructions as {placeholder}.',
				{ placeholder: this.fieldToken('key') },
			)
		},

		/** @return {number} The most fields an agent can ask. */
		maxFields() {
			return MAX_FIELDS
		},

		/** @return {Array<{label: string, value: string}>} The answer types. */
		typeOptions() {
			return [
				{ label: this.t('hermiq', 'Short text'), value: 'text' },
				{ label: this.t('hermiq', 'Long text'), value: 'paragraph' },
				{ label: this.t('hermiq', 'Choice'), value: 'select' },
				{ label: this.t('hermiq', 'Number'), value: 'number' },
				{ label: this.t('hermiq', 'Date'), value: 'date' },
			]
		},
	},

	methods: {
		/**
		 * The placeholder for an answer, as written in the instructions.
		 *
		 * @param {string} key The field key.
		 * @return {string}
		 */
		fieldToken(key) {
			return `{{field.${key}}}`
		},

		/**
		 * The option for a type.
		 *
		 * @param {string} type The type.
		 * @return {object}
		 */
		typeOption(type) {
			return (
				this.typeOptions.find((option) => option.value === type)
				|| this.typeOptions[0]
			)
		},

		/**
		 * Whether a key can be used as a placeholder name.
		 *
		 * @param {string} key The key.
		 * @return {boolean}
		 */
		keyIsValid(key) {
			return KEY_PATTERN.test(String(key || ''))
		},

		/**
		 * Options typed as one comma-separated line.
		 *
		 * @param {string} text The line.
		 * @return {string[]}
		 */
		splitOptions(text) {
			return String(text || '')
				.split(',')
				.map((option) => option.trim())
				.filter((option) => option !== '')
		},

		/**
		 * Emit the fields with one changed.
		 *
		 * @param {number} index The field.
		 * @param {object} patch The changed properties.
		 * @return {void}
		 */
		change(index, patch) {
			const fields = this.modelValue.map((field, i) =>
				i === index ? { ...field, ...patch } : field,
			)
			this.$emit('update:modelValue', fields)
		},

		/** @return {void} Add an empty short-text question. */
		add() {
			const key = `field_${this.modelValue.length + 1}`
			this.$emit('update:modelValue', [
				...this.modelValue,
				{
					key,
					label: '',
					type: 'text',
					options: [],
					required: false,
					default: '',
				},
			])
		},

		/**
		 * Remove a question.
		 *
		 * @param {number} index The field.
		 * @return {void}
		 */
		remove(index) {
			this.$emit(
				'update:modelValue',
				this.modelValue.filter((field, i) => i !== index),
			)
		},
	},
}
</script>

<style scoped>
.start-fields-editor {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.start-fields-editor__legend {
	padding: 0 4px;
	font-weight: bold;
}

.start-fields-editor__hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.start-fields-editor__row {
	display: flex;
	flex-direction: column;
	gap: 4px;
	padding-block-end: 8px;
	border-block-end: 1px solid var(--color-border-dark);
}

.start-fields-editor__row-actions {
	display: flex;
	align-items: center;
	justify-content: space-between;
}
</style>
