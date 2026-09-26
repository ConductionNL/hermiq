<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  Chat — the Hermiq chat page (agent-engine-port task 5.1).

  Merges OpenRegister's chat surface (src/views/chat/ChatIndex.vue +
  src/sidebars/chat/ChatSideBar.vue + src/components/AgentSelector.vue) onto
  hermiq's manifest SPA idioms: one custom page with an internal
  session-list column (active/archive) and a thread column
  (messages + composer + feedback), all against the Hermiq engine routes at
  /apps/hermiq/api/{chat,sessions} (chunk 2). Agents come from the
  createObjectStore agent store; session/chat transport lives in
  src/api/chat.js (see its docblock for the store-vs-helper split). All
  dialogs are isolated modal files (ADR-004): SessionRenameModal,
  SessionDeleteModal, ChatSettingsModal.

  STREAMING (hydra ADR-034): sending uses POST /api/chat/stream (SSE
  six-event envelope) with incremental token rendering, degrading to
  POST /api/chat/send on transport failure (the fallback ladder). Two
  ground-truth adaptations, both deliberate:
  - OR's frontend at HEAD has NO SSE consumption (its chat always POSTs
    /chat/send); the streaming consumption here is written against the ported
    ChatStreamController's contract instead of ported from OR code.
  - The stream endpoint accepts only message/agentUuid/sessionUuid, so
    when the user customises per-session views/tools/RAG settings the
    turn is sent over POST /api/chat/send (which accepts them) instead of the
    stream — behaviourally identical to OR, which always used /send.

  After every completed turn the thread is re-read from the server so
  message ids (needed for feedback), RAG sources, and the auto-generated
  session title reflect persisted truth rather than optimistic state.
