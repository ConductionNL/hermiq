<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  SessionBulkDeleteModal: confirm permanently deleting several chat sessions at
  once (chat-session-search-and-bulk-delete, REQ-SSRCH-002).

  The page's selection bar opens it with the selected sessions. It names the
  count ("Delete 3 sessions? This cannot be undone."), and on confirm posts one
  `POST /api/sessions/bulk` with action `delete`. The server answers per uuid;
  the modal hands that answer to the page, which removes the sessions that were
  deleted and says how many could not be.
-->
<template>
	<NcModal
		:show="show"
		size="small"
		:name="t('hermiq', 'Delete sessions permanently')"
		@close="close">
		<div class="session-bulk-delete" data-testid="session-bulk-delete">
			<h2 class="session-bulk-delete__title">
				{{ t('hermiq', 'Delete sessions permanently') }}
			</h2>

			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>

			<p class="session-bulk-delete__text" data-testid="session-bulk-delete-question">
				{{
					n(
						'hermiq',
						'Delete %n session? This cannot be undone.',
						'Delete %n sessions? This cannot be undone.',
						uuids.length,
					)
				}}
			</p>

			<div class="session-bulk-delete__actions">
				<NcButton :disabled="deleting" @click="close">
					{{ t('hermiq', 'Cancel') }}
				</NcButton>
				<NcButton
					variant="error"
					data-testid="session-bulk-delete-confirm"
					:disabled="deleting || uuids.length === 0"
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
import { bulkSessions } from '../api/chat.js'

export default {
	name: 'SessionBulkDeleteModal',

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

		/** The uuids of the sessions to delete permanently. */
		uuids: {
			type: Array,
			default: () => [],
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
		 * @spec openspec/changes/chat-session-search-and-bulk-delete/specs/session-surface/spec.md#requirement-a-person-can-archive-restore-or-delete-several-sessions-in-one-action-req-ssrch-002
		 */
		show(open) {
			if (open) {
				this.error = ''
			}
		},
	},

	methods: {
		/**
		 * Close unless a delete is under way.
		 *
		 * @return {void}
		 * @spec openspec/changes/chat-session-search-and-bulk-delete/specs/session-surface/spec.md#requirement-a-person-can-archive-restore-or-delete-several-sessions-in-one-action-req-ssrch-002
		 */
		close() {
			if (!this.deleting) {
				this.$emit('close')
			}
		},

		/**
		 * Delete the sessions in one bulk request and hand the per-uuid answer to the page.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/chat-session-search-and-bulk-delete/specs/session-surface/spec.md#requirement-a-person-can-archive-restore-or-delete-several-sessions-in-one-action-req-ssrch-002
		 */
		async confirmDelete() {
			if (this.uuids.length === 0) {
				return
			}
			this.deleting = true
			this.error = ''
			try {
				const results = await bulkSessions('delete', [...this.uuids])
				this.deleting = false
				this.$emit('deleted', results)
				this.$emit('close')
			} catch (e) {
				this.error =
					e?.response?.data?.error
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
.session-bulk-delete {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 20px;
}

.session-bulk-delete__title {
	margin: 0 0 4px;
	font-size: 20px;
	font-weight: 600;
}

.session-bulk-delete__text {
	margin: 0;
}

.session-bulk-delete__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 8px;
}
</style>
