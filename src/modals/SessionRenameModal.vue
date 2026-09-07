<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  SessionRenameModal — rename a chat session (agent-engine-port
  task 5.1; OR rendered this inline in ChatIndex.vue, hermiq's modal-isolation
  gate requires an own file).

  Persists via PATCH /apps/hermiq/api/sessions/{uuid} (only `title` is
  writable server-side) and emits `saved` with the updated session.
-->
<template>
	<NcModal
		:show="show"
		size="small"
		:name="t('hermiq', 'Rename session')"
		@close="$emit('close')">
		<div class="session-rename">
			<h2 class="session-rename__title">
				{{ t('hermiq', 'Rename session') }}
			</h2>

			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>

			<NcTextField
				v-model="title"
				:label="t('hermiq', 'Session title')"
				:placeholder="t('hermiq', 'New session')" />

			<div class="session-rename__actions">
				<NcButton :disabled="saving" @click="$emit('close')">
					{{ t('hermiq', 'Cancel') }}
				</NcButton>
				<NcButton
					variant="primary"
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
import { renameSession } from '../api/chat.js'

export default {
	name: 'SessionRenameModal',

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

		/** The session being renamed. */
		session: {
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
				this.title = this.session?.title || ''
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
			if (!this.session?.uuid || !this.title.trim()) {
				return
			}
			this.saving = true
			this.error = ''
			try {
				const updated = await renameSession(
					this.session.uuid,
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
.session-rename {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 20px;
}

.session-rename__title {
	margin: 0 0 4px;
	font-size: 20px;
	font-weight: 600;
}

.session-rename__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 8px;
}
</style>
