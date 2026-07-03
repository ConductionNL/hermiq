<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  EmailField — scaffold demo for kind: "form-field" registry entries.

  Registers with appliesTo: { format: "email" } so the form renderer
  automatically substitutes this component for any JSON Schema property
  with format: "email".

  Replace or delete when scaffolding a new app. To add your own
  form-field:

  1. Copy this file to src/formFields/<YourField>.vue.
  2. Register it in src/registry.js with kind: "form-field" and the
     appropriate appliesTo.format or appliesTo.property.
  3. The form renderer will auto-bind it to matching properties.

  @spec openspec/changes/scaffold-v2/specs/scaffold-v2/spec.md
-->
<template>
	<div class="email-field">
		<label :for="fieldId" class="email-field__label">
			{{ label }}
		</label>
		<input
			:id="fieldId"
			type="email"
			class="email-field__input"
			:value="value"
			:placeholder="placeholder"
			@input="$emit('input', $event.target.value)">
	</div>
</template>

<script>
let _counter = 0

export default {
	name: 'EmailField',

	props: {
		/** Current field value. */
		value: {
			type: String,
			default: '',
		},
		/** Field label. */
		label: {
			type: String,
			default: 'Email',
		},
		/** Placeholder text. */
		placeholder: {
			type: String,
			default: 'example@domain.com',
		},
	},

	emits: ['input'],

	data() {
		_counter += 1
		return {
			fieldId: `email-field-${_counter}`,
		}
	},
}
</script>

<style scoped>
.email-field {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline, 4px);
}

.email-field__label {
	font-weight: 600;
	font-size: 0.875rem;
}

.email-field__input {
	width: 100%;
	padding: var(--default-grid-baseline, 4px) calc(var(--default-grid-baseline, 4px) * 2);
	border: 1px solid var(--color-border-dark, #ccc);
	border-radius: var(--border-radius, 4px);
}
</style>
