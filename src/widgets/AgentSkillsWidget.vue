<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  AgentSkillsWidget — this agent's skill attach/detach section as a
  type:"detail" custom widget (manifest-driven-pages).

  Extracted from AgentDetail.vue's Skills section. `Agent.skillInstalls` is an
  array-of-uuid field referencing an independent `Skill` catalogue — the
  reverse of an `object-list`'s FK-child-collection shape, so it can't be
  expressed declaratively either (design.md Decision 3). Self-fetches the
  agent id from `$route.params.id` and the tenant's skill catalogue.

  Installed-skill membership is read from `Skill.installedOn` (an
  array-of-agentId on each catalogue skill, returned by
  `SkillController::shape()`) rather than `Agent.skillInstalls` — that mirror
  is written best-effort by `SkillService::syncAgentSkillInstalls()` behind a
  swallowed catch, so it can silently fail to round-trip and leave the list
  empty even after a successful attach. `Skill.installedOn` is authoritative.

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
		 * The agent's installed skills as { label, value } options, derived from the
		 * AUTHORITATIVE `Skill.installedOn` field on each catalogue skill (rather than
		 * the best-effort `Agent.skillInstalls` mirror, which can silently fail to
		 * round-trip — SkillService::syncAgentSkillInstalls swallows its write error).
		 *
		 * @return {Array<object>} The installed-skill options.
		 */
		installedSkills() {
			return this.skills
				.filter((skill) => Array.isArray(skill.installedOn) && skill.installedOn.includes(this.agentId))
				.map((skill) => ({ label: skill.name || skill.uuid || skill.id, value: skill.uuid || skill.id }))
		},

		/**
		 * Catalogue skills not yet installed on this agent (per `Skill.installedOn`),
		 * as attach options.
		 *
		 * @return {Array<object>} The attachable-skill options.
		 */
		attachableSkillOptions() {
			return this.skills
				.filter((skill) => !(Array.isArray(skill.installedOn) && skill.installedOn.includes(this.agentId)))
				.map((skill) => ({ label: skill.name || skill.uuid || skill.id, value: skill.uuid || skill.id }))
		},
	},

	created() {
		this.load()
	},

	methods: {
		/**
		 * Load the tenant's skill catalogue (the authoritative source for this
		 * agent's installed skills, via each skill's `installedOn`).
		 *
		 * @return {Promise<void>}
		 */
		async load() {
			this.loading = true
			try {
				const skills = await listSkills().catch(() => [])
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
