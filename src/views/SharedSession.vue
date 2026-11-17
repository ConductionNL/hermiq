<!--
  SharedSession (chat-fork-and-share-link).

  The read-only page of a session shared with the reader: every turn with its
  sources and attachment names, the line "Shared by ... on ...", and no
  message box and no actions. The server decides who may read it; anyone the
  session is not shared with, and a share that was taken back, sees
  "This session is not available", without the title.

  @spec openspec/changes/chat-fork-and-share-link/specs/session-sharing/spec.md#requirement-a-share-recipient-can-read-and-do-nothing-else-req-sshare-002
-->
<template>
	<div class="shared-session" data-testid="shared-session">
		<NcLoadingIcon v-if="loading" :size="32" />

		<NcEmptyContent
			v-else-if="unavailable"
			:name="t('hermiq', 'This session is not available')"
			:description="t('hermiq', 'It was not shared with you, the share was taken back, or the session was deleted.')"
			data-testid="shared-session-unavailable">
			<template #icon>
				<MessageOffOutline :size="20" />
			</template>
		</NcEmptyContent>

		<template v-else>
			<header class="shared-session__header">
				<h2>{{ session.title || t('hermiq', 'New session') }}</h2>
				<p v-if="sharedBy" class="shared-session__by" data-testid="shared-session-by">
					{{ sharedBy.date
						? t('hermiq', 'Shared by {name} on {date}', sharedBy)
						: t('hermiq', 'Shared by {name}', sharedBy) }}
				</p>
				<p class="shared-session__by">
					{{ t('hermiq', 'You can read this session. You cannot add to it.') }}
				</p>
			</header>

			<ol class="shared-session__thread">
				<li
					v-for="message in messages"
					:key="message.uuid"
					class="shared-session__turn"
					:class="`shared-session__turn--${message.role}`">
					<span class="shared-session__author">
						{{ message.role === 'assistant'
							? t('hermiq', 'Assistant')
							: (message.authorDisplayName || t('hermiq', 'Question')) }}
					</span>
					<!-- Markdown is sanitised via DOMPurify with the shared safe config. -->
					<!-- eslint-disable-next-line vue/no-v-html -->
					<div class="shared-session__content" v-html="renderMarkdown(message.content)" />
					<ul v-if="message.sources && message.sources.length > 0" class="shared-session__sources">
						<li v-for="(source, index) in message.sources" :key="index">
							{{ sharedSourceName(source) }}
						</li>
					</ul>
					<ul v-if="message.attachments && message.attachments.length > 0" class="shared-session__sources">
						<li v-for="attachment in message.attachments" :key="attachment.fileId">
							{{ attachment.name }}
						</li>
					</ul>
				</li>
			</ol>
		</template>
	</div>
</template>

<script>
import { SAFE_MARKDOWN_DOMPURIFY_CONFIG } from '@conduction/nextcloud-vue'
import { NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import DOMPurify from 'dompurify'
import { marked } from 'marked'
import MessageOffOutline from 'vue-material-design-icons/MessageOffOutline.vue'
import { getSession, listMessages } from '../api/chat.js'
import { sharedByParts, sharedSourceName } from '../utils/sessionShare.js'

export default {
	name: 'SharedSession',

	components: {
		MessageOffOutline,
		NcEmptyContent,
		NcLoadingIcon,
	},

	data() {
		return {
			loading: true,
			unavailable: false,
			session: null,
			messages: [],
		}
	},

	computed: {
		/**
		 * The "Shared by" line's parts.
		 *
		 * @return {{name: string, date: string}|null}
		 * @spec openspec/changes/chat-fork-and-share-link/specs/session-sharing/spec.md#requirement-a-share-recipient-can-read-and-do-nothing-else-req-sshare-002
		 */
		sharedBy() {
			return sharedByParts(this.session?.share ?? null, document.documentElement.lang || undefined)
		},
	},

	watch: {
		/**
		 * Load again when the address names another session.
		 *
		 * @spec openspec/changes/chat-fork-and-share-link/specs/session-sharing/spec.md#requirement-the-owner-can-copy-a-link-to-a-shared-session-req-sshare-004
		 */
		'$route.params.id': function() {
			this.load()
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		sharedSourceName,

		/**
		 * Read the session and its turns; any refusal shows "not available".
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/chat-fork-and-share-link/specs/session-sharing/spec.md#requirement-the-owner-can-revoke-a-share-at-once-req-sshare-003
		 */
		async load() {
			const uuid = this.$route?.params?.id
			this.loading = true
			this.unavailable = false
			try {
				const [session, page] = await Promise.all([getSession(uuid), listMessages(uuid)])
				this.session = session
				this.messages = page.results || []
			} catch {
				this.session = null
				this.messages = []
				this.unavailable = true
			} finally {
				this.loading = false
			}
		},

		/**
		 * Render markdown safely (marked + DOMPurify with the shared safe config).
		 *
		 * @param {string} content The raw text.
		 * @return {string} Sanitised HTML.
		 */
		renderMarkdown(content) {
			return DOMPurify.sanitize(marked.parse(content || ''), SAFE_MARKDOWN_DOMPURIFY_CONFIG)
		},
	},
}
</script>

<style scoped>
.shared-session {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 4);
	max-width: 900px;
	margin: 0 auto;
	padding: calc(var(--default-grid-baseline) * 5);
}

.shared-session__by,
.shared-session__author {
	color: var(--color-text-maxcontrast);
}

.shared-session__thread {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 4);
	list-style: none;
	padding: 0;
}

.shared-session__turn {
	border-radius: var(--border-radius-large);
	padding: calc(var(--default-grid-baseline) * 3);
	background: var(--color-background-hover);
}

.shared-session__turn--assistant {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
}

.shared-session__sources {
	margin-top: calc(var(--default-grid-baseline) * 2);
	color: var(--color-text-maxcontrast);
}
</style>
