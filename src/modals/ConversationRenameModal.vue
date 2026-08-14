<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  ConversationRenameModal — rename a chat conversation (agent-engine-port
  task 5.1; OR rendered this inline in ChatIndex.vue, hermiq's modal-isolation
  gate requires an own file).

  Persists via PATCH /apps/hermiq/api/conversations/{uuid} (only `title` is
  writable server-side) and emits `saved` with the updated conversation.
-->
<template>
	<NcModal
		:show="show"
		size="small"
		:name="t('hermiq', 'Rename conversation')"
		@close="$emit('close')">
		<div class="conversation-rename">
			<h2 class="conversation-rename__title">
				{{ t('hermiq', 'Rename conversation') }}
			</h2>

			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>

			<NcTextField
				v-model="title"
				:label="t('hermiq', 'Conversation title')"
				:placeholder="t('hermiq', 'New conversation')" />

			<div class="conversation-rename__actions">
				<NcButton :disabled="saving" @click="$emit('close')">
					{{ t('hermiq', 'Cancel') }}
				</NcButton>
				<NcButton
					type="primary"
					:disabled="saving || !title.trim()"
					@click="save">
					<template v-if="saving" #icon>
						<NcLoadingIcon :size="20" />
					</template>
					{{ t('hermiq', 'Save') }}
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
	NcTextField,
} from '@nextcloud/vue'
import { renameConversation } from '../api/chat.js'

export default {
	name: 'ConversationRenameModal',

	components: {
		NcButton,
		NcLoadingIcon,
		NcModal,
		NcNoteCard,
		NcTextField,
	},

	props: {
		/** Whether the modal is visible. */
		show: {
			type: Boolean,
			default: false,
		},

		/** The conversation being renamed. */
		conversation: {
			type: Object,
			default: null,
		},
	},

	emits: ['close', 'saved'],

	data() {
		return {
			title: '',
			saving: false,
			error: '',
		}
	},

	watch: {
		show(open) {
			if (open) {
				this.error = ''
				this.title = this.conversation?.title || ''
			}
		},
	},

	methods: {
		/**
		 * Persist the new title and notify the parent.
		 *
		 * @return {Promise<void>}
		 */
		async save() {
			if (!this.conversation?.uuid || !this.title.trim()) {
				return
			}
			this.saving = true
			this.error = ''
			try {
				const updated = await renameConversation(
					this.conversation.uuid,
					this.title.trim(),
				)
				this.$emit('saved', updated)
				this.$emit('close')
			} catch (e) {
				this.error =
					e?.response?.data?.message
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
.conversation-rename {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 20px;
}

.conversation-rename__title {
	margin: 0 0 4px;
	font-size: 20px;
	font-weight: 600;
}

.conversation-rename__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 8px;
}
</style>