-->
<template>
	<div class="chat-page">
		<!-- Session list column -->
		<aside class="chat-page__list">
			<div class="chat-page__list-head">
				<NcButton variant="primary" wide @click="newSession">
					<template #icon>
						<Plus :size="20" />
					</template>
					{{ t('hermiq', 'New session') }}
				</NcButton>
				<div class="chat-page__tabs">
					<NcCheckboxRadioSwitch
						:buttonVariant="true"
						:modelValue="showArchive ? 'archive' : 'active'"
						value="active"
						name="chat_list_tab"
						type="radio"
						buttonVariantGrouped="horizontal"
						@update:modelValue="setArchiveTab(false)">
						{{ t('hermiq', 'Active') }}
					</NcCheckboxRadioSwitch>
					<NcCheckboxRadioSwitch
						:buttonVariant="true"
						:modelValue="showArchive ? 'archive' : 'active'"
						value="archive"
						name="chat_list_tab"
						type="radio"
						buttonVariantGrouped="horizontal"
						@update:modelValue="setArchiveTab(true)">
						{{ t('hermiq', 'Archive') }}
					</NcCheckboxRadioSwitch>
				</div>
			</div>

			<div v-if="sessionsLoading" class="chat-page__list-state">
				<NcLoadingIcon :size="28" />
			</div>

			<NcNoteCard v-else-if="visibleSessions.length === 0" type="info">
				{{
					showArchive
						? t('hermiq', 'No archived sessions.')
						: t(
								'hermiq',
								'No sessions yet. Start one to chat with an agent.',
							)
				}}
			</NcNoteCard>

			<!--
				Human and automated sessions are listed apart: a chat someone is
				holding and a session a cron or a flow opened are not the same
				thing to a user deciding what needs attention. A group with no
				sessions is not rendered, so the heading is never a promise the
				list does not keep.
			-->
			<div v-else class="chat-page__rows">
				<template v-for="group in sessionGroups" :key="group.key">
					<h3
						v-if="group.heading"
						class="chat-page__group"
						:data-testid="`chat-session-group-${group.key}`">
						{{ group.heading }}
					</h3>
					<div
						v-for="session in group.sessions"
						:key="session.uuid"
						class="chat-page__row"
						:data-testid="`chat-session-row-${group.key}`"
						:class="{ 'chat-page__row--active': isActive(session) }">
						<div
							class="chat-page__row-main"
							data-testid="chat-session-row"
							role="button"
							tabindex="0"
							@click="selectSession(session)"
							@keydown.enter="selectSession(session)">
							<span class="chat-page__row-icon">
								<component :is="originIcon(session)" :size="20" />
							</span>
							<span class="chat-page__row-text">
								<strong>{{
									session.title || t('hermiq', 'New session')
								}}</strong>
								<span class="chat-page__row-meta">{{
									rowMeta(session)
								}}</span>
							</span>
						</div>
						<NcActions
							class="chat-page__row-actions"
							:aria-label="t('hermiq', 'Session actions')">
							<NcActionButton
								:disabled="isActive(session)"
								@click="selectSession(session)">
								<template #icon>
									<MessageText :size="20" />
								</template>
								{{ t('hermiq', 'Continue') }}
							</NcActionButton>
							<NcActionButton
								v-if="!showArchive"
								@click="archive(session)">
								<template #icon>
									<Archive :size="20" />
								</template>
								{{ t('hermiq', 'Archive session') }}
							</NcActionButton>
							<NcActionButton v-else @click="restore(session)">
								<template #icon>
									<Restore :size="20" />
								</template>
								{{ t('hermiq', 'Restore session') }}
							</NcActionButton>
							<NcActionButton @click="openDelete(session)">
								<template #icon>
									<Delete :size="20" />
								</template>
								{{ t('hermiq', 'Delete session') }}
							</NcActionButton>
						</NcActions>
					</div>
				</template>
			</div>
		</aside>

		<!-- Thread column -->
		<section class="chat-page__thread">
			<div class="chat-page__header">
				<h2 class="chat-page__heading">
					<Creation :size="26" />
					{{ headerTitle }}
				</h2>
				<div v-if="activeSession" class="chat-page__header-actions">
					<NcButton
						variant="tertiary"
						:aria-label="t('hermiq', 'Rename session')"
						@click="showRename = true">
						<template #icon>
							<Pencil :size="20" />
						</template>
					</NcButton>
					<NcButton
						variant="tertiary"
						:aria-label="t('hermiq', 'Chat settings')"
						@click="showSettings = true">
						<template #icon>
							<CogOutline :size="20" />
						</template>
					</NcButton>
				</div>
			</div>

			<!--
				No session: the start-a-session surface.

				The scroll container and the centring live on DIFFERENT elements
				on purpose. A scrolling flex column that centres its own children
				pushes the first one above the scroll origin once the content is
				taller than the column, and nothing can scroll back up to it — so
				the top row of agent cards is unreachable. An inner block with
				`margin: auto` centres identically while there is room and
				collapses to zero when there is not, which is the whole fix.
			-->
			<div v-if="!activeSession" ref="startSurface" class="chat-page__empty">
				<div class="chat-page__empty-inner" data-testid="chat-start-surface">
					<div class="chat-page__empty-icon">
						<MessageText :size="56" />
					</div>
					<h3>{{ t('hermiq', 'Start a session') }}</h3>
					<p>
						{{
							t(
								'hermiq',
								'Select an agent to begin chatting with your data.',
							)
						}}
					</p>
					<AgentSelector
						:agents="agents"
						:loading="agentsLoading"
						:error="agentsError"
						:startingId="startingId"
						@start="startWithAgent" />
				</div>
			</div>

			<!-- Thread -->
			<template v-else>
				<div ref="messagesContainer" class="chat-page__messages">
					<div
						v-if="messagesLoading && messages.length === 0"
						class="chat-page__messages-state">
						<NcLoadingIcon :size="28" />
						<p>{{ t('hermiq', 'Loading session…') }}</p>
					</div>

					<div
						v-for="message in messages"
						:key="message.uuid || message.id"
						class="chat-page__message"
						:class="`chat-page__message--${message.role}`">
						<div class="chat-page__avatar">
							<NcAvatar
								v-if="message.role === 'user'"
								:user="currentUserId"
								:displayName="currentUserName"
								:size="30"
								:disableMenu="true"
								:disableTooltip="true" />
							<Creation v-else :size="30" />
						</div>
						<div class="chat-page__bubble">
							<div class="chat-page__bubble-head">
								<span class="chat-page__sender">
									{{
										message.role === 'user'
											? t('hermiq', 'You')
											: agentName
									}}
								</span>
								<span class="chat-page__time">{{
									formatTime(message.created)
								}}</span>
							</div>
							<!-- Assistant markdown is sanitised via DOMPurify with the shared safe config. -->
							<!-- eslint-disable-next-line vue/no-v-html -->
							<div
								class="chat-page__text"
								v-html="renderMarkdown(message.content)" />

							<!-- RAG sources -->
							<div
								v-if="message.sources && message.sources.length > 0"
								class="chat-page__sources">
								<div class="chat-page__sources-head">
									<FileDocumentOutline :size="16" />
									<span>{{ t('hermiq', 'Sources') }}</span>
								</div>
								<div
									v-for="(source, sourceIndex) in message.sources"
									:key="sourceIndex"
									class="chat-page__source">
									<FileDocument
										v-if="source.type === 'file'"
										:size="16" />
									<CubeOutline v-else :size="16" />
									<span class="chat-page__source-name">{{
										source.name || source.id
									}}</span>
									<span
										v-if="source.similarity"
										class="chat-page__source-match">
										{{ Math.round(source.similarity * 100) }}%
									</span>
								</div>
							</div>

							<!-- Feedback (assistant messages with a persisted id) -->
							<div
								v-if="
									message.role === 'assistant'
									&& (message.uuid || message.id)
								"
								class="chat-page__feedback">
								<NcButton
									variant="tertiary"
									:aria-label="t('hermiq', 'Helpful')"
									:class="{
										'chat-page__feedback--active-positive':
											message.feedback === 'positive',
									}"
									@click="sendFeedback(message, 'positive')">
									<template #icon>
										<ThumbUp :size="16" />
									</template>
								</NcButton>
								<NcButton
									variant="tertiary"
									:aria-label="t('hermiq', 'Not helpful')"
									:class="{
										'chat-page__feedback--active-negative':
											message.feedback === 'negative',
									}"
									@click="sendFeedback(message, 'negative')">
									<template #icon>
										<ThumbDown :size="16" />
									</template>
								</NcButton>
								<!-- hermiq-skill-conversational-authoring: turn this assistant message
								     (e.g. a SKILL.md drafted by the seeded skill-creator skill) into a
								     reviewable Skill via the pre-filled authoring modal. -->
								<NcButton
									variant="tertiary"
									:aria-label="t('hermiq', 'Save as skill')"
									@click="openSaveAsSkill(message)">
									<template #icon>
										<PuzzlePlusOutline :size="16" />
									</template>
								</NcButton>
							</div>
							<div
								v-if="message.showFeedbackInput"
								class="chat-page__feedback-comment">
								<!-- `aria-label`, not the placeholder alone: a placeholder is
								     not an accessible name. It disappears the moment the user
								     types, so anyone relying on a screen reader loses the only
								     description of the field mid-entry (WCAG 3.3.2, 4.1.2). -->
								<textarea
									:value="message.feedbackComment"
									class="chat-page__feedback-input"
									rows="2"
									:aria-label="t('hermiq', 'Feedback details')"
									:placeholder="
										t(
											'hermiq',
											'Optionally add details to your feedback…',
										)
									"
									@input="
										message.feedbackComment = $event.target.value
									" />
								<NcButton
									variant="secondary"
									:disabled="
										!message.feedbackComment
										|| !message.feedbackComment.trim()
									"
									@click="saveFeedbackComment(message)">
									{{ t('hermiq', 'Send feedback') }}
								</NcButton>
							</div>
						</div>
					</div>

					<!-- Live streaming bubble -->
					<div
						v-if="isStreaming"
						class="chat-page__message chat-page__message--assistant">
						<div class="chat-page__avatar">
							<Creation :size="30" />
						</div>
						<div class="chat-page__bubble">
							<div class="chat-page__bubble-head">
								<span class="chat-page__sender">{{
									agentName
								}}</span>
							</div>
							<div
								v-if="streamingTools.length > 0"
								class="chat-page__stream-tools">
								<span
									v-for="(tool, toolIndex) in streamingTools"
									:key="toolIndex"
									class="chat-page__stream-tool">
									{{
										tool.done
											? t('hermiq', 'Used tool: {tool}', {
													tool: tool.toolId,
												})
											: t('hermiq', 'Using tool: {tool}…', {
													tool: tool.toolId,
												})
									}}
								</span>
							</div>
							<!-- Streamed markdown is sanitised via DOMPurify with the shared safe config. -->
							<!-- eslint-disable-next-line vue/no-v-html -->
							<div
								v-if="streamingText"
								class="chat-page__text"
								v-html="renderMarkdown(streamingText)" />
							<div v-else class="chat-page__typing">
								<span /><span /><span />
							</div>
						</div>
					</div>
				</div>

				<!-- Composer -->
				<div class="chat-page__composer">
					<NcNoteCard v-if="sendError" type="error">
						{{ sendError }}
					</NcNoteCard>
					<div class="chat-page__composer-row">
						<!-- Same reason as the feedback box: the placeholder is a hint,
						     not a name, and it is gone as soon as there is a message. -->
						<textarea
							ref="messageInput"
							v-model="currentMessage"
							class="chat-page__input"
							rows="1"
							:aria-label="t('hermiq', 'Message')"
							:placeholder="t('hermiq', 'Ask a question…')"
							:disabled="sending"
							@keydown.enter.exact.prevent="handleSend"
							@input="autoResize" />
						<NcButton
							variant="primary"
							:disabled="!currentMessage.trim() || sending"
							:aria-label="t('hermiq', 'Send message')"
							@click="handleSend">
							<template #icon>
								<NcLoadingIcon v-if="sending" :size="20" />
								<Send v-else :size="20" />
							</template>
						</NcButton>
					</div>
					<p class="chat-page__composer-hint">
						{{
							t(
								'hermiq',
								'Press Enter to send, Shift+Enter for a new line',
							)
						}}
					</p>
				</div>
			</template>
		</section>

		<!-- Isolated modals (ADR-004) -->
		<SessionRenameModal
			:show="showRename"
			:session="activeSession"
			@close="showRename = false"
			@saved="onRenamed" />
		<SessionDeleteModal
			:show="showDelete"
			:session="deleteTarget"
			@close="showDelete = false"
			@deleted="onDeleted" />
		<ChatSettingsModal
			:show="showSettings"
			:availableViews="availableViews"
			:availableTools="availableTools"
			:value="settings"
			@input="settings = $event"
			@close="showSettings = false" />

		<!-- hermiq-skill-conversational-authoring: "Save as skill" seam — opens the
		     hermiq-skill-markdown-authoring SkillFormModal pre-filled from an assistant
		     message, saving through the quarantine review path (source: local). -->
		<SkillFormModal
			:show="showSaveAsSkill"
			:initialBody="saveAsSkillBody"
			saveTarget="quarantine"
			@close="showSaveAsSkill = false"
			@saved="onSkillSaved" />
	</div>
