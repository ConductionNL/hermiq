<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  SetDefaultAgentDialog — make the agent on this detail page the user's default.

  Own file per ADR-004 modal-isolation, using NcDialog, mirroring
  AgentFactsheetDialog.vue's structure. Opened from AgentDetail's "Set as default"
  header action (registry `set-default-agent` open-modal target), so — like the
  sibling agent modals — it self-resolves the current agent from the route's `:id`
  (open-modal action props are static JSON, not resolved against the current object).

  It writes the same per-user `default-agent` preference the personal-settings picker
  uses, so the two surfaces stay in lockstep. Setting a default here is a preference,
  never an authorization: the server re-checks access on every resolve, so this only
  records a choice.
-->
<template>
	<NcDialog
		:name="t('hermiq', 'Default agent')"
		:open="show"
		size="normal"
		@update:open="$emit('close')">
		<div class="set-default-agent-dialog">
			<div v-if="loading" class="set-default-agent-dialog__loading">
				<NcLoadingIcon :size="32" />
			</div>

			<NcNoteCard v-else-if="error" type="error">
				{{ error }}
			</NcNoteCard>

			<template v-else>
				<p v-if="isDefault" class="set-default-agent-dialog__text">
					{{ t('hermiq', '{name} is your default agent — new conversations start with it.', { name: agentName }) }}
				</p>
				<p v-else class="set-default-agent-dialog__text">
					{{ t('hermiq', 'Make {name} the agent new conversations start with. This replaces any default you set before, including one chosen by your administrator.', { name: agentName }) }}
				</p>

				<NcNoteCard v-if="saved" type="success">
					{{ savedMessage }}
				</NcNoteCard>
			</template>
		</div>

		<template #actions>
			<NcButton @click="$emit('close')">
				{{ t('hermiq', 'Close') }}
			</NcButton>
			<NcButton
				v-if="!loading && !error && isDefault"
				type="secondary"
				:disabled="saving"
				@click="clear">
				<template v-if="saving" #icon>
					<NcLoadingIcon :size="18" />
				</template>
				{{ t('hermiq', 'Clear default') }}
			</NcButton>
			<NcButton
				v-if="!loading && !error && !isDefault"
				type="primary"
				:disabled="saving"
				@click="set">
				<template v-if="saving" #icon>
					<NcLoadingIcon :size="18" />
				</template>
				{{ t('hermiq', 'Set as default') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import { getDefaultAgent, setDefaultAgent } from '../api/agents.js'
import { useAgentStore } from '../store/store.js'

export default {
	name: 'SetDefaultAgentDialog',

	components: {
		NcButton,
		NcDialog,
		NcLoadingIcon,
		NcNoteCard,
	},

	props: {
		/** Whether the dialog is visible. */
		show: {
			type: Boolean,
			default: false,
		},
		/**
		 * The agent UUID. Optional — when opened as the registry
		 * `set-default-agent` open-modal target, no prop is available
		 * (open-modal action props are static JSON, not resolved against the
		 * current object), so it self-resolves from the route's `:id` (see
		 * `resolvedAgentId`).
		 */
		agentId: {
			type: String,
			default: '',
		},
	},

	emits: ['close'],

	data() {
		return {
			agentName: '',
			isDefault: false,
			loading: false,
			saving: false,
			saved: false,
			savedMessage: '',
			error: '',
		}
	},

	computed: {
		/**
		 * The agent uuid — the `agentId` prop when supplied, else the route `:id`.
		 *
		 * @return {string} The resolved agent uuid.
		 */
		resolvedAgentId() {
			return this.agentId || this.$route?.params?.id || ''
		},
	},

	watch: {
		// `immediate: true`: opened via open-modal, CnAppRoot mounts this FRESH
		// with `show` already true, so a plain watcher would never fire.
		show: {
			immediate: true,
			handler(open) {
				if (open) {
					this.load()
				}
			},
		},
	},

	methods: {
		/**
		 * Resolve the agent's name and whether it is the current default.
		 *
		 * The agent store is resolved HERE, not in `created()`: the `show` watcher is
		 * `immediate`, so it fires during initialisation BEFORE `created()` runs, and a
		 * store set up in `created()` would still be undefined on that first load.
		 *
		 * @return {Promise<void>}
		 */
		async load() {
			this.loading = true
			this.error = ''
			this.saved = false
			const agentStore = useAgentStore()
			agentStore.registerObjectType('agent', 'agent', 'hermiq')
			try {
				const [agent, current] = await Promise.all([
					agentStore.fetchObject('agent', this.resolvedAgentId).catch(() => null),
					getDefaultAgent(),
				])
				this.agentName = agent?.name || this.t('hermiq', 'this agent')
				this.isDefault = current === this.resolvedAgentId
			} catch (e) {
				this.error = e?.response?.data?.error || e?.message || this.t('hermiq', 'Unknown error')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Make this agent the user's default.
		 *
		 * @return {Promise<void>}
		 */
		async set() {
			await this.persist(this.resolvedAgentId, this.t('hermiq', 'Default agent set.'))
		},

		/**
		 * Clear the user's default (fall back to the admin default / first accessible).
		 *
		 * @return {Promise<void>}
		 */
		async clear() {
			await this.persist('', this.t('hermiq', 'Default agent cleared.'))
		},

		/**
		 * Persist the preference and reflect the new state.
		 *
		 * @param {string} uuid    The agent UUID, or `''` to clear.
		 * @param {string} message The success message to show.
		 * @return {Promise<void>}
		 */
		async persist(uuid, message) {
			this.saving = true
			this.error = ''
			try {
				await setDefaultAgent(uuid)
				this.isDefault = uuid !== ''
				this.savedMessage = message
				this.saved = true
			} catch (e) {
				this.error = e?.response?.data?.error || e?.message || this.t('hermiq', 'Could not save the default agent.')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.set-default-agent-dialog {
	padding: 8px 0;
}

.set-default-agent-dialog__text {
	color: var(--color-text-maxcontrast);
}

.set-default-agent-dialog__loading {
	display: flex;
	justify-content: center;
	padding: 24px 0;
}
</style>
