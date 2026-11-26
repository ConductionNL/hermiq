<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  RemoteAgents — the agents on other servers a local agent may hand work to, in the
  admin settings (flows-hand-off-and-wait D5, REQ-A2A-001).

  Each is reached only through an integriq source, which holds the remote server's
  address and its credential in the broker; hermiq stores no credential. Its agent
  card is fetched once when it is registered and shown here as the snapshot that was
  kept. A new remote agent is off until an admin switches it on. Only an admin
  reaches this section, and the routes refuse anyone else.

  @spec openspec/changes/archive/2026-11-25-flows-hand-off-and-wait/specs/remote-agents/spec.md#requirement-an-admin-registers-a-remote-agent-through-an-integriq-source-req-a2a-001
-->
<template>
	<div class="remote-agents" data-testid="remote-agents">
		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<p v-if="!loading && agents.length === 0" class="remote-agents__empty">
			{{ t('hermiq', 'No remote agent is registered. Agents cannot hand work to another server until an admin adds one.') }}
		</p>

		<ul v-else-if="agents.length > 0" class="remote-agents__list">
			<li
				v-for="agent in agents"
				:key="agent.id"
				class="remote-agents__item"
				data-testid="remote-agent">
				<div class="remote-agents__head">
					<strong>{{ agent.name }}</strong>
					<span class="remote-agents__slug">{{ agent.slug }}</span>
				</div>
				<p v-if="agent.description" class="remote-agents__description">
					{{ agent.description }}
				</p>
				<p class="remote-agents__card">
					{{ cardLine(agent) }}
				</p>
				<ul v-if="skillsOf(agent).length > 0" class="remote-agents__skills">
					<li v-for="skill in skillsOf(agent)" :key="skill.id || skill.name">
						{{ skill.name }}<span v-if="skill.description"> — {{ skill.description }}</span>
					</li>
				</ul>
				<div class="remote-agents__actions">
					<NcCheckboxRadioSwitch
						:modelValue="agent.enabled === true"
						type="switch"
						:disabled="busy"
						@update:modelValue="(on) => setEnabled(agent, on)">
						{{ t('hermiq', 'Agents may hand work to it') }}
					</NcCheckboxRadioSwitch>
					<NcButton variant="tertiary" :disabled="busy" @click="edit(agent)">
						{{ t('hermiq', 'Edit') }}
					</NcButton>
					<NcButton variant="tertiary" :disabled="busy" @click="remove(agent)">
						{{ t('hermiq', 'Delete') }}
					</NcButton>
				</div>
			</li>
		</ul>

		<NcButton variant="secondary" :disabled="loading || busy" @click="edit(null)">
			{{ t('hermiq', 'Add remote agent') }}
		</NcButton>

		<RemoteAgentFormModal
			:show="editing !== false"
			:agent="editing || null"
			@saved="load"
			@close="editing = false" />
	</div>
</template>

<script>
import { NcButton, NcCheckboxRadioSwitch, NcNoteCard } from '@nextcloud/vue'
import RemoteAgentFormModal from '../../modals/RemoteAgentFormModal.vue'
import { deleteRemoteAgent, listRemoteAgents, updateRemoteAgent } from '../../api/remoteAgents.js'
import { settingsCall } from './settingsCall.js'

