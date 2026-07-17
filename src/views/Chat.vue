<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  Chat — the Hermiq chat page (agent-engine-port task 5.1).

  Merges OpenRegister's chat surface (src/views/chat/ChatIndex.vue +
  src/sidebars/chat/ChatSideBar.vue + src/components/AgentSelector.vue) onto
  hermiq's manifest SPA idioms: one custom page with an internal
  conversation-list column (active/archive) and a thread column
  (messages + composer + feedback), all against the Hermiq engine routes at
  /apps/hermiq/api/{chat,conversations} (chunk 2). Agents come from the
  createObjectStore agent store; conversation/chat transport lives in
  src/api/chat.js (see its docblock for the store-vs-helper split). All
  dialogs are isolated modal files (ADR-004): ConversationRenameModal,
  ConversationDeleteModal, ChatSettingsModal.

  STREAMING (hydra ADR-034): sending uses POST /api/chat/stream (SSE
  six-event envelope) with incremental token rendering, degrading to
  POST /api/chat/send on transport failure (the fallback ladder). Two
  ground-truth adaptations, both deliberate:
  - OR's frontend at HEAD has NO SSE consumption (its chat always POSTs
    /chat/send); the streaming consumption here is written against the ported
    ChatStreamController's contract instead of ported from OR code.
  - The stream endpoint accepts only message/agentUuid/conversationUuid, so
    when the user customises per-conversation views/tools/RAG settings the
    turn is sent over POST /api/chat/send (which accepts them) instead of the
    stream — behaviourally identical to OR, which always used /send.

  After every completed turn the thread is re-read from the server so
  message ids (needed for feedback), RAG sources, and the auto-generated
  conversation title reflect persisted truth rather than optimistic state.
