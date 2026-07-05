<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  SkillsCatalog — the Hermiq "Skills" nav page (skills-catalog).

  Browse the tenant's skills (name, description, state, installed count), import an
  agentskills.io package (paste + Import), export a skill back to a package, and install
  a skill onto an agent (agent picker + Install). All reads/writes go through the
  tenant-scoped SkillController endpoints (src/api/skills.js) — no new write path, no
  custom Pinia store.

  A standard nav page — NOT a dashboard (dashboard-antipattern gate). The agent run loop
  that makes an installed skill available during a run is an OpenRegister seam (ADR-001
  Option C+). Every NcSelect carries an inputLabel (ADR-004, WCAG 2.1 AA).

  @spec openspec/changes/skills-catalog/tasks.md#task-5-2
  @spec openspec/changes/skills-catalog/specs/skills-catalog/spec.md
-->
<template>
	<div class="skills-catalog">
		<div class="skills-catalog__header">
			<h2 class="skills-catalog__heading">
				{{ t('hermiq', 'Skills') }}
			</h2>
		</div>

		<NcNoteCard v-if="error" type="error" :heading="t('hermiq', 'Skills error')">
			{{ error }}
		</NcNoteCard>

		<!-- Import an agentskills.io package -->
		<section class="skills-catalog__import">
			<h3 class="skills-catalog__subhead">
				{{ t('hermiq', 'Import a skill') }}
			</h3>
			<textarea
				v-model="importText"
				class="skills-catalog__textarea"
				:disabled="busy"
				:placeholder="importPlaceholder" />
			<NcButton
				type="primary"
				:disabled="busy || !importText.trim()"
				:aria-label="t('hermiq', 'Import skill')"
				@click="doImport">
				<template v-if="busy" #icon>
					<NcLoadingIcon :size="18" />
				</template>
				{{ t('hermiq', 'Import') }}
			</NcButton>
		</section>

		<!-- Skills list -->
		<section class="skills-catalog__section">
			<h3 class="skills-catalog__subhead">
				{{ t('hermiq', 'Skills') }} ({{ skills.length }})
			</h3>

			<div v-if="loading" class="skills-catalog__loading">
				<NcLoadingIcon :size="32" />
			</div>

			<NcEmptyContent
				v-else-if="skills.length === 0"
				:name="t('hermiq', 'No skills yet')"
				:description="t('hermiq', 'Import an agentskills.io package to add a skill.')">
				<template #icon>
					<PuzzleIcon :size="20" />
				</template>
			</NcEmptyContent>

			<ul v-else class="skills-catalog__list">
				<li v-for="skill in skills" :key="skill.uuid" class="skills-catalog__skill">
					<div class="skills-catalog__skill-main">
						<span class="skills-catalog__skill-name">{{ skill.name }}</span>
						<span class="skills-catalog__skill-state" :class="`skills-catalog__skill-state--${skill.state}`">{{ skill.state }}</span>
						<span class="skills-catalog__skill-desc">{{ skill.description || '—' }}</span>
						<span class="skills-catalog__skill-installed">
							{{ n('hermiq', 'installed on %n agent', 'installed on %n agents', installedCount(skill)) }}
						</span>
					</div>
					<div class="skills-catalog__skill-actions">
						<NcSelect
							v-model="installTarget[skill.uuid]"
							class="skills-catalog__agent-select"
							:input-label="t('hermiq', 'Agent')"
							:options="agentOptions"
							:placeholder="t('hermiq', 'Select agent')"
							label="label"
							track-by="value" />
						<NcButton
							type="secondary"
							:disabled="busy || !installTarget[skill.uuid]"
							:aria-label="t('hermiq', 'Install on agent')"
							@click="doInstall(skill)">
							{{ t('hermiq', 'Install') }}
						</NcButton>
						<NcButton
							type="tertiary"
							:aria-label="t('hermiq', 'Export skill')"
							@click="doExport(skill)">
							{{ t('hermiq', 'Export') }}
						</NcButton>
					</div>
				</li>
			</ul>
		</section>

		<!-- Export result -->
		<NcModal v-if="showExport" @close="showExport = false">
			<div class="skills-catalog__export-modal">
				<h3>{{ t('hermiq', 'Exported package') }}</h3>
				<textarea class="skills-catalog__textarea" readonly :value="exportedPackage" />
			</div>
		</NcModal>
	</div>
</template>

<script>
import { NcButton, NcEmptyContent, NcLoadingIcon, NcModal, NcNoteCard, NcSelect } from '@nextcloud/vue'
import PuzzleIcon from 'vue-material-design-icons/PuzzleOutline.vue'
import { listAgents } from '../api/agents.js'
import { exportSkill, importSkill, installSkill, listSkills } from '../api/skills.js'

