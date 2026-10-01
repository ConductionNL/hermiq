<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  AppTemplateCollectModal: the Store's "Check apps for templates".

  Asks every installed app for the agent templates it offers for itself
  (CollectAgentTemplatesEvent, the same collect the repair step runs on install
  and upgrade) and reports what happened. Offered templates land quarantined, so
  they show in the Store list to be reviewed. Emits `imported` when anything
  changed (the page refreshes the list) and `close` to dismiss.

  @spec openspec/changes/agents-bound-to-their-app/specs/agent-template-gallery/spec.md#requirement-an-installed-app-can-offer-an-agent-template-for-itself-req-appag-005
-->
<template>
	<NcModal size="normal" :noClose="busy" @close="$emit('close')">
		<div class="app-template-collect">
			<h2 class="app-template-collect__title">
				{{ t('hermiq', 'Check apps for templates') }}
			</h2>

			<p>
				{{
					t(
						'hermiq',
						'Installed apps can offer an agent template for themselves. An offered template starts quarantined: review and approve it before anyone can use it.',
					)
				}}
			</p>

			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>

			<NcNoteCard v-if="result" type="success">
				{{ summary }}
			</NcNoteCard>

			<div class="app-template-collect__actions">
				<NcButton
					variant="tertiary"
					:disabled="busy"
					@click="$emit('close')">
					{{ t('hermiq', 'Close') }}
				</NcButton>
				<NcButton variant="primary" :disabled="busy" @click="run">
					<template v-if="busy" #icon>
						<NcLoadingIcon :size="18" />
					</template>
					{{ t('hermiq', 'Check now') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { NcButton, NcLoadingIcon, NcModal, NcNoteCard } from '@nextcloud/vue'
import { collectAppTemplates } from '../api/agentTemplates.js'

export default {
	name: 'AppTemplateCollectModal',

	components: {
		NcButton,
		NcLoadingIcon,
		NcModal,
		NcNoteCard,
	},

	emits: ['close', 'imported'],

	data() {
		return {
			busy: false,
			error: '',
			result: null,
		}
	},

	computed: {
		/**
		 * The counts of the last check, in words.
		 *
		 * @return {string} The summary line.
		 */
		summary() {
			return this.t(
				'hermiq',
				'{imported} new, {updated} updated, {unchanged} unchanged, {refused} refused.',
				{
					imported: this.result?.imported ?? 0,
					updated: this.result?.updated ?? 0,
					unchanged: this.result?.unchanged ?? 0,
					refused: this.result?.refused ?? 0,
				},
			)
		},
	},

	methods: {
		/**
		 * Run the collect and show its counts.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/agents-bound-to-their-app/specs/agent-template-gallery/spec.md#requirement-an-installed-app-can-offer-an-agent-template-for-itself-req-appag-005
		 */
		async run() {
			this.busy = true
			this.error = ''
			this.result = null
			try {
				this.result = await collectAppTemplates()
				if ((this.result.imported ?? 0) + (this.result.updated ?? 0) > 0) {
					/**
					 * @event imported Emitted when templates were added or replaced, so the list refreshes.
					 */
					this.$emit('imported')
				}
			} catch (e) {
				this.error =
					e?.response?.data?.error
					|| this.t('hermiq', 'Could not check the apps for templates')
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.app-template-collect {
	padding: 24px;
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.app-template-collect__title {
	margin: 0;
	font-size: 20px;
	font-weight: 600;
}

.app-template-collect__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
}
</style>
