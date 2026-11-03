<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  GuardrailPolicyFields: the content filters and the per-tool classification
  of one guardrail policy draft, shared by the create and the edit form of
  GuardrailPolicySettings. It renders the fields side by side without a wrapper
  of its own and reports each change as a field and value; the parent owns
  the draft.

  @spec openspec/changes/inapp-settings-section/specs/inapp-settings-section/spec.md#requirement-guardrail-policy-administration-must-exist-in-exactly-one-place
-->
<template>
	<NcSelect
		:modelValue="actionOption(draft.inputPiiAction, piiOptions)"
		:options="piiOptions"
		:inputLabel="t('hermiq', 'Input: PII/secret action')"
		:clearable="false"
		@update:modelValue="pick('inputPiiAction', $event)" />
	<NcSelect
		:modelValue="
			actionOption(draft.inputPromptInjectionAction, injectionOptions)
		"
		:options="injectionOptions"
		:inputLabel="t('hermiq', 'Input: prompt-injection action')"
		:clearable="false"
		@update:modelValue="pick('inputPromptInjectionAction', $event)" />
	<NcSelect
		:modelValue="actionOption(draft.outputPiiAction, piiOptions)"
		:options="piiOptions"
		:inputLabel="t('hermiq', 'Output: PII/secret action')"
		:clearable="false"
		@update:modelValue="pick('outputPiiAction', $event)" />
	<NcTextArea
		:modelValue="draft.toolPolicyText"
		:label="t('hermiq', 'Per-tool classification')"
		:placeholder="t('hermiq', 'One per line: toolId: auto|confirm|deny')"
		resize="vertical"
		@update:modelValue="
			$emit('change', { field: 'toolPolicyText', value: $event })
		" />
</template>

<script>
import { NcSelect, NcTextArea } from '@nextcloud/vue'

export default {
	name: 'GuardrailPolicyFields',

	components: {
		NcSelect,
		NcTextArea,
	},

	props: {
		draft: {
			type: Object,
			required: true,
		},

		piiOptions: {
			type: Array,
			required: true,
		},

		injectionOptions: {
			type: Array,
			required: true,
		},
	},

	emits: ['change'],

	methods: {
		/**
		 * The select option for a stored action value, or the first option.
		 *
		 * @param {string} value The stored action value.
		 * @param {Array<object>} options The option list to search.
		 * @return {object} The matching option.
		 * @spec openspec/changes/inapp-settings-section/specs/inapp-settings-section/spec.md#requirement-guardrail-policy-administration-must-exist-in-exactly-one-place
		 */
		actionOption(value, options) {
			return options.find((option) => option.value === value) || options[0]
		},

		/**
		 * Report a picked action; clearing the select means off.
		 *
		 * @param {string} field The draft field.
		 * @param {object|null} option The picked option.
		 * @return {void}
		 * @spec openspec/changes/inapp-settings-section/specs/inapp-settings-section/spec.md#requirement-guardrail-policy-administration-must-exist-in-exactly-one-place
		 */
		pick(field, option) {
			this.$emit('change', { field, value: option ? option.value : 'off' })
		},
	},
}
</script>
