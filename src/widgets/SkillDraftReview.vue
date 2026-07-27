<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  SkillDraftReview — the SkillDetail page's draft review surface
  (skill-self-improvement): for a draft in `awaiting-approval` it renders the
  side-by-side diff of proposed vs active content (added/removed lines carry +/−
  markers and accessible labels — never color-only), the driving learnings
  entries, the scan verdict, and the eval delta or the verbatim "no eval
  evidence" flag, plus the three action-gated decisions:

  - Accept        → POST /api/skill-drafts/{id}/accept — decides by transitioning
                    the linked Approval; the apply step fires on THAT transition
                    (identical to a generic-inbox approval).
  - Edit-then-accept → opens the draft content editor first; saving INVALIDATES
                    the stored scan/eval evidence and re-runs pre-qualification
                    (the Approval is not approvable anywhere until it passes).
  - Reject        → optional note + per-entry bad-learnings marking
                    (rejectedLearningRefs — recorded on the DRAFT, never as an
                    edit to learnings.md).

  A "Propose improvement" action (owner-guarded, gated like the background job)
  is offered when no draft is open. Self-fetches from $route.params.id (the
  SkillLearnings pattern).

  @spec openspec/specs/skill-self-improvement/spec.md#requirement-the-skilldetail-review-surface-presents-diff-provenance-and-verdicts-with-three-actions
-->
<template>
	<div class="skill-draft-review">
		<NcNoteCard v-if="error" type="error" :heading="t('hermiq', 'Draft review error')">
			{{ error }}
		</NcNoteCard>

		<NcLoadingIcon v-if="loading" :size="24" class="skill-draft-review__loading" />

		<template v-else>
			<template v-if="pendingDraft">
				<div class="skill-draft-review__meta">
					<span class="skill-draft-review__chip" role="status">
						{{ t('hermiq', 'Awaiting review') }}
					</span>
					<span class="skill-draft-review__fact">
						{{ t('hermiq', 'Trigger: {trigger}', { trigger: pendingDraft.trigger || '—' }) }}
					</span>
					<span class="skill-draft-review__fact">
						{{ t('hermiq', 'Scan verdict: {verdict}', { verdict: pendingDraft.scanVerdict || t('hermiq', 'pending') }) }}
					</span>
					<span v-if="pendingDraft.noEvalEvidence" class="skill-draft-review__fact skill-draft-review__fact--warn">
						{{ t('hermiq', 'No eval evidence — accepting this draft can never grant L5.') }}
					</span>
					<span v-else-if="evalDeltaLabel" class="skill-draft-review__fact">
						{{ evalDeltaLabel }}
					</span>
					<span v-if="pendingDraft.editedBeforeAccept" class="skill-draft-review__fact">
						{{ t('hermiq', 'Edited before accept by {editor}', { editor: pendingDraft.editedBy || '—' }) }}
					</span>
				</div>

				<h4 class="skill-draft-review__heading">
					{{ t('hermiq', 'Proposed change (side-by-side)') }}
				</h4>
				<div class="skill-draft-review__columns">
					<div class="skill-draft-review__column">
						<h5>{{ t('hermiq', 'Active version') }}</h5>
						<pre class="skill-draft-review__pre">{{ activeBody }}</pre>
					</div>
					<div class="skill-draft-review__column">
						<h5>{{ t('hermiq', 'Proposed version') }}</h5>
						<pre class="skill-draft-review__pre">{{ proposedBody }}</pre>
					</div>
				</div>

				<h4 class="skill-draft-review__heading">
					{{ t('hermiq', 'Line changes') }}
				</h4>
				<ul class="skill-draft-review__diff" :aria-label="t('hermiq', 'Changed lines between the active and proposed version')">
					<li v-for="(line, index) in diffLines"
						:key="index"
						:class="['skill-draft-review__diff-line', 'skill-draft-review__diff-line--' + line.kind]">
						<span class="skill-draft-review__diff-marker" :aria-label="line.kind === 'added' ? t('hermiq', 'Added line') : t('hermiq', 'Removed line')">
							{{ line.kind === 'added' ? '+' : '−' }}
						</span>
						<span class="skill-draft-review__diff-text">{{ line.text }}</span>
					</li>
					<li v-if="diffLines.length === 0" class="skill-draft-review__empty">
						{{ t('hermiq', 'The proposed body is identical to the active body.') }}
					</li>
				</ul>

				<h4 class="skill-draft-review__heading">
					{{ t('hermiq', 'Driving learnings entries') }}
				</h4>
				<ul class="skill-draft-review__refs">
					<li v-for="ref in learningRefs" :key="ref">
						<code>{{ ref }}</code>
					</li>
					<li v-if="learningRefs.length === 0" class="skill-draft-review__empty">
						{{ t('hermiq', 'No driving entries recorded.') }}
					</li>
				</ul>

				<!--
				  No aria-label overrides here: each button's visible text IS its
				  accessible name. An aria-label that replaces visible text breaks
				  WCAG 2.5.3 (Label in Name) when the visible label is not contained
				  in it ("Edit, then accept" vs "Edit the draft before accepting").
				-->
				<div class="skill-draft-review__actions">
					<NcButton type="primary"
						:disabled="busy"
						@click="doAccept">
						{{ busy ? t('hermiq', 'Working…') : t('hermiq', 'Accept') }}
					</NcButton>
					<NcButton type="secondary"
						:disabled="busy"
						@click="openEditor">
						{{ t('hermiq', 'Edit, then accept') }}
					</NcButton>
					<NcButton type="tertiary"
						:disabled="busy"
						@click="showReject = true">
						{{ t('hermiq', 'Reject') }}
					</NcButton>
				</div>
			</template>

			<template v-else-if="qualifyingDraft">
				<p class="skill-draft-review__empty">
					{{ t('hermiq', 'A draft is being qualified (content scan and paired eval). It appears here once it passes the gates.') }}
				</p>
			</template>

			<template v-else>
				<p class="skill-draft-review__empty">
					{{ t('hermiq', 'No open improvement draft. Consolidation proposes one automatically once enough learnings accumulate — or propose one now.') }}
				</p>
				<NcButton type="secondary"
					:disabled="busy"
					:aria-label="t('hermiq', 'Propose an improvement draft now')"
					@click="doPropose">
					{{ t('hermiq', 'Propose improvement') }}
				</NcButton>
			</template>
		</template>

		<SkillDraftEditModal v-if="showEditor"
			:draft="pendingDraft"
			:busy="busy"
			@save="doEditContent"
			@close="showEditor = false" />

		<SkillDraftRejectModal v-if="showReject"
			:learning-refs="learningRefs"
			:busy="busy"
			@reject="doReject"
			@close="showReject = false" />
	</div>
