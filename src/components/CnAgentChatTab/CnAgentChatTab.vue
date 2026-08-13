<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  -
  - CnAgentChatTab — the agent render leaf's object-scoped CHAT surface
  - (agent-object-leaf). Reuses Hermiq's TOOL-FREE conversational endpoint
  - `POST /api/assistant/converse` and introduces NO new LLM/prompt/tool logic.
  -
  - It forwards ONLY the bounded object context built from the schema's
  - `x-openregister-agent-context` allowlist (buildAgentContext — the JS mirror of
  - the PHP AgentContextBuilder), so an unlisted (confidential) property never
  - reaches the agent. The object identity is carried so follow-up turns stay
  - grounded on the same object. Render-only: it reads and chats; it writes no
  - object field and invokes no tool.
  -
  - When the allowlist resolves to ZERO properties the surface says so IN TEXT
  - (hydra-console-agent-leaves). Fail-closed context is correct security, but an
  - agent answering about an object it received no properties for is
  - indistinguishable from one that read it — so the two states must be
  - distinguishable in the surface, and a reply produced with no context is marked
  - as not grounded in the object rather than presented as if it were.
  -
  - @spec openspec/changes/hermiq-agent-leaf/specs/agent-object-leaf/spec.md#requirement-object-scoped-agent-chat-reuses-the-tool-free-surface
  - @spec openspec/changes/hydra-console-agent-leaves/specs/agent-object-leaf/spec.md#requirement-declarative-bounded-agent-context-allowlist
-->
<template>
	<div class="cn-agent-chat-tab" data-testid="cn-agent-chat-tab">
		<NcNoteCard v-if="unavailable" type="warning">
			{{ t('hermiq', 'The agent assistant is not available on this object.') }}
		</NcNoteCard>

		<div v-else class="cn-agent-chat-tab__conversation">
			<NcNoteCard
				v-if="contextResolved && !hasBoundedContext"
				type="warning"
				data-testid="cn-agent-chat-tab-no-context">
				{{
					t(
						'hermiq',
						"No object context is available. This object's schema shares no fields with the agent, so its answers are not grounded in this object.",
					)
				}}
			</NcNoteCard>

			<ul class="cn-agent-chat-tab__messages">
				<li
					v-for="(entry, idx) in messages"
					:key="idx"
					class="cn-agent-chat-tab__message"
					:class="`cn-agent-chat-tab__message--${entry.role}`">
					<span class="cn-agent-chat-tab__role">{{
						roleLabel(entry.role)
					}}</span>
					<span class="cn-agent-chat-tab__text">{{ entry.text }}</span>
					<span
						v-if="entry.role === 'agent' && entry.grounded === false"
						class="cn-agent-chat-tab__ungrounded"
						data-testid="cn-agent-chat-tab-ungrounded">
						{{
							t(
								'hermiq',
								'Not grounded in this object — no object context was shared.',
							)
						}}
					</span>
				</li>
				<li v-if="messages.length === 0" class="cn-agent-chat-tab__empty">
					{{
						t(
							'hermiq',
							'Ask the agent about this object. Only allowlisted fields are shared.',
						)
					}}
				</li>
			</ul>

			<form class="cn-agent-chat-tab__composer" @submit.prevent="send">
				<NcTextArea
					v-model="draft"
					:label="t('hermiq', 'Message')"
					:disabled="sending"
					resize="vertical"
					data-testid="cn-agent-chat-tab-input" />
				<NcButton
					variant="primary"
					type="submit"
					:disabled="sending || draft.trim() === ''"
					data-testid="cn-agent-chat-tab-send">
					<template v-if="sending" #icon>
						<NcLoadingIcon :size="20" />
					</template>
					{{ t('hermiq', 'Send') }}
				</NcButton>
			</form>

			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>
		</div>
	</div>
</template>

<script>
import { generateUrl } from '@nextcloud/router'
import { getRequestToken } from '@nextcloud/auth'
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcLoadingIcon, NcNoteCard, NcTextArea } from '@nextcloud/vue'
import { buildAgentContext } from '../../utils/agentContext.js'

