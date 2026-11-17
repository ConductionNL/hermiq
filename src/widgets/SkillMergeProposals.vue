<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  SkillMergeProposals — the skill curator's merge proposals on the Skills page
  (skills-curator-merges-duplicates), resolved via `page.slots.below-header` on the
  SkillsCatalog index page.

  Per proposal: the skill that goes and the skill that stays, how many agents use each,
  and why the pair was proposed. "Merge" archives the duplicate and moves its agents to
  the skill that stays; "Keep both" leaves both skills as they are and the curator does
  not propose the pair again. Nothing is deleted. Both decisions go to
  SkillMergeController, which gates them on the skill.merge-duplicates action: a person
  without it (the list answers 401/403) sees nothing here, and with no pending proposal
  the section is not shown.

  @spec openspec/changes/skills-curator-merges-duplicates/specs/skills-marketplace/spec.md#requirement-the-curator-shall-propose-merging-duplicate-skills
-->
<template>
	<section v-if="visible" class="skill-merges" :aria-label="t('hermiq', 'Merge proposals')">
		<h3 class="skill-merges__title">
			{{ t('hermiq', 'Merge proposals') }}
		</h3>
		<p class="skill-merges__intro">
			{{ t('hermiq', 'The curator found skills that duplicate each other. Merging archives the duplicate and moves its agents to the skill that stays; nothing is deleted.') }}
		</p>

		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>
		<NcNoteCard v-if="notice" type="success">
			{{ notice }}
		</NcNoteCard>

		<ul class="skill-merges__list">
			<li
				v-for="proposal in proposals"
				:key="proposal.duplicate.id"
				class="skill-merges__item">
				<div class="skill-merges__pair">
					<p class="skill-merges__line">
						<span class="skill-merges__role">{{ t('hermiq', 'Goes') }}</span>
						<strong>{{ proposal.duplicate.name }}</strong>
						<span class="skill-merges__agents">{{ agentsLabel(proposal.duplicate.agents) }}</span>
					</p>
					<p class="skill-merges__line">
						<span class="skill-merges__role">{{ t('hermiq', 'Stays') }}</span>
						<strong>{{ proposal.survivor.name }}</strong>
						<span class="skill-merges__agents">{{ agentsLabel(proposal.survivor.agents) }}</span>
					</p>
					<p class="skill-merges__reason">
						{{ reasonLabel(proposal.reason) }}
					</p>
				</div>
				<div class="skill-merges__actions">
					<NcButton
						variant="primary"
						:disabled="busy !== ''"
						@click="decide(proposal, 'merge')">
						{{ t('hermiq', 'Merge') }}
					</NcButton>
					<NcButton
						variant="secondary"
						:disabled="busy !== ''"
						@click="decide(proposal, 'keep')">
						{{ t('hermiq', 'Keep both') }}
					</NcButton>
				</div>
			</li>
		</ul>
	</section>
</template>

<script>
import { NcButton, NcNoteCard } from '@nextcloud/vue'
import { approveMerge, listMergeProposals, rejectMerge } from '../api/skillMerges.js'
import { errorMessage } from '../utils/errorMessage.js'

/** The statuses that mean the person may not decide merges: show nothing. */
const NOT_ALLOWED = [401, 403]