</template>

<script>
import { emit } from '@nextcloud/event-bus'
import { NcButton, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import {
	acceptSkillDraft,
	listSkillDrafts,
	proposeSkillImprovement,
	rejectSkillDraft,
	updateSkillDraftContent,
} from '../api/skills.js'
import SkillDraftEditModal from '../modals/SkillDraftEditModal.vue'
import SkillDraftRejectModal from '../modals/SkillDraftRejectModal.vue'
import { useSkillStore } from '../store/store.js'

export default {
	name: 'SkillDraftReview',

	components: {
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		SkillDraftEditModal,
		SkillDraftRejectModal,
	},

	data() {
		return {
			skill: null,
			drafts: [],
			loading: true,
			busy: false,
			error: '',
			showEditor: false,
			showReject: false,
		}
	},

	computed: {
		/**
		 * This skill's uuid from the route param.
		 *
		 * @spec exclude pure route-param accessor, no spec behaviour
		 * @return {string} The skill uuid.
		 */
		skillId() {
			return this.$route.params.id
		},

		/**
		 * The draft awaiting the human decision, when one exists.
		 *
		 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-the-skilldetail-review-surface-presents-diff-provenance-and-verdicts-with-three-actions
		 * @return {object|null} The awaiting-approval draft.
		 */
		pendingDraft() {
			return this.drafts.find((draft) => draft?.status === 'awaiting-approval') || null
		},

		/**
		 * A draft still inside pre-qualification (proposed), when one exists.
		 *
		 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-the-skilldetail-review-surface-presents-diff-provenance-and-verdicts-with-three-actions
		 * @return {object|null} The proposed draft.
		 */
		qualifyingDraft() {
			return this.drafts.find((draft) => draft?.status === 'proposed') || null
		},

		/**
		 * The active skill body ('' when unavailable).
		 *
		 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-the-skilldetail-review-surface-presents-diff-provenance-and-verdicts-with-three-actions
		 * @return {string} The active body.
		 */
		activeBody() {
			return typeof this.skill?.body === 'string' ? this.skill.body : ''
		},

		/**
		 * The proposed draft body ('' when unavailable).
		 *
		 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-the-skilldetail-review-surface-presents-diff-provenance-and-verdicts-with-three-actions
		 * @return {string} The proposed body.
		 */
		proposedBody() {
			return typeof this.pendingDraft?.proposedBody === 'string' ? this.pendingDraft.proposedBody : ''
		},

		/**
		 * The driving learnings refs from the draft's provenance.
		 *
		 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-the-skilldetail-review-surface-presents-diff-provenance-and-verdicts-with-three-actions
		 * @return {Array<string>} The refs.
		 */
		learningRefs() {
			const refs = this.pendingDraft?.provenance?.learningRefs
			return Array.isArray(refs) ? refs.filter((ref) => typeof ref === 'string' && ref !== '') : []
		},

		/**
		 * A human eval-delta label ('' when no eval evidence is recorded).
		 *
		 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-the-skilldetail-review-surface-presents-diff-provenance-and-verdicts-with-three-actions
		 * @return {string} The label.
		 */
		evalDeltaLabel() {
			const evidence = this.pendingDraft?.evalEvidence
			if (!evidence || typeof evidence.delta !== 'number') {
				return ''
			}
			const draftRate = Math.round((evidence.draftPassRate || 0) * 100)
			const activeRate = Math.round((evidence.activePassRate || 0) * 100)
			const delta = Math.round(evidence.delta * 100)
			return this.t('hermiq', 'Eval: draft {draft}% vs active {active}% (delta {delta})', {
				draft: draftRate,
				active: activeRate,
				delta: (delta >= 0 ? '+' : '') + delta + '%',
			})
		},

		/**
		 * A simple line-level diff (added/removed) between the active and proposed
		 * bodies — markers + accessible names, never color-only.
		 *
		 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-the-skilldetail-review-surface-presents-diff-provenance-and-verdicts-with-three-actions
		 * @return {Array<{kind: string, text: string}>} The changed lines.
		 */
		diffLines() {
			const activeLines = this.activeBody.split('\n')
			const proposedLines = this.proposedBody.split('\n')
			const activeSet = new Set(activeLines)
			const proposedSet = new Set(proposedLines)
			const removed = activeLines
				.filter((line) => line.trim() !== '' && !proposedSet.has(line))
				.map((text) => ({ kind: 'removed', text }))
			const added = proposedLines
				.filter((line) => line.trim() !== '' && !activeSet.has(line))
				.map((text) => ({ kind: 'added', text }))
			return [...removed, ...added]
		},
	},

	/**
	 * Vue lifecycle hook — wires the store and kicks off the initial load.
	 *
	 * @spec exclude framework lifecycle hook, only wires the store and delegates to load()
	 */
	created() {
		this.skillStore = useSkillStore()
		this.skillStore.registerObjectType('agentskill', 'agentskill', 'hermiq')
		this.load()
	},

	methods: {
		/**
		 * Load the skill + its drafts.
		 *
		 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-the-skilldetail-review-surface-presents-diff-provenance-and-verdicts-with-three-actions
		 * @return {Promise<void>}
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				const [skill, drafts] = await Promise.all([
					this.skillStore.fetchObject('agentskill', this.skillId),
					listSkillDrafts(this.skillId),
				])
				this.skill = skill || null
				this.drafts = drafts
			} catch (e) {
				this.error = e?.response?.data?.error || e?.message || this.t('hermiq', 'Could not load the drafts.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Manual propose (trigger (c)) — the gates apply server-side.
		 *
		 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-consolidation-proposes-a-draft-version-and-never-edits-the-active-skill
		 * @return {Promise<void>}
		 */
		async doPropose() {
			this.busy = true
			this.error = ''
			try {
				await proposeSkillImprovement(this.skillId)
				await this.load()
			} catch (e) {
				this.error = e?.response?.data?.error || e?.message || this.t('hermiq', 'Propose failed.')
			} finally {
				this.busy = false
			}
		},

		/**
		 * Accept — the linked Approval transitions to approved and the apply step
		 * fires on that transition (a new skill version).
		 *
		 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-draft-acceptance-runs-through-the-approval-state-machine-behind-action-authorization
		 * @return {Promise<void>}
		 */
		async doAccept() {
			this.busy = true
			this.error = ''
			try {
				await acceptSkillDraft(this.pendingDraft.uuid)
				await this.load()
				// The accept applied a NEW skill version: the page's other surfaces
				// (body display, version history) must refetch too — same pattern
				// as SkillRowActions' qualify.
				emit('cn:page:refresh', {})
			} catch (e) {
				this.error = e?.response?.data?.error || e?.message || this.t('hermiq', 'Accept failed.')
			} finally {
				this.busy = false
			}
		},

		/**
		 * Open the edit-then-accept editor (SkillDetail-only surface).
		 *
		 * @spec exclude pure UI state toggle showing the edit modal; the spec behaviour lives in doEditContent
		 */
		openEditor() {
			this.showEditor = true
		},

		/**
		 * Save the edited draft content — invalidates scan/eval evidence and re-runs
		 * pre-qualification server-side.
		 *
		 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-draft-acceptance-runs-through-the-approval-state-machine-behind-action-authorization
		 * @param {object} content The edited `{ body }` payload.
		 * @return {Promise<void>}
		 */
		async doEditContent(content) {
			this.busy = true
			this.error = ''
			try {
				await updateSkillDraftContent(this.pendingDraft.uuid, content)
				this.showEditor = false
				await this.load()
			} catch (e) {
				this.error = e?.response?.data?.error || e?.message || this.t('hermiq', 'Edit failed.')
			} finally {
				this.busy = false
			}
		},

		/**
		 * Reject with an optional note + bad-learnings marking.
		 *
		 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-draft-acceptance-runs-through-the-approval-state-machine-behind-action-authorization
		 * @param {object} decision The `{ note, rejectedLearningRefs }` payload.
		 * @return {Promise<void>}
		 */
		async doReject(decision) {
			this.busy = true
			this.error = ''
			try {
				await rejectSkillDraft(this.pendingDraft.uuid, decision.note, decision.rejectedLearningRefs)
				this.showReject = false
				await this.load()
				// The decision changed the draft's lifecycle — refresh the page's
				// other surfaces (same pattern as doAccept).
				emit('cn:page:refresh', {})
			} catch (e) {
				this.error = e?.response?.data?.error || e?.message || this.t('hermiq', 'Reject failed.')
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.skill-draft-review {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 4px;
	overflow-y: auto;
	height: 100%;
}

.skill-draft-review__loading {
	margin: 24px auto;
}

.skill-draft-review__meta {
	display: flex;
	flex-wrap: wrap;
	gap: 12px;
	align-items: center;
}

.skill-draft-review__chip {
	display: inline-block;
	padding: 2px 8px;
	border: 1px solid var(--color-primary-element);
	border-radius: var(--border-radius-pill);
	font-size: 12px;
}

.skill-draft-review__fact {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.skill-draft-review__fact--warn {
	color: var(--color-warning-text, var(--color-main-text));
	font-weight: 600;
}

.skill-draft-review__heading {
	margin: 8px 0 0;
	font-size: 14px;
	font-weight: 600;
}

.skill-draft-review__columns {
	display: flex;
	gap: 12px;
}

.skill-draft-review__column {
	flex: 1 1 0;
	min-width: 0;
}

.skill-draft-review__column h5 {
	margin: 0 0 4px;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.skill-draft-review__pre {
	max-height: 240px;
	overflow: auto;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 8px;
	font-size: 12px;
	white-space: pre-wrap;
}

.skill-draft-review__diff {
	margin: 0;
	padding: 0;
	list-style: none;
	font-size: 12px;
}

.skill-draft-review__diff-line {
	display: flex;
	gap: 8px;
	padding: 1px 4px;
}

.skill-draft-review__diff-line--added {
	background-color: var(--color-success-hover, transparent);
}

.skill-draft-review__diff-line--removed {
	background-color: var(--color-error-hover, transparent);
}

.skill-draft-review__diff-marker {
	font-weight: 700;
	width: 12px;
	flex: none;
}

.skill-draft-review__diff-text {
	white-space: pre-wrap;
	word-break: break-word;
}

.skill-draft-review__refs {
	margin: 0;
	padding-inline-start: 20px;
	font-size: 13px;
}

.skill-draft-review__actions {
	display: flex;
	gap: 8px;
	margin-top: 8px;
}

.skill-draft-review__empty {
	color: var(--color-text-maxcontrast);
	margin: 4px 0;
}
</style>
