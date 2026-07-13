<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  ScheduleWebhookSecretDialog — mint/rotate/revoke a schedule's OUTBOUND
  webhook signing secret, with copy-once plaintext reveal (delivery-channels).

  Own file per ADR-004 modal-isolation, placed under src/modals/ (not
  src/dialogs/) to mirror the existing WebhookSecretDialog.vue (the per-agent
  INBOUND trigger secret) exactly — both are NcModal-based reveal-once secret
  dialogs; only the backing service/endpoints differ.

  @spec openspec/changes/delivery-channels/tasks.md#task-7-frontend-scheduleformmodalvue-new-channels-schedulewebhooksecretdialogvue
  @spec openspec/changes/delivery-channels/design.md
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
					{{ t('hermiq', 'This secret is shown only once. Copy it now — it cannot be displayed again.') }}
				</NcNoteCard>

				<NcTextField
					:value="revealedSecret"
					readonly
					:label="t('hermiq', 'Secret')"
					@focus="selectAll" />

				<p class="schedule-webhook-secret-dialog__hint">
					{{ t('hermiq', 'The receiving endpoint verifies deliveries using the {header} header (sha256=&lt;hex hmac&gt;).', { header: 'X-Hermiq-Signature' }) }}
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
				<p v-if="status.configured" class="schedule-webhook-secret-dialog__hint">
					{{ t('hermiq', 'A signing secret is configured for this schedule.') }}
				</p>
				<p v-else class="schedule-webhook-secret-dialog__hint">
					{{ t('hermiq', 'No signing secret is configured yet — webhook deliveries will fail until one is minted.') }}
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
import { NcButton, NcModal, NcNoteCard, NcTextField } from '@nextcloud/vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import {
	getScheduleWebhookSecretStatus,
	mintScheduleWebhookSecret,
	rotateScheduleWebhookSecret,
	revokeScheduleWebhookSecret,
} from '../api/scheduleWebhookSecret.js'

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
		show(open) {
			if (open) {
				this.revealedSecret = ''
				this.error = ''
				this.loadStatus()
			}
		},
	},

	methods: {
		/**
		 * Load the current webhook-secret status ({configured:false} when unconfigured).
		 *
		 * @return {Promise<void>}
		 */
		async loadStatus() {
			this.status = await getScheduleWebhookSecretStatus(this.scheduleId).catch(() => ({ configured: false }))
		},

		/**
		 * Select the full value in the readonly field for an easy manual copy.
		 *
		 * @param {FocusEvent} event The focus event.
		 * @return {void}
		 */
		selectAll(event) {
			if (event && event.target && typeof event.target.select === 'function') {
				event.target.select()
			}
		},

		/**
		 * Copy the revealed secret to the clipboard, with a graceful fallback
		 * message when the Clipboard API is unavailable.
		 *
		 * @return {Promise<void>}
		 */
		async copy() {
			try {
				await navigator.clipboard.writeText(this.revealedSecret)
				showSuccess(this.t('hermiq', 'Secret copied to clipboard.'))
			} catch (e) {
				showError(this.t('hermiq', 'Could not copy automatically — select and copy the value manually.'))
			}
		},

		/**
		 * Mint a new webhook signing secret for this schedule.
		 *
		 * @return {Promise<void>}
		 */
		async mint() {
			this.busy = true
			this.error = ''
			try {
				const result = await mintScheduleWebhookSecret(this.scheduleId)
				this.revealedSecret = result.secret
				this.status = { configured: true, rotatedAt: result.rotatedAt }
			} catch (e) {
				this.error = e?.response?.data?.error || this.t('hermiq', 'Could not mint webhook secret')
			} finally {
				this.busy = false
			}
		},

		/**
		 * Rotate this schedule's webhook signing secret, invalidating the
		 * previous one immediately.
		 *
		 * @return {Promise<void>}
		 */
		async rotate() {
			this.busy = true
			this.error = ''
			try {
				const result = await rotateScheduleWebhookSecret(this.scheduleId)
				this.revealedSecret = result.secret
				this.status = { configured: true, rotatedAt: result.rotatedAt }
			} catch (e) {
				this.error = e?.response?.data?.error || this.t('hermiq', 'Could not rotate webhook secret')
			} finally {
				this.busy = false
			}
		},

		/**
		 * Revoke this schedule's webhook signing secret.
		 *
		 * @return {Promise<void>}
		 */
		async revoke() {
			this.busy = true
			this.error = ''
			try {
				this.status = await revokeScheduleWebhookSecret(this.scheduleId)
				this.revealedSecret = ''
				showSuccess(this.t('hermiq', 'Webhook secret revoked.'))
			} catch (e) {
				this.error = e?.response?.data?.error || this.t('hermiq', 'Could not revoke webhook secret')
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
