<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  GenerateImageModal: the chat action "Create an image"
  (chat-attachments-and-images D7). The person describes the image; hermiq
  creates it through the instance's text-to-image provider, saves it in their
  Files under Hermiq/Generated images, tagged Agent authored, and adds it to the
  session as the answer.

  Posts to POST /apps/hermiq/api/chat/images and emits `created` with the two
  stored turns.
-->
<template>
	<NcModal
		:show="show"
		size="normal"
		:name="t('hermiq', 'Create an image')"
		@close="$emit('close')">
		<div class="image-form" data-testid="image-form">
			<h2 class="image-form__title">
				{{ t('hermiq', 'Create an image') }}
			</h2>
			<p class="image-form__help">
				{{
					t(
						'hermiq',
						'The image is saved in your Files under Hermiq/Generated images and marked as made by an agent.',
					)
				}}
			</p>

			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>

			<NcTextArea
				v-model="prompt"
				data-testid="image-prompt"
				:label="t('hermiq', 'What should the image show?')"
				:placeholder="
					t(
						'hermiq',
						'A bicycle shed at Zwolle station in the morning sun',
					)
				"
				:maxlength="1000"
				:disabled="creating" />

			<div class="image-form__actions">
				<NcButton :disabled="creating" @click="$emit('close')">
					{{ t('hermiq', 'Cancel') }}
				</NcButton>
				<NcButton
					variant="primary"
					data-testid="image-create"
					:disabled="creating || !prompt.trim()"
					@click="create">
					<template v-if="creating" #icon>
						<NcLoadingIcon :size="20" />
					</template>
					{{ t('hermiq', 'Create') }}
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
import { createChatImage } from '../api/chat.js'

export default {
	name: 'GenerateImageModal',

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

		/** The session the image is added to. */
		session: {
			type: Object,
			default: null,
		},
	},

	emits: ['close', 'created'],

	data() {
		return {
			prompt: '',
			creating: false,
			error: '',
		}
	},

	watch: {
		/**
		 * Start from an empty description each time the modal opens.
		 *
		 * @param {boolean} open Whether the modal is now shown.
		 * @spec openspec/changes/chat-attachments-and-images/specs/image-generation/spec.md#requirement-a-created-image-shows-in-the-answer-req-cimg-004
		 */
		show(open) {
			if (open) {
				this.prompt = ''
				this.error = ''
			}
		},
	},

	methods: {
		/**
		 * Create the image and hand the two turns to the parent.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/chat-attachments-and-images/specs/image-generation/spec.md#scenario-a-communications-advisor-asks-for-an-illustration
		 */
		async create() {
			const prompt = this.prompt.trim()
			if (!this.session?.uuid || !prompt) {
				return
			}
			this.creating = true
			this.error = ''
			try {
				const turns = await createChatImage(this.session.uuid, prompt)
				this.$emit('created', turns)
			} catch (e) {
				this.error =
					e?.response?.data?.error
					|| t('hermiq', 'The image could not be created.')
			} finally {
				this.creating = false
			}
		},
	},
}
</script>

<style scoped>
.image-form {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 20px;
}

.image-form__title {
	margin: 0 0 4px;
	font-size: 20px;
	font-weight: 600;
}

.image-form__help {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.image-form__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
}
</style>