-->
<template>
	<div class="chat-page">
		<!-- Conversation list column -->
		<aside class="chat-page__list">
			<div class="chat-page__list-head">
				<NcButton type="primary" wide @click="newConversation">
					<template #icon>
						<Plus :size="20" />
					</template>
					{{ t('hermiq', 'New session') }}
				</NcButton>
				<div class="chat-page__tabs">
					<NcCheckboxRadioSwitch
						:button-variant="true"
						:checked="showArchive ? 'archive' : 'active'"
						value="active"
						name="chat_list_tab"
						type="radio"
						button-variant-grouped="horizontal"
						@update:checked="setArchiveTab(false)">
						{{ t('hermiq', 'Active') }}
					</NcCheckboxRadioSwitch>
					<NcCheckboxRadioSwitch
						:button-variant="true"
						:checked="showArchive ? 'archive' : 'active'"
						value="archive"
						name="chat_list_tab"
						type="radio"
						button-variant-grouped="horizontal"
						@update:checked="setArchiveTab(true)">
						{{ t('hermiq', 'Archive') }}
					</NcCheckboxRadioSwitch>
				</div>
			</div>

			<div v-if="conversationsLoading" class="chat-page__list-state">
				<NcLoadingIcon :size="28" />
			</div>

			<NcNoteCard
				v-else-if="visibleConversations.length === 0"
				type="info">
				{{ showArchive
					? t('hermiq', 'No archived sessions.')
					: t('hermiq', 'No sessions yet. Start one to chat with an agent.') }}
			</NcNoteCard>

			<div v-else class="chat-page__rows">
				<div
					v-for="conversation in visibleConversations"
					:key="conversation.uuid"
					class="chat-page__row"
					:class="{ 'chat-page__row--active': isActive(conversation) }">
					<div
						class="chat-page__row-main"
						role="button"
						tabindex="0"
						@click="selectConversation(conversation)"
						@keydown.enter="selectConversation(conversation)">
						<strong>{{ conversation.title || t('hermiq', 'New session') }}</strong>
						<span class="chat-page__row-date">{{ formatTime(conversation.updated) }}</span>
					</div>
					<div class="chat-page__row-actions">
						<template v-if="!showArchive">
							<NcButton
								type="tertiary"
								:aria-label="t('hermiq', 'Archive session')"
								@click="archive(conversation)">
								<template #icon>
									<Archive :size="20" />
								</template>
							</NcButton>
						</template>
						<template v-else>
							<NcButton
								type="tertiary"
								:aria-label="t('hermiq', 'Restore session')"
								@click="restore(conversation)">
								<template #icon>
									<Restore :size="20" />
								</template>
							</NcButton>
							<NcButton
								type="tertiary"
								:aria-label="t('hermiq', 'Delete permanently')"
								@click="openDelete(conversation)">
								<template #icon>
									<Delete :size="20" />
								</template>
							</NcButton>
						</template>
					</div>
				</div>
			</div>
		</aside>

		<!-- Thread column -->
		<section class="chat-page__thread">
			<div class="chat-page__header">
				<h2 class="chat-page__heading">
					<Robot :size="26" />
					{{ headerTitle }}
				</h2>
				<div v-if="activeConversation" class="chat-page__header-actions">
					<NcButton
						type="tertiary"
						:aria-label="t('hermiq', 'Rename session')"
						@click="showRename = true">
						<template #icon>
							<Pencil :size="20" />
						</template>
					</NcButton>
					<NcButton
						type="tertiary"
						:aria-label="t('hermiq', 'Chat settings')"
						@click="showSettings = true">
						<template #icon>
							<CogOutline :size="20" />
						</template>
					</NcButton>
				</div>
			</div>

			<!-- No conversation: agent selector -->
			<div v-if="!activeConversation" class="chat-page__empty">
				<div class="chat-page__empty-icon">
					<MessageText :size="56" />
				</div>
				<h3>{{ t('hermiq', 'Start a session') }}</h3>
				<p>{{ t('hermiq', 'Select an agent to begin chatting with your data.') }}</p>
				<AgentSelector
					:agents="agents"
					:loading="agentsLoading"
					:error="agentsError"
					:starting-id="startingId"
					@start="startWithAgent" />
			</div>

			<!-- Thread -->
			<template v-else>
				<div ref="messagesContainer" class="chat-page__messages">
					<div v-if="messagesLoading && messages.length === 0" class="chat-page__messages-state">
						<NcLoadingIcon :size="28" />
						<p>{{ t('hermiq', 'Loading session…') }}</p>
					</div>

					<div
						v-for="message in messages"
						:key="message.uuid || message.id"
						class="chat-page__message"
						:class="`chat-page__message--${message.role}`">
						<div class="chat-page__avatar">
							<AccountCircle v-if="message.role === 'user'" :size="30" />
							<Robot v-else :size="30" />
						</div>
						<div class="chat-page__bubble">
							<div class="chat-page__bubble-head">
								<span class="chat-page__sender">
									{{ message.role === 'user' ? t('hermiq', 'You') : agentName }}
								</span>
								<span class="chat-page__time">{{ formatTime(message.created) }}</span>
							</div>
							<!-- Assistant markdown is sanitised via DOMPurify with the shared safe config. -->
							<!-- eslint-disable-next-line vue/no-v-html -->
							<div class="chat-page__text" v-html="renderMarkdown(message.content)" />

							<!-- RAG sources -->
							<div v-if="message.sources && message.sources.length > 0" class="chat-page__sources">
								<div class="chat-page__sources-head">
									<FileDocumentOutline :size="16" />
									<span>{{ t('hermiq', 'Sources') }}</span>
								</div>
								<div
									v-for="(source, sourceIndex) in message.sources"
									:key="sourceIndex"
									class="chat-page__source">
									<FileDocument v-if="source.type === 'file'" :size="16" />
									<CubeOutline v-else :size="16" />
									<span class="chat-page__source-name">{{ source.name || source.id }}</span>
									<span v-if="source.similarity" class="chat-page__source-match">
										{{ Math.round(source.similarity * 100) }}%
									</span>
								</div>
							</div>

							<!-- Feedback (assistant messages with a persisted id) -->
							<div v-if="message.role === 'assistant' && (message.uuid || message.id)" class="chat-page__feedback">
								<NcButton
									type="tertiary"
									:aria-label="t('hermiq', 'Helpful')"
									:class="{ 'chat-page__feedback--active-positive': message.feedback === 'positive' }"
									@click="sendFeedback(message, 'positive')">
									<template #icon>
										<ThumbUp :size="16" />
									</template>
								</NcButton>
								<NcButton
									type="tertiary"
									:aria-label="t('hermiq', 'Not helpful')"
									:class="{ 'chat-page__feedback--active-negative': message.feedback === 'negative' }"
									@click="sendFeedback(message, 'negative')">
									<template #icon>
										<ThumbDown :size="16" />
									</template>
								</NcButton>
								<!-- hermiq-skill-conversational-authoring: turn this assistant message
								     (e.g. a SKILL.md drafted by the seeded skill-creator skill) into a
								     reviewable Skill via the pre-filled authoring modal. -->
								<NcButton
									type="tertiary"
									:aria-label="t('hermiq', 'Save as skill')"
									@click="openSaveAsSkill(message)">
									<template #icon>
										<PuzzlePlusOutline :size="16" />
									</template>
								</NcButton>
							</div>
							<div v-if="message.showFeedbackInput" class="chat-page__feedback-comment">
								<textarea
									:value="message.feedbackComment"
									class="chat-page__feedback-input"
									rows="2"
									:placeholder="t('hermiq', 'Optionally add details to your feedback…')"
									@input="$set(message, 'feedbackComment', $event.target.value)" />
								<NcButton
									type="secondary"
									:disabled="!message.feedbackComment || !message.feedbackComment.trim()"
									@click="saveFeedbackComment(message)">
									{{ t('hermiq', 'Send feedback') }}
								</NcButton>
							</div>
						</div>
					</div>

					<!-- Live streaming bubble -->
					<div v-if="isStreaming" class="chat-page__message chat-page__message--assistant">
						<div class="chat-page__avatar">
							<Robot :size="30" />
						</div>
						<div class="chat-page__bubble">
							<div class="chat-page__bubble-head">
								<span class="chat-page__sender">{{ agentName }}</span>
							</div>
							<div v-if="streamingTools.length > 0" class="chat-page__stream-tools">
								<span
									v-for="(tool, toolIndex) in streamingTools"
									:key="toolIndex"
									class="chat-page__stream-tool">
									{{ tool.done
										? t('hermiq', 'Used tool: {tool}', { tool: tool.toolId })
										: t('hermiq', 'Using tool: {tool}…', { tool: tool.toolId }) }}
								</span>
							</div>
							<!-- Streamed markdown is sanitised via DOMPurify with the shared safe config. -->
							<!-- eslint-disable-next-line vue/no-v-html -->
							<div v-if="streamingText" class="chat-page__text" v-html="renderMarkdown(streamingText)" />
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
						<textarea
							ref="messageInput"
							v-model="currentMessage"
							class="chat-page__input"
							rows="1"
							:placeholder="t('hermiq', 'Ask a question…')"
							:disabled="sending"
							@keydown.enter.exact.prevent="handleSend"
							@input="autoResize" />
						<NcButton
							type="primary"
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
						{{ t('hermiq', 'Press Enter to send, Shift+Enter for a new line') }}
					</p>
				</div>
			</template>
		</section>

		<!-- Isolated modals (ADR-004) -->
		<ConversationRenameModal
			:show="showRename"
			:conversation="activeConversation"
			@close="showRename = false"
			@saved="onRenamed" />
		<ConversationDeleteModal
			:show="showDelete"
			:conversation="deleteTarget"
			@close="showDelete = false"
			@deleted="onDeleted" />
		<ChatSettingsModal
			:show="showSettings"
			:available-views="availableViews"
			:available-tools="availableTools"
			:value="settings"
			@input="settings = $event"
			@close="showSettings = false" />

		<!-- hermiq-skill-conversational-authoring: "Save as skill" seam — opens the
		     hermiq-skill-markdown-authoring SkillFormModal pre-filled from an assistant
		     message, saving through the quarantine review path (source: local). -->
		<SkillFormModal
			:show="showSaveAsSkill"
			:initial-body="saveAsSkillBody"
			save-target="quarantine"
			@close="showSaveAsSkill = false"
			@saved="onSkillSaved" />
	</div>
