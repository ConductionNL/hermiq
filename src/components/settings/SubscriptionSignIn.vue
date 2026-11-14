<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  SubscriptionSignIn — the user-settings block that signs the user in with a
  model subscription (models-provider-connections D5, D6). Shown in the app's
  own settings dialog, beside the credentials list; never in admin settings.
  Without the llm-runner ExApp the buttons are replaced by a line saying so;
  the ChatGPT button shows only where the runner's Codex CLI offers the sign-in.
-->
<template>
	<div class="subscription-sign-in-settings">
		<NcLoadingIcon v-if="loading" :size="20" />
		<NcNoteCard v-else-if="error" type="error">
			{{ error }}
		</NcNoteCard>
		<p v-else-if="!offer.available" class="subscription-sign-in-settings__text">
			{{ t('hermiq', 'Subscription sign-in needs the Hermiq LLM runner app.') }}
		</p>
		<template v-else>
			<p class="subscription-sign-in-settings__text">
				{{ t('hermiq', 'Sign in on the vendor\'s own page instead of pasting a token. The subscription is saved as your personal credential.') }}
			</p>
			<div class="subscription-sign-in-settings__buttons">
				<NcButton
					v-for="entry in offer.providers"
					:key="entry.provider"
					variant="secondary"
					@click="open(entry.provider)">
					{{ labelFor(entry.provider) }}
				</NcButton>
			</div>
			<p v-if="lastSaved" class="subscription-sign-in-settings__text">
				{{ t('hermiq', 'Saved as "{label}". Reopen this dialog to see it in the credentials list.', { label: lastSaved }) }}
			</p>
		</template>

		<SubscriptionSignInModal
			:show="provider !== ''"
			:provider="provider || 'anthropic'"
			@signedIn="signedIn"
			@close="provider = ''" />
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import SubscriptionSignInModal from '../../modals/SubscriptionSignInModal.vue'
import { fetchSignInOffer } from '../../api/subscriptionLogin.js'

export default {
	name: 'SubscriptionSignIn',

	components: {
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		SubscriptionSignInModal,
	},

	data() {
		return {
			loading: true,
			error: '',
			offer: { available: false, providers: [] },
			provider: '',
			lastSaved: '',
		}
	},

	/**
	 * Load which sign-ins are offered.
	 *
	 * @return {Promise<void>}
	 * @spec openspec/changes/models-provider-connections/specs/agent-credentials/spec.md#requirement-a-user-can-sign-in-with-a-claude-subscription-req-signin-001
	 */
	async created() {
		try {
			this.offer = await fetchSignInOffer()
		} catch (e) {
			this.error = e?.response?.data?.message || e?.message || this.t('hermiq', 'Unknown error')
		} finally {
			this.loading = false
		}
	},

	methods: {
		/**
		 * The button label of a provider.
		 *
		 * @param {string} provider `anthropic` or `openai`.
		 * @return {string} The label.
		 * @spec openspec/changes/models-provider-connections/specs/agent-credentials/spec.md#requirement-a-chatgpt-subscription-can-sign-in-where-the-codex-cli-allows-it-req-signin-004
		 */
		labelFor(provider) {
			return provider === 'openai'
				? this.t('hermiq', 'Sign in with your ChatGPT subscription')
				: this.t('hermiq', 'Sign in with your Claude subscription')
		},

		/**
		 * Open the sign-in dialog for a provider.
		 *
		 * @param {string} provider The provider.
		 * @return {void}
		 * @spec exclude Trivial state setter.
		 */
		open(provider) {
			this.provider = provider
		},

		/**
		 * Note the saved credential.
		 *
		 * @param {{id: string, label: string}} credential The new credential.
		 * @return {void}
		 * @spec openspec/changes/models-provider-connections/specs/agent-credentials/spec.md#requirement-a-signed-in-token-is-kept-only-in-the-broker-req-signin-002
		 */
		signedIn(credential) {
			this.lastSaved = credential?.label || ''
		},
	},
}
</script>

<style scoped>
.subscription-sign-in-settings__text {
	color: var(--color-text-maxcontrast);
	margin-block: calc(var(--default-grid-baseline) * 2);
}

.subscription-sign-in-settings__buttons {
	display: flex;
	flex-wrap: wrap;
	gap: calc(var(--default-grid-baseline) * 2);
}
</style>