export default {
	name: 'SkillMergeProposals',

	components: {
		NcButton,
		NcNoteCard,
	},

	data() {
		return {
			proposals: [],
			loaded: false,
			allowed: true,
			busy: '',
			error: '',
			notice: '',
		}
	},

	computed: {
		/**
		 * Whether the section shows: loaded, allowed, and something to show.
		 *
		 * @return {boolean} True when visible.
		 * @spec openspec/changes/skills-curator-merges-duplicates/specs/skills-marketplace/spec.md#scenario-two-duplicate-skills-get-one-merge-proposal
		 */
		visible() {
			return this.loaded && this.allowed && (this.proposals.length > 0 || this.error !== '' || this.notice !== '')
		},
	},

	/**
	 * Load the proposals when the page opens.
	 *
	 * @spec openspec/changes/skills-curator-merges-duplicates/specs/skills-marketplace/spec.md#scenario-two-duplicate-skills-get-one-merge-proposal
	 */
	mounted() {
		this.load()
	},

	methods: {
		/**
		 * Read the pending proposals; a refusal hides the section.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/skills-curator-merges-duplicates/specs/skills-marketplace/spec.md#scenario-two-duplicate-skills-get-one-merge-proposal
		 */
		async load() {
			try {
				this.proposals = await listMergeProposals()
			} catch (e) {
				if (NOT_ALLOWED.includes(e?.response?.status)) {
					this.allowed = false
				} else {
					this.error = errorMessage(e, this.t('hermiq', 'Could not load the merge proposals'))
				}
			} finally {
				this.loaded = true
			}
		},

		/**
		 * Merge the pair, or keep both, and take the proposal off the list.
		 *
		 * @param {object} proposal The proposal.
		 * @param {string} choice 'merge' or 'keep'.
		 * @return {Promise<void>}
		 * @spec openspec/changes/skills-curator-merges-duplicates/specs/skills-marketplace/spec.md#scenario-an-approved-merge-archives-the-duplicate-and-moves-its-agents
		 * @spec openspec/changes/skills-curator-merges-duplicates/specs/skills-marketplace/spec.md#scenario-a-rejected-merge-changes-nothing
		 */
		async decide(proposal, choice) {
			const id = proposal.duplicate.id
			this.busy = id
			this.error = ''
			this.notice = ''
			try {
				if (choice === 'merge') {
					await approveMerge(id)
					this.notice = this.t('hermiq', '{duplicate} was merged into {survivor}.', {
						duplicate: proposal.duplicate.name,
						survivor: proposal.survivor.name,
					}, undefined, { escape: false })
				} else {
					await rejectMerge(id)
					this.notice = this.t('hermiq', 'Both skills were kept.')
				}
				this.proposals = this.proposals.filter((candidate) => candidate.duplicate.id !== id)
			} catch (e) {
				this.error = errorMessage(e, this.t('hermiq', 'Unknown error'))
			} finally {
				this.busy = ''
			}
		},

		/**
		 * How many agents use a skill.
		 *
		 * @param {number} count The agent count.
		 * @return {string} The label.
		 * @spec openspec/changes/skills-curator-merges-duplicates/specs/skills-marketplace/spec.md#scenario-two-duplicate-skills-get-one-merge-proposal
		 */
		agentsLabel(count) {
			return this.n('hermiq', 'used by %n agent', 'used by %n agents', count)
		},

		/**
		 * Why the pair was proposed.
		 *
		 * @param {string} reason `same-name` or `same-instructions`.
		 * @return {string} The label.
		 * @spec openspec/changes/skills-curator-merges-duplicates/specs/skills-marketplace/spec.md#scenario-two-duplicate-skills-get-one-merge-proposal
		 */
		reasonLabel(reason) {
			if (reason === 'same-instructions') {
				return this.t('hermiq', 'Both skills have the same instructions.')
			}
			return this.t('hermiq', 'Both skills have the same name.')
		},
	},
}
</script>

<style scoped>
.skill-merges {
	margin-block: 12px 20px;
	padding: 12px 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	background-color: var(--color-main-background);
}

.skill-merges__title {
	margin: 0 0 4px;
	font-size: 1rem;
	font-weight: bold;
}

.skill-merges__intro {
	margin: 0 0 12px;
	color: var(--color-text-maxcontrast);
}

.skill-merges__list {
	margin: 0;
	padding: 0;
	list-style: none;
}

.skill-merges__item {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	padding-block: 10px;
	border-block-start: 1px solid var(--color-border);
}

.skill-merges__line,
.skill-merges__reason {
	margin: 0;
}

.skill-merges__role {
	display: inline-block;
	min-width: 4em;
	margin-inline-end: 8px;
	color: var(--color-text-maxcontrast);
}

.skill-merges__agents,
.skill-merges__reason {
	color: var(--color-text-maxcontrast);
	margin-inline-start: 8px;
}

.skill-merges__reason {
	margin-inline-start: 0;
	font-size: 0.9em;
}

.skill-merges__actions {
	display: flex;
	gap: 8px;
}
</style>
