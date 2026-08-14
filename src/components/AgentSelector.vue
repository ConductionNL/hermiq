<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  AgentSelector — agent picker cards for starting a chat conversation.

  Ported from OpenRegister's src/components/AgentSelector.vue
  (agent-engine-port task 5.1) and adapted to hermiq: real t('hermiq', …)
  translations (OR shipped a placeholder t()), CSS variables only, and the
  empty state links to hermiq's own /agents catalogue page. Each card shows
  the agent's tool whitelist / views and offers a one-click
  "Start conversation" — the parent (Chat.vue) creates the conversation.
-->
<template>
	<div class="agent-selector">
		<!-- Loading state -->
		<div v-if="loading" class="agent-selector__state">
			<NcLoadingIcon :size="32" />
			<p>{{ t('hermiq', 'Loading agents…') }}</p>
		</div>

		<!-- Error state -->
		<NcNoteCard v-else-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<!-- Empty state -->
		<NcEmptyContent
			v-else-if="agents.length === 0"
			:name="t('hermiq', 'No agents available')"
			:description="
				t(
					'hermiq',
					'You need an agent to start a conversation. Create one in the Agents page.',
				)
			">
			<template #icon>
				<Creation :size="20" />
			</template>
			<template #action>
				<NcButton variant="primary" @click="$router.push('/agents')">
					{{ t('hermiq', 'Go to agents') }}
				</NcButton>
			</template>
		</NcEmptyContent>

		<!-- Agent cards -->
		<div v-else class="agent-selector__grid">
			<div
				v-for="agent in agents"
				:key="agent.id || agent.uuid"
				class="agent-selector__card">
				<div class="agent-selector__card-head">
					<div class="agent-selector__icon">
						<Creation :size="28" />
					</div>
					<div class="agent-selector__title">
						<h3>{{ agent.name || t('hermiq', 'Untitled agent') }}</h3>
						<p v-if="agent.description">
							{{ agent.description }}
						</p>
					</div>
				</div>

				<div
					v-if="agent.model || (agent.tools && agent.tools.length)"
					class="agent-selector__meta">
					<span v-if="agent.model" class="agent-selector__chip">{{
						agent.model
					}}</span>
					<span
						v-if="agent.tools && agent.tools.length"
						class="agent-selector__chip">
						{{ n('hermiq', '%n tool', '%n tools', agent.tools.length) }}
					</span>
					<span v-else class="agent-selector__chip">{{
						t('hermiq', 'All tools')
					}}</span>
				</div>

				<NcButton
					variant="primary"
					wide
					:disabled="!!startingId"
					@click="$emit('start', agent)">
					<template #icon>
						<NcLoadingIcon
							v-if="startingId === (agent.id || agent.uuid)"
							:size="20" />
						<MessagePlus v-else :size="20" />
					</template>
					{{ t('hermiq', 'Start conversation') }}
				</NcButton>
			</div>
		</div>
	</div>
</template>

<script>
import { NcButton, NcEmptyContent, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
// Agents carry the AI sparkles, not a robot — one mark for "the model", used
// identically in the nav, the launcher hex and the chat empty state.
import Creation from 'vue-material-design-icons/Creation.vue'
import MessagePlus from 'vue-material-design-icons/MessagePlus.vue'

export default {
	name: 'AgentSelector',

	components: {
		MessagePlus,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		Creation,
	},

	props: {
		/** The selectable agents. */
		agents: {
			type: Array,
			default: () => [],
		},

		/** Whether the agent list is loading. */
		loading: {
			type: Boolean,
			default: false,
		},

		/** Load error message, if any. */
		error: {
			type: String,
			default: '',
		},

		/** The id of the agent a conversation is being started with, if any. */
		startingId: {
			type: String,
			default: '',
		},
	},

	emits: ['start'],
}
</script>

<style scoped>
.agent-selector__state {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 12px;
	padding: 32px;
	color: var(--color-text-maxcontrast);
}

.agent-selector__state p {
	margin: 0;
}

.agent-selector__grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
	gap: 16px;
}

.agent-selector__card {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 12px);
	background: var(--color-main-background);
	transition: border-color 0.2s ease;
}

.agent-selector__card:hover {
	border-color: var(--color-primary-element);
}

.agent-selector__card-head {
	display: flex;
	gap: 12px;
	align-items: flex-start;
}

.agent-selector__icon {
	flex-shrink: 0;
	display: flex;
	align-items: center;
	justify-content: center;
	width: 44px;
	height: 44px;
	border-radius: var(--border-radius-large, 12px);
	background: var(--color-primary-element-light);
}

.agent-selector__title {
	min-width: 0;
}

.agent-selector__title h3 {
	margin: 0 0 2px;
	font-size: 16px;
	font-weight: 600;
}

.agent-selector__title p {
	margin: 0;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.agent-selector__meta {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
}

.agent-selector__chip {
	padding: 2px 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-pill, 12px);
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	background: var(--color-background-hover);
}

/* WCAG 2.2 AA 2.3.3 (Animation from Interactions): honour the OS-level
   "reduce motion" setting. Users who set it may be doing so for vestibular
   disorders or motion-triggered migraine, so the transition is removed
   outright rather than shortened. */
@media (prefers-reduced-motion: reduce) {
	.agent-selector__card {
		transition: none;
	}
}
</style>