</template>

<script>
import { NcButton, NcCheckboxRadioSwitch, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { SAFE_MARKDOWN_DOMPURIFY_CONFIG } from '@conduction/nextcloud-vue'
import DOMPurify from 'dompurify'
import { marked } from 'marked'
import AccountCircle from 'vue-material-design-icons/AccountCircle.vue'
import Archive from 'vue-material-design-icons/Archive.vue'
import CogOutline from 'vue-material-design-icons/CogOutline.vue'
import CubeOutline from 'vue-material-design-icons/CubeOutline.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import FileDocument from 'vue-material-design-icons/FileDocument.vue'
import FileDocumentOutline from 'vue-material-design-icons/FileDocumentOutline.vue'
import MessageText from 'vue-material-design-icons/MessageText.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import PuzzlePlusOutline from 'vue-material-design-icons/PuzzlePlusOutline.vue'
import Restore from 'vue-material-design-icons/Restore.vue'
import Robot from 'vue-material-design-icons/Robot.vue'
import Send from 'vue-material-design-icons/Send.vue'
import ThumbDown from 'vue-material-design-icons/ThumbDown.vue'
import ThumbUp from 'vue-material-design-icons/ThumbUp.vue'
import {
	archiveConversation,
	ChatStreamError,
	createConversation,
	getConversation,
	listConversations,
	listMessages,
	restoreConversation,
	sendChatMessage,
	sendMessageFeedback,
	streamChatMessage,
} from '../api/chat.js'
import { useAgentStore } from '../store/store.js'
import AgentSelector from '../components/AgentSelector.vue'
import ChatSettingsModal from '../modals/ChatSettingsModal.vue'
import ConversationDeleteModal from '../modals/ConversationDeleteModal.vue'
import ConversationRenameModal from '../modals/ConversationRenameModal.vue'
import SkillFormModal from '../modals/SkillFormModal.vue'

export default {
	name: 'Chat',

	components: {
		AccountCircle,
		AgentSelector,
		Archive,
		ChatSettingsModal,
		CogOutline,
		ConversationDeleteModal,
		ConversationRenameModal,
		CubeOutline,
		Delete,
		FileDocument,
		FileDocumentOutline,
		MessageText,
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcNoteCard,
		Pencil,
		Plus,
		PuzzlePlusOutline,
		Restore,
		Robot,
		Send,
		SkillFormModal,
		ThumbDown,
		ThumbUp,
	},

	data() {
		return {
			// Conversation lists
			conversations: [],
			archivedConversations: [],
			showArchive: false,
			conversationsLoading: true,

			// Active thread
			activeConversation: null,
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

			// Per-conversation settings (rides on POST /api/chat/send)
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
		 * The conversations for the visible tab.
		 *
		 * @return {Array<object>} Active or archived conversations.
		 */
		visibleConversations() {
			return this.showArchive ? this.archivedConversations : this.conversations
		},

		/**
		 * The thread header title.
		 *
		 * @return {string} Agent name, session title, or the page name.
		 */
		headerTitle() {
			return this.currentAgent?.name
				|| this.activeConversation?.title
				|| this.t('hermiq', 'Sessions')
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
			const raw = Array.isArray(this.currentAgent?.views) ? this.currentAgent.views : []
			return raw.map((view) => (typeof view === 'string' ? { uuid: view, name: view } : view))
		},

		/**
		 * The current agent's selectable tools ({uuid, name}).
		 *
		 * @return {Array<object>} The tool descriptors.
		 */
		availableTools() {
			const raw = Array.isArray(this.currentAgent?.tools) ? this.currentAgent.tools : []
			return raw.map((tool) => {
				if (typeof tool === 'string') {
					const label = tool.replace(/^[a-z0-9_-]+\./i, '').replace(/_/g, ' ')
					return { uuid: tool, name: label.charAt(0).toUpperCase() + label.slice(1) }
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
			return this.settings.views.length !== defaults.views.length
				|| this.settings.tools.length !== defaults.tools.length
				|| this.settings.includeObjects !== defaults.includeObjects
				|| this.settings.includeFiles !== defaults.includeFiles
				|| this.settings.numSourcesObjects !== defaults.numSourcesObjects
				|| this.settings.numSourcesFiles !== defaults.numSourcesFiles
		},
	},

	created() {
		this.agentStore = useAgentStore()
		this.agentStore.registerObjectType('agent', 'agent', 'hermiq')
		this.loadConversations()
		this.loadAgents()
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
				views: views.map((view) => (typeof view === 'string' ? view : view.uuid)),
				tools: tools.map((tool) => (typeof tool === 'string' ? tool : tool.uuid)),
				includeObjects: agent.searchObjects ?? true,
				includeFiles: agent.searchFiles ?? true,
				numSourcesObjects: agent.ragNumSources ?? 5,
				numSourcesFiles: agent.ragNumSources ?? 5,
			}
		},

		/**
		 * Load the active conversation list (and the archive when visible).
		 *
		 * @param {boolean} soft True to skip the loading state.
		 * @return {Promise<void>}
		 */
		async loadConversations(soft = false) {
			if (!soft) {
				this.conversationsLoading = true
			}
			try {
				const { results } = await listConversations({ archived: false })
				this.conversations = results
				if (this.showArchive) {
					const archived = await listConversations({ archived: true })
					this.archivedConversations = archived.results
				}
			} catch (e) {
				showError(this.t('hermiq', 'Could not load sessions.'))
			} finally {
				this.conversationsLoading = false
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
				this.agentsError = this.agentStore.errors.agent.message
					|| this.t('hermiq', 'Could not load agents.')
			}
			this.agentsLoading = false
		},

		/**
		 * Switch between the active and archive conversation tabs.
		 *
		 * @param {boolean} archive True for the archive tab.
		 * @return {Promise<void>}
		 */
		async setArchiveTab(archive) {
			this.showArchive = archive
			if (archive) {
				this.conversationsLoading = true
				try {
					const { results } = await listConversations({ archived: true })
					this.archivedConversations = results
				} catch (e) {
					showError(this.t('hermiq', 'Could not load archived sessions.'))
				} finally {
					this.conversationsLoading = false
				}
			}
		},

		/**
		 * Whether a conversation is the active one.
		 *
		 * @param {object} conversation The conversation to check.
		 * @return {boolean} True when active.
		 */
		isActive(conversation) {
			return this.activeConversation?.uuid === conversation.uuid
		},

		/**
		 * Clear the active thread so the agent selector shows.
		 *
		 * @return {void}
		 */
		newConversation() {
			this.activeConversation = null
			this.messages = []
			this.currentAgent = null
			this.sendError = ''
			this.settings = this.defaultSettings()
		},

		/**
		 * Open a conversation: load its messages and its agent, and seed the
		 * per-conversation settings from the agent's capabilities.
		 *
		 * @param {object} conversation The conversation to open.
		 * @return {Promise<void>}
		 */
		async selectConversation(conversation) {
			this.activeConversation = conversation
			this.messages = []
			this.sendError = ''
			this.messagesLoading = true
			try {
				const [{ results }] = await Promise.all([
					listMessages(conversation.uuid),
					this.loadAgentFor(conversation),
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
		 * Load a conversation's agent (non-fatal on miss).
		 *
		 * @param {object} conversation The conversation whose agent to load.
		 * @return {Promise<void>}
		 */
		async loadAgentFor(conversation) {
			this.currentAgent = null
			if (!conversation.agentId) {
				return
			}
			const agent = await this.agentStore.fetchObject('agent', conversation.agentId)
			this.currentAgent = agent || null
		},

		/**
		 * Create a conversation with the picked agent and activate it.
		 *
		 * @param {object} agent The agent to start with.
		 * @return {Promise<void>}
		 */
		async startWithAgent(agent) {
			const agentUuid = agent.uuid || agent.id
			this.startingId = agentUuid
			try {
				const conversation = await createConversation(agentUuid)
				this.currentAgent = agent
				this.activeConversation = conversation
				this.messages = []
				this.settings = this.defaultSettingsFor(agent)
				await this.loadConversations(true)
				showSuccess(this.t('hermiq', 'Session started with {agent}', { agent: agent.name || agentUuid }))
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
		 */
		async handleSend() {
			const text = this.currentMessage.trim()
			if (!text || this.sending || !this.activeConversation) {
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

			const uuid = this.activeConversation.uuid
			try {
				if (this.settingsCustomised) {
					await this.sendViaPost(text, uuid)
				} else {
					await this.sendViaStream(text, uuid)
				}
			} catch (e) {
				this.sendError = e?.response?.data?.message
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
		 * @param {string} uuid The conversation UUID.
		 * @return {Promise<void>}
		 */
		async sendViaStream(text, uuid) {
			this.isStreaming = true
			this.streamingText = ''
			this.streamingTools = []
			try {
				await streamChatMessage(
					{ message: text, conversationUuid: uuid },
					{
						onToken: (delta) => {
							this.streamingText += delta
							this.scrollToBottom()
						},
						onToolCall: (payload) => {
							this.streamingTools.push({ toolId: payload.toolId || this.t('hermiq', 'tool'), done: false })
						},
						onToolResult: (payload) => {
							const entry = this.streamingTools.find(
								(tool) => tool.toolId === payload.toolId && !tool.done,
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
		 * Send one turn over POST /api/chat/send with the per-conversation
		 * views/tools/RAG settings.
		 *
		 * @param {string} text The user message.
		 * @param {string} uuid The conversation UUID.
		 * @return {Promise<void>}
		 */
		async sendViaPost(text, uuid) {
			await sendChatMessage({
				message: text,
				conversationUuid: uuid,
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
		 * @param {string} uuid The conversation UUID.
		 * @return {Promise<void>}
		 */
		async refreshThread(uuid) {
			try {
				const [{ results }, conversation] = await Promise.all([
					listMessages(uuid),
					getConversation(uuid),
				])
				if (this.activeConversation?.uuid === uuid) {
					this.messages = results
					this.activeConversation = conversation
				}
				await this.loadConversations(true)
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
		 */
		async sendFeedback(message, type) {
			const cleared = message.feedback === type
			this.$set(message, 'feedback', cleared ? null : type)
			this.$set(message, 'showFeedbackInput', !cleared)
			if (cleared) {
				return
			}
			try {
				await sendMessageFeedback(this.activeConversation.uuid, message.uuid || message.id, { type })
				showSuccess(this.t('hermiq', 'Feedback recorded'))
			} catch (e) {
				showError(this.t('hermiq', 'Could not record feedback.'))
				this.$set(message, 'feedback', null)
				this.$set(message, 'showFeedbackInput', false)
			}
		},

		/**
		 * Send the optional feedback elaboration comment.
		 *
		 * @param {object} message The assistant message.
		 * @return {Promise<void>}
		 */
		async saveFeedbackComment(message) {
			if (!message.feedbackComment || !message.feedbackComment.trim()) {
				return
			}
			try {
				await sendMessageFeedback(this.activeConversation.uuid, message.uuid || message.id, {
					type: message.feedback,
					comment: message.feedbackComment.trim(),
				})
				this.$set(message, 'showFeedbackInput', false)
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
			showSuccess(this.t('hermiq', 'Skill saved for review. Approve it in the Skills catalog before it can be used.'))
		},

		/**
		 * Archive (soft delete) a conversation.
		 *
		 * @param {object} conversation The conversation to archive.
		 * @return {Promise<void>}
		 */
		async archive(conversation) {
			try {
				await archiveConversation(conversation.uuid)
				if (this.isActive(conversation)) {
					this.newConversation()
				}
				await this.loadConversations(true)
				showSuccess(this.t('hermiq', 'Session archived'))
			} catch (e) {
				showError(this.t('hermiq', 'Could not archive the session.'))
			}
		},

		/**
		 * Restore an archived conversation.
		 *
		 * @param {object} conversation The conversation to restore.
		 * @return {Promise<void>}
		 */
		async restore(conversation) {
			try {
				await restoreConversation(conversation.uuid)
				this.archivedConversations = this.archivedConversations
					.filter((entry) => entry.uuid !== conversation.uuid)
				await this.loadConversations(true)
				showSuccess(this.t('hermiq', 'Session restored'))
			} catch (e) {
				showError(this.t('hermiq', 'Could not restore the session.'))
			}
		},

		/**
		 * Open the permanent-delete confirmation modal.
		 *
		 * @param {object} conversation The archived conversation.
		 * @return {void}
		 */
		openDelete(conversation) {
			this.deleteTarget = conversation
			this.showDelete = true
		},

		/**
		 * Handle a completed permanent delete.
		 *
		 * @param {object} conversation The deleted conversation.
		 * @return {void}
		 */
		onDeleted(conversation) {
			this.archivedConversations = this.archivedConversations
				.filter((entry) => entry.uuid !== conversation.uuid)
			if (this.isActive(conversation)) {
				this.newConversation()
			}
			showSuccess(this.t('hermiq', 'Session deleted'))
		},

		/**
		 * Handle a completed rename.
		 *
		 * @param {object} conversation The updated conversation.
		 * @return {Promise<void>}
		 */
		async onRenamed(conversation) {
			this.activeConversation = conversation
			await this.loadConversations(true)
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
			return DOMPurify.sanitize(marked.parse(content || ''), SAFE_MARKDOWN_DOMPURIFY_CONFIG)
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
				return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
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

/* ── Conversation list column ─────────────────────────────────────── */

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
	background-color: var(--color-primary-element-light-hover, var(--color-primary-element-light));
}

.chat-page__row-main {
	display: flex;
	flex-direction: column;
	gap: 2px;
	flex: 1;
	min-width: 0;
	cursor: pointer;
}

.chat-page__row-main strong {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.chat-page__row-date {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.chat-page__row-actions {
	display: flex;
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
	align-items: center;
	justify-content: center;
	gap: 8px;
	padding: 32px 20px;
	overflow-y: auto;
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
	word-wrap: break-word;
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
	0%, 80%, 100% {
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
</style>
