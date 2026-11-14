<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  SubscriptionSignInModal — sign in with a model subscription through the
  vendor's own page (models-provider-connections D5). A code sign-in (Claude)
  asks for the code the page shows; a device sign-in (ChatGPT) shows the code
  to enter there. The token goes to the credential broker server-side; this
  dialog only ever sees the new credential's label.
-->
<template>
	<NcModal
		:show="show"
		size="normal"
		:name="title"
		@close="close">
		<div class="subscription-sign-in">
			<h2 class="subscription-sign-in__title">
				{{ title }}
			</h2>

			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>

			<NcNoteCard v-if="done" type="success">
				{{ doneText }}
			</NcNoteCard>

			<template v-else-if="signIn">
				<p>
					{{ t('hermiq', 'Open the sign-in page, sign in with your subscription, and come back here.') }}
				</p>
				<p>
					<a
						class="subscription-sign-in__link"
						:href="signIn.url"
						target="_blank"
						rel="noopener noreferrer">{{ t('hermiq', 'Open the sign-in page') }}</a>
				</p>
				<p v-if="signIn.mode === 'device' && signIn.userCode">
					{{ t('hermiq', 'Enter this code on the sign-in page:') }}
					<strong class="subscription-sign-in__code">{{ signIn.userCode }}</strong>
				</p>
				<NcTextField
					v-if="signIn.mode === 'code'"
					v-model="code"
					:label="t('hermiq', 'Code from the sign-in page')" />
				<p class="subscription-sign-in__terms">
					{{ t('hermiq', 'A subscription is saved as a personal credential: it serves only your own runs, as the vendor\'s terms require.') }}
				</p>
			</template>

			<div class="subscription-sign-in__actions">
				<NcButton :disabled="busy" @click="close">
					{{ done ? t('hermiq', 'Close') : t('hermiq', 'Cancel') }}
				</NcButton>
				<NcButton
					v-if="signIn && !done"
					variant="primary"
					:disabled="busy || (signIn.mode === 'code' && !code.trim())"
					@click="finish">
					<template v-if="busy" #icon>
						<NcLoadingIcon :size="20" />
					</template>
					{{ signIn.mode === 'code' ? t('hermiq', 'Finish sign-in') : t('hermiq', 'I have signed in') }}
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
import { finishSignIn, startSignIn } from '../api/subscriptionLogin.js'

export default {
	name: 'SubscriptionSignInModal',

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

		/** `anthropic` or `openai`. */
		provider: {
			type: String,
			default: 'anthropic',
		},
	},

	emits: ['close', 'signedIn'],

	data() {
		return {
			signIn: null,
			code: '',
			busy: false,
			error: '',
			done: false,
		}
	},

	computed: {
		/**
		 * The dialog title for the provider.
		 *
		 * @return {string} The title.
		 * @spec openspec/changes/models-provider-connections/specs/agent-credentials/spec.md#requirement-a-user-can-sign-in-with-a-claude-subscription-req-signin-001
		 */
		title() {
			return this.provider === 'openai'
				? this.t('hermiq', 'Sign in with your ChatGPT subscription')
				: this.t('hermiq', 'Sign in with your Claude subscription')
		},

		/**
		 * The success text for the provider.
		 *
		 * @return {string} The text.
		 * @spec openspec/changes/models-provider-connections/specs/agent-credentials/spec.md#requirement-a-user-can-sign-in-with-a-claude-subscription-req-signin-001
		 */
		doneText() {
			return this.provider === 'openai'
				? this.t('hermiq', 'Signed in. Your ChatGPT subscription is saved as a personal credential.')
				: this.t('hermiq', 'Signed in. Your Claude subscription is saved as a personal credential.')
		},
	},

	watch: {
		/**
		 * Start a fresh sign-in each time the dialog opens.
		 *
		 * @param {boolean} open Whether the dialog is now shown.
		 * @spec openspec/changes/models-provider-connections/specs/agent-credentials/spec.md#requirement-a-user-can-sign-in-with-a-claude-subscription-req-signin-001
		 */
		show(open) {
			if (open) {
				this.start()
			}
		},
	},

	methods: {
		/**
		 * Ask hermiq to start the sign-in and show its URL.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/models-provider-connections/specs/agent-credentials/spec.md#requirement-a-user-can-sign-in-with-a-claude-subscription-req-signin-001
		 */
		async start() {
			this.signIn = null
			this.code = ''
			this.error = ''
			this.done = false
			this.busy = true
			try {
				this.signIn = await startSignIn(this.provider)
			} catch (e) {
				this.error = this.messageOf(e)
			} finally {
				this.busy = false
			}
		},

		/**
		 * Hand the code on and report the saved credential.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/models-provider-connections/specs/agent-credentials/spec.md#requirement-a-signed-in-token-is-kept-only-in-the-broker-req-signin-002
		 */
		async finish() {
			this.busy = true
			this.error = ''
			try {
				const credential = await finishSignIn(this.signIn.loginId, this.code.trim())
				this.done = true
				this.$emit('signedIn', credential)
			} catch (e) {
				this.error = this.messageOf(e)
			} finally {
				this.busy = false
			}
		},

		/**
		 * The message of a failed call.
		 *
		 * @param {Error} e The failure.
		 * @return {string} The message.
		 * @spec openspec/changes/models-provider-connections/specs/agent-credentials/spec.md#requirement-a-user-can-sign-in-with-a-claude-subscription-req-signin-001
		 */
		messageOf(e) {
			return e?.response?.data?.message || e?.message || this.t('hermiq', 'Unknown error')
		},

		/**
		 * Close the dialog.
		 *
		 * @return {void}
		 * @spec exclude Trivial emit wrapper.
		 */
		close() {
			this.$emit('close')
		},
	},
}
</script>

<style scoped>
.subscription-sign-in {
	padding: calc(var(--default-grid-baseline) * 4);
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
}

.subscription-sign-in__link {
	color: var(--color-primary-element);
	text-decoration: underline;
}

.subscription-sign-in__code {
	font-family: var(--font-face-monospace, monospace);
}

.subscription-sign-in__terms {
	color: var(--color-text-maxcontrast);
}

.subscription-sign-in__actions {
	display: flex;
	justify-content: flex-end;
	gap: calc(var(--default-grid-baseline) * 2);
}
</style>
