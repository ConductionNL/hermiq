<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  SkillLinkPanel — the EvalDatasetDetail page's skill link/unlink section
  (skill-evals). `skillRefs` is a plain relation property on the EvalDataset
  (array of skill uuids, relation dialect), so linking and unlinking are
  ordinary object writes through the generic createObjectStore path — no
  bespoke link endpoint. The picker offers the caller's VISIBLE ACTIVE skills
  (quarantined/stale/archived skills are never link candidates: the run-loop
  seam would refuse to expose them anyway). Self-fetches the dataset id from
  `$route.params.id` (the EvalRunPanelWidget pattern).

  UI copy recommends ONE linked skill per dataset — in joint mode (the
  default) the measured delta is the joint contribution of the whole linked
  set, so a single link is the cheapest clean attribution.

  @spec openspec/specs/agent-evals/spec.md#requirement-the-evaldataset-detail-surface-manages-skill-links-and-paired-runs
  @spec openspec/specs/agent-evals/spec.md#requirement-an-evaldataset-links-skills-via-skillrefs-per-the-relation-dialect
-->
<template>
	<div class="skill-link-panel">
		<NcNoteCard
			v-if="error"
			type="error"
			:heading="t('hermiq', 'Skill link error')">
			{{ error }}
		</NcNoteCard>

		<div class="skill-link-panel__attach">
			<NcSelect
				v-model="skillToLink"
				:inputLabel="t('hermiq', 'Link a skill')"
				:options="linkableSkillOptions"
				:loading="loading"
				:disabled="busy || linkableSkillOptions.length === 0"
				label="label"
				trackBy="value"
				:placeholder="t('hermiq', 'Select a skill to link')" />
			<NcButton
				variant="secondary"
				:disabled="busy || !skillToLink"
				@click="linkSkill">
				<template v-if="busy" #icon>
					<NcLoadingIcon :size="20" />
				</template>
				{{ t('hermiq', 'Link') }}
			</NcButton>
		</div>
		<p class="skill-link-panel__hint">
			{{
				t(
					'hermiq',
					'Linked skills are measured by paired baseline runs. Link one skill per dataset for the cleanest attribution.',
				)
			}}
		</p>

		<p
			v-if="!loading && linkedSkills.length === 0"
			class="skill-link-panel__empty-hint">
			{{
				t(
					'hermiq',
					'No skills linked yet. Link one to enable paired baseline runs.',
				)
			}}
		</p>
		<ul v-else class="skill-link-panel__list">
			<li
				v-for="skill in linkedSkills"
				:key="skill.value"
				class="skill-link-panel__item">
				<span class="skill-link-panel__name">{{ skill.label }}</span>
				<NcButton
					variant="tertiary"
					:disabled="busy"
					:aria-label="t('hermiq', 'Unlink skill')"
					@click="unlinkSkill(skill.value)">
					<template #icon>
						<Close :size="20" />
					</template>
					{{ t('hermiq', 'Unlink') }}
				</NcButton>
			</li>
		</ul>
	</div>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { NcButton, NcLoadingIcon, NcNoteCard, NcSelect } from '@nextcloud/vue'
import Close from 'vue-material-design-icons/Close.vue'
import { listSkills } from '../api/skills.js'
import { useEvalDatasetStore } from '../store/store.js'

