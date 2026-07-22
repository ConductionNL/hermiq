<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  BudgetMeter — one shared, presentational used/limit readout + progress bar,
  reused everywhere a Hermiq "budget" quantity is displayed (memory character
  budget, cost/spend budget tokens + EUR, …).

  Hermiq has FIVE genuinely different budget-shaped quantities (memory chars,
  context chars, cost tokens, cost EUR, RAG source count) that measure
  different things and must never be merged into one number — this component
  does not attempt that. It only unifies the PRESENTATION: a consistent
  label, a "used / limit unit" readout, and a bar with a shared unlimited/
  warn/over visual language, so every caller renders its own distinct
  quantity through one look instead of ad-hoc bespoke markup per widget.

  Purely presentational — no fetching, no store access. Callers pass `used`/
  `limit` already resolved from whichever backend shape they own (memory's
  own char-count calc, BudgetService::status()'s tokens/eur payload, …).
  When a caller only has a limit and no live "used" figure, omit `used`
  (leave it null) rather than fabricating one — the readout degrades to
  "Limit: N unit" and the bar is omitted.
-->
<template>
	<div class="budget-meter">
		<div class="budget-meter__head">
			<span class="budget-meter__label">{{ label }}</span>
			<span class="budget-meter__count">{{ readout }}</span>
		</div>
		<div
			v-if="hasLimit && hasUsed"
			class="budget-meter__bar"
			:class="{ 'budget-meter__bar--warn': isWarn, 'budget-meter__bar--over': isOver }">
			<div class="budget-meter__fill" :style="{ width: barPercent + '%' }" />
		</div>
		<p v-else-if="!hasLimit" class="budget-meter__unlimited">
			{{ t('hermiq', 'Unlimited') }}
		</p>
	</div>
</template>

<script>
export default {
	name: 'BudgetMeter',

	props: {
		/** The row label (e.g. "Memory", "Cost (tokens)", "Cost (€)"). */
		label: {
			type: String,
			required: true,
		},
		/** The current usage. Null/undefined when no live figure is available. */
		used: {
			type: Number,
			default: null,
		},
		/** The configured limit. 0, null or undefined all mean "unlimited". */
		limit: {
			type: Number,
			default: null,
		},
		/** The unit appended (suffix mode) or prepended (prefix mode), e.g. 'characters', 'tokens', '€'. */
		unit: {
			type: String,
			default: '',
		},
		/**
		 * Where the unit sits relative to the number: 'suffix' ("8000 tokens")
		 * or 'prefix' ("€8.00") — currency-shaped quantities use 'prefix'.
		 */
		format: {
			type: String,
			default: 'suffix',
			validator: (value) => ['suffix', 'prefix'].includes(value),
		},
		/**
		 * Decimal places to force on the displayed numbers via `toFixed`. Null
		 * (the default) displays the raw number as-is — the exact behaviour the
		 * ad-hoc bars/readouts this component replaces already had (plain Vue
		 * interpolation, no rounding).
		 */
		precision: {
			type: Number,
			default: null,
		},
		/**
		 * A backend-computed percent (e.g. BudgetService::status()'s rounded
		 * `tokens.percent`) to drive the bar fill and, when `showPercentText`
		 * is set, the trailing "(N%)" text — takes priority over the locally
		 * computed percentage so the displayed number always matches the
		 * server's own rounding.
		 */
		percentOverride: {
			type: Number,
			default: null,
		},
		/** Append the used/limit percent in parentheses after the readout. */
		showPercentText: {
			type: Boolean,
			default: false,
		},
		/**
		 * Explicit over-budget override (e.g. Memory's own
		 * `needsConsolidation` server flag) — takes priority over the locally
		 * computed `used >= limit` comparison so callers with their own
		 * over-budget semantics stay exact.
		 */
		over: {
			type: Boolean,
			default: null,
		},
		/** Percent (0-100) at or above which the bar switches to the warn colour, absent an explicit over-budget signal. */
		warnThreshold: {
			type: Number,
			default: 90,
		},
	},

	computed: {
		/**
		 * @spec exclude Trivial presence check; no behavioural spec.
		 * @return {boolean} Whether a live `used` figure was supplied.
		 */
		hasUsed() {
			return this.used !== null && this.used !== undefined && !Number.isNaN(Number(this.used))
		},

		/**
		 * @spec exclude Trivial presence check; no behavioural spec.
		 * @return {boolean} Whether a positive `limit` was supplied (0/null/undefined = unlimited).
		 */
		hasLimit() {
			return this.limit !== null && this.limit !== undefined && Number(this.limit) > 0
		},

		/**
		 * @spec exclude Trivial computed display helper; no behavioural spec.
		 * @return {number} The used/limit percent (0-100), preferring the caller-supplied override.
		 */
		computedPercent() {
			if (this.percentOverride !== null && this.percentOverride !== undefined) {
				return this.percentOverride
			}
			if (!this.hasLimit || !this.hasUsed) {
				return 0
			}
			return Math.round((Number(this.used) / Number(this.limit)) * 100)
		},

		/**
		 * @spec exclude Trivial computed display helper; no behavioural spec.
		 * @return {number} The bar fill percent, clamped to [0, 100].
		 */
		barPercent() {
			return Math.max(0, Math.min(100, this.computedPercent))
		},

		/**
		 * @spec exclude Trivial computed display helper; no behavioural spec.
		 * @return {boolean} Whether usage has reached or exceeded the limit.
		 */
		isOver() {
			if (this.over !== null && this.over !== undefined) {
				return this.over
			}
			return this.hasLimit && this.hasUsed && Number(this.used) >= Number(this.limit)
		},

		/**
		 * @spec exclude Trivial computed display helper; no behavioural spec.
		 * @return {boolean} Whether usage has crossed the warn threshold but not the hard limit.
		 */
		isWarn() {
			if (this.isOver) {
				return false
			}
			return this.hasLimit && this.hasUsed && this.computedPercent >= this.warnThreshold
		},

		/**
		 * @spec exclude Trivial computed display helper; no behavioural spec.
		 * @return {string} The used/limit readout text.
		 */
		readout() {
			const usedStr = this.hasUsed ? this.formatNumber(this.used) : null
			const limitStr = this.hasLimit ? this.formatNumber(this.limit) : null
			const percentSuffix = (this.showPercentText && usedStr && limitStr) ? ` (${this.computedPercent}%)` : ''

			if (this.format === 'prefix') {
				if (usedStr && limitStr) {
					return `${this.unit}${usedStr} / ${this.unit}${limitStr}${percentSuffix}`
				}
				if (usedStr) {
					return `${this.unit}${usedStr}`
				}
				if (limitStr) {
					return `${this.t('hermiq', 'Limit')}: ${this.unit}${limitStr}`
				}
				return '—'
			}

			const unitSuffix = this.unit ? ` ${this.unit}` : ''
			if (usedStr && limitStr) {
				return `${usedStr} / ${limitStr}${unitSuffix}${percentSuffix}`
			}
			if (usedStr) {
				return `${usedStr}${unitSuffix}`
			}
			if (limitStr) {
				return `${this.t('hermiq', 'Limit')}: ${limitStr}${unitSuffix}`
			}
			return '—'
		},
	},

	methods: {
		/**
		 * Format a number at the configured precision.
		 *
		 * @spec exclude Trivial formatting helper; no behavioural spec.
		 * @param {number} value The value to format.
		 * @return {string} The formatted number.
		 */
		formatNumber(value) {
			const num = Number(value)
			if (this.precision === null || this.precision === undefined) {
				return String(num)
			}
			return num.toFixed(this.precision)
		},
	},
}
</script>

<style scoped>
.budget-meter__head {
	display: flex;
	justify-content: space-between;
	gap: 12px;
	margin-bottom: 8px;
	font-weight: 600;
}

.budget-meter__count {
	color: var(--color-text-maxcontrast);
	font-weight: normal;
	white-space: nowrap;
}

.budget-meter__bar {
	height: 8px;
	background: var(--color-background-dark);
	border-radius: 4px;
	overflow: hidden;
}

.budget-meter__fill {
	height: 100%;
	background: var(--color-primary-element);
	transition: width 0.3s ease;
}

.budget-meter__bar--warn .budget-meter__fill {
	background: var(--color-warning, #e9a209);
}

.budget-meter__bar--over .budget-meter__fill {
	background: var(--color-error);
}

.budget-meter__unlimited {
	margin: 0;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	font-style: italic;
}
</style>
