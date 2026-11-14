<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  ToolCallMarkModal — mark one recorded tool call of an agent as misuse, with a reason
  (verify-row-scenarios, row ov-tool-oversight).

  Own file per ADR-004 modal-isolation. The reason is required: the Mark button stays
  disabled until one is entered, and the server refuses a mark without one as well. The
  server records the mark, with the operator and the time, on the agent, so the agent's
  audit trail holds it. The parent ToolInvocationTable opens it for one row and shows the
  returned mark on `marked`.

  @spec openspec/changes/verify-row-scenarios/specs/agent-tool-governance/spec.md#scenario-an-operator-marks-a-tool-call-as-misuse
-->
<template>
	<NcModal :show="show" size="small" :name="t('hermiq', 'Mark tool call')" @close="$emit('close')">
		<div class="tool-call-mark">
			<h2 class="tool-call-mark__title">
				{{ t('hermiq', 'Mark tool call') }}
			</h2>

			<p class="tool-call-mark__subtitle">
				{{ toolLabel }}
			</p>

			<NcNoteCard
				v-if="error"
				type="error"
				:heading="t('hermiq', 'Could not mark the tool call')">
				{{ error }}
			</NcNoteCard>

			<NcTextArea
				v-model="reason"
				:label="t('hermiq', 'Reason')"
				:helperText="t('hermiq', 'Why is this call misuse? A mark needs a reason.')"
				resize="vertical" />

			<div class="tool-call-mark__actions">
				<NcButton :disabled="saving" @click="$emit('close')">
					{{ t('hermiq', 'Cancel') }}
				</NcButton>
				<NcButton variant="primary" :disabled="saving || !hasReason" @click="confirm">
					<template v-if="saving" #icon>
						<NcLoadingIcon :size="20" />
					</template>
					{{ t('hermiq', 'Mark') }}
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
import { markToolCall } from '../api/toolOversight.js'
import { errorMessage } from '../utils/errorMessage.js'

export default {
	name: 'ToolCallMarkModal',

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

		/** The agent UUID whose call is marked. */
		agentId: {
			type: String,
			required: true,
		},

		/** The tool activity row being marked, or null. */
		row: {
			type: Object,
			default: null,
		},
	},

	emits: ['close', 'marked'],

	data() {
		return {
			reason: '',
			saving: false,
			error: '',
		}
	},

	computed: {
		/**
		 * Whether a reason other than whitespace was entered.
		 *
		 * @return {boolean} True when the mark may be sent.
		 * @spec openspec/changes/verify-row-scenarios/specs/agent-tool-governance/spec.md#scenario-an-operator-marks-a-tool-call-as-misuse
		 */
		hasReason() {
			return this.reason.trim() !== ''
		},

		/**
		 * The tool the marked call used, for context.
		 *
		 * @return {string} The tool id, or an empty string.
		 * @spec openspec/changes/verify-row-scenarios/specs/agent-tool-governance/spec.md#scenario-an-operator-marks-a-tool-call-as-misuse
		 */
		toolLabel() {
			return (this.row && this.row.toolId) || ''
		},
	},

	watch: {
		/**
		 * Start every opening with an empty reason and no error.
		 *
		 * @param {boolean} open Whether the modal opened.
		 * @spec openspec/changes/verify-row-scenarios/specs/agent-tool-governance/spec.md#scenario-an-operator-marks-a-tool-call-as-misuse
		 */
		show(open) {
			if (open) {
				this.reason = ''
				this.error = ''
			}
		},
	},

	methods: {
		/**
		 * Send the mark and hand the recorded mark to the parent.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/verify-row-scenarios/specs/agent-tool-governance/spec.md#scenario-an-operator-marks-a-tool-call-as-misuse
		 */
		async confirm() {
			if (!this.row || !this.row.callId || !this.hasReason) {
				return
			}
			this.saving = true
			this.error = ''
			try {
				const mark = await markToolCall(this.agentId, this.row.callId, this.reason.trim())
				this.$emit('marked', mark)
				this.$emit('close')
			} catch (e) {
				this.error = errorMessage(e, this.t('hermiq', 'Unknown error'))
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.tool-call-mark {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 20px;
}

.tool-call-mark__title {
	margin: 0;
	font-size: 20px;
	font-weight: 600;
}

.tool-call-mark__subtitle {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-family: monospace;
}

.tool-call-mark__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 8px;
}
</style>
