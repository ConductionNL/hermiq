<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  SkillProvenance — the SkillDetail page's review-status and origin card
  (skill-install-idempotency): where this skill came from, when it was last
  refreshed from there, its review state, and — when it matters — that local
  learnings are ahead of the source.

  This card exists because the backend already knew all of this and a person
  could not see ANY of it. Installing a skill bundle re-quarantines a skill whose
  content changed and preserves local learnings that an update would otherwise
  overwrite; both were reported only in the install API response, i.e. nowhere a
  human would ever look. A warning that never reaches a person is not a warning.

  The learnings notice is deliberately narrow: it renders only when
  lastAcceptedVersionAt postdates sourceUpdatedAt, which is exactly the condition
  under which an update would have discarded local learnings. A skill nobody has
  taught anything never shows it.

  NOTE the clock is sourceUpdatedAt, never publishedAt — publishedAt records
  publication TO a remote and is empty forever on an instance that only installs,
  so a comparison against it would silently never fire.

  READ-ONLY: this card reports state, it never changes it. Approval stays with the
  existing quarantine review gate.

  @spec openspec/changes/skill-install-idempotency/specs/skills-marketplace/spec.md#requirement-local-learnings-are-never-overwritten-by-an-update
-->
<template>
	<div class="skill-provenance">
		<NcNoteCard
			v-if="error"
			type="error"
			:heading="t('hermiq', 'Provenance error')">
			{{ error }}
		</NcNoteCard>

		<NcLoadingIcon v-if="loading" :size="24" class="skill-provenance__loading" />

		<template v-else>
			<NcNoteCard
				v-if="isQuarantined"
				type="warning"
				:heading="t('hermiq', 'Awaiting review')">
				{{
					quarantineReason
					|| t(
						'hermiq',
						'This skill is quarantined and is not available to agents until it is approved.',
					)
				}}
			</NcNoteCard>

			<NcNoteCard
				v-if="learningsAheadOfSource"
				type="info"
				:heading="t('hermiq', 'Local learnings are ahead of the source')">
				{{
					t(
						'hermiq',
						'This skill has learnings accepted here since it was last updated from its source. Updating keeps them — the incoming learnings file is not applied.',
					)
				}}
			</NcNoteCard>

			<dl class="skill-provenance__facts">
				<div class="skill-provenance__fact">
					<dt>{{ t('hermiq', 'State') }}</dt>
					<dd>{{ stateLabel }}</dd>
				</div>
				<div class="skill-provenance__fact">
					<dt>{{ t('hermiq', 'Source') }}</dt>
					<dd>
						<a
							v-if="sourceUrl"
							:href="sourceUrl"
							target="_blank"
							rel="noreferrer noopener"
							>{{ sourceUrl }}</a
						>
						<span v-else>{{
							t(
								'hermiq',
								'Authored here — not installed from a bundle',
							)
						}}</span>
					</dd>
				</div>
				<div class="skill-provenance__fact">
					<dt>{{ t('hermiq', 'Last updated from source') }}</dt>
					<dd>{{ formatDate(skill && skill.sourceUpdatedAt) }}</dd>
				</div>
				<div class="skill-provenance__fact">
					<dt>{{ t('hermiq', 'Learnings last accepted') }}</dt>
					<dd>{{ formatDate(skill && skill.lastAcceptedVersionAt) }}</dd>
				</div>
			</dl>
		</template>
	</div>
</template>

