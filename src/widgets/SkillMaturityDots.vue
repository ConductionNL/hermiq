<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  SkillMaturityDots — the SkillsCatalog list's `maturityLevel` column badge
  (skill-maturity): seven dots, `maturityLevel` of them filled, plus a visible
  textual "L<n>" level so the representation is never color-only (WCAG 2.2 AA
  1.4.1/1.3.1 — filled/unfilled SHAPE + text, all via CSS variables).

  Mounted as a consumer cell widget: the manifest column declares
  `"widget": "maturity-dots"`, resolved through CnAppRoot's `cellWidgets`
  registry (App.vue); CnCellRenderer hands it `{ value, row, property,
  formatted }` — only `value` (the stored maturityLevel) is used.

  @spec openspec/specs/skill-maturity/spec.md#requirement-the-catalog-ui-surfaces-maturity-dots-a-detail-scorecard-and-a-qualify-action
-->
<template>
	<span
		class="skill-maturity-dots"
		role="img"
		:aria-label="ariaLabel"
		:title="ariaLabel">
		<span
			v-for="dot in maxLevel"
			:key="dot"
			class="skill-maturity-dots__dot"
			:class="{ 'skill-maturity-dots__dot--filled': dot <= level }"
			aria-hidden="true" />
		<span class="skill-maturity-dots__label">{{ levelLabel }}</span>
	</span>
</template>

<script>
export default {
	name: 'SkillMaturityDots',

	props: {
		/** The cell value — the skill's stored maturityLevel (0–7). */
		value: {
			type: [Number, String],
			default: 0,
		},
		/** The full row object (unused; part of the cell-widget contract). */
		row: {
			type: Object,
			default: () => ({}),
		},
		/** The schema property definition (unused; cell-widget contract). */
		property: {
			type: Object,
			default: () => ({}),
		},
		/** The formatter-shaped value (unused; cell-widget contract). */
		formatted: {
			type: [Number, String],
			default: null,
		},
	},

	computed: {
		/**
		 * The maximum maturity level (dot count).
		 *
		 * @spec openspec/specs/skill-maturity/spec.md#requirement-the-catalog-ui-surfaces-maturity-dots-a-detail-scorecard-and-a-qualify-action
		 * @return {number} Always 7.
		 */
		maxLevel() {
			return 7
		},

		/**
		 * The clamped numeric maturity level (0–7; absent/invalid reads as 0).
		 *
		 * @spec openspec/specs/skill-maturity/spec.md#requirement-the-catalog-ui-surfaces-maturity-dots-a-detail-scorecard-and-a-qualify-action
		 * @return {number} The level.
		 */
		level() {
			const parsed = Number(this.value)
			if (!Number.isFinite(parsed)) {
				return 0
			}
			return Math.min(Math.max(Math.trunc(parsed), 0), this.maxLevel)
		},

		/**
		 * The visible textual level ("L0"–"L7") — the non-color-only channel.
		 *
		 * @spec openspec/specs/skill-maturity/spec.md#requirement-the-catalog-ui-surfaces-maturity-dots-a-detail-scorecard-and-a-qualify-action
		 * @return {string} The level label.
		 */
		levelLabel() {
			return `L${this.level}`
		},

		/**
		 * The accessible name announcing the level out of 7.
		 *
		 * @spec openspec/specs/skill-maturity/spec.md#requirement-the-catalog-ui-surfaces-maturity-dots-a-detail-scorecard-and-a-qualify-action
		 * @return {string} The aria label.
		 */
		ariaLabel() {
			return this.t('hermiq', 'Maturity level {level} of 7', { level: this.level })
		},
	},
}
</script>

<style scoped>
.skill-maturity-dots {
	display: inline-flex;
	align-items: center;
	gap: 3px;
}

.skill-maturity-dots__dot {
	width: 8px;
	height: 8px;
	border-radius: 50%;
	border: 1px solid var(--color-text-maxcontrast);
	background: transparent;
	flex-shrink: 0;
}

.skill-maturity-dots__dot--filled {
	background: var(--color-primary-element);
	border-color: var(--color-primary-element);
}

.skill-maturity-dots__label {
	margin-inline-start: 6px;
	font-size: 0.85em;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}
</style>
