<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  QuotaStatWidget — ONE organisation quota tile (Schedules or Agents in use).

  Reads the computed /api/tenant-ops/quota endpoint (TenantOpsController::quota() →
  TenantOpsService::quotaStatus()) — count vs. a configured limit, with an at-limit
  warning derived server-side. Quota is not a plain OR-schema count aggregate (it
  compares against a configured limit and a distinct-agentId derivation), so this
  can't be a declarative stats-block dataSource (ADR-049).

  ONE tile per placement, on purpose. The predecessor (QuotaUsageWidget) drew its
  own title and its own two bordered cards INSIDE the dashboard's widget card — a
  well inside a well, which reads as nested chrome and does not line up with the
  KPI tiles beside it. A dashboard tile is already a card: the widget's job is to
  fill it, not to draw another one. The two placements share this component and
  differ only by `content.metric`, the procest KPI-row shape.

  Visibility: gated on the same `can_manage_killswitch` capability (loadState,
  never a DOM read — ADR-004) that already governs the quota display on
  TenantOps.vue. DashboardController provides this instance-wide, so no backend
  change is needed. Renders nothing for a non-manager viewer
  (dashboard-org-widgets Decision 4) rather than an explanatory empty state.

  @spec openspec/changes/dashboard-org-widgets/specs/dashboard-org-widgets/spec.md#requirement-the-dashboard-must-show-organisation-quota-usage-to-org-owners-and-instance-admins
-->
<template>
	<div
		v-if="canManage"
		class="quota-stat"
		:class="{ 'quota-stat--warn': atLimit }"
		:data-testid="`quota-${metric}-card`">
		<div v-if="loading" class="quota-stat__loading">
			<NcLoadingIcon :size="24" />
		</div>
		<NcNoteCard v-else-if="error" type="error">
			{{ error }}
		</NcNoteCard>
		<template v-else>
			<span class="quota-stat__value">{{ valueLabel }}</span>
			<span class="quota-stat__label">{{ label }}</span>
			<span v-if="atLimit" class="quota-stat__warn">{{
				t('hermiq', 'Quota reached')
			}}</span>
		</template>
	</div>
</template>

<script>
import { NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import { loadState } from '@nextcloud/initial-state'
import { getQuota } from '../api/tenantOps.js'

export default {
	name: 'QuotaStatWidget',

	components: {
		NcLoadingIcon,
		NcNoteCard,
	},

	props: {
		/**
		 * The manifest widget definition for this placement. CnDashboardPage
		 * hands custom-slot widgets `{ item, widget }`; `widget.content.metric`
		 * is which quota this tile shows (`schedules` | `agents`).
		 *
		 * @type {{content?: {metric?: string}}}
		 */
		widget: {
			type: Object,
			default: () => ({}),
		},
	},

	data() {
		return {
			// Capability comes from the backend via IInitialState (loadState) —
			// never a DOM data-attribute read (ADR-004). Provided app-wide by
			// DashboardController::provideKillSwitchCapability().
			canManage: loadState('hermiq', 'can_manage_killswitch', false) === true,
			quota: {},
			loading: true,
			error: '',
		}
	},

	computed: {
		/**
		 * Which quota this tile renders. Defaults to `schedules` so a placement
		 * that forgot its content still shows a real number rather than a dash.
		 *
		 * @return {string} The metric key.
		 */
		metric() {
			const configured = this.widget?.content?.metric
			return configured === 'agents' ? 'agents' : 'schedules'
		},

		/**
		 * The tile's label.
		 *
		 * @return {string} The human label.
		 */
		label() {
			return this.metric === 'agents'
				? this.t('hermiq', 'Agents in use')
				: this.t('hermiq', 'Schedules')
		},

		/**
		 * This tile's slice of the quota payload.
		 *
		 * @return {object} `{count, limit, atLimit}` or an empty object.
		 */
		entry() {
			return this.quota?.[this.metric] || {}
		},

		/**
		 * The count against its limit, e.g. "1 / 100".
		 *
		 * @return {string} The value label.
		 */
		valueLabel() {
			const count = this.entry.count ?? 0
			const limit = this.entry.limit ?? 0
			return `${count} / ${limit}`
		},

		/**
		 * Whether the organisation has reached this quota (derived server-side).
		 *
		 * @return {boolean} True when at the limit.
		 */
		atLimit() {
			return this.entry.atLimit === true
		},
	},

	mounted() {
		if (this.canManage) {
			this.load()
		}
	},

	methods: {
		/**
		 * Load the caller's organisation quota status.
		 *
		 * Both quota tiles call this; the two requests are the same GET and are
		 * cheap, and keeping each tile self-fetching means neither depends on
		 * the other being placed.
		 *
		 * @return {Promise<void>}
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				this.quota = await getQuota()
			} catch (e) {
				this.error =
					e?.response?.data?.error
					|| e?.message
					|| this.t('hermiq', 'Unknown error')
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
/* Flat tile: the dashboard's widget card IS the card. No border, no inner
   background — that is what produced the well-in-a-well. */
.quota-stat {
	display: flex;
	flex-direction: column;
	gap: 4px;
	justify-content: center;
	min-width: 0;
}

.quota-stat__loading {
	display: flex;
	justify-content: center;
	padding: 8px 0;
}

.quota-stat__value {
	font-size: 24px;
	font-weight: 700;
	color: var(--color-main-text);
	font-variant-numeric: tabular-nums;
}

.quota-stat__label {
	color: var(--color-text-maxcontrast);
}

.quota-stat--warn .quota-stat__value {
	color: var(--color-warning);
}

.quota-stat__warn {
	color: var(--color-warning);
	font-weight: 600;
	font-size: 13px;
}
</style>
