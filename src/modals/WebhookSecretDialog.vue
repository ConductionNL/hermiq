<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  WebhookSecretDialog — copy-once secret reveal for a just-created/just-rotated
  agent webhook secret (agent-webhook-trigger).

  Own file per ADR-004 modal-isolation. Shows the PLAINTEXT secret exactly once:
  the parent (AgentDetail) holds the secret only transiently (cleared on close)
  and never re-opens this dialog to re-reveal a past secret — the webhook panel
  itself only ever shows the masked secretPrefix afterward.

  @spec openspec/changes/archive/2026-07-12-agent-webhook-trigger/tasks.md#task-7-agentdetail-webhook-panel-copy-once-secret-dialog
  @spec openspec/specs/agent-management-ui/spec.md
-->
<template>
	<NcModal
		:show="show"
		size="normal"
		:name="t('hermiq', 'Webhook secret')"
		@close="$emit('close')">
		<div class="webhook-secret-dialog">
			<h2 class="webhook-secret-dialog__title">
				{{ t('hermiq', 'Webhook secret') }}
			</h2>

			<NcNoteCard type="warning">
				{{
					t(
						'hermiq',
						'This secret is shown only once. Copy it now — it cannot be displayed again.',
					)
				}}
			</NcNoteCard>

			<NcTextField
				:modelValue="secret"
				readonly
				:label="t('hermiq', 'Secret')"
				@focus="selectAll" />

			<p class="webhook-secret-dialog__hint">
				{{
					t(
						'hermiq',
						'Send this value in the {header} header when calling the trigger endpoint.',
						{ header: 'X-Hermiq-Webhook-Secret' },
					)
				}}
			</p>

			<div class="webhook-secret-dialog__actions">
				<NcButton type="primary" @click="copy">
					<template #icon>
						<ContentCopy :size="20" />
					</template>
					{{ t('hermiq', 'Copy to clipboard') }}
				</NcButton>
				<NcButton @click="$emit('close')">
					{{ t('hermiq', 'Done') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { NcButton, NcModal, NcNoteCard, NcTextField } from '@nextcloud/vue'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import { copySecret, selectAll } from './secretReveal.js'

export default {
	name: 'WebhookSecretDialog',

	components: {
		ContentCopy,
		NcButton,
		NcModal,
		NcNoteCard,
		NcTextField,
	},

	props: {
		/** Whether the dialog is visible. */
		show: {
			type: Boolean,
			default: false,
		},

		/** The plaintext secret to reveal once, or '' when none is pending. */
		secret: {
			type: String,
			default: '',
		},
	},

	emits: ['close'],

	methods: {
		selectAll,

		/**
		 * Copy the secret to the clipboard.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/agent-management-ui/spec.md#requirement-agent-detail-manages-the-webhook-trigger-in-place-mvp
		 */
		copy() {
			return copySecret(this.secret)
		},
	},
}
</script>

<style scoped>
.webhook-secret-dialog {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 20px;
}

.webhook-secret-dialog__title {
	margin: 0;
	font-size: 20px;
	font-weight: 600;
}

.webhook-secret-dialog__hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.webhook-secret-dialog__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 8px;
}
</style>
