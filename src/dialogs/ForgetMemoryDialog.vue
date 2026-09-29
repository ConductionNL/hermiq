<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  - SPDX-License-Identifier: EUPL-1.2
-->

<template>
	<NcDialog
		:name="t('hermiq', 'Forget this fact?')"
		:open="true"
		size="small"
		@update:open="$emit('close')">
		<p class="forget-memory__text">
			{{ text }}
		</p>
		<p>
			{{
				t(
					'hermiq',
					'The agent stops using this fact. It stays in the memory history, marked as forgotten.',
				)
			}}
		</p>

		<template #actions>
			<NcButton variant="tertiary" :disabled="busy" @click="$emit('close')">
				{{ t('hermiq', 'Cancel') }}
			</NcButton>
			<NcButton variant="error" :disabled="busy" @click="$emit('confirm')">
				{{ t('hermiq', 'Forget') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog } from '@nextcloud/vue'

/**
 * ForgetMemoryDialog: the confirmation before an owner makes an agent forget one
 * fact. Emits `confirm` or `close`; the caller does the request.
 *
 * @spec openspec/changes/memory-correct-and-forget/specs/agent-memory/spec.md#requirement-an-owner-can-make-an-agent-forget-a-fact-req-memedit-002
 */
export default {
	name: 'ForgetMemoryDialog',

	components: {
		NcButton,
		NcDialog,
	},

	props: {
		/** The text of the fact that will be forgotten. */
		text: {
			type: String,
			required: true,
		},

		/** True while the request runs. */
		busy: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['close', 'confirm'],
}
</script>

<style scoped>
.forget-memory__text {
	font-weight: 600;
	margin-bottom: 8px;
}
</style>
