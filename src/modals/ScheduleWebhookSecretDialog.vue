<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  ScheduleWebhookSecretDialog — mint/rotate/revoke a schedule's OUTBOUND
  webhook signing secret, with copy-once plaintext reveal (delivery-channels).

  Own file per ADR-004 modal-isolation, placed under src/modals/ (not
  src/dialogs/) to mirror the existing WebhookSecretDialog.vue (the per-agent
  INBOUND trigger secret) exactly — both are NcModal-based reveal-once secret
  dialogs; only the backing service/endpoints differ.

  @spec openspec/changes/archive/2026-07-13-delivery-channels/tasks.md#task-7-frontend-scheduleformmodalvue-new-channels-schedulewebhooksecretdialogvue
  @spec openspec/changes/archive/2026-07-13-delivery-channels/design.md
-->
<template>
	<NcModal
		:show="show"
		size="normal"
		:name="t('hermiq', 'Webhook signing secret')"
		@close="$emit('close')">
		<div class="schedule-webhook-secret-dialog">
			<h2 class="schedule-webhook-secret-dialog__title">
				{{ t('hermiq', 'Webhook signing secret') }}
			</h2>

			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>

			<template v-if="revealedSecret">
				<NcNoteCard type="warning">
					{{
						t(
							'hermiq',
							'This secret is shown only once. Copy it now — it cannot be displayed again.',
						)
					}}
				</NcNoteCard>

				<NcTextField
					:modelValue="revealedSecret"
					readonly
					:label="t('hermiq', 'Secret')"
					@focus="selectAll" />

				<p class="schedule-webhook-secret-dialog__hint">
					{{
						t(
							'hermiq',
							'The receiving endpoint verifies deliveries using the {header} header (sha256=&lt;hex hmac&gt;).',
							{ header: 'X-Hermiq-Signature' },
						)
					}}
				</p>

				<div class="schedule-webhook-secret-dialog__actions">
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
			</template>

			<template v-else>
				<p
					v-if="status.configured"
					class="schedule-webhook-secret-dialog__hint">
					{{
						t(
							'hermiq',
							'A signing secret is configured for this schedule.',
						)
					}}
				</p>
				<p v-else class="schedule-webhook-secret-dialog__hint">
					{{
						t(
							'hermiq',
							'No signing secret is configured yet — webhook deliveries will fail until one is minted.',
						)
					}}
				</p>

				<div class="schedule-webhook-secret-dialog__actions">
					<NcButton
						v-if="!status.configured"
						type="primary"
						:disabled="busy"
						@click="mint">
						{{ t('hermiq', 'Mint secret') }}
					</NcButton>
					<template v-else>
						<NcButton :disabled="busy" @click="rotate">
							{{ t('hermiq', 'Rotate secret') }}
						</NcButton>
						<NcButton type="error" :disabled="busy" @click="revoke">
							{{ t('hermiq', 'Revoke secret') }}
						</NcButton>
					</template>
					<NcButton :disabled="busy" @click="$emit('close')">
						{{ t('hermiq', 'Close') }}
					</NcButton>
				</div>
			</template>
		</div>
	</NcModal>
</template>

<script>
import { showSuccess } from '@nextcloud/dialogs'
import { NcButton, NcModal, NcNoteCard, NcTextField } from '@nextcloud/vue'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import {
	getScheduleWebhookSecretStatus,
	mintScheduleWebhookSecret,
	revokeScheduleWebhookSecret,
	rotateScheduleWebhookSecret,
} from '../api/scheduleWebhookSecret.js'
import { copySecret, selectAll } from './secretReveal.js'

