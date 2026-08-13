<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  ApprovalDenyModal — deny a pending approval with an optional reason
  (human-approval-gate-ui).

  Own file per ADR-004 modal-isolation. Calls the guarded deny endpoint via the
  plain approvals API helper (src/api/approvals.js); the gated run never executes.
  The parent ApprovalInbox opens it for one row and reloads on `denied`.

  @spec openspec/changes/human-approval-gate-ui/tasks.md#task-3-1
  @spec openspec/changes/human-approval-gate-ui/specs/human-approval-gate-ui/spec.md
-->
<template>
	<NcModal :show="show" size="small" :name="heading" @close="$emit('close')">
		<div class="approval-deny">
			<h2 class="approval-deny__title">
				{{ heading }}
			</h2>

			<p class="approval-deny__subtitle">
				{{ scheduleName }}
			</p>

			<NcNoteCard
				v-if="error"
				type="error"
				:heading="t('hermiq', 'Could not deny approval')">
				{{ error }}
			</NcNoteCard>

			<NcTextArea
				v-model="reason"
				:label="t('hermiq', 'Reason (optional)')"
				:placeholder="t('hermiq', 'Why is this run being denied?')"
				resize="vertical" />

			<div class="approval-deny__actions">
				<NcButton :disabled="saving" @click="$emit('close')">
					{{ t('hermiq', 'Cancel') }}
				</NcButton>
				<NcButton type="error" :disabled="saving" @click="confirm">
					<template v-if="saving" #icon>
						<NcLoadingIcon :size="20" />
					</template>
					{{ t('hermiq', 'Deny') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import {
	NcButton,
	NcLoadingIcon,
	NcModal,
	NcNoteCard,
	NcTextArea,
} from '@nextcloud/vue'
import { denyApproval } from '../api/approvals.js'

export default {
	name: 'ApprovalDenyModal',

	components: {
		NcButton,
		NcLoadingIcon,
		NcModal,
		NcNoteCard,
		NcTextArea,
	},

	props: {
		/** Whether the modal is visible. */
		show: {
			type: Boolean,
			default: false,
		},
		/** The pending approval being denied, or null. */
		approval: {
			type: Object,
			default: null,
		},
	},

	emits: ['close', 'denied'],

	data() {
		return {
			reason: '',
			saving: false,
			error: '',
		}
	},

	computed: {
		/**
		 * Modal heading.
		 *
		 * @return {string} The localised heading.
		 */
		heading() {
			return this.t('hermiq', 'Deny approval')
		},

		/**
		 * The gated schedule's name, for context.
		 *
		 * @return {string} The schedule name, or an empty string.
		 */
		scheduleName() {
			return (
				(this.approval && this.approval.scheduleName)
				|| (this.approval && this.approval.prompt)
				|| ''
			)
		},
	},

	watch: {
		show(open) {
			if (open) {
				this.reason = ''
				this.error = ''
			}
		},
	},

	methods: {
		/**
		 * Deny the approval via the guarded endpoint and notify the parent.
		 *
		 * @return {Promise<void>}
		 */
		async confirm() {
			if (!this.approval || !this.approval.id) {
				return
			}
			this.saving = true
			this.error = ''
			try {
				await denyApproval(this.approval.id, this.reason)
				this.$emit('denied', this.approval.id)
				this.$emit('close')
			} catch (e) {
				this.error =
					e?.response?.data?.error
					|| e?.message
					|| this.t('hermiq', 'Unknown error')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.approval-deny {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 20px;
}

.approval-deny__title {
	margin: 0;
	font-size: 20px;
	font-weight: 600;
}

.approval-deny__subtitle {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.approval-deny__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 8px;
}
</style>
