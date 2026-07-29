<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  SkillInstallModal — the "Install on agent" picker opened from the Skills
  catalog row actions. Own file per the modal-isolation rule; imported by
  SkillRowActions.vue.

  Agents are loaded lazily on mount via `useAgentStore()` +
  `registerObjectType('agent', 'agent', 'hermiq')` + `fetchCollection('agent')`
  — the same store the retired SkillsCatalog.vue used. The install itself
  calls the existing tenant-scoped SkillController endpoint unchanged
  (src/api/skills.js) and emits `installed` so the parent can refresh the list.

  @spec openspec/specs/skills-catalog/spec.md#requirement-browse-and-install-skills-into-an-agent
-->
<template>
	<NcModal @close="$emit('close')">
		<div class="skill-install-modal">
			<h3>{{ t('hermiq', 'Install on agent') }}</h3>
			<NcNoteCard v-if="installError" type="error">
				{{ installError }}
			</NcNoteCard>
			<NcSelect v-model="selectedAgent"
				:options="agentOptions"
				:input-label="t('hermiq', 'Agent')"
				:loading="loadingAgents"
				:placeholder="t('hermiq', 'Select agent')"
				label="label" />
			<p v-if="!loadingAgents && agentOptions.length === 0" class="skill-install-modal__hint">
				{{ t('hermiq', 'No agents yet. Create one under Agents, then reopen this dialog.') }}
			</p>
			<NcButton
				type="primary"
				:disabled="installing || !canInstall"
				@click="doInstall">
				{{ installing ? t('hermiq', 'Installing…') : t('hermiq', 'Install') }}
			</NcButton>
		</div>
	</NcModal>
</template>

<script>
import { NcButton, NcModal, NcNoteCard, NcSelect } from '@nextcloud/vue'
import { installSkill } from '../api/skills.js'
import { useAgentStore } from '../store/store.js'

export default {
	name: 'SkillInstallModal',

	components: {
		NcButton,
		NcModal,
		NcNoteCard,
		NcSelect,
	},

	props: {
		/** The skill uuid the install applies to. */
		skillId: {
			type: String,
			required: true,
		},
	},

	emits: ['close', 'installed'],

	data() {
		return {
			installing: false,
			installError: '',
			selectedAgent: null,
			agents: [],
			loadingAgents: false,
		}
	},

	computed: {
		/**
		 * The available agents as NcSelect options.
		 *
		 * @spec openspec/specs/skills-catalog/spec.md#requirement-browse-and-install-skills-into-an-agent
		 * @return {Array<object>} NcSelect options.
		 */
		agentOptions() {
			return this.agents.map((agent) => ({
				label: agent.name || agent.uuid || agent.id,
				value: agent.uuid || agent.id,
			}))
		},

		/**
		 * Whether the install form has everything it needs to submit.
		 *
		 * @spec openspec/specs/skills-catalog/spec.md#requirement-browse-and-install-skills-into-an-agent
		 * @return {boolean}
		 */
		canInstall() {
			return !!this.selectedAgent
		},
	},

	mounted() {
		this.fetchAgents()
	},

	methods: {
		/**
		 * Load the tenant's agents (for the agent picker), mirroring the
		 * retired SkillsCatalog.vue's own agent load.
		 *
		 * @spec openspec/specs/skills-catalog/spec.md#requirement-browse-and-install-skills-into-an-agent
		 * @return {Promise<void>}
		 */
		async fetchAgents() {
			this.loadingAgents = true
			try {
				const agentStore = useAgentStore()
				agentStore.registerObjectType('agent', 'agent', 'hermiq')
				const agents = await agentStore.fetchCollection('agent')
				this.agents = Array.isArray(agents) ? agents : []
			} catch (e) {
				this.agents = []
			} finally {
				this.loadingAgents = false
			}
		},

		/**
		 * Install this skill onto the selected agent (records the agent on
		 * `installedOn`), then emit `installed` so the parent refreshes the
		 * catalog list.
		 *
		 * @spec openspec/specs/skills-catalog/spec.md#requirement-browse-and-install-skills-into-an-agent
		 * @return {Promise<void>}
		 */
		async doInstall() {
			if (!this.selectedAgent) {
				return
			}
			this.installing = true
			this.installError = ''
			try {
				await installSkill(this.skillId, this.selectedAgent.value)
				this.$emit('installed')
			} catch (e) {
				this.installError = e?.response?.data?.error || e?.message || this.t('hermiq', 'Unknown error')
			} finally {
				this.installing = false
			}
		},
	},
}
</script>

<style scoped>
.skill-install-modal {
	padding: 20px;
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.skill-install-modal__hint {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	margin: -4px 0 0;
}
</style>