export default {
	name: 'RemoteAgents',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcNoteCard,
		RemoteAgentFormModal,
	},

	mixins: [settingsCall],

	data() {
		return {
			agents: [],
			/** The remote agent the form is open for, null for a new one, false when closed. */
			editing: false,
		}
	},

	methods: {
		/**
		 * Read the registered remote agents.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/archive/2026-11-25-flows-hand-off-and-wait/specs/remote-agents/spec.md#requirement-an-admin-registers-a-remote-agent-through-an-integriq-source-req-a2a-001
		 */
		async load() {
			await this.withFlag('loading', async () => {
				this.agents = await listRemoteAgents()
			})
		},

		/**
		 * The server's wording for a failed call: RemoteAgentController answers {message}.
		 *
		 * @param {object} e The caught error.
		 * @return {string} The message to show.
		 * @spec exclude Error wording of the settings block; no behavioural spec.
		 */
		messageFor(e) {
			return e?.response?.data?.message || e?.response?.data?.error || e?.message || this.t('hermiq', 'Unknown error')
		},

		/**
		 * The skills of a remote agent's kept card.
		 *
		 * @param {object} agent The remote agent.
		 * @return {Array<{id: string, name: string, description: string}>} The skills.
		 * @spec openspec/changes/archive/2026-11-25-flows-hand-off-and-wait/specs/remote-agents/spec.md#requirement-an-admin-registers-a-remote-agent-through-an-integriq-source-req-a2a-001
		 */
		skillsOf(agent) {
			const skills = agent?.agentCard?.skills
			return Array.isArray(skills) ? skills.filter((skill) => skill && skill.name) : []
		},

		/**
		 * The line saying which card was kept.
		 *
		 * @param {object} agent The remote agent.
		 * @return {string} The line.
		 * @spec openspec/changes/archive/2026-11-25-flows-hand-off-and-wait/specs/remote-agents/spec.md#requirement-an-admin-registers-a-remote-agent-through-an-integriq-source-req-a2a-001
		 */
		cardLine(agent) {
			const card = agent?.agentCard || {}
			if (!card.name) {
				return this.t('hermiq', 'No agent card was kept.')
			}
			return this.t('hermiq', 'Card kept at registration: {name}, version {version}.', {
				name: card.name,
				version: card.version || '–',
			}, undefined, { escape: false })
		},

		/**
		 * Open the form for a remote agent, or for a new one.
		 *
		 * @param {object|null} agent The remote agent, or null.
		 * @return {void}
		 * @spec openspec/changes/archive/2026-11-25-flows-hand-off-and-wait/specs/remote-agents/spec.md#requirement-an-admin-registers-a-remote-agent-through-an-integriq-source-req-a2a-001
		 */
		edit(agent) {
			this.editing = agent
		},

		/**
		 * Switch whether agents may hand work to a remote agent.
		 *
		 * @param {object} agent The remote agent.
		 * @param {boolean} on Whether it is switched on.
		 * @return {Promise<void>}
		 * @spec openspec/changes/archive/2026-11-25-flows-hand-off-and-wait/specs/remote-agents/spec.md#requirement-an-admin-registers-a-remote-agent-through-an-integriq-source-req-a2a-001
		 */
		async setEnabled(agent, on) {
			await this.withFlag('busy', async () => {
				const saved = await updateRemoteAgent(agent.id, { enabled: on === true })
				this.agents = this.agents.map((row) => (row.id === agent.id ? saved : row))
			})
		},

		/**
		 * Remove a remote agent and read the list again.
		 *
		 * @param {object} agent The remote agent.
		 * @return {Promise<void>}
		 * @spec openspec/changes/archive/2026-11-25-flows-hand-off-and-wait/specs/remote-agents/spec.md#requirement-an-admin-registers-a-remote-agent-through-an-integriq-source-req-a2a-001
		 */
		async remove(agent) {
			await this.withFlag('busy', async () => {
				await deleteRemoteAgent(agent.id)
				this.agents = await listRemoteAgents()
			})
		},
	},
}
</script>

<style scoped>
.remote-agents {
	display: flex;
	flex-direction: column;
	gap: 12px;
	align-items: flex-start;
}

.remote-agents__list {
	display: flex;
	flex-direction: column;
	gap: 12px;
	width: 100%;
	margin: 0;
	padding: 0;
	list-style: none;
}

.remote-agents__item {
	padding: 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.remote-agents__head {
	display: flex;
	gap: 8px;
	align-items: baseline;
}

.remote-agents__slug,
.remote-agents__card,
.remote-agents__empty {
	color: var(--color-text-maxcontrast);
}

.remote-agents__description,
.remote-agents__card {
	margin: 4px 0 0;
}

.remote-agents__skills {
	margin: 4px 0 0;
	padding-inline-start: 20px;
}

.remote-agents__actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	align-items: center;
	margin-top: 8px;
}
</style>
