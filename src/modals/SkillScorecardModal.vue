<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  SkillScorecardModal — the per-level maturity scorecard shown right after a
  Qualify row action on the Skills catalog (skill-maturity). Own file per the
  modal-isolation rule; imported by SkillRowActions.vue.

  Renders the qualify endpoint's `{ maturityLevel, targetLevel, scorecard }`
  payload: dots + textual level, then seven pass/fail rows (icon shape + text,
  never color-only) with the failure reasons — the first failing level's
  reasons are what a curator acts on. Reason strings are canonical English
  keys from the server, translated at display time via t('hermiq', reason)
  (ADR-007).

  @spec openspec/specs/skill-maturity/spec.md#requirement-the-catalog-ui-surfaces-maturity-dots-a-detail-scorecard-and-a-qualify-action
-->
<template>
	<!-- size="large" like the other content-heavy modals (SkillFormModal,
	     SkillDraftEditModal): the seven-row scorecard table's Reasons column
	     clips at the default modal width. -->
	<NcModal size="large" @close="$emit('close')">
		<div class="skill-scorecard-modal">
			<h3 class="skill-scorecard-modal__title">
				{{ t('hermiq', 'Maturity scorecard') }}
			</h3>
			<p v-if="skillName" class="skill-scorecard-modal__subtitle">
				{{ skillName }}
			</p>

			<div class="skill-scorecard-modal__summary">
				<SkillMaturityDots :value="result.maturityLevel" />
				<span
					v-if="result.targetLevel"
					class="skill-scorecard-modal__target">
					{{
						t('hermiq', 'Target: {level}', {
							level: 'L' + result.targetLevel,
						})
					}}
				</span>
			</div>

			<table class="skill-scorecard-modal__table">
				<thead>
					<tr>
						<th scope="col">{{ t('hermiq', 'Level') }}</th>
						<th scope="col">{{ t('hermiq', 'Status') }}</th>
						<th scope="col">{{ t('hermiq', 'Reasons') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="entry in entries" :key="entry.level">
						<td>
							<strong>{{ 'L' + entry.level }}</strong>
							{{ entry.name }}
						</td>
						<td>
							<span class="skill-scorecard-modal__status">
								<CheckBold
									v-if="entry.passed"
									:size="16"
									aria-hidden="true" />
								<CloseThick v-else :size="16" aria-hidden="true" />
								<span>{{
									entry.passed
										? t('hermiq', 'Passed')
										: t('hermiq', 'Not passed')
								}}</span>
							</span>
						</td>
						<td>
							<ul
								v-if="entry.reasons.length > 0"
								class="skill-scorecard-modal__reasons">
								<li v-for="reason in entry.reasons" :key="reason">
									{{ t('hermiq', reason) }}
								</li>
							</ul>
						</td>
					</tr>
				</tbody>
			</table>

			<div class="skill-scorecard-modal__footer">
				<NcButton type="primary" @click="$emit('close')">
					{{ t('hermiq', 'Close') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { NcButton, NcModal } from '@nextcloud/vue'
import CheckBold from 'vue-material-design-icons/CheckBold.vue'
import CloseThick from 'vue-material-design-icons/CloseThick.vue'
import SkillMaturityDots from '../widgets/SkillMaturityDots.vue'

export default {
	name: 'SkillScorecardModal',

	components: {
		CheckBold,
		CloseThick,
		NcButton,
		NcModal,
		SkillMaturityDots,
	},

	props: {
		/** The qualify endpoint's `{ maturityLevel, targetLevel, scorecard }` payload. */
		result: {
			type: Object,
			required: true,
		},

		/** The qualified skill's display name (subtitle). */
		skillName: {
			type: String,
			default: '',
		},
	},

	emits: ['close'],

	computed: {
		/**
		 * The scorecard rows with their translated level names.
		 *
		 * @spec openspec/specs/skill-maturity/spec.md#requirement-the-catalog-ui-surfaces-maturity-dots-a-detail-scorecard-and-a-qualify-action
		 * @return {Array<object>} The display entries.
		 */
		entries() {
			const names = [
				this.t('hermiq', 'Anatomy'),
				this.t('hermiq', 'Triggering'),
				this.t('hermiq', 'Patterns'),
				this.t('hermiq', 'Personalization'),
				this.t('hermiq', 'Measurement'),
				this.t('hermiq', 'Self-Improvement'),
				this.t('hermiq', 'AI Workforce'),
			]
			return (this.result.scorecard || []).map((entry) => ({
				level: entry.level,
				name: names[entry.level - 1] || '',
				passed: !!entry.passed,
				reasons: entry.reasons || [],
			}))
		},
	},
}
</script>

<style scoped>
.skill-scorecard-modal {
	padding: 20px;
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.skill-scorecard-modal__title {
	margin: 0;
	font-size: 20px;
	font-weight: 600;
}

.skill-scorecard-modal__subtitle {
	margin: -8px 0 0;
	color: var(--color-text-maxcontrast);
}

.skill-scorecard-modal__summary {
	display: flex;
	align-items: center;
	gap: 16px;
}

.skill-scorecard-modal__target {
	color: var(--color-text-maxcontrast);
}

.skill-scorecard-modal__table {
	width: 100%;
	border-collapse: collapse;
}

.skill-scorecard-modal__table th,
.skill-scorecard-modal__table td {
	text-align: start;
	padding: 6px 8px;
	border-bottom: 1px solid var(--color-border);
	vertical-align: top;
}

.skill-scorecard-modal__status {
	display: inline-flex;
	align-items: center;
	gap: 6px;
}

.skill-scorecard-modal__reasons {
	margin: 0;
	padding-inline-start: 18px;
	color: var(--color-text-maxcontrast);
}

/* Long canonical reason keys must wrap inside the column, never clip. */
.skill-scorecard-modal__reasons li {
	overflow-wrap: anywhere;
}

.skill-scorecard-modal__footer {
	display: flex;
	justify-content: flex-end;
}
</style>