</template>

<script>
import { SAFE_MARKDOWN_DOMPURIFY_CONFIG } from '@conduction/nextcloud-vue'
import { getCurrentUser } from '@nextcloud/auth'
import { showError, showSuccess } from '@nextcloud/dialogs'
import {
	NcActionButton,
	NcActions,
	NcAvatar,
	NcButton,
	NcCheckboxRadioSwitch,
	NcLoadingIcon,
	NcNoteCard,
} from '@nextcloud/vue'
import DOMPurify from 'dompurify'
import { marked } from 'marked'
import Archive from 'vue-material-design-icons/Archive.vue'
// One icon per session row, chosen by what started the session. A row that
// always drew the same mark would leave the human/automated split visible only
// in the group headings, which scroll away; the agent itself is named in the
// row's meta line beside the time.
import ClockOutline from 'vue-material-design-icons/ClockOutline.vue'
import CogOutline from 'vue-material-design-icons/CogOutline.vue'
// The assistant is drawn with the AI sparkles, not a robot — the same mark as
// the launcher hex and the chat empty state, so "this came from the model"
// reads identically wherever it appears.
import Creation from 'vue-material-design-icons/Creation.vue'
import CubeOutline from 'vue-material-design-icons/CubeOutline.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import FileDocument from 'vue-material-design-icons/FileDocument.vue'
import FileDocumentOutline from 'vue-material-design-icons/FileDocumentOutline.vue'
import FlashOutline from 'vue-material-design-icons/FlashOutline.vue'
import MessageText from 'vue-material-design-icons/MessageText.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import PuzzlePlusOutline from 'vue-material-design-icons/PuzzlePlusOutline.vue'
import Restore from 'vue-material-design-icons/Restore.vue'
import Send from 'vue-material-design-icons/Send.vue'
import SitemapOutline from 'vue-material-design-icons/SitemapOutline.vue'
import ThumbDown from 'vue-material-design-icons/ThumbDown.vue'
import ThumbUp from 'vue-material-design-icons/ThumbUp.vue'
import AgentSelector from '../components/AgentSelector.vue'
import ChatSettingsModal from '../modals/ChatSettingsModal.vue'
import SessionDeleteModal from '../modals/SessionDeleteModal.vue'
import SessionRenameModal from '../modals/SessionRenameModal.vue'
import SkillFormModal from '../modals/SkillFormModal.vue'
import {
	archiveSession,
	ChatStreamError,
	createSession,
	getSession,
	listMessages,
	listSessions,
	restoreSession,
	sendChatMessage,
	sendMessageFeedback,
	streamChatMessage,
} from '../api/chat.js'
import { useAgentStore } from '../store/store.js'

/**
 * The trigger origins that mean "no person started this".
 *
 * Listed positively so an origin the frontend has not heard of falls to the
 * human group. The alternative — treating anything that is not `human` as
 * automated — would hide a real chat behind a heading the user does not expect
 * to look under the first time a new origin ships.
 */
const AUTOMATED_ORIGINS = ['cron', 'event', 'flow']

/** The row icon for each trigger origin; anything else uses the agent mark. */
const ORIGIN_ICONS = {
	cron: 'ClockOutline',
	event: 'FlashOutline',
	flow: 'SitemapOutline',
	human: 'Creation',
}

