<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  ConversationDeleteModal — confirm permanently deleting an archived chat
  conversation (agent-engine-port task 5.1; OR used a bare confirm() in
  ChatIndex.vue, hermiq's modal-isolation gate requires an own file).

  Permanent deletion (DELETE /apps/hermiq/api/conversations/{uuid}/permanent)
  removes the conversation's messages first and is irreversible through any
  Hermiq surface — hence an explicit, destructive-styled confirmation. Archiving
  (the reversible soft delete) is one-click in the Chat page and does not pass
  through this modal.
-->
<template>
	<NcModal
		:show="show"
		size="small"
		:name="t('hermiq', 'Delete conversation permanently')"
		@close="$emit('close')">
		<div class="conversation-delete">
			<h2 class="conversation-delete__title">
				{{ t('hermiq', 'Delete conversation permanently') }}
			</h2>

			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>

			<p class="conversation-delete__text">
				{{
					t(
						'hermiq',
						'This permanently deletes the conversation and all of its messages. This cannot be undone.',
					)
				}}
			</p>
			<p v-if="conversation" class="conversation-delete__name">
				{{ conversation.title || t('hermiq', 'New conversation') }}
			</p>

			<div class="conversation-delete__actions">
				<NcButton :disabled="deleting" @click="$emit('close')">
					{{ t('hermiq', 'Cancel') }}
				</NcButton>
				<NcButton type="error" :disabled="deleting" @click="confirmDelete">
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
import { deleteConversationPermanent } from '../api/chat.js'

export default {
	name: 'ConversationDeleteModal',

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

		/** The (archived) conversation to delete permanently. */
		conversation: {
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
		show(open) {
			if (open) {
				this.error = ''
			}
		},
	},

	methods: {
		/**
		 * Permanently delete the conversation and notify the parent.
		 *
		 * @return {Promise<void>}
		 */
		async confirmDelete() {
			if (!this.conversation?.uuid) {
				return
			}
			this.deleting = true
			this.error = ''
			try {
				await deleteConversationPermanent(this.conversation.uuid)
				this.$emit('deleted', this.conversation)
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
.conversation-delete {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 20px;
}

.conversation-delete__title {
	margin: 0 0 4px;
	font-size: 20px;
	font-weight: 600;
}

.conversation-delete__text {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.conversation-delete__name {
	margin: 0;
	font-weight: 600;
}

.conversation-delete__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 8px;
}
</style>
