<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Nextcloud admin app-settings panel.

 Mounted into `#hermiq-settings` by `src/settings.js` (which
 itself is loaded from `templates/settings/admin.php` via
 `Util::addScript`). This is the panel users reach via Nextcloud's
 "Administration settings" → "Hermiq".

 It carries the "before the app boots" wiring that the manifest-driven
 SPA cannot: choosing which LLM provider Hermiq's background work runs
 on (SPECTR-NEXTCLOUD-PLAN.md §8 move 1). The provider picker itself
 lives in src/modals/LlmProviderModal.vue (modal-isolation gate).
-->
<template>
	<div class="hermiq-admin-settings">
		<NcSettingsSection
			:name="t('hermiq', 'AI provider')"
			:description="t('hermiq', 'Choose which language-model provider Hermiq uses for background work (conversation titles, summaries). Streaming chat and embeddings always use the directly-configured provider — they cannot run through Nextcloud’s TaskProcessing.')">
			<div class="hermiq-admin-settings__provider">
				<span class="hermiq-admin-settings__provider-label">{{ t('hermiq', 'Current provider') }}:</span>
				<strong>{{ currentProviderLabel }}</strong>
			</div>
			<NcButton type="primary" @click="showModal = true">
				<template #icon>
					<Cog :size="20" />
				</template>
				{{ t('hermiq', 'Configure provider') }}
			</NcButton>

			<LlmProviderModal
				:show="showModal"
				@close="showModal = false"
				@saved="onSaved" />
		</NcSettingsSection>
	</div>
</template>

<script>
import { NcButton, NcSettingsSection } from '@nextcloud/vue'
import Cog from 'vue-material-design-icons/Cog.vue'
import LlmProviderModal from '../modals/LlmProviderModal.vue'
import { getLlmSettings } from '../api/llm.js'

const PROVIDER_LABELS = {
	openai: 'OpenAI',
	ollama: 'Ollama (local)',
	fireworks: 'Fireworks AI',
	nextcloud: 'Nextcloud Assistant (TaskProcessing)',
}

export default {
	name: 'AdminRoot',
	components: {
		Cog,
		LlmProviderModal,
		NcButton,
		NcSettingsSection,
	},

	data() {
		return {
			showModal: false,
			chatProvider: null,
		}
	},

	computed: {
		/**
		 * Human-readable name of the current provider, or a "not configured" hint.
		 *
		 * @return {string} The label.
		 *
		 * @spec exclude Trivial computed display helper; no behavioural spec.
		 */
		currentProviderLabel() {
			if (!this.chatProvider) {
				return this.t('hermiq', 'Not configured')
			}
			return PROVIDER_LABELS[this.chatProvider] || this.chatProvider
		},
	},

	mounted() {
		this.refresh()
	},

	methods: {
		/**
		 * Fetch the current provider selection for display.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/taskprocessing-consume-ui/tasks.md#task-2-3
		 */
		async refresh() {
			try {
				const config = await getLlmSettings()
				this.chatProvider = config.chatProvider || null
			} catch (e) {
				this.chatProvider = null
			}
		},

		/**
		 * Handle a save from the modal: reflect the new provider immediately.
		 *
		 * @param {string} provider The newly-selected provider.
		 * @return {void}
		 *
		 * @spec openspec/changes/taskprocessing-consume-ui/tasks.md#task-2-3
		 */
		onSaved(provider) {
			this.chatProvider = provider
		},
	},
}
</script>

<style scoped>
.hermiq-admin-settings {
	max-width: 720px;
}

.hermiq-admin-settings__provider {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-bottom: 12px;
}

.hermiq-admin-settings__provider-label {
	color: var(--color-text-maxcontrast);
}
</style>
