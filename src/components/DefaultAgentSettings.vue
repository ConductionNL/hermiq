<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  DefaultAgentSettings — the user's own default companion agent.

  A per-user choice, so it lives in Personal settings (above Talk delivery). The
  user picks any agent they can access; the choice is stored as the per-user
  `default-agent` preference and read as the HIGHEST-precedence tier when the
  companion widget starts a conversation — above the instance-wide companion
  agent, above the first-accessible fallback. Clearing it (the ✕) falls back to
  those tiers. The stored value is a preference, never an authorization: the
  server re-checks access on every resolve.
-->
<template>
	<div class="default-agent">
		<p class="default-agent__text">
			{{ t('hermiq', 'Choose the agent new conversations start with. Leave empty to use whatever default the administrator has set, or the first agent you can access.') }}
		</p>
		<NcSelect
			class="default-agent__select"
			:input-label="t('hermiq', 'Default agent')"
			:options="agentOptions"
			:value="selected"
			:loading="loading"
			label="label"
			:placeholder="t('hermiq', 'Search your agents…')"
			@input="onSelect" />
		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>
		<NcNoteCard v-else-if="saved" type="success">
			{{ t('hermiq', 'Default agent saved.') }}
		</NcNoteCard>
	</div>
</template>

<script>
import { NcNoteCard, NcSelect } from '@nextcloud/vue'
import { getDefaultAgent, setDefaultAgent } from '../api/agents.js'
import { useAgentStore } from '../store/store.js'

export default {
	name: 'DefaultAgentSettings',

	components: {
		NcNoteCard,
		NcSelect,
	},

	data() {
		return {
			agents: [],
			selected: null,
			loading: false,
			saved: false,
			error: '',
		}
	},

	computed: {
		/**
		 * The accessible agents as NcSelect options.
		 *
		 * @return {Array<object>} The { label, value } options.
		 */
		agentOptions() {
			return this.agents.map((agent) => ({
				label: agent.name || agent.uuid || agent.id,
				value: agent.uuid || agent.id,
			}))
		},
	},

	created() {
		this.agentStore = useAgentStore()
		this.agentStore.registerObjectType('agent', 'agent', 'hermiq')
		this.load()
	},

	methods: {
		/**
		 * Load the accessible agents and the user's stored default, then
		 * pre-select the stored one when it is still in the list.
		 *
		 * @return {Promise<void>}
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				const [agents, storedUuid] = await Promise.all([
					this.agentStore.fetchCollection('agent'),
					getDefaultAgent(),
				])
				this.agents = Array.isArray(agents) ? agents : []
				if (this.agentStore.errors?.agent) {
					this.error = this.agentStore.errors.agent.message || this.t('hermiq', 'Unknown error')
				}
				this.selected = this.agentOptions.find((o) => o.value === storedUuid) || null
			} catch (e) {
				this.error = e?.response?.data?.error || e?.message || this.t('hermiq', 'Unknown error')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Persist the chosen agent (or clear it when the selection is removed).
		 *
		 * @param {object|null} option The chosen { label, value } option, or null.
		 * @return {Promise<void>}
		 */
		async onSelect(option) {
			this.selected = option
			this.saved = false
			this.error = ''
			try {
				await setDefaultAgent(option ? option.value : '')
				this.saved = true
			} catch (e) {
				this.error = e?.response?.data?.error || e?.message || this.t('hermiq', 'Could not save the default agent.')
			}
		},
	},
}
</script>

<style scoped>
.default-agent__text {
	margin-bottom: 8px;
	color: var(--color-text-maxcontrast);
}

.default-agent__select {
	width: 100%;
	max-width: 400px;
}
</style>