export default {
	name: 'ScheduleWebhookSecretDialog',

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

		/** The schedule UUID whose webhook secret is managed. */
		scheduleId: {
			type: String,
			required: true,
		},
	},

	emits: ['close'],

	data() {
		return {
			status: { configured: false },
			revealedSecret: '',
			busy: false,
			error: '',
		}
	},

	watch: {
		/**
		 * @spec openspec/changes/archive/2026-07-13-delivery-channels/tasks.md#task-7-frontend-scheduleformmodalvue-new-channels-schedulewebhooksecretdialogvue
		 */
		show(open) {
			if (open) {
				this.revealedSecret = ''
				this.error = ''
				this.loadStatus()
			}
		},
	},

	methods: {
		selectAll,

		/**
		 * Load the current webhook-secret status ({configured:false} when unconfigured).
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/archive/2026-07-13-delivery-channels/tasks.md#task-7-frontend-scheduleformmodalvue-new-channels-schedulewebhooksecretdialogvue
		 */
		async loadStatus() {
			this.status = await getScheduleWebhookSecretStatus(
				this.scheduleId,
			).catch(() => ({ configured: false }))
		},

		/**
		 * Copy the revealed secret to the clipboard.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/archive/2026-07-13-delivery-channels/tasks.md#task-7-frontend-scheduleformmodalvue-new-channels-schedulewebhooksecretdialogvue
		 */
		copy() {
			return copySecret(this.revealedSecret)
		},

		/**
		 * Mint a new webhook signing secret for this schedule.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/archive/2026-07-13-delivery-channels/tasks.md#task-7-frontend-scheduleformmodalvue-new-channels-schedulewebhooksecretdialogvue
		 */
		mint() {
			return this.runSecretAction(
				async () => this.reveal(await mintScheduleWebhookSecret(this.scheduleId)),
				this.t('hermiq', 'Could not mint webhook secret'),
			)
		},

		/**
		 * Rotate this schedule's webhook signing secret, invalidating the
		 * previous one immediately.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/archive/2026-07-13-delivery-channels/tasks.md#task-7-frontend-scheduleformmodalvue-new-channels-schedulewebhooksecretdialogvue
		 */
		rotate() {
			return this.runSecretAction(
				async () => this.reveal(await rotateScheduleWebhookSecret(this.scheduleId)),
				this.t('hermiq', 'Could not rotate webhook secret'),
			)
		},

		/**
		 * Revoke this schedule's webhook signing secret.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/archive/2026-07-13-delivery-channels/tasks.md#task-7-frontend-scheduleformmodalvue-new-channels-schedulewebhooksecretdialogvue
		 */
		revoke() {
			return this.runSecretAction(
				async () => {
					this.status = await revokeScheduleWebhookSecret(this.scheduleId)
					this.revealedSecret = ''
					showSuccess(this.t('hermiq', 'Webhook secret revoked.'))
				},
				this.t('hermiq', 'Could not revoke webhook secret'),
			)
		},

		/**
		 * Show a just-minted or just-rotated secret once, and the configured status.
		 *
		 * @param {object} result The server's { secret, rotatedAt }.
		 * @return {void}
		 * @spec openspec/changes/archive/2026-07-13-delivery-channels/tasks.md#task-7-frontend-scheduleformmodalvue-new-channels-schedulewebhooksecretdialogvue
		 */
		reveal(result) {
			this.revealedSecret = result.secret
			this.status = { configured: true, rotatedAt: result.rotatedAt }
		},

		/**
		 * Run one secret action with the busy flag set, showing the server's error
		 * (or the fallback) when it fails.
		 *
		 * @param {function(): Promise<void>} action The action.
		 * @param {string} failure The translated fallback error.
		 * @return {Promise<void>}
		 * @spec openspec/changes/archive/2026-07-13-delivery-channels/tasks.md#task-7-frontend-scheduleformmodalvue-new-channels-schedulewebhooksecretdialogvue
		 */
		async runSecretAction(action, failure) {
			this.busy = true
			this.error = ''
			try {
				await action()
			} catch (e) {
				this.error = e?.response?.data?.error || failure
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.schedule-webhook-secret-dialog {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 20px;
}

.schedule-webhook-secret-dialog__title {
	margin: 0;
	font-size: 20px;
	font-weight: 600;
}

.schedule-webhook-secret-dialog__hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.schedule-webhook-secret-dialog__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 8px;
}
</style>
