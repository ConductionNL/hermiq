<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  SessionDeleteModal: confirm permanently deleting a chat session
  (agent-engine-port task 5.1; OR used a bare confirm() in ChatIndex.vue,
  hermiq's modal-isolation gate requires an own file).

  Permanent deletion (DELETE /apps/hermiq/api/sessions/{uuid}/permanent)
  removes the session's messages first and is irreversible through any
  Hermiq surface, so it asks for an explicit, destructive-styled confirmation.
  Archiving, the reversible soft delete, does not pass through this modal.

  Reached from BOTH tabs since the session row gained an action menu
  (session-frontend-rename task 3.4). The endpoint never required the session
  to be archived first, so an active session deletes the same way; the prop
  below says "the session", not "the archived session", because that is now
  what it receives.
-->
<template>
	<NcModal
		:show="show"
		size="small"
		:name="t('hermiq', 'Delete session permanently')"
		@close="$emit('close')">
		<div class="session-delete">
			<h2 class="session-delete__title">
				{{ t('hermiq', 'Delete session permanently') }}
			</h2>

			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>

			<p class="session-delete__text">
				{{
					t(
						'hermiq',
						'This permanently deletes the session and all of its messages. This cannot be undone.',
					)
				}}
			</p>
			<p v-if="session" class="session-delete__name">
				{{ session.title || t('hermiq', 'New session') }}
			</p>

			<div class="session-delete__actions">
				<NcButton :disabled="deleting" @click="$emit('close')">
					{{ t('hermiq', 'Cancel') }}
				</NcButton>
				<NcButton
					variant="error"
					:disabled="deleting"
					@click="confirmDelete">
					<template #icon>
						<NcLoadingIcon v-if="deleting" :size="20" />
						<Delete v-else :size="20" />
					</template>
					{{ t('hermiq', 'Delete permanently') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { NcButton, NcLoadingIcon, NcModal, NcNoteCard } from '@nextcloud/vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import { deleteSessionPermanent } from '../api/chat.js'

export default {
	name: 'SessionDeleteModal',

	components: {
		Delete,
		NcButton,
		NcLoadingIcon,
		NcModal,
		NcNoteCard,
	},

	props: {
		/** Whether the modal is visible. */
		show: {
			type: Boolean,
			default: false,
		},

		/** The session to delete permanently, active or archived. */
		session: {
			type: Object,
			default: null,
		},
	},

	emits: ['close', 'deleted'],

	data() {
		return {
			deleting: false,
			error: '',
		}
	},

	watch: {
		/**
		 * Clear a previous error when the modal opens.
		 *
		 * @param {boolean} open Whether the modal is now shown.
		 * @spec openspec/changes/session-frontend-rename/specs/session-surface/spec.md#requirement-the-application-must-use-one-word-for-a-session
		 */
		show(open) {
			if (open) {
				this.error = ''
			}
		},
	},

	methods: {
		/**
		 * Permanently delete the session and notify the parent.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/session-frontend-rename/specs/session-surface/spec.md#requirement-the-application-must-use-one-word-for-a-session
		 */
		async confirmDelete() {
			if (!this.session?.uuid) {
				return
			}
			this.deleting = true
			this.error = ''
			try {
				await deleteSessionPermanent(this.session.uuid)
				this.$emit('deleted', this.session)
				this.$emit('close')
			} catch (e) {
				this.error =
					e?.response?.data?.message
					|| e?.message
					|| this.t('hermiq', 'Unknown error')
			} finally {
				this.deleting = false
			}
		},
	},
}
</script>

<style scoped>
.session-delete {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 20px;
}

.session-delete__title {
	margin: 0 0 4px;
	font-size: 20px;
	font-weight: 600;
}

.session-delete__text {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.session-delete__name {
	margin: 0;
	font-weight: 600;
}

.session-delete__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 8px;
}
</style>
