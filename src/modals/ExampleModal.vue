<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  ExampleModal — scaffold demo for kind: "modal" registry entries.

  Uses NcDialog (hydra ADR-004 modal-isolation pattern). This shape — a
  simple confirm-action modal — is the canonical starting point for
  modals opened via actions[].type: "open-modal" in v2 manifests.

  Replace or delete when scaffolding a new app. To add your own modal:

  1. Copy this file to src/modals/<YourModal>.vue.
  2. Register it in src/registry.js with kind: "modal".
  3. Reference it in manifest actions via
     { type: "open-modal", target: "<your-key>", props: { ... } }.

  @spec openspec/changes/scaffold-v2/specs/scaffold-v2/spec.md
-->
<template>
	<NcDialog
		:name="title"
		:open="open"
		@update:open="$emit('update:open', $event)">
		<p>{{ message }}</p>
		<template #actions>
			<NcButton @click="onConfirm">
				{{ t('hermiq', 'Confirm') }}
			</NcButton>
			<NcButton @click="onCancel">
				{{ t('hermiq', 'Cancel') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog } from '@nextcloud/vue'

export default {
	name: 'ExampleModal',

	components: {
		NcButton,
		NcDialog,
	},

	props: {
		/** Whether the modal is open. */
		open: {
			type: Boolean,
			default: false,
		},
		/** Modal heading (i18n key resolved by the caller). */
		title: {
			type: String,
			default: 'Confirm action',
		},
		/** Modal body text. */
		message: {
			type: String,
			default: 'Are you sure?',
		},
	},

	emits: ['update:open', 'confirm', 'cancel'],

	methods: {
		/**
		 * Relay a confirmation, then request the parent close the modal.
		 *
		 * @spec openspec/specs/scaffold-components/spec.md#REQ-COMP-002
		 * @return {void}
		 */
		onConfirm() {
			this.$emit('confirm')
			this.$emit('update:open', false)
		},
		/**
		 * Relay a cancellation, then request the parent close the modal.
		 *
		 * @spec openspec/specs/scaffold-components/spec.md#REQ-COMP-002
		 * @return {void}
		 */
		onCancel() {
			this.$emit('cancel')
			this.$emit('update:open', false)
		},
	},
}
</script>
