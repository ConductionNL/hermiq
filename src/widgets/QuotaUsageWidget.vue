<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  QuotaUsageWidget — the caller's organisation Schedules and Agents-in-use quota
  usage, as a Dashboard widget.

  Reads the computed /api/tenant-ops/quota endpoint (TenantOpsController::quota() →
  TenantOpsService::quotaStatus()) — count vs. a configured limit, with an at-limit
  warning derived server-side. Quota is not a plain OR-schema count aggregate (it
  compares against a configured limit and a distinct-agentId derivation), so this
  can't be a declarative stats-block dataSource (ADR-049), mirroring
  AnalyticsKpiWidget's computed-endpoint pattern.

  Visibility: gated on the same `can_manage_killswitch` capability (loadState,
  never a DOM read — ADR-004) that already governs the quota display on
  TenantOps.vue today; DashboardController provides this instance-wide, not just
  on /tenant-ops, so no backend change was needed to surface it here. Renders
  nothing for a non-manager viewer (dashboard-org-widgets Decision 4) rather than
  an explanatory empty state, to avoid visual noise for everyday Dashboard users.

  @spec openspec/changes/dashboard-org-widgets/specs/dashboard-org-widgets/spec.md#requirement-the-dashboard-must-show-organisation-quota-usage-to-org-owners-and-instance-admins
-->
<template>
	<div v-if="canManage" class="quota-usage" data-testid="quota-usage-widget">
		<div class="quota-usage__header">
			<h2 class="quota-usage__title">
				{{ t('hermiq', 'Quota usage') }}
			</h2>
		</div>
		<div v-if="loading" class="quota-usage__loading">
			<NcLoadingIcon :size="28" />
		</div>
		<NcNoteCard v-else-if="error" type="error">
			{{ error }}
		</NcNoteCard>
		<template v-else>
			<div class="quota-usage__cards">
				<div
					class="quota-usage__card"
					data-testid="quota-schedules-card"
					:class="{ 'quota-usage__card--warn': quota.schedules && quota.schedules.atLimit }">
					<span class="quota-usage__value">{{ quota.schedules ? quota.schedules.count : 0 }} / {{ quota.schedules ? quota.schedules.limit : 0 }}</span>
					<span class="quota-usage__label">{{ t('hermiq', 'Schedules') }}</span>
					<span v-if="quota.schedules && quota.schedules.atLimit" class="quota-usage__warn">{{ t('hermiq', 'Quota reached') }}</span>
				</div>
				<div
					class="quota-usage__card"
					data-testid="quota-agents-card"
					:class="{ 'quota-usage__card--warn': quota.agents && quota.agents.atLimit }">
					<span class="quota-usage__value">{{ quota.agents ? quota.agents.count : 0 }} / {{ quota.agents ? quota.agents.limit : 0 }}</span>
					<span class="quota-usage__label">{{ t('hermiq', 'Agents in use') }}</span>
					<span v-if="quota.agents && quota.agents.atLimit" class="quota-usage__warn">{{ t('hermiq', 'Quota reached') }}</span>
				</div>
			</div>
			<p class="quota-usage__note">
				{{ t('hermiq', 'The authoritative agent inventory and create-time quota reject live in OpenRegister.') }}
			</p>
		</template>
	</div>
</template>

<script>
import { NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import { loadState } from '@nextcloud/initial-state'
import { getQuota } from '../api/tenantOps.js'

export default {
	name: 'QuotaUsageWidget',

	components: {
		NcLoadingIcon,
		NcNoteCard,
	},

	data() {
		return {
			// Capability comes from the backend via IInitialState (loadState) —
			// never a DOM data-attribute read (ADR-004). Provided app-wide by
			// DashboardController::provideKillSwitchCapability(), not just on
			// /tenant-ops.
			canManage: loadState('hermiq', 'can_manage_killswitch', false) === true,
			quota: {},
			loading: true,
			error: '',
		}
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
		 * @return {Promise<void>}
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				this.quota = await getQuota()
			} catch (e) {
				this.error = e?.response?.data?.error || e?.message || this.t('hermiq', 'Unknown error')
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.quota-usage__header {
	padding-top: 8px;
	margin-bottom: 16px;
}

.quota-usage__title {
	margin: 0;
	font-size: 22px;
	font-weight: 600;
}

.quota-usage__loading {
	display: flex;
	justify-content: center;
	padding: 16px;
}

.quota-usage__cards {
	display: flex;
	gap: 12px;
	flex-wrap: wrap;
	margin-bottom: 8px;
}

.quota-usage__card {
	flex: 1 1 180px;
	display: flex;
	flex-direction: column;
	gap: 4px;
	padding: 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	background: var(--color-background-hover);
}

.quota-usage__card--warn {
	border-color: var(--color-warning);
}

.quota-usage__value {
	font-size: 24px;
	font-weight: 700;
	color: var(--color-main-text);
}

.quota-usage__label {
	color: var(--color-text-maxcontrast);
}

.quota-usage__warn {
	color: var(--color-warning);
	font-weight: 600;
}

.quota-usage__note {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	margin: 4px 0 0;
}
</style>
