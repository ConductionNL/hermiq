/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * A skill detail widget that loads the skill of the route itself: `skill`,
 * `loading` and `error` in its data, the route's `skillId`, the skill store
 * wired on creation, and `load()`. Shared by the learnings and provenance
 * widgets.
 */

import { useSkillStore } from '../store/store.js'
import { errorMessage } from '../utils/errorMessage.js'

export const routeSkill = {
	data() {
		return {
			skill: null,
			loading: true,
			error: '',
		}
	},

	computed: {
		/**
		 * This skill's uuid from the route param.
		 *
		 * @spec exclude trivial route-param accessor (framework plumbing), no domain behaviour
		 * @return {string} The skill uuid.
		 */
		skillId() {
			return this.$route.params.id
		},
	},

	/**
	 * Wire the skill store (agentskill object type) and self-fetch the skill.
	 *
	 * @spec openspec/specs/skill-learnings/spec.md#requirement-skilldetail-shows-a-read-only-learnings-surface
	 * @spec openspec/changes/skill-install-idempotency/specs/skills-marketplace/spec.md#requirement-local-learnings-are-never-overwritten-by-an-update
	 * @return {void}
	 */
	created() {
		this.skillStore = useSkillStore()
		this.skillStore.registerObjectType('agentskill', 'agentskill', 'hermiq')
		this.load()
	},

	methods: {
		/**
		 * Load the skill. A missing skill leaves `skill` null; a failed fetch is
		 * the error state.
		 *
		 * @spec openspec/specs/skill-learnings/spec.md#requirement-skilldetail-shows-a-read-only-learnings-surface
		 * @spec openspec/changes/skill-install-idempotency/specs/skills-marketplace/spec.md#requirement-installing-a-skill-that-is-already-present-updates-it
		 * @return {Promise<void>}
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				const skill = await this.skillStore.fetchObject(
					'agentskill',
					this.skillId,
				)
				this.skill = skill || null
			} catch (e) {
				this.error = errorMessage(e, this.t('hermiq', 'Could not load the skill.'))
			} finally {
				this.loading = false
			}
		},
	},
}