export default {
	name: 'SkillsCatalog',

	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcModal,
		NcNoteCard,
		NcSelect,
		PuzzleIcon,
	},

	data() {
		return {
			skills: [],
			agents: [],
			installTarget: {},
			importText: '',
			exportedPackage: '',
			showExport: false,
			loading: true,
			busy: false,
			error: '',
		}
	},

	computed: {
		/**
		 * The agents as NcSelect options.
		 *
		 * @return {Array<object>} The { label, value } options.
		 */
		agentOptions() {
			return this.agents.map((agent) => ({
				label: agent.name || agent.uuid || agent.id,
				value: agent.uuid || agent.id,
			}))
		},

		/**
		 * The import textarea placeholder (an agentskills.io skeleton).
		 *
		 * @return {string} The placeholder text.
		 */
		importPlaceholder() {
			return '---\nname: My Skill\ndescription: What it does\n---\n# My Skill\n\nInstructions…'
		},
	},

	created() {
		this.load()
	},

	methods: {
		/**
		 * Load the skills + agents.
		 *
		 * @return {Promise<void>}
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				const [skills, agents] = await Promise.all([listSkills(), listAgents()])
				this.skills = skills
				this.agents = agents
			} catch (e) {
				this.error = e?.response?.data?.error || e?.message || this.t('hermiq', 'Unknown error')
			} finally {
				this.loading = false
			}
		},

		/**
		 * The number of agents a skill is installed on.
		 *
		 * @param {object} skill The skill record.
		 * @return {number} The installed count.
		 */
		installedCount(skill) {
			return Array.isArray(skill.installedOn) ? skill.installedOn.length : 0
		},

		/**
		 * Import the pasted agentskills.io package.
		 *
		 * @return {Promise<void>}
		 */
		async doImport() {
			const pkg = this.importText.trim()
			if (!pkg) {
				return
			}
			this.busy = true
			this.error = ''
			try {
				await importSkill(pkg)
				this.importText = ''
				await this.load()
			} catch (e) {
				this.error = e?.response?.data?.error || e?.message || this.t('hermiq', 'Unknown error')
			} finally {
				this.busy = false
			}
		},

		/**
		 * Install a skill onto the selected agent.
		 *
		 * @param {object} skill The skill record.
		 * @return {Promise<void>}
		 */
		async doInstall(skill) {
			const target = this.installTarget[skill.uuid]
			if (!target) {
				return
			}
			this.busy = true
			this.error = ''
			try {
				await installSkill(skill.uuid, target.value)
				await this.load()
			} catch (e) {
				this.error = e?.response?.data?.error || e?.message || this.t('hermiq', 'Unknown error')
			} finally {
				this.busy = false
			}
		},

		/**
		 * Export a skill to an agentskills.io package and show it.
		 *
		 * @param {object} skill The skill record.
		 * @return {Promise<void>}
		 */
		async doExport(skill) {
			this.error = ''
			try {
				this.exportedPackage = await exportSkill(skill.uuid)
				this.showExport = true
			} catch (e) {
				this.error = e?.response?.data?.error || e?.message || this.t('hermiq', 'Unknown error')
			}
		},
	},
}
</script>

<style scoped>
.skills-catalog {
	padding: 20px;
	max-width: 900px;
	margin: 0 auto;
}

.skills-catalog__heading {
	margin: 0 0 16px;
	font-size: 22px;
	font-weight: 600;
}

.skills-catalog__subhead {
	font-size: 16px;
	font-weight: 600;
	margin: 0 0 8px;
}

.skills-catalog__import {
	margin-bottom: 24px;
}

.skills-catalog__textarea {
	width: 100%;
	min-height: 120px;
	font-family: monospace;
	font-size: 13px;
	margin-bottom: 8px;
	resize: vertical;
}

.skills-catalog__section {
	margin-bottom: 24px;
}

.skills-catalog__loading {
	display: flex;
	justify-content: center;
	padding: 32px 0;
}

.skills-catalog__list {
	list-style: none;
	margin: 0;
	padding: 0;
}

.skills-catalog__skill {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	padding: 12px;
	border-bottom: 1px solid var(--color-border);
	flex-wrap: wrap;
}

.skills-catalog__skill-main {
	display: flex;
	flex-direction: column;
	gap: 2px;
	flex: 1 1 320px;
}

.skills-catalog__skill-name {
	font-weight: 600;
}

.skills-catalog__skill-state {
	font-size: 12px;
	text-transform: uppercase;
	width: fit-content;
	padding: 1px 6px;
	border-radius: 4px;
	background: var(--color-background-dark);
}

.skills-catalog__skill-state--active {
	color: var(--color-success);
}

.skills-catalog__skill-state--archived {
	color: var(--color-text-maxcontrast);
}

.skills-catalog__skill-desc {
	color: var(--color-text-maxcontrast);
}

.skills-catalog__skill-installed {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.skills-catalog__skill-actions {
	display: flex;
	align-items: flex-end;
	gap: 8px;
}

.skills-catalog__agent-select {
	min-width: 180px;
}

.skills-catalog__export-modal {
	padding: 20px;
}
</style>
