<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  SkillMaturityScorecard — the durable per-skill maturity scorecard on the new
  SkillDetail manifest page (`/skills/:id`, skill-maturity). Self-fetches the
  skill from `$route.params.id` (the EvalRunPanelWidget pattern) and renders:

  - the current maturity level as dots + text, and the curator `targetLevel`;
  - seven per-level rows (Anatomy … AI Workforce) with a non-color-only
    pass/fail (icon shape + text), the failure reasons of the last
    qualification, and the stored evidence timestamps (L4 attestation,
    L5 eval evidence, L6 learnings, L7 orchestration);
  - a Qualify action (owner-guarded server-side) refreshing the scorecard with
    per-level reasons, and an action-gated Attest-L4 form (403 surfaces as an
    error note; the skill is unchanged).

  Server reason strings are canonical English keys translated at display time
  via t('hermiq', reason) (ADR-007 — EN + NL entries live in l10n/).

  @spec openspec/specs/skill-maturity/spec.md#requirement-the-catalog-ui-surfaces-maturity-dots-a-detail-scorecard-and-a-qualify-action
-->
<template>
	<div class="skill-maturity-scorecard">
		<NcNoteCard
			v-if="error"
			type="error"
			:heading="t('hermiq', 'Maturity error')">
			{{ error }}
		</NcNoteCard>

		<NcLoadingIcon
			v-if="loading"
			:size="24"
			class="skill-maturity-scorecard__loading" />

		<template v-else-if="skill">
			<div class="skill-maturity-scorecard__header">
				<SkillMaturityDots :value="maturityLevel" />
				<span class="skill-maturity-scorecard__target">
					{{ targetLabel }}
				</span>
				<div class="skill-maturity-scorecard__actions">
					<NcButton
						type="secondary"
						:disabled="qualifying"
						:aria-label="t('hermiq', 'Qualify skill')"
						@click="doQualify">
						<template v-if="qualifying" #icon>
							<NcLoadingIcon :size="20" />
						</template>
						{{ t('hermiq', 'Qualify') }}
					</NcButton>
				</div>
			</div>

			<table class="skill-maturity-scorecard__table">
				<thead>
					<tr>
						<th scope="col">{{ t('hermiq', 'Level') }}</th>
						<th scope="col">{{ t('hermiq', 'Status') }}</th>
						<th scope="col">{{ t('hermiq', 'Details') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="entry in rows" :key="entry.level">
						<td class="skill-maturity-scorecard__level">
							<strong>{{ 'L' + entry.level }}</strong>
							{{ entry.name }}
						</td>
						<td>
							<span class="skill-maturity-scorecard__status">
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
						<td class="skill-maturity-scorecard__details">
							<ul
								v-if="entry.reasons.length > 0"
								class="skill-maturity-scorecard__reasons">
								<li v-for="reason in entry.reasons" :key="reason">
									{{ t('hermiq', reason) }}
								</li>
							</ul>
							<p
								v-if="entry.evidence"
								class="skill-maturity-scorecard__evidence">
								{{ entry.evidence }}
							</p>
						</td>
					</tr>
				</tbody>
			</table>

			<div class="skill-maturity-scorecard__attest">
				<h3 class="skill-maturity-scorecard__attest-title">
					{{ t('hermiq', 'Attest L4 (personalization)') }}
				</h3>
				<p class="skill-maturity-scorecard__hint">
					{{
						t(
							'hermiq',
							'A curator act: confirms this skill has been tuned for your organisation. Requires the skill.attest-maturity action.',
						)
					}}
				</p>
				<NcNoteCard v-if="attestError" type="error">
					{{ attestError }}
				</NcNoteCard>
				<div class="skill-maturity-scorecard__attest-form">
					<NcTextField
						v-model="attestNote"
						:label="t('hermiq', 'Attestation note')"
						:placeholder="
							t(
								'hermiq',
								'e.g. Tuned for our municipality\'s WOO workflow',
							)
						" />
					<NcButton
						type="primary"
						:disabled="attesting"
						:aria-label="t('hermiq', 'Attest maturity level 4')"
						@click="doAttest">
						<template v-if="attesting" #icon>
							<NcLoadingIcon :size="20" />
						</template>
						{{ t('hermiq', 'Attest L4') }}
					</NcButton>
				</div>
			</div>
		</template>

		<p v-else class="skill-maturity-scorecard__hint">
			{{ t('hermiq', 'Skill not found.') }}
		</p>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon, NcNoteCard, NcTextField } from '@nextcloud/vue'
import CheckBold from 'vue-material-design-icons/CheckBold.vue'
import CloseThick from 'vue-material-design-icons/CloseThick.vue'
import SkillMaturityDots from './SkillMaturityDots.vue'
import { attestSkillL4, qualifySkill } from '../api/skills.js'
import { useSkillStore } from '../store/store.js'
import { busyAction, BusyFields } from '../utils/busyAction.js'

/**
 * The line for a level the system checks by itself.
 *
 * @param {object} vm The component, for t() and formatDate().
 * @param {object} [entry] The level's evidence.
 * @return {string} The line, or '' when it was never checked.
 */
function checkedLine(vm, entry) {
	if (!entry?.checkedAt) {
		return ''
	}
	return vm.t('hermiq', 'Checked {date}', {
		date: vm.formatDate(entry.checkedAt),
	})
}

/**
 * The line for an attested level, with the note when there is one.
 *
 * @param {object} vm The component, for t() and formatDate().
 * @param {object} entry The level's evidence.
 * @return {string} The line.
 */
function attestedLine(vm, entry) {
	const base = vm.t('hermiq', 'Attested by {user} on {date}', {
		user: entry.attestedBy,
		date: vm.formatDate(entry.attestedAt),
	})
	return entry.note ? `${base} — ${entry.note}` : base
}

/**
 * The evidence lines of levels 4 to 7, by level. A level without its evidence reads as ''.
 *
 * @type {Object<number, Function>}
 */
const EVIDENCE_LINES = {
	4: (vm, { l4 }) => (l4?.attestedBy ? attestedLine(vm, l4) : ''),
	5: (vm, { l5 }) =>
		l5?.lastValidated
			? vm.t(
					'hermiq',
					'Pass rate {rate}, baseline delta {delta}, validated {date}',
					{
						rate: String(l5.passRate ?? '—'),
						delta: String(l5.baselineDelta ?? '—'),
						date: vm.formatDate(l5.lastValidated),
					},
				)
			: '',
	6: (vm, { l6 }) =>
		l6?.lastConsolidatedAt
			? vm.t('hermiq', '{count} learnings, consolidated {date}', {
					count: String(l6.learningsCount ?? 0),
					date: vm.formatDate(l6.lastConsolidatedAt),
				})
			: '',
	7: (vm, { l7 }) =>
		l7?.lastExecutedAt
			? vm.t('hermiq', 'Chain executed {date}', {
					date: vm.formatDate(l7.lastExecutedAt),
				})
			: '',
}

const QUALIFY_FIELDS = new BusyFields('qualifying', 'error')

const ATTEST_FIELDS = new BusyFields('attesting', 'attestError')

export default {
	name: 'SkillMaturityScorecard',

	components: {
		CheckBold,
		CloseThick,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		NcTextField,
		SkillMaturityDots,
	},

	data() {
		return {
			skill: null,
			scorecard: null,
			loading: true,
			qualifying: false,
			attesting: false,
			attestNote: '',
			error: '',
			attestError: '',
		}
	},

	computed: {
		/**
		 * This skill's uuid from the route param.
		 *
		 * @spec openspec/specs/skill-maturity/spec.md#requirement-the-catalog-ui-surfaces-maturity-dots-a-detail-scorecard-and-a-qualify-action
		 * @return {string} The skill uuid.
		 */
		skillId() {
			return this.$route.params.id
		},

		/**
		 * The skill's current maturity level (stored, or the freshest qualify result).
		 *
		 * @spec openspec/specs/skill-maturity/spec.md#requirement-the-catalog-ui-surfaces-maturity-dots-a-detail-scorecard-and-a-qualify-action
		 * @return {number} The level (0–7).
		 */
		maturityLevel() {
			return Number(this.skill?.maturityLevel) || 0
		},

		/**
		 * The target-level line ("Target: L4" or a not-set hint).
		 *
		 * @spec openspec/specs/skill-maturity/spec.md#requirement-the-catalog-ui-surfaces-maturity-dots-a-detail-scorecard-and-a-qualify-action
		 * @return {string} The label.
		 */
		targetLabel() {
			const target = Number(this.skill?.targetLevel)
			if (Number.isFinite(target) && target >= 1) {
				return this.t('hermiq', 'Target: {level}', { level: `L${target}` })
			}
			return this.t('hermiq', 'No target level set')
		},

		/**
		 * The seven per-level display rows: name, pass/fail, reasons (from the last
		 * qualify call, when available) and stored evidence summaries.
		 *
		 * @spec openspec/specs/skill-maturity/spec.md#requirement-the-catalog-ui-surfaces-maturity-dots-a-detail-scorecard-and-a-qualify-action
		 * @return {Array<object>} The rows.
		 */
		rows() {
			const names = [
				this.t('hermiq', 'Anatomy'),
				this.t('hermiq', 'Triggering'),
				this.t('hermiq', 'Patterns'),
				this.t('hermiq', 'Personalization'),
				this.t('hermiq', 'Measurement'),
				this.t('hermiq', 'Self-Improvement'),
				this.t('hermiq', 'AI Workforce'),
			]
			const evidence = this.skill?.levelEvidence || {}
			return names.map((name, index) => {
				const level = index + 1
				const fresh = (this.scorecard || []).find(
					(entry) => entry.level === level,
				)
				return {
					level,
					name,
					passed: fresh
						? fresh.passed
						: this.storedPassed(level, evidence),
					reasons: fresh ? fresh.reasons : [],
					evidence: this.evidenceSummary(level, evidence),
				}
			})
		},
	},

	watch: {
		/**
		 * Refetch when the route's skill id changes.
		 *
		 * @spec exclude framework watcher — re-triggers the spec-tagged
		 *   fetchSkill() when navigating between skills; no domain logic here.
		 */
		skillId() {
			this.fetchSkill()
		},
	},

	mounted() {
		this.fetchSkill()
	},

	methods: {
		/**
		 * Whether a level reads as passed from the STORED object alone (before any
		 * qualify call in this session): l1–l3 from their refreshed evidence,
		 * falling back to the contiguous stored level; l4 from the attestation.
		 *
		 * @spec openspec/specs/skill-maturity/spec.md#requirement-the-catalog-ui-surfaces-maturity-dots-a-detail-scorecard-and-a-qualify-action
		 * @param {number} level The level (1–7).
		 * @param {object} evidence The stored levelEvidence map.
		 * @return {boolean} Whether the level reads as passed.
		 */
		storedPassed(level, evidence) {
			if (level <= 3) {
				const entry = evidence[`l${level}`]
				if (entry && typeof entry.passed === 'boolean') {
					return entry.passed
				}
			}
			if (level === 4) {
				return !!(evidence.l4?.attestedBy && evidence.l4?.attestedAt)
			}
			return this.maturityLevel >= level
		},

		/**
		 * A one-line evidence summary for a level (attestation, eval evidence,
		 * learnings, orchestration timestamps) — empty when there is none.
		 *
		 * @spec openspec/specs/skill-maturity/spec.md#requirement-the-catalog-ui-surfaces-maturity-dots-a-detail-scorecard-and-a-qualify-action
		 * @param {number} level The level (1–7).
		 * @param {object} evidence The stored levelEvidence map.
		 * @return {string} The summary line.
		 */
		evidenceSummary(level, evidence) {
			if (level <= 3) {
				return checkedLine(this, evidence[`l${level}`])
			}
			return EVIDENCE_LINES[level]?.(this, evidence) ?? ''
		},

		/**
		 * A locale date-time string for an ISO timestamp ('' on garbage input).
		 *
		 * @spec exclude pure date formatting helper, no domain behaviour
		 * @param {string} value The ISO timestamp.
		 * @return {string} The formatted date.
		 */
		formatDate(value) {
			const date = new Date(value)
			if (Number.isNaN(date.getTime())) {
				return ''
			}
			return date.toLocaleString()
		},

		/**
		 * Load the skill object for the route's id.
		 *
		 * @spec openspec/specs/skill-maturity/spec.md#requirement-the-catalog-ui-surfaces-maturity-dots-a-detail-scorecard-and-a-qualify-action
		 * @return {Promise<void>}
		 */
		async fetchSkill() {
			this.loading = true
			this.error = ''
			try {
				const store = useSkillStore()
				this.skill = await store.fetchObject('agentskill', this.skillId)
			} catch (e) {
				this.skill = null
				this.error =
					e?.response?.data?.error
					|| e?.message
					|| this.t('hermiq', 'Could not load the skill.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Qualify the skill: recompute + persist server-side, then show the returned
		 * per-level scorecard (with failure reasons) and refresh the stored object.
		 *
		 * @spec openspec/specs/skill-maturity/spec.md#requirement-the-qualify-endpoint-is-owner-guarded-and-returns-a-scorecard
		 * @return {Promise<void>}
		 */
		async doQualify() {
			await busyAction(this, QUALIFY_FIELDS)(async () => {
				const result = await qualifySkill(this.skillId)
				this.scorecard = result.scorecard || []
				await this.fetchSkill()
			}, () => this.t('hermiq', 'Unknown error'))
		},

		/**
		 * Attest L4 (action-gated server-side). A 403 surfaces as an error note; the
		 * skill is unchanged in that case.
		 *
		 * @spec openspec/specs/skill-maturity/spec.md#requirement-l4-is-human-attested-only-behind-action-authorization
		 * @return {Promise<void>}
		 */
		async doAttest() {
			await busyAction(this, ATTEST_FIELDS)(async () => {
				const result = await attestSkillL4(
					this.skillId,
					this.attestNote.trim(),
				)
				this.scorecard = result.scorecard || []
				this.attestNote = ''
				await this.fetchSkill()
			}, () => this.t('hermiq', 'Unknown error'))
		},
	},
}
</script>

<style scoped>
.skill-maturity-scorecard {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding: 4px;
	overflow-y: auto;
	height: 100%;
}

.skill-maturity-scorecard__loading {
	margin: 24px auto;
}

.skill-maturity-scorecard__header {
	display: flex;
	align-items: center;
	gap: 16px;
	flex-wrap: wrap;
}

.skill-maturity-scorecard__target {
	color: var(--color-text-maxcontrast);
}

.skill-maturity-scorecard__actions {
	margin-inline-start: auto;
}

.skill-maturity-scorecard__table {
	width: 100%;
	border-collapse: collapse;
}

.skill-maturity-scorecard__table th,
.skill-maturity-scorecard__table td {
	text-align: start;
	padding: 6px 8px;
	border-bottom: 1px solid var(--color-border);
	vertical-align: top;
}

.skill-maturity-scorecard__status {
	display: inline-flex;
	align-items: center;
	gap: 6px;
}

.skill-maturity-scorecard__reasons {
	margin: 0;
	padding-inline-start: 18px;
	color: var(--color-text-maxcontrast);
}

.skill-maturity-scorecard__evidence {
	margin: 4px 0 0;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.skill-maturity-scorecard__attest {
	border-top: 1px solid var(--color-border);
	padding-top: 12px;
}

.skill-maturity-scorecard__attest-title {
	margin: 0 0 4px;
	font-size: 16px;
	font-weight: 600;
}

.skill-maturity-scorecard__attest-form {
	display: flex;
	align-items: flex-end;
	gap: 12px;
	flex-wrap: wrap;
}

.skill-maturity-scorecard__hint {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	margin: 0 0 8px;
}
</style>
