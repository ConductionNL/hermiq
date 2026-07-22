<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  AgentSkillsWidget — this agent's skill attach/detach section as a
  type:"detail" custom widget (manifest-driven-pages).

  Extracted from AgentDetail.vue's Skills section. `skillInstalls` is an
  array-of-uuid field on `Agent` referencing an independent `Skill` catalogue
  — the reverse of an `object-list`'s FK-child-collection shape, so it can't
  be expressed declaratively either (design.md Decision 3). Self-fetches the
  agent id from `$route.params.id` and the agent object (for its current
  `skillInstalls`) plus the tenant's skill catalogue.

  @spec openspec/changes/manifest-driven-pages/specs/manifest-driven-pages/spec.md#req-004-a-skills-attach-or-detach-custom-widget-manages-the-agents-skill-installs
-->
<template>
	<div class="agent-skills-widget">
		<div class="agent-skills-widget__head">
			<div class="agent-skills-widget__attach">
				<NcSelect
					v-model="skillToAttach"
					:input-label="t('hermiq', 'Attach a skill')"
					:options="attachableSkillOptions"
					:loading="loading"
					:disabled="skillBusy || attachableSkillOptions.length === 0"
					label="label"
					track-by="value"
					:placeholder="t('hermiq', 'Select a skill to attach')" />
				<NcButton
					type="secondary"
					:disabled="skillBusy || !skillToAttach"
					@click="attachSkill">
					<template v-if="skillBusy" #icon>
						<NcLoadingIcon :size="20" />
					</template>
					{{ t('hermiq', 'Attach') }}
				</NcButton>
			</div>
		</div>

		<p v-if="!loading && installedSkills.length === 0" class="agent-skills-widget__empty-hint">
			{{ t('hermiq', 'No skills installed yet. Attach one to give this agent extra capabilities.') }}
		</p>
		<ul v-else class="agent-skills-widget__list">
			<li v-for="skill in installedSkills" :key="skill.value" class="agent-skills-widget__item">
				<span class="agent-skills-widget__name">{{ skill.label }}</span>
				<NcButton
					type="tertiary"
					:disabled="skillBusy"
					:aria-label="t('hermiq', 'Detach skill')"
					@click="detachSkill(skill.value)">
					<template #icon>
						<Close :size="20" />
					</template>
					{{ t('hermiq', 'Detach') }}
				</NcButton>
			</li>
		</ul>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon, NcSelect } from '@nextcloud/vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import Close from 'vue-material-design-icons/Close.vue'
import { installSkill, listSkills, uninstallSkill } from '../api/skills.js'
import { useAgentStore } from '../store/store.js'

export default {
	name: 'AgentSkillsWidget',

	components: {
		Close,
		NcButton,
		NcLoadingIcon,
		NcSelect,
	},

	data() {
		return {
			agent: null,
			skills: [],
			loading: true,
			skillBusy: false,
			skillToAttach: null,
		}
	},

	computed: {
		/**
		 * This agent's uuid from the route param.
		 *
		 * @return {string} The agent uuid.
		 */
		agentId() {
			return this.$route.params.id
		},

		/**
		 * The agent's installed skills as { label, value } options, resolved from
		 * skillInstalls uuids against the skills catalogue (falls back to the uuid).
		 *
		 * @return {Array<object>} The installed-skill options.
		 */
		installedSkills() {
			const installed = Array.isArray(this.agent && this.agent.skillInstalls) ? this.agent.skillInstalls : []
			return installed.map((uuid) => {
				const match = this.skills.find((skill) => (skill.uuid || skill.id) === uuid)
				return { label: (match && match.name) || uuid, value: uuid }
			})
		},

		/**
		 * Catalogue skills not yet installed on this agent, as attach options.
		 *
		 * @return {Array<object>} The attachable-skill options.
		 */
		attachableSkillOptions() {
			const installed = Array.isArray(this.agent && this.agent.skillInstalls) ? this.agent.skillInstalls : []
			return this.skills
				.filter((skill) => !installed.includes(skill.uuid || skill.id))
				.map((skill) => ({ label: skill.name || skill.uuid || skill.id, value: skill.uuid || skill.id }))
		},
	},

	created() {
		this.agentStore = useAgentStore()
		this.agentStore.registerObjectType('agent', 'agent', 'hermiq')
		this.load()
	},

	methods: {
		/**
		 * Load this agent (for its skillInstalls) and the tenant's skill catalogue.
		 *
		 * @return {Promise<void>}
		 */
		async load() {
			this.loading = true
			try {
				const [agent, skills] = await Promise.all([
					this.agentStore.fetchObject('agent', this.agentId),
					listSkills().catch(() => []),
				])
				this.agent = agent || null
				this.skills = Array.isArray(skills) ? skills : []
			} finally {
				this.loading = false
			}
		},

		/**
		 * Attach the selected catalogue skill to this agent, then refresh.
		 *
		 * @return {Promise<void>}
		 */
		async attachSkill() {
			if (!this.skillToAttach) {
				return
			}
			this.skillBusy = true
			try {
				await installSkill(this.skillToAttach.value, this.agentId)
				this.skillToAttach = null
				await this.load()
				showSuccess(this.t('hermiq', 'Skill attached.'))
			} catch (e) {
				showError(this.t('hermiq', 'Could not attach the skill.'))
			} finally {
				this.skillBusy = false
			}
		},

		/**
		 * Detach a skill from this agent, then refresh.
		 *
		 * @param {string} skillUuid The Skill UUID to detach.
		 * @return {Promise<void>}
		 */
		async detachSkill(skillUuid) {
			this.skillBusy = true
			try {
				await uninstallSkill(skillUuid, this.agentId)
				await this.load()
				showSuccess(this.t('hermiq', 'Skill detached.'))
			} catch (e) {
				showError(this.t('hermiq', 'Could not detach the skill.'))
			} finally {
				this.skillBusy = false
			}
		},
	},
}
</script>

<style scoped>
.agent-skills-widget__head {
	margin-bottom: 12px;
}

.agent-skills-widget__attach {
	display: flex;
	align-items: flex-end;
	gap: 8px;
	min-width: 320px;
}

.agent-skills-widget__attach .v-select {
	min-width: 240px;
}

.agent-skills-widget__empty-hint {
	color: var(--color-text-maxcontrast);
	margin: 4px 0;
}

.agent-skills-widget__list {
	list-style: none;
	margin: 0;
	padding: 0;
}

.agent-skills-widget__item {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	padding: 8px 0;
	border-bottom: 1px solid var(--color-border);
}

.agent-skills-widget__name {
	flex: 1 1 auto;
}
</style>