export default {
	name: 'Chat',

	components: {
		AgentSelector,
		Archive,
		ChatSettingsModal,
		ClockOutline,
		CogOutline,
		SessionDeleteModal,
		SessionRenameModal,
		CubeOutline,
		Delete,
		FileDocument,
		FileDocumentOutline,
		FlashOutline,
		MessageText,
		NcActionButton,
		NcActions,
		NcAvatar,
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcNoteCard,
		Pencil,
		Plus,
		PuzzlePlusOutline,
		Restore,
		Creation,
		Send,
		SitemapOutline,
		SkillFormModal,
		ThumbDown,
		ThumbUp,
	},

	// nc-vue's floating AI companion self-gates on the injected `cnAiContext`
	// holder: it hides itself when `pageKind === 'chat'`. Its own doc comment
	// promises it "hides on chat pages", but nothing declares the page kind for
	// a hand-written view — nc-vue only sets it from CnIndexPage — so on hermiq's
	// own chat page the companion stayed mounted and its bottom-right FAB sat
	// directly over the composer's Send button, swallowing the click. Declaring
	// the page kind here is the contract's intended opt-out, and it also stops
	// hermiq offering an "Open AI chat" shortcut on top of the chat itself.
	inject: {
		cnAiContext: { default: null },
	},

	data() {
		return {
			// The signed-in user, for their own turns' avatar. Read once: it cannot
			// change while the thread is open, and NcAvatar needs the uid to fetch
			// the real image rather than render an initial.
			currentUserId: getCurrentUser()?.uid || '',
			currentUserName: getCurrentUser()?.displayName || '',
			// Session lists
			sessions: [],
			archivedSessions: [],
			showArchive: false,
			sessionsLoading: true,

			// Active thread
			activeSession: null,
			messages: [],
			messagesLoading: false,
			currentAgent: null,

			// Agent selector
			agents: [],
			agentsLoading: true,
			agentsError: '',
			startingId: '',

			// Composer + streaming
			currentMessage: '',
			sending: false,
			sendError: '',
			isStreaming: false,
			streamingText: '',
			streamingTools: [],

			// Per-session settings (rides on POST /api/chat/send)
			settings: this.defaultSettings(),

			// Modals
			showRename: false,
			showSettings: false,
			showDelete: false,
			deleteTarget: null,

			// hermiq-skill-conversational-authoring: "Save as skill" seam state.
			showSaveAsSkill: false,
			saveAsSkillBody: '',
		}
	},

	computed: {
		/**
		 * The sessions for the visible tab.
		 *
		 * @return {Array<object>} Active or archived sessions.
		 * @spec openspec/changes/session-frontend-rename/specs/session-surface/spec.md#requirement-human-and-automated-sessions-must-be-listed-separately
		 */
		visibleSessions() {
			return this.showArchive ? this.archivedSessions : this.sessions
		},

		/**
		 * The visible sessions, grouped for rendering.
		 *
		 * The Active tab splits into what a person started and what a cron, an
		 * event or a flow started: they need different attention, and a list that
		 * mixes them makes an overnight run look like an unread message. The
		 * Archive tab is one flat list — there is nothing to triage there.
		 *
		 * A group with no sessions is dropped rather than rendered empty, and a
		 * lone group renders without a heading, so an installation that has never
		 * run an automated session sees exactly the list it saw before.
		 *
		 * @return {Array<{key: string, heading: string, sessions: Array<object>}>} The groups to render, in order.
		 * @spec openspec/changes/session-frontend-rename/specs/session-surface/spec.md#requirement-human-and-automated-sessions-must-be-listed-separately
		 */
		sessionGroups() {
			if (this.showArchive) {
				return [
					{ key: 'archive', heading: '', sessions: this.visibleSessions },
				]
			}

			const human = []
			const automated = []
			for (const session of this.visibleSessions) {
				;(this.isAutomated(session) ? automated : human).push(session)
			}

			const groups = []
			if (human.length > 0) {
				groups.push({
					key: 'human',
					heading: this.t('hermiq', 'Started by you'),
					sessions: human,
				})
			}
			if (automated.length > 0) {
				groups.push({
					key: 'automated',
					heading: this.t('hermiq', 'Started automatically'),
					sessions: automated,
				})
			}
			// One group needs no heading: the tab label already says what it is.
			if (groups.length === 1) {
				groups[0].heading = ''
			}
			return groups
		},

		/**
		 * The thread header title.
		 *
		 * @return {string} Agent name, session title, or the page name.
		 * @spec openspec/changes/session-frontend-rename/specs/session-surface/spec.md#requirement-the-application-must-use-one-word-for-a-session
		 */
		headerTitle() {
			return (
				this.currentAgent?.name
				|| this.activeSession?.title
				|| this.t('hermiq', 'Chat')
			)
		},

		/**
		 * The assistant display name for message bubbles.
		 *
		 * @return {string} The agent name or a generic label.
		 */
		agentName() {
			return this.currentAgent?.name || this.t('hermiq', 'Assistant')
		},

		/**
		 * The current agent's selectable views ({uuid, name}).
		 *
		 * @return {Array<object>} The view descriptors.
		 */
		availableViews() {
			const raw = Array.isArray(this.currentAgent?.views)
				? this.currentAgent.views
				: []
			return raw.map((view) =>
				typeof view === 'string' ? { uuid: view, name: view } : view,
			)
		},

		/**
		 * The current agent's selectable tools ({uuid, name}).
		 *
		 * @return {Array<object>} The tool descriptors.
		 */
		availableTools() {
			const raw = Array.isArray(this.currentAgent?.tools)
				? this.currentAgent.tools
				: []
			return raw.map((tool) => {
				if (typeof tool === 'string') {
					const label = tool
						.replace(/^[a-z0-9_-]+\./i, '')
						.replace(/_/g, ' ')
					return {
						uuid: tool,
						name: label.charAt(0).toUpperCase() + label.slice(1),
					}
				}
				return tool
			})
		},

		/**
		 * Whether the user narrowed the default all-enabled settings. When
		 * true the turn must go over POST /api/chat/send (the stream endpoint
		 * does not accept views/tools/RAG settings — see the docblock).
		 *
		 * @return {boolean} True when settings differ from the defaults.
		 */
		settingsCustomised() {
			const defaults = this.defaultSettingsFor(this.currentAgent)
			return (
				this.settings.views.length !== defaults.views.length
				|| this.settings.tools.length !== defaults.tools.length
				|| this.settings.includeObjects !== defaults.includeObjects
				|| this.settings.includeFiles !== defaults.includeFiles
				|| this.settings.numSourcesObjects !== defaults.numSourcesObjects
				|| this.settings.numSourcesFiles !== defaults.numSourcesFiles
			)
		},
	},

	/**
	 * Register the agent object type, then load the sessions and agents.
	 *
	 * @spec openspec/changes/session-frontend-rename/specs/session-surface/spec.md#requirement-the-application-must-use-one-word-for-a-session
	 */
	created() {
		if (this.cnAiContext) {
			this.cnAiContext.pageKind = 'chat'
		}
		this.agentStore = useAgentStore()
		this.agentStore.registerObjectType('agent', 'agent', 'hermiq')
		this.loadSessions()
		this.loadAgents()
	},

	beforeUnmount() {
		// The holder is shared across routes, so leaving it on 'chat' would
		// suppress the companion on every page visited after the chat.
		if (this.cnAiContext) {
			this.cnAiContext.pageKind = 'custom'
		}
	},

	methods: {
		/**
		 * The all-defaults settings object (no agent context).
		 *
		 * @return {object} The default settings.
		 */
		defaultSettings() {
			return {
				views: [],
				tools: [],
				includeObjects: true,
				includeFiles: true,
				numSourcesObjects: 5,
				numSourcesFiles: 5,
			}
		},

		/**
		 * The default (everything-enabled) settings for an agent, mirroring
		 * OR's loadAgentCapabilities(): all views/tools selected, RAG values
		 * seeded from the agent.
		 *
		 * @param {object|null} agent The agent object.
		 * @return {object} The default settings.
		 */
		defaultSettingsFor(agent) {
			if (!agent) {
				return this.defaultSettings()
			}
			const views = Array.isArray(agent.views) ? agent.views : []
			const tools = Array.isArray(agent.tools) ? agent.tools : []
			return {
				views: views.map((view) =>
					typeof view === 'string' ? view : view.uuid,
				),

				tools: tools.map((tool) =>
					typeof tool === 'string' ? tool : tool.uuid,
				),

				includeObjects: agent.searchObjects ?? true,
				includeFiles: agent.searchFiles ?? true,
				numSourcesObjects: agent.ragNumSources ?? 5,
				numSourcesFiles: agent.ragNumSources ?? 5,
			}
		},

		/**
		 * Load the active session list (and the archive when visible).
		 *
		 * @param {boolean} soft True to skip the loading state.
		 * @return {Promise<void>}
		 * @spec openspec/changes/session-frontend-rename/specs/session-surface/spec.md#requirement-the-application-must-use-one-word-for-a-session
		 */
		async loadSessions(soft = false) {
			if (!soft) {
				this.sessionsLoading = true
			}
			try {
				const { results } = await listSessions({ archived: false })
				this.sessions = results
				if (this.showArchive) {
					const archived = await listSessions({ archived: true })
					this.archivedSessions = archived.results
				}
			} catch (e) {
				showError(this.t('hermiq', 'Could not load sessions.'))
			} finally {
				this.sessionsLoading = false
			}
		},

		/**
		 * Load the agents for the selector (createObjectStore, hermiq register).
		 *
		 * @return {Promise<void>}
		 */
		async loadAgents() {
			this.agentsLoading = true
			this.agentsError = ''
			const agents = await this.agentStore.fetchCollection('agent')
			this.agents = Array.isArray(agents) ? agents : []
			if (this.agentStore.errors?.agent) {
				this.agentsError =
					this.agentStore.errors.agent.message
					|| this.t('hermiq', 'Could not load agents.')
			}
			this.agentsLoading = false
		},

		/**
		 * Switch between the active and archive session tabs.
		 *
		 * @param {boolean} archive True for the archive tab.
		 * @return {Promise<void>}
		 * @spec openspec/changes/session-frontend-rename/specs/session-surface/spec.md#requirement-human-and-automated-sessions-must-be-listed-separately
		 */
		async setArchiveTab(archive) {
			this.showArchive = archive
			if (archive) {
				this.sessionsLoading = true
				try {
					const { results } = await listSessions({ archived: true })
					this.archivedSessions = results
				} catch (e) {
					showError(this.t('hermiq', 'Could not load archived sessions.'))
				} finally {
					this.sessionsLoading = false
				}
			}
		},

		/**
		 * Whether a session is the active one.
		 *
		 * @param {object} session The session to check.
		 * @return {boolean} True when active.
		 */
		isActive(session) {
			return this.activeSession?.uuid === session.uuid
		},

		/**
		 * Whether a session was started by something other than a person.
		 *
		 * The test is on the negative: an unrecognised origin, or a session
		 * stored before the property existed, counts as human. Grouping the
		 * unknown as automated would quietly move a person's own chat out of the
		 * list they look at first.
		 *
		 * @param {object} session The session to classify.
		 * @return {boolean} True for a cron, event or flow session.
		 * @spec openspec/changes/session-frontend-rename/specs/session-surface/spec.md#requirement-human-and-automated-sessions-must-be-listed-separately
		 */
		isAutomated(session) {
			return AUTOMATED_ORIGINS.includes(session?.triggerOrigin)
		},

		/**
		 * The icon for a session row, from what started the session.
		 *
		 * @param {object} session The session.
		 * @return {string} The registered component name to render.
		 * @spec openspec/changes/session-frontend-rename/specs/session-surface/spec.md#requirement-a-session-row-must-identify-its-agent-and-its-time
		 */
		originIcon(session) {
			return ORIGIN_ICONS[session?.triggerOrigin] || 'Creation'
		},

		/**
		 * The agent's name for a session, when the agent is known.
		 *
		 * The agent list is loaded for the picker; a session whose agent has since
		 * been deleted, or one read before that list lands, simply contributes no
		 * name rather than an id the user cannot read.
		 *
		 * @param {object} session The session.
		 * @return {string} The agent name, or an empty string.
		 * @spec openspec/changes/session-frontend-rename/specs/session-surface/spec.md#requirement-a-session-row-must-identify-its-agent-and-its-time
		 */
		agentNameFor(session) {
			const id = session?.agentId
			if (!id) {
				return ''
			}
			const agent = this.agents.find(
				(entry) => entry.id === id || entry.uuid === id,
			)
			return agent?.name || ''
		},

		/**
		 * The row's second line: which agent, and when it was last active.
		 *
		 * @param {object} session The session.
		 * @return {string} The meta line.
		 * @spec openspec/changes/session-frontend-rename/specs/session-surface/spec.md#requirement-a-session-row-must-identify-its-agent-and-its-time
		 */
		rowMeta(session) {
			const time = this.formatTime(session?.updated)
			const agent = this.agentNameFor(session)
			if (agent === '') {
				return time
			}
			return `${agent} · ${time}`
		},

		/**
		 * Return to the start-a-session surface.
		 *
		 * Clearing the thread is only visible when there was a thread. With none
		 * open the control had nothing to do and read as broken, so it also moves
		 * focus to the start surface: the click now always changes something the
		 * user can see.
		 *
		 * @return {void}
		 * @spec openspec/changes/session-frontend-rename/specs/session-surface/spec.md#requirement-starting-a-new-session-must-produce-a-visible-result
		 */
		newSession() {
			this.activeSession = null
			this.messages = []
			this.currentAgent = null
			this.sendError = ''
			this.settings = this.defaultSettings()
			this.$nextTick(() => {
				const surface = this.$refs.startSurface
				if (!surface) {
					return
				}
				surface.scrollTop = 0
				const card = surface.querySelector(
					'.agent-selector__card button, .agent-selector button',
				)
				if (card) {
					card.focus()
				}
			})
		},

		/**
		 * Open a session: load its messages and its agent, and seed the
		 * per-session settings from the agent's capabilities.
		 *
		 * @param {object} session The session to open.
		 * @return {Promise<void>}
		 * @spec openspec/changes/session-frontend-rename/specs/session-surface/spec.md#requirement-the-application-must-use-one-word-for-a-session
		 */
		async selectSession(session) {
			this.activeSession = session
			this.messages = []
			this.sendError = ''
			this.messagesLoading = true
			try {
				const [{ results }] = await Promise.all([
					listMessages(session.uuid),
					this.loadAgentFor(session),
				])
				this.messages = results
				this.settings = this.defaultSettingsFor(this.currentAgent)
				this.scrollToBottom()
			} catch (e) {
				showError(this.t('hermiq', 'Could not load the session.'))
			} finally {
				this.messagesLoading = false
			}
		},

		/**
		 * Load a session's agent (non-fatal on miss).
		 *
		 * @param {object} session The session whose agent to load.
		 * @return {Promise<void>}
		 * @spec openspec/changes/session-frontend-rename/specs/session-surface/spec.md#requirement-the-application-must-use-one-word-for-a-session
		 */
		async loadAgentFor(session) {
			this.currentAgent = null
			if (!session.agentId) {
				return
			}
			const agent = await this.agentStore.fetchObject('agent', session.agentId)
			this.currentAgent = agent || null
		},

		/**
		 * Create a session with the picked agent and activate it.
		 *
		 * @param {object} agent The agent to start with.
		 * @return {Promise<void>}
		 * @spec openspec/changes/session-frontend-rename/specs/session-surface/spec.md#requirement-starting-a-new-session-must-produce-a-visible-result
		 */
		async startWithAgent(agent) {
			const agentUuid = agent.uuid || agent.id
			this.startingId = agentUuid
			try {
				const session = await createSession(agentUuid)
				this.currentAgent = agent
				this.activeSession = session
				this.messages = []
				this.settings = this.defaultSettingsFor(agent)
				await this.loadSessions(true)
				showSuccess(
					this.t('hermiq', 'Session started with {agent}', {
						agent: agent.name || agentUuid,
					}),
				)
			} catch (e) {
				showError(this.t('hermiq', 'Could not start the session.'))
			} finally {
				this.startingId = ''
			}
		},

		/**
		 * Send the composed message: stream by default, POST /send when
		 * settings are customised, and fall back from stream to /send on
		 * transport failure (ADR-034 fallback ladder).
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/session-frontend-rename/specs/session-surface/spec.md#requirement-the-application-must-use-one-word-for-a-session
		 */
		async handleSend() {
			const text = this.currentMessage.trim()
			if (!text || this.sending || !this.activeSession) {
				return
			}
			this.currentMessage = ''
			this.sendError = ''
			this.sending = true

			// Optimistic user bubble (replaced by server truth after the turn).
			this.messages.push({
				id: `optimistic-${Date.now()}`,
				role: 'user',
				content: text,
				created: new Date().toISOString(),
			})
			this.scrollToBottom()

			const uuid = this.activeSession.uuid
			try {
				if (this.settingsCustomised) {
					await this.sendViaPost(text, uuid)
				} else {
					await this.sendViaStream(text, uuid)
				}
			} catch (e) {
				this.sendError =
					e?.response?.data?.message
					|| e?.message
					|| this.t('hermiq', 'Failed to get a response.')
			} finally {
				this.isStreaming = false
				this.streamingText = ''
				this.streamingTools = []
				this.sending = false
				// Server truth: persisted ids (feedback), sources, generated title.
				await this.refreshThread(uuid)
				this.scrollToBottom()
				this.focusComposer()
			}
		},

		/**
		 * Stream one turn over SSE, falling back to POST /send on transport
		 * failure. A terminal `error` event is NOT retried (the turn failed
		 * server-side; retrying would duplicate the user message).
		 *
		 * @param {string} text The user message.
		 * @param {string} uuid The session UUID.
		 * @return {Promise<void>}
		 * @spec openspec/changes/session-frontend-rename/specs/session-surface/spec.md#requirement-the-application-must-use-one-word-for-a-session
		 */
		async sendViaStream(text, uuid) {
			this.isStreaming = true
			this.streamingText = ''
			this.streamingTools = []
			try {
				await streamChatMessage(
					{ message: text, sessionUuid: uuid },
					{
						onToken: (delta) => {
							this.streamingText += delta
							this.scrollToBottom()
						},
						onToolCall: (payload) => {
							this.streamingTools.push({
								toolId: payload.toolId || this.t('hermiq', 'tool'),
								done: false,
							})
						},
						onToolResult: (payload) => {
							const entry = this.streamingTools.find(
								(tool) =>
									tool.toolId === payload.toolId && !tool.done,
							)
							if (entry) {
								entry.done = true
							}
						},
					},
				)
			} catch (e) {
				if (e instanceof ChatStreamError && e.transport) {
					// ADR-034 fallback ladder: degrade to the synchronous endpoint.
					this.isStreaming = false
					await this.sendViaPost(text, uuid)
					return
				}
				throw e
			}
		},

		/**
		 * Send one turn over POST /api/chat/send with the per-session
		 * views/tools/RAG settings.
		 *
		 * @param {string} text The user message.
		 * @param {string} uuid The session UUID.
		 * @return {Promise<void>}
		 * @spec openspec/changes/session-frontend-rename/specs/session-surface/spec.md#requirement-the-application-must-use-one-word-for-a-session
		 */
		async sendViaPost(text, uuid) {
			await sendChatMessage({
				message: text,
				sessionUuid: uuid,
				views: this.settings.views,
				tools: this.settings.tools,
				ragSettings: {
					includeObjects: this.settings.includeObjects,
					includeFiles: this.settings.includeFiles,
					numSourcesObjects: this.settings.numSourcesObjects,
					numSourcesFiles: this.settings.numSourcesFiles,
				},
			})
		},

		/**
		 * Re-read the thread and lists from the server after a turn.
		 *
		 * @param {string} uuid The session UUID.
		 * @return {Promise<void>}
		 * @spec openspec/changes/session-frontend-rename/specs/session-surface/spec.md#requirement-the-application-must-use-one-word-for-a-session
		 */
		async refreshThread(uuid) {
			try {
				const [{ results }, session] = await Promise.all([
					listMessages(uuid),
					getSession(uuid),
				])
				if (this.activeSession?.uuid === uuid) {
					this.messages = results
					this.activeSession = session
				}
				await this.loadSessions(true)
			} catch (e) {
				// Non-fatal: the optimistic thread stays; the next action re-syncs.
			}
		},

		/**
		 * Record thumbs up/down on an assistant message and open the optional
		 * comment box (mirrors OR's toggle semantics).
		 *
		 * @param {object} message The assistant message.
		 * @param {string} type 'positive' or 'negative'.
		 * @return {Promise<void>}
		 * @spec openspec/changes/session-frontend-rename/specs/session-surface/spec.md#requirement-the-application-must-use-one-word-for-a-session
		 */
		async sendFeedback(message, type) {
			const cleared = message.feedback === type
			message.feedback = cleared ? null : type
			message.showFeedbackInput = !cleared
			if (cleared) {
				return
			}
			try {
				await sendMessageFeedback(
					this.activeSession.uuid,
					message.uuid || message.id,
					{ type },
				)
				showSuccess(this.t('hermiq', 'Feedback recorded'))
			} catch (e) {
				showError(this.t('hermiq', 'Could not record feedback.'))
				message.feedback = null
				message.showFeedbackInput = false
			}
		},

		/**
		 * Send the optional feedback elaboration comment.
		 *
		 * @param {object} message The assistant message.
		 * @return {Promise<void>}
		 * @spec openspec/changes/session-frontend-rename/specs/session-surface/spec.md#requirement-the-application-must-use-one-word-for-a-session
		 */
		async saveFeedbackComment(message) {
			if (!message.feedbackComment || !message.feedbackComment.trim()) {
				return
			}
			try {
				await sendMessageFeedback(
					this.activeSession.uuid,
					message.uuid || message.id,
					{
						type: message.feedback,
						comment: message.feedbackComment.trim(),
					},
				)
				message.showFeedbackInput = false
				showSuccess(this.t('hermiq', 'Thanks for the additional feedback!'))
			} catch (e) {
				showError(this.t('hermiq', 'Could not save the feedback comment.'))
			}
		},

		/**
		 * "Save as skill" (hermiq-skill-conversational-authoring): open the
		 * hermiq-skill-markdown-authoring SkillFormModal pre-filled with this
		 * assistant message's content as the SKILL.md `body`, for review/edit
		 * before saving. No new agent run — the SKILL.md is whatever the
		 * existing chat/agent engine already produced in this message.
		 *
		 * @param {object} message The assistant message to turn into a skill.
		 * @return {void}
		 */
		openSaveAsSkill(message) {
			this.saveAsSkillBody = message.content || ''
			this.showSaveAsSkill = true
		},

		/**
		 * SkillFormModal's `saved` handler for the chat seam — the skill was
		 * saved via `save-target="quarantine"`, so it lands `quarantined` and
		 * is NOT immediately usable by an agent until Approved.
		 *
		 * @return {void}
		 */
		onSkillSaved() {
			showSuccess(
				this.t(
					'hermiq',
					'Skill saved for review. Approve it in the Skills catalog before it can be used.',
				),
			)
		},

		/**
		 * Archive (soft delete) a session.
		 *
		 * @param {object} session The session to archive.
		 * @return {Promise<void>}
		 * @spec openspec/changes/session-frontend-rename/specs/session-surface/spec.md#requirement-session-row-actions-must-live-in-an-action-menu
		 */
		async archive(session) {
			try {
				await archiveSession(session.uuid)
				if (this.isActive(session)) {
					this.newSession()
				}
				await this.loadSessions(true)
				showSuccess(this.t('hermiq', 'Session archived'))
			} catch (e) {
				showError(this.t('hermiq', 'Could not archive the session.'))
			}
		},

		/**
		 * Restore an archived session.
		 *
		 * @param {object} session The session to restore.
		 * @return {Promise<void>}
		 * @spec openspec/changes/session-frontend-rename/specs/session-surface/spec.md#requirement-session-row-actions-must-live-in-an-action-menu
		 */
		async restore(session) {
			try {
				await restoreSession(session.uuid)
				this.archivedSessions = this.archivedSessions.filter(
					(entry) => entry.uuid !== session.uuid,
				)
				await this.loadSessions(true)
				showSuccess(this.t('hermiq', 'Session restored'))
			} catch (e) {
				showError(this.t('hermiq', 'Could not restore the session.'))
			}
		},

		/**
		 * Open the permanent-delete confirmation modal.
		 *
		 * @param {object} session The archived session.
		 * @return {void}
		 * @spec openspec/changes/session-frontend-rename/specs/session-surface/spec.md#requirement-session-row-actions-must-live-in-an-action-menu
		 */
		openDelete(session) {
			this.deleteTarget = session
			this.showDelete = true
		},

		/**
		 * Handle a completed permanent delete.
		 *
		 * @param {object} session The deleted session.
		 * @return {void}
		 * @spec openspec/changes/session-frontend-rename/specs/session-surface/spec.md#requirement-session-row-actions-must-live-in-an-action-menu
		 */
		onDeleted(session) {
			this.archivedSessions = this.archivedSessions.filter(
				(entry) => entry.uuid !== session.uuid,
			)
			if (this.isActive(session)) {
				this.newSession()
			}
			showSuccess(this.t('hermiq', 'Session deleted'))
		},

		/**
		 * Handle a completed rename.
		 *
		 * @param {object} session The updated session.
		 * @return {Promise<void>}
		 * @spec openspec/changes/session-frontend-rename/specs/session-surface/spec.md#requirement-session-row-actions-must-live-in-an-action-menu
		 */
		async onRenamed(session) {
			this.activeSession = session
			await this.loadSessions(true)
			showSuccess(this.t('hermiq', 'Session renamed'))
		},

		/**
		 * Render assistant/user markdown safely (marked + DOMPurify with the
		 * shared nc-vue safe config).
		 *
		 * @param {string} content The raw message text.
		 * @return {string} Sanitised HTML.
		 */
		renderMarkdown(content) {
			return DOMPurify.sanitize(
				marked.parse(content || ''),
				SAFE_MARKDOWN_DOMPURIFY_CONFIG,
			)
		},

		/**
		 * Compact relative/absolute timestamp for list rows and bubbles.
		 *
		 * @param {string} timestamp ISO timestamp.
		 * @return {string} Human label.
		 */
		formatTime(timestamp) {
			if (!timestamp) {
				return ''
			}
			const date = new Date(timestamp)
			if (Number.isNaN(date.getTime())) {
				return ''
			}
			const diff = Date.now() - date.getTime()
			if (diff < 60000) {
				return this.t('hermiq', 'Just now')
			}
			if (diff < 86400000) {
				return date.toLocaleTimeString([], {
					hour: '2-digit',
					minute: '2-digit',
				})
			}
			return date.toLocaleDateString()
		},

		/**
		 * Auto-grow the composer textarea (capped).
		 *
		 * @return {void}
		 */
		autoResize() {
			const textarea = this.$refs.messageInput
			if (textarea) {
				textarea.style.height = 'auto'
				textarea.style.height = Math.min(textarea.scrollHeight, 150) + 'px'
			}
		},

		/**
		 * Scroll the thread to the latest message.
		 *
		 * @return {void}
		 */
		scrollToBottom() {
			this.$nextTick(() => {
				const container = this.$refs.messagesContainer
				if (container) {
					container.scrollTop = container.scrollHeight
				}
			})
		},

		/**
		 * Return focus to the composer after a turn.
		 *
		 * @return {void}
		 */
		focusComposer() {
			this.$nextTick(() => {
				this.$refs.messageInput?.focus()
			})
		},
	},
}
</script>

