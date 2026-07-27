<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  SkillLearnings — the SkillDetail page's read-only Learnings card
  (skill-learnings): renders the skill's files['learnings.md'] as sanitised
  markdown (marked + DOMPurify with the shared safe config — the Chat.vue
  pattern) plus an activity strip from levelEvidence.l6 (candidate count,
  promoted learnings count, last capture, last promotion).

  READ-ONLY BY CONTRACT: no editing, adding, or deleting affordance exists in
  this change — a manual editor would create a second write channel bypassing
  the capture pipeline's redaction (spec: "no new write channel"). A skill
  without learnings files shows an honest empty state, never an error.
  Candidates themselves are NOT rendered (unpromoted, noisy,
  operator-irrelevant — design.md Decision 9); only their count is shown.
  Self-fetches the skill id from $route.params.id (the SkillEvalEvidence
  pattern).

  @spec openspec/specs/skill-learnings/spec.md#requirement-skilldetail-shows-a-read-only-learnings-surface
-->
<template>
	<div class="skill-learnings">
		<NcNoteCard v-if="error" type="error" :heading="t('hermiq', 'Learnings error')">
			{{ error }}
		</NcNoteCard>

		<NcLoadingIcon v-if="loading" :size="24" class="skill-learnings__loading" />

		<template v-else>
			<template v-if="hasLearningsActivity">
				<dl class="skill-learnings__facts">
					<div class="skill-learnings__fact">
						<dt>{{ t('hermiq', 'Promoted learnings') }}</dt>
						<dd>{{ countLabel(l6.learningsCount) }}</dd>
					</div>
					<div class="skill-learnings__fact">
						<dt>{{ t('hermiq', 'Open candidates') }}</dt>
						<dd>{{ countLabel(l6.candidateCount) }}</dd>
					</div>
					<div class="skill-learnings__fact">
						<dt>{{ t('hermiq', 'Last capture') }}</dt>
						<dd>{{ formatDate(l6.lastCaptureAt) }}</dd>
					</div>
					<div class="skill-learnings__fact">
						<dt>{{ t('hermiq', 'Last promotion') }}</dt>
						<dd>{{ formatDate(l6.lastPromotedAt) }}</dd>
					</div>
				</dl>

				<!-- Learnings markdown is sanitised via DOMPurify with the shared safe config. -->
				<!-- eslint-disable-next-line vue/no-v-html -->
				<div v-if="learningsMarkdown" class="skill-learnings__content" v-html="renderMarkdown(learningsMarkdown)" />
				<p v-else class="skill-learnings__empty">
					{{ t('hermiq', 'Candidates are being collected, but nothing has been promoted into learnings yet.') }}
				</p>

				<p class="skill-learnings__hint">
					{{ t('hermiq', 'Learnings are captured automatically after runs that used this skill and promoted by a daily background pass. This view is read-only.') }}
				</p>
			</template>

			<p v-else class="skill-learnings__empty">
				{{ t('hermiq', 'No learnings yet. Once agents run with this skill, observations are captured automatically and confirmed ones are promoted here.') }}
			</p>
		</template>
	</div>
</template>

<script>
import { NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import { SAFE_MARKDOWN_DOMPURIFY_CONFIG } from '@conduction/nextcloud-vue'
import DOMPurify from 'dompurify'
import { marked } from 'marked'
import { useSkillStore } from '../store/store.js'

export default {
	name: 'SkillLearnings',

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
		 * @return {string} The skill uuid.
		 */
		skillId() {
			return this.$route.params.id
		},

		/**
		 * The stored l6 activity map ({} when absent).
		 *
		 * @return {object} The levelEvidence.l6 map.
		 */
		l6() {
			const evidence = this.skill?.levelEvidence
			return (evidence && typeof evidence.l6 === 'object' && evidence.l6) || {}
		},

		/**
		 * The files array ([] when absent).
		 *
		 * @return {Array<object>} The skill's files entries.
		 */
		files() {
			return Array.isArray(this.skill?.files) ? this.skill.files : []
		},

		/**
		 * The learnings.md content ('' when absent).
		 *
		 * @return {string} The markdown source.
		 */
		learningsMarkdown() {
			const entry = this.files.find((file) => file?.name === 'learnings.md')
			return typeof entry?.content === 'string' ? entry.content : ''
		},

		/**
		 * Whether ANY learnings activity exists — either learnings file, or a
		 * recorded l6 activity stamp. Without it the honest empty state renders.
		 *
		 * @return {boolean} True when the learnings surface has content to show.
		 */
		hasLearningsActivity() {
			const hasCandidates = this.files.some((file) => file?.name === 'learning-candidates.md')
			return this.learningsMarkdown !== '' || hasCandidates || !!this.l6.lastCaptureAt || !!this.l6.lastPromotedAt
		},
	},

	created() {
		this.skillStore = useSkillStore()
		this.skillStore.registerObjectType('agentskill', 'agentskill', 'hermiq')
		this.load()
	},

	methods: {
		/**
		 * Load the skill (files + l6 activity). A missing skill is an error state;
		 * a skill without learnings files is the empty state, never an error.
		 *
		 * @return {Promise<void>}
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				const skill = await this.skillStore.fetchObject('agentskill', this.skillId)
				this.skill = skill || null
			} catch (e) {
				this.error = e?.response?.data?.error || e?.message || this.t('hermiq', 'Could not load the skill.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Render the learnings markdown safely (marked + DOMPurify with the
		 * shared safe config — the Chat.vue pattern).
		 *
		 * @param {string} content The markdown source.
		 * @return {string} Sanitised HTML.
		 */
		renderMarkdown(content) {
			return DOMPurify.sanitize(marked.parse(content || ''), SAFE_MARKDOWN_DOMPURIFY_CONFIG)
		},

		/**
		 * A count label ('—' when the subsystem has not stamped the field yet).
		 *
		 * @param {number} value The stored count.
		 * @return {string} The label.
		 */
		countLabel(value) {
			return typeof value === 'number' ? String(value) : '—'
		},

		/**
		 * Format an ISO timestamp for display.
		 *
		 * @param {string} value The ISO timestamp.
		 * @return {string} The formatted date, or an em dash.
		 */
		formatDate(value) {
			if (!value) {
				return '—'
			}
			const date = new Date(value)
			return Number.isNaN(date.getTime()) ? value : date.toLocaleString()
		},
	},
}
</script>

<style scoped>
.skill-learnings {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 4px;
	overflow-y: auto;
	height: 100%;
}

.skill-learnings__loading {
	margin: 24px auto;
}

.skill-learnings__facts {
	display: flex;
	flex-wrap: wrap;
	gap: 24px;
	margin: 0;
}

.skill-learnings__fact dt {
	color: var(--color-text-maxcontrast);
	font-size: 12px;
}

.skill-learnings__fact dd {
	margin: 0;
	font-size: 18px;
	font-weight: 600;
}

.skill-learnings__content {
	border-top: 1px solid var(--color-border);
	padding-top: 8px;
	font-size: 14px;
	line-height: 1.5;
}

.skill-learnings__content :deep(h1),
.skill-learnings__content :deep(h2) {
	font-size: 15px;
	font-weight: 600;
	margin: 12px 0 4px;
}

.skill-learnings__content :deep(ul) {
	margin: 0;
	padding-inline-start: 20px;
}

.skill-learnings__empty {
	color: var(--color-text-maxcontrast);
	margin: 4px 0;
}

.skill-learnings__hint {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	margin: 4px 0 0;
}
</style>