export default {
	name: 'CnAgentChatTab',
	components: { NcButton, NcLoadingIcon, NcNoteCard, NcTextArea },
	props: {
		/** OpenRegister register id (slug or uuid). */
		register: { type: String, default: '' },
		/** OpenRegister schema id (slug or uuid). */
		schema: { type: String, default: '' },
		/** The object id (sidebar context). */
		objectId: { type: String, default: '' },
		/** The object type label (sidebar context). */
		objectType: { type: String, default: '' },
	},
	data() {
		return {
			messages: [],
			draft: '',
			sending: false,
			error: '',
			unavailable: false,
			sessionId: null,
			boundedContext: {},
			// False until the allowlist has actually been resolved for this object,
			// so the "no context" notice never flashes during the initial load and
			// is never shown for an object that simply has not been read yet.
			contextResolved: false,
		}
	},
	computed: {
		/**
		 * Whether the schema's `x-openregister-agent-context` allowlist resolved to at
		 * least one property on this object.
		 *
		 * @return {boolean} True when the agent receives any object field at all.
		 * @spec openspec/changes/hydra-console-agent-leaves/specs/agent-object-leaf/spec.md#scenario-the-user-is-told-the-object-contributed-no-context
		 */
		hasBoundedContext() {
			return Object.keys(this.boundedContext || {}).length > 0
		},
	},
	watch: {
		objectId: {
			immediate: true,
			handler() {
				this.reset()
			},
		},
	},
	methods: {
		t,
		roleLabel(role) {
			return role === 'user' ? t('hermiq', 'You') : t('hermiq', 'Agent')
		},
		async reset() {
			this.messages = []
			this.draft = ''
			this.error = ''
			this.sessionId = null
			this.unavailable = false
			this.boundedContext = {}
			this.contextResolved = false
			if (this.objectId === '') {
				return
			}
			await this.loadBoundedContext()
		},
		/**
		 * Resolve the object + its schema and build the fail-closed bounded context.
		 * Any failure leaves an EMPTY context — the safe default — never the object.
		 *
		 * `contextResolved` is set either way: a failed read yields the same empty
		 * context a missing allowlist does, and the user must be told about both. An
		 * empty context that stayed invisible is the defect this flag exists for.
		 *
		 * @spec openspec/changes/hydra-console-agent-leaves/specs/agent-object-leaf/spec.md#scenario-the-user-is-told-the-object-contributed-no-context
		 */
		async loadBoundedContext() {
			try {
				const [objectData, schemaDef] = await Promise.all([
					this.fetchObject(),
					this.fetchSchema(),
				])
				this.boundedContext = buildAgentContext(objectData, schemaDef)
			} catch (e) {
				this.boundedContext = {}
			} finally {
				this.contextResolved = true
			}
		},
		async fetchObject() {
			const url = generateUrl(
				'/apps/openregister/api/objects/{register}/{schema}/{id}',
				{
					register: this.register,
					schema: this.schema,
					id: this.objectId,
				},
			)
			const res = await fetch(url, {
				headers: { requesttoken: getRequestToken() },
			})
			if (!res.ok) {
				return {}
			}
			const body = await res.json()
			// OR returns either the object directly or `{ '@self': …, ...properties }`.
			return body && typeof body === 'object' ? body : {}
		},
		async fetchSchema() {
			const url = generateUrl('/apps/openregister/api/schemas/{id}', {
				id: this.schema,
			})
			const res = await fetch(url, {
				headers: { requesttoken: getRequestToken() },
			})
			if (!res.ok) {
				return {}
			}
			const body = await res.json()
			return body && typeof body === 'object' ? body : {}
		},
		async send() {
			const message = this.draft.trim()
			if (message === '' || this.sending) {
				return
			}
			this.error = ''
			this.sending = true
			this.messages.push({ role: 'user', text: message })
			this.draft = ''

			const payload = {
				message,
				context: {
					app: 'hermiq',
					objectType: this.objectType || this.schema,
					objectRef: this.objectId,
					contextData: this.boundedContext,
				},
			}
			if (this.sessionId) {
				payload.sessionId = this.sessionId
			}

			try {
				const res = await fetch(
					generateUrl('/apps/hermiq/api/assistant/converse'),
					{
						method: 'POST',
						headers: {
							'Content-Type': 'application/json',
							requesttoken: getRequestToken(),
						},
						body: JSON.stringify(payload),
					},
				)
				const body = await res.json()
				if (res.status === 503) {
					this.unavailable = true
					return
				}
				if (!res.ok) {
					this.error =
						body?.message || t('hermiq', 'The agent could not answer.')
					return
				}
				this.sessionId = body?.sessionId || this.sessionId
				// Carry the grounding fact onto the reply itself, not just onto the
				// tab: a reply scrolled away from the notice must still be readable
				// as ungrounded.
				this.messages.push({
					role: 'agent',
					text: body?.reply || '',
					grounded: this.hasBoundedContext,
				})
			} catch (e) {
				this.error = t('hermiq', 'The agent service is unreachable.')
			} finally {
				this.sending = false
			}
		},
	},
}
</script>

<style scoped>
.cn-agent-chat-tab {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 8px 4px;
}

.cn-agent-chat-tab__messages {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.cn-agent-chat-tab__message {
	display: flex;
	flex-direction: column;
	gap: 2px;
	padding: 8px 10px;
	border-radius: var(--border-radius-large, 8px);
	background: var(--color-background-hover);
}

.cn-agent-chat-tab__message--user {
	background: var(--color-primary-element-light, var(--color-background-dark));
}

.cn-agent-chat-tab__role {
	font-size: 0.8em;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

.cn-agent-chat-tab__text {
	white-space: pre-wrap;
}

.cn-agent-chat-tab__empty {
	color: var(--color-text-maxcontrast);
	padding: 8px 0;
}
/* Text state, never a colour-only state (WCAG 2.1 AA 1.4.1): the words carry the
   meaning and the variable-driven colour only reinforces it. */
.cn-agent-chat-tab__ungrounded {
	font-size: 0.8em;
	font-style: italic;
	color: var(--color-text-maxcontrast);
}

.cn-agent-chat-tab__composer {
	display: flex;
	flex-direction: column;
	gap: 8px;
}
</style>
