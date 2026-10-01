<!--
  SPDX-License-Identifier: EUPL-1.2
  Copyright (C) 2026 Conduction B.V.

  StartFieldsForm (agents-instruction-variables): the fields an agent asks a
  person to fill in before a session's first message. Shown above the chat
  composer until the session has its answers. A required field is marked, and
  a field the server refused shows the reason under it.

  @spec openspec/specs/agent-management-ui/spec.md#requirement-an-agent-can-ask-for-fields-before-a-conversation-starts-req-agvar-002
-->
<template>
	<fieldset class="start-fields" data-testid="chat-start-fields">
		<legend class="start-fields__legend">
			{{ t('hermiq', 'Before you start') }}
		</legend>
		<div
			v-for="field in fields"
			:key="field.key"
			class="start-fields__field"
			:data-testid="`chat-start-field-${field.key}`">
			<NcSelect
				v-if="field.type === 'select'"
				:modelValue="modelValue[field.key] || null"
				:inputLabel="labelFor(field)"
				:options="field.options"
				:clearable="!field.required"
				:required="field.required"
				@update:modelValue="update(field.key, $event)" />
			<NcTextArea
				v-else-if="field.type === 'paragraph'"
				:modelValue="modelValue[field.key] || ''"
				:label="labelFor(field)"
				:required="field.required"
				resize="vertical"
				@update:modelValue="update(field.key, $event)" />
			<NcTextField
				v-else
				:modelValue="modelValue[field.key] || ''"
				:type="inputType(field.type)"
				:label="labelFor(field)"
				:required="field.required"
				:error="Boolean(problems[field.key])"
				@update:modelValue="update(field.key, $event)" />
			<p v-if="problems[field.key]" class="start-fields__problem" role="alert">
				{{ problemText(problems[field.key]) }}
			</p>
		</div>
	</fieldset>
</template>

<script>
import { NcSelect, NcTextArea, NcTextField } from '@nextcloud/vue'

export default {
	name: 'StartFieldsForm',

	components: {
		NcSelect,
		NcTextArea,
		NcTextField,
	},

	props: {
		/** The agent's start fields, from startFieldsOf(). */
		fields: {
			type: Array,
			required: true,
		},

		/** The answers per field key. */
		modelValue: {
			type: Object,
			required: true,
		},

		/** The server's reason per field key, when it refused an answer. */
		problems: {
			type: Object,
			default: () => ({}),
		},
	},

	emits: ['update:modelValue'],

	methods: {
		/**
		 * The field's label, marked when it is required.
		 *
		 * @param {object} field The field.
		 * @return {string}
		 * @spec openspec/specs/agent-management-ui/spec.md#requirement-an-agent-can-ask-for-fields-before-a-conversation-starts-req-agvar-002
		 */
		labelFor(field) {
			if (field.required) {
				return this.t('hermiq', '{label} (required)', { label: field.label })
			}
			return field.label
		},

		/**
		 * The input type for a one-line field.
		 *
		 * @param {string} type The field type.
		 * @return {string}
		 * @spec openspec/specs/agent-management-ui/spec.md#requirement-an-agent-can-ask-for-fields-before-a-conversation-starts-req-agvar-002
		 */
		inputType(type) {
			if (type === 'number' || type === 'date') {
				return type
			}
			return 'text'
		},

		/**
		 * The server's reason in words a person reads.
		 *
		 * @param {string} reason The reason.
		 * @return {string}
		 * @spec openspec/specs/agent-management-ui/spec.md#requirement-an-agent-can-ask-for-fields-before-a-conversation-starts-req-agvar-002
		 */
		problemText(reason) {
			switch (reason) {
				case 'required':
					return this.t('hermiq', 'Fill this in to start.')
				case 'not one of the options':
					return this.t('hermiq', 'Pick one of the options.')
				case 'not a number':
					return this.t('hermiq', 'Enter a number.')
				case 'not a date (YYYY-MM-DD)':
					return this.t('hermiq', 'Enter a date.')
				default:
					return reason
			}
		},

		/**
		 * Emit the answers with one field changed.
		 *
		 * @param {string} key The field key.
		 * @param {string|null} value The new answer.
		 * @return {void}
		 * @spec openspec/specs/agent-management-ui/spec.md#requirement-an-agent-can-ask-for-fields-before-a-conversation-starts-req-agvar-002
		 */
		update(key, value) {
			this.$emit('update:modelValue', {
				...this.modelValue,
				[key]: value === null || value === undefined ? '' : String(value),
			})
		},
	},
}
</script>

<style scoped>
.start-fields {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin: 0 0 8px;
	padding: 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.start-fields__legend {
	padding: 0 4px;
	font-weight: bold;
}

.start-fields__problem {
	margin: 4px 0 0;
	color: var(--color-error-text);
}
</style>