<style scoped>
.chat-page {
	display: flex;
	height: 100%;
	min-height: 0;
}

/* ── Session list column ─────────────────────────────────────── */

.chat-page__list {
	display: flex;
	flex-direction: column;
	gap: 12px;
	width: 300px;
	flex-shrink: 0;
	padding: 16px 12px;
	border-right: 1px solid var(--color-border);
	overflow-y: auto;
}

.chat-page__list-head {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.chat-page__tabs {
	display: flex;
}

.chat-page__list-state {
	display: flex;
	justify-content: center;
	padding: 24px 0;
}

.chat-page__rows {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

/* Flush list rows matching nc-vue's index-sidebar / NC app-navigation
   convention (cf. procest /cases): no per-item border, a subtle rounded
   hover, and a filled active pill — rather than bordered cards. */
.chat-page__row {
	display: flex;
	align-items: center;
	gap: 4px;
	padding: 8px 12px;
	border-radius: var(--border-radius-large, 8px);
	transition: background-color 0.1s ease-in-out;
}

.chat-page__row:hover {
	background-color: var(--color-background-hover);
}

.chat-page__row--active {
	background-color: var(--color-primary-element-light);
}

.chat-page__row--active:hover {
	background-color: var(
		--color-primary-element-light-hover,
		var(--color-primary-element-light)
	);
}

.chat-page__group {
	margin: 12px 0 2px;
	padding: 0 12px;
	font-size: 12px;
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: 0.04em;
	color: var(--color-text-maxcontrast);
}

.chat-page__group:first-child {
	margin-top: 0;
}

.chat-page__row-main {
	display: flex;
	align-items: center;
	gap: 10px;
	flex: 1;
	min-width: 0;
	cursor: pointer;
}

.chat-page__row-icon {
	display: flex;
	flex-shrink: 0;
	color: var(--color-text-maxcontrast);
}

.chat-page__row-text {
	display: flex;
	flex-direction: column;
	gap: 2px;
	min-width: 0;
}

.chat-page__row-text strong {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.chat-page__row-meta {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.chat-page__row-actions {
	flex-shrink: 0;
}

/* ── Thread column ────────────────────────────────────────────────── */

.chat-page__thread {
	display: flex;
	flex-direction: column;
	flex: 1;
	min-width: 0;
	min-height: 0;
}

.chat-page__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	padding: 16px 20px;
	border-bottom: 1px solid var(--color-border);
}

.chat-page__heading {
	display: flex;
	align-items: center;
	gap: 10px;
	margin: 0;
	font-size: 20px;
	font-weight: 600;
	min-width: 0;
}

.chat-page__header-actions {
	display: flex;
	gap: 4px;
}

.chat-page__empty {
	flex: 1;
	display: flex;
	flex-direction: column;
	padding: 32px 20px;
	overflow-y: auto;
}

/* `margin: auto` centres while the content fits and resolves to 0 when it
   does not, so the first row of agent cards is never pushed above the scroll
   origin. `justify-content: center` on the scrolling element is the bug this
   replaces: overflow past the start of a centred flex container cannot be
   scrolled to. */
.chat-page__empty-inner {
	margin: auto;
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 8px;
	width: 100%;
}

.chat-page__empty-icon {
	opacity: 0.5;
}

.chat-page__empty h3 {
	margin: 8px 0 0;
	font-size: 20px;
	font-weight: 600;
}

.chat-page__empty p {
	margin: 0 0 16px;
	color: var(--color-text-maxcontrast);
}

.chat-page__empty .agent-selector {
	width: 100%;
	max-width: 900px;
}

/* ── Messages ─────────────────────────────────────────────────────── */

.chat-page__messages {
	flex: 1;
	overflow-y: auto;
	display: flex;
	flex-direction: column;
	gap: 20px;
	padding: 16px 20px;
}

.chat-page__messages-state {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 8px;
	padding: 32px;
	color: var(--color-text-maxcontrast);
}

.chat-page__messages-state p {
	margin: 0;
}

.chat-page__message {
	display: flex;
	gap: 12px;
}

.chat-page__avatar {
	flex-shrink: 0;
}

.chat-page__bubble {
	flex: 1;
	max-width: 80%;
	padding: 10px 14px;
	border-radius: var(--border-radius-large, 8px);
}

.chat-page__message--user .chat-page__bubble {
	background: var(--color-background-hover);
}

.chat-page__bubble-head {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 8px;
	margin-bottom: 6px;
}

.chat-page__sender {
	font-weight: 600;
	font-size: 13px;
}

.chat-page__time {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.chat-page__text {
	font-size: 14px;
	line-height: 1.6;
	overflow-wrap: break-word;
}

.chat-page__text :deep(p) {
	margin: 0 0 8px;
}

.chat-page__text :deep(p:last-child) {
	margin: 0;
}

.chat-page__text :deep(code) {
	padding: 2px 6px;
	background: var(--color-background-dark);
	border-radius: 4px;
	font-size: 13px;
}

.chat-page__text :deep(pre) {
	padding: 12px;
	background: var(--color-background-dark);
	border-radius: 6px;
	overflow-x: auto;
}

/* ── Sources ──────────────────────────────────────────────────────── */

.chat-page__sources {
	margin-top: 10px;
	padding-top: 10px;
	border-top: 1px solid var(--color-border);
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.chat-page__sources-head {
	display: flex;
	align-items: center;
	gap: 6px;
	font-size: 12px;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

.chat-page__source {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 6px 8px;
	background: var(--color-background-hover);
	border-radius: 6px;
	font-size: 13px;
}

.chat-page__source-name {
	flex: 1;
	min-width: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.chat-page__source-match {
	font-size: 11px;
	color: var(--color-text-maxcontrast);
}

/* ── Feedback ─────────────────────────────────────────────────────── */

.chat-page__feedback {
	display: flex;
	gap: 4px;
	margin-top: 6px;
}

.chat-page__feedback--active-positive {
	color: var(--color-success-text, var(--color-success));
}

.chat-page__feedback--active-negative {
	color: var(--color-error-text, var(--color-error));
}

.chat-page__feedback-comment {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin-top: 8px;
	padding: 10px;
	background: var(--color-background-hover);
	border-radius: 6px;
}

.chat-page__feedback-input {
	width: 100%;
	padding: 8px 10px;
	border: 1px solid var(--color-border);
	border-radius: 6px;
	font-family: inherit;
	font-size: 14px;
	resize: vertical;
	background: var(--color-main-background);
	color: var(--color-main-text);
}

/* ── Streaming ────────────────────────────────────────────────────── */

.chat-page__stream-tools {
	display: flex;
	flex-direction: column;
	gap: 4px;
	margin-bottom: 6px;
}

.chat-page__stream-tool {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.chat-page__typing {
	display: flex;
	gap: 4px;
	padding: 6px 0;
}

.chat-page__typing span {
	width: 8px;
	height: 8px;
	background: var(--color-text-maxcontrast);
	border-radius: 50%;
	animation: chat-page-bounce 1.4s infinite ease-in-out both;
}

.chat-page__typing span:nth-child(1) {
	animation-delay: -0.32s;
}

.chat-page__typing span:nth-child(2) {
	animation-delay: -0.16s;
}

@keyframes chat-page-bounce {
	0%,
	80%,
	100% {
		transform: scale(0);
	}
	40% {
		transform: scale(1);
	}
}

/* ── Composer ─────────────────────────────────────────────────────── */

.chat-page__composer {
	display: flex;
	flex-direction: column;
	gap: 6px;
	padding: 12px 20px 16px;
	border-top: 1px solid var(--color-border);
}

.chat-page__composer-row {
	display: flex;
	gap: 8px;
	align-items: flex-end;
}

.chat-page__input {
	flex: 1;
	padding: 10px 14px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	font-family: inherit;
	font-size: 14px;
	line-height: 1.6;
	resize: none;
	min-height: 44px;
	max-height: 150px;
	background: var(--color-main-background);
	color: var(--color-main-text);
}

.chat-page__input:focus {
	outline: none;
	border-color: var(--color-primary-element);
}

.chat-page__input:disabled {
	opacity: 0.6;
	cursor: not-allowed;
}

.chat-page__composer-hint {
	margin: 0;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

/* ── Responsive ───────────────────────────────────────────────────── */

@media (max-width: 720px) {
	.chat-page {
		flex-direction: column;
	}

	.chat-page__list {
		width: 100%;
		max-height: 40%;
		border-right: none;
		border-bottom: 1px solid var(--color-border);
	}
}

/* WCAG 2.2 AA 2.3.3 (Animation from Interactions).
   The typing indicator is the one that matters here: it is an INFINITE bounce,
   and a looping animation is exactly the pattern that triggers vestibular
   symptoms. It is replaced with a static opacity rather than removed, so the
   "assistant is typing" state is still conveyed — dropping the animation
   without a replacement would delete the information along with the motion. */
@media (prefers-reduced-motion: reduce) {
	.chat-page__row {
		transition: none;
	}

	.chat-page__typing span {
		animation: none;
		opacity: 0.7;
	}
}
</style>
