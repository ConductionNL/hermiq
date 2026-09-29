<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  AgentRatingsWidget: how people rated this agent's answers
  (observability-feedback-per-agent). The share rated helpful with the thumbs
  up and thumbs down counts, and the latest thumbs-down comments with their
  date. The rater is never named.

  @spec openspec/specs/run-analytics/spec.md#requirement-an-agent-owner-sees-how-answers-were-rated-req-fbstat-001
-->
<template>
	<div class="agent-ratings">
		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>
		<div v-if="loading" class="agent-ratings__loading">
			<NcLoadingIcon :size="32" />
		</div>
		<template v-else>
			<section class="agent-ratings__tile" data-testid="agent-ratings-tile">
				<span class="agent-ratings__label">{{
					t('hermiq', 'Rated helpful')
				}}</span>
				<template v-if="rated > 0">
					<span class="agent-ratings__value">{{ helpfulPercent }}%</span>
					<span class="agent-ratings__counts">{{
						t('hermiq', '{up} up, {down} down', {
							up: feedback.positive,
							down: feedback.negative,
						})
					}}</span>
				</template>
				<span v-else class="agent-ratings__counts">{{
					t('hermiq', 'No ratings yet')
				}}</span>
			</section>

			<section class="agent-ratings__low">
				<h3 class="agent-ratings__subhead">
					{{ t('hermiq', 'Latest low ratings') }}
				</h3>
				<p v-if="lowRatings.length === 0" class="agent-ratings__none">
					{{ t('hermiq', 'No thumbs down with a comment yet.') }}
				</p>
				<ul v-else class="agent-ratings__list">
					<li
						v-for="(row, i) in lowRatings"
						:key="i"
						class="agent-ratings__item">
						<span class="agent-ratings__comment">{{ row.comment }}</span>
						<span class="agent-ratings__date">{{
							formatDate(row.date)
						}}</span>
					</li>
				</ul>
			</section>
		</template>
	</div>
</template>

<script>
import { NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import { getAnalytics, listLowRatings } from '../api/analytics.js'

export default {
	name: 'AgentRatingsWidget',

	components: {
		NcLoadingIcon,
		NcNoteCard,
	},

	data() {
		return {
			feedback: { positive: 0, negative: 0, helpfulRate: null },
			lowRatings: [],
			loading: false,
			error: '',
		}
	},

	computed: {
		/**
		 * This agent's uuid from the route param.
		 *
		 * @spec exclude framework route-param accessor; behaviour owned by the widget's spec-tagged load method
		 * @return {string} The agent uuid.
		 */
		agentId() {
			return this.$route.params.id
		},

		/**
		 * How many ratings there are.
		 *
		 * @return {number} Thumbs up plus thumbs down.
		 * @spec openspec/specs/run-analytics/spec.md#requirement-an-agent-owner-sees-how-answers-were-rated-req-fbstat-001
		 */
		rated() {
			return (
				Number(this.feedback.positive || 0)
				+ Number(this.feedback.negative || 0)
			)
		},

		/**
		 * The share rated helpful as a whole percentage.
		 *
		 * @return {number} 0 to 100.
		 * @spec openspec/specs/run-analytics/spec.md#requirement-an-agent-owner-sees-how-answers-were-rated-req-fbstat-001
		 */
		helpfulPercent() {
			return Math.round(Number(this.feedback.helpfulRate || 0) * 100)
		},
	},

	watch: {
		agentId: {
			immediate: true,
			/**
			 * @spec openspec/specs/run-analytics/spec.md#requirement-an-agent-owner-sees-how-answers-were-rated-req-fbstat-001
			 */
			handler() {
				this.load()
			},
		},
	},

	methods: {
		/**
		 * Load the counts and the latest low ratings.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/run-analytics/spec.md#requirement-an-agent-owner-reads-the-latest-low-ratings-req-fbstat-002
		 */
		async load() {
			if (!this.agentId) {
				return
			}
			this.loading = true
			this.error = ''
			try {
				const [metrics, lowRatings] = await Promise.all([
					getAnalytics(this.agentId),
					listLowRatings(this.agentId),
				])
				this.feedback = metrics?.feedback || this.feedback
				this.lowRatings = lowRatings
			} catch (e) {
				this.error =
					e?.response?.data?.error
					|| e?.message
					|| this.t('hermiq', 'Unknown error')
			} finally {
				this.loading = false
			}
		},

		/**
		 * A readable date.
		 *
		 * @param {string} value The ISO timestamp.
		 * @return {string} The formatted date.
		 * @spec openspec/specs/run-analytics/spec.md#requirement-an-agent-owner-reads-the-latest-low-ratings-req-fbstat-002
		 */
		formatDate(value) {
			const date = new Date(value)
			return value && !Number.isNaN(date.getTime())
				? date.toLocaleString()
				: ''
		},
	},
}
</script>

<style scoped>
.agent-ratings {
	display: flex;
	flex-direction: column;
	gap: 12px;
	height: 100%;
}

.agent-ratings__loading {
	display: flex;
	justify-content: center;
	padding: 24px 0;
}

.agent-ratings__tile {
	display: flex;
	align-items: baseline;
	gap: 12px;
}

.agent-ratings__label {
	font-weight: 600;
}

.agent-ratings__value {
	font-size: 24px;
	font-weight: 700;
}

.agent-ratings__counts,
.agent-ratings__date,
.agent-ratings__none {
	color: var(--color-text-maxcontrast);
}

.agent-ratings__subhead {
	font-size: 16px;
	font-weight: 600;
	margin: 0 0 8px;
}

.agent-ratings__low {
	flex: 1 1 auto;
	min-height: 0;
	overflow-y: auto;
}

.agent-ratings__list {
	list-style: none;
	margin: 0;
	padding: 0;
}

.agent-ratings__item {
	display: flex;
	gap: 12px;
	justify-content: space-between;
	padding: 6px 0;
	border-bottom: 1px solid var(--color-border);
}
</style>