export default {
	name: 'SkillLinkPanel',

	components: {
		Close,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
	},

	data() {
		return {
			dataset: null,
			skills: [],
			loading: true,
			busy: false,
			skillToLink: null,
			error: '',
		}
	},

	computed: {
		/**
		 * This dataset's uuid from the route param.
		 *
		 * @spec exclude framework route-param accessor; behaviour owned by the panel's spec-tagged load/link/unlink methods
		 * @return {string} The dataset uuid.
		 */
		datasetId() {
			return this.$route.params.id
		},

		/**
		 * The dataset's linked skill uuids (skillRefs).
		 *
		 * @spec openspec/specs/agent-evals/spec.md#requirement-an-evaldataset-links-skills-via-skillrefs-per-the-relation-dialect
		 * @return {Array<string>} The linked skill uuids.
		 */
		linkedSkillIds() {
			const refs = this.dataset?.skillRefs
			return Array.isArray(refs)
				? refs.filter((ref) => typeof ref === 'string' && ref !== '')
				: []
		},

		/**
		 * The linked skills as { label, value } rows, resolved against the skill
		 * catalogue (falls back to the uuid).
		 *
		 * @spec openspec/specs/agent-evals/spec.md#requirement-the-evaldataset-detail-surface-manages-skill-links-and-paired-runs
		 * @return {Array<object>} The linked-skill rows.
		 */
		linkedSkills() {
			return this.linkedSkillIds.map((uuid) => {
				const match = this.skills.find(
					(skill) => (skill.uuid || skill.id) === uuid,
				)
				return { label: (match && match.name) || uuid, value: uuid }
			})
		},

		/**
		 * Visible ACTIVE skills not yet linked, as picker options.
		 *
		 * @spec openspec/specs/agent-evals/spec.md#requirement-the-evaldataset-detail-surface-manages-skill-links-and-paired-runs
		 * @return {Array<object>} The { label, value } options.
		 */
		linkableSkillOptions() {
			return this.skills
				.filter((skill) => (skill.state || 'active') === 'active')
				.filter(
					(skill) => !this.linkedSkillIds.includes(skill.uuid || skill.id),
				)
				.map((skill) => ({
					label: skill.name || skill.uuid || skill.id,
					value: skill.uuid || skill.id,
				}))
		},
	},

	/**
	 * Wire the dataset store and kick off the initial load.
	 *
	 * @spec exclude framework lifecycle hook; only registers the store and delegates to the spec-tagged load()
	 */
	created() {
		this.datasetStore = useEvalDatasetStore()
		this.datasetStore.registerObjectType('evaldataset', 'evaldataset', 'hermiq')
		this.load()
	},

	methods: {
		/**
		 * Load this dataset (for its skillRefs) and the caller's skill catalogue.
		 *
		 * @spec openspec/specs/agent-evals/spec.md#requirement-the-evaldataset-detail-surface-manages-skill-links-and-paired-runs
		 * @return {Promise<void>}
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				const [dataset, skills] = await Promise.all([
					this.datasetStore.fetchObject('evaldataset', this.datasetId),
					listSkills().catch(() => []),
				])
				this.dataset = dataset || null
				this.skills = Array.isArray(skills) ? skills : []
			} catch (e) {
				this.error =
					e?.response?.data?.error
					|| e?.message
					|| this.t('hermiq', 'Could not load the dataset.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Persist an updated skillRefs array through the generic object path
		 * (plain PUT — the id is preserved so saveObject updates in place).
		 *
		 * @spec openspec/specs/agent-evals/spec.md#requirement-an-evaldataset-links-skills-via-skillrefs-per-the-relation-dialect
		 * @param {Array<string>} skillRefs The new linked uuids.
		 * @return {Promise<void>}
		 */
		async saveRefs(skillRefs) {
			const payload = { ...this.dataset, skillRefs }
			await this.datasetStore.saveObject('evaldataset', payload)
			await this.load()
		},

		/**
		 * Link the selected catalogue skill to this dataset.
		 *
		 * @spec openspec/specs/agent-evals/spec.md#requirement-the-evaldataset-detail-surface-manages-skill-links-and-paired-runs
		 * @return {Promise<void>}
		 */
		async linkSkill() {
			if (!this.skillToLink || !this.dataset) {
				return
			}
			this.busy = true
			try {
				await this.saveRefs([...this.linkedSkillIds, this.skillToLink.value])
				this.skillToLink = null
				showSuccess(this.t('hermiq', 'Skill linked.'))
			} catch (e) {
				showError(this.t('hermiq', 'Could not link the skill.'))
			} finally {
				this.busy = false
			}
		},

		/**
		 * Unlink a skill from this dataset.
		 *
		 * @spec openspec/specs/agent-evals/spec.md#requirement-the-evaldataset-detail-surface-manages-skill-links-and-paired-runs
		 * @param {string} skillUuid The skill uuid to unlink.
		 * @return {Promise<void>}
		 */
		async unlinkSkill(skillUuid) {
			if (!this.dataset) {
				return
			}
			this.busy = true
			try {
				await this.saveRefs(
					this.linkedSkillIds.filter((uuid) => uuid !== skillUuid),
				)
				showSuccess(this.t('hermiq', 'Skill unlinked.'))
			} catch (e) {
				showError(this.t('hermiq', 'Could not unlink the skill.'))
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.skill-link-panel__attach {
	display: flex;
	align-items: flex-end;
	gap: 8px;
	min-width: 320px;
}

.skill-link-panel__attach .v-select {
	min-width: 240px;
}

.skill-link-panel__hint {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	margin: 4px 0 8px;
}

.skill-link-panel__empty-hint {
	color: var(--color-text-maxcontrast);
	margin: 4px 0;
}

.skill-link-panel__list {
	list-style: none;
	margin: 0;
	padding: 0;
}

.skill-link-panel__item {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	padding: 8px 0;
	border-bottom: 1px solid var(--color-border);
}

.skill-link-panel__name {
	flex: 1 1 auto;
}
</style>