<script>
import { NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import { useSkillStore } from '../store/store.js'

export default {
	name: 'SkillProvenance',

	components: {
		NcLoadingIcon,
		NcNoteCard,
	},

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

		/**
		 * Whether this skill is awaiting review.
		 *
		 * @spec openspec/changes/skill-install-idempotency/specs/skills-marketplace/spec.md#requirement-a-content-change-returns-a-skill-to-quarantine
		 * @return {boolean} True when quarantined.
		 */
		isQuarantined() {
			return (this.skill && this.skill.state) === 'quarantined'
		},

		/**
		 * Why this skill is quarantined — set to a content-changed reason when an
		 * update replaced content an earlier approval was made about.
		 *
		 * @spec openspec/changes/skill-install-idempotency/specs/skills-marketplace/spec.md#requirement-a-content-change-returns-a-skill-to-quarantine
		 * @return {string} The reason, or ''.
		 */
		quarantineReason() {
			return (this.skill && this.skill.quarantineReason) || ''
		},

		/**
		 * The canonical origin this skill was installed from.
		 *
		 * @spec openspec/changes/skill-install-idempotency/specs/skills-marketplace/spec.md#requirement-installing-a-skill-that-is-already-present-updates-it
		 * @return {string} The source url, or ''.
		 */
		sourceUrl() {
			return (this.skill && this.skill.sourceUrl) || ''
		},

		/**
		 * Human-readable review state.
		 *
		 * @spec exclude presentational label mapping, no domain behaviour
		 * @return {string} The state label.
		 */
		stateLabel() {
			const state = (this.skill && this.skill.state) || ''
			if (state === 'quarantined') {
				return this.t('hermiq', 'Quarantined — awaiting review')
			}

			return state || this.t('hermiq', 'Unknown')
		},

		/**
		 * Whether learnings have been accepted here since the last refresh from
		 * source — the exact condition under which an update preserves the local
		 * learnings file instead of taking the incoming one.
		 *
		 * Compared against sourceUpdatedAt, NEVER publishedAt: publishedAt records
		 * publication TO a remote and stays empty on an instance that only
		 * installs, so that comparison would never fire.
		 *
		 * @spec openspec/changes/skill-install-idempotency/specs/skills-marketplace/spec.md#requirement-local-learnings-are-never-overwritten-by-an-update
		 * @return {boolean} True when local learnings are ahead of the source.
		 */
		learningsAheadOfSource() {
			const accepted = (this.skill && this.skill.lastAcceptedVersionAt) || ''
			if (!accepted) {
				return false
			}

			const synced = (this.skill && this.skill.sourceUpdatedAt) || ''
			if (!synced) {
				// Never synced but learnings exist: local work with no evidence it
				// came from the bundle, so say so rather than stay quiet.
				return true
			}

			return new Date(accepted).getTime() > new Date(synced).getTime()
		},
	},

	/**
	 * Wire the skill store (agentskill object type) and self-fetch the skill.
	 *
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
		 * Load the skill. A missing skill is an error state; a skill with no
		 * provenance at all is a legitimate empty state (authored here), never an
		 * error.
		 *
		 * @spec openspec/changes/skill-install-idempotency/specs/skills-marketplace/spec.md#requirement-installing-a-skill-that-is-already-present-updates-it
		 * @return {Promise<void>}
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				this.skill =
					(await this.skillStore.fetchObject('agentskill', this.skillId))
					|| null
			} catch (e) {
				this.error =
					e?.response?.data?.error
					|| e?.message
					|| this.t('hermiq', 'Could not load the skill.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Format a stored timestamp, with an honest dash when absent.
		 *
		 * @param {string} value The ISO-8601 timestamp.
		 *
		 * @spec exclude presentational date formatting, no domain behaviour
		 * @return {string} The formatted date, or '—'.
		 */
		formatDate(value) {
			if (!value) {
				return '—'
			}

			const parsed = new Date(value)
			if (Number.isNaN(parsed.getTime())) {
				return '—'
			}

			return parsed.toLocaleString()
		},
	},
}
</script>

<style scoped>
.skill-provenance__loading {
	margin: 12px auto;
}

.skill-provenance__facts {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
	gap: 12px;
	margin-top: 12px;
}

.skill-provenance__fact dt {
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.skill-provenance__fact dd {
	margin: 2px 0 0;
	overflow-wrap: break-word;
}
</style>
