<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  TenantOps — the Hermiq "Tenant ops" nav page (multi-tenant-ops).

  Org-level operational controls: per-organisation quota usage (schedules + agents-in-use
  vs. configured limits, with an at-limit warning) and a per-tenant EU AI Act audit export
  (downloads the caller's own governance records from OpenRegister's hash-chained
  AuditTrail). Shown only to org owners / instance admins via the backend
  `can_manage_killswitch` capability (loadState — never a DOM read, ADR-004).

  The hard create-time quota reject + the authoritative agent inventory live in
  OpenRegister (object creation flows through OR's object API) — this page surfaces + advises.

  @spec openspec/changes/multi-tenant-ops/tasks.md#task-3-2
  @spec openspec/changes/multi-tenant-ops/specs/multi-tenant-ops/spec.md
-->
<template>
	<div class="tenant-ops">
		<h2 class="tenant-ops__heading">
			{{ t('hermiq', 'Tenant ops') }}
		</h2>

		<NcEmptyContent
			v-if="!canManage"
			:name="t('hermiq', 'Organisation admins only')"
			:description="t('hermiq', 'Quota and audit export are available to organisation owners and instance admins.')">
			<template #icon>
				<ShieldIcon :size="20" />
			</template>
		</NcEmptyContent>

		<template v-else>
			<NcNoteCard v-if="error" type="error" :heading="t('hermiq', 'Tenant ops error')">
				{{ error }}
			</NcNoteCard>

			<div v-if="loading" class="tenant-ops__loading">
				<NcLoadingIcon :size="32" />
			</div>

			<template v-else>
				<section class="tenant-ops__section">
					<h3 class="tenant-ops__subhead">
						{{ t('hermiq', 'Quota usage') }}
					</h3>
					<div class="tenant-ops__cards">
						<div class="tenant-ops__card" :class="{ 'tenant-ops__card--warn': quota.schedules && quota.schedules.atLimit }">
							<span class="tenant-ops__card-value">{{ quota.schedules ? quota.schedules.count : 0 }} / {{ quota.schedules ? quota.schedules.limit : 0 }}</span>
							<span class="tenant-ops__card-label">{{ t('hermiq', 'Schedules') }}</span>
							<span v-if="quota.schedules && quota.schedules.atLimit" class="tenant-ops__card-warn">{{ t('hermiq', 'Quota reached') }}</span>
						</div>
						<div class="tenant-ops__card" :class="{ 'tenant-ops__card--warn': quota.agents && quota.agents.atLimit }">
							<span class="tenant-ops__card-value">{{ quota.agents ? quota.agents.count : 0 }} / {{ quota.agents ? quota.agents.limit : 0 }}</span>
							<span class="tenant-ops__card-label">{{ t('hermiq', 'Agents in use') }}</span>
							<span v-if="quota.agents && quota.agents.atLimit" class="tenant-ops__card-warn">{{ t('hermiq', 'Quota reached') }}</span>
						</div>
					</div>
					<p class="tenant-ops__note">
						{{ t('hermiq', 'The authoritative agent inventory and create-time quota reject live in OpenRegister.') }}
					</p>
				</section>

				<section v-if="organisations.length > 0" class="tenant-ops__section">
					<div class="tenant-ops__section-head">
						<h3 class="tenant-ops__subhead">
							{{ t('hermiq', 'Cost guardrails') }}
						</h3>
						<NcButton type="secondary" @click="openCreateBudget">
							{{ t('hermiq', 'Add budget') }}
						</NcButton>
					</div>

					<div v-if="organisations.length > 1" class="tenant-ops__org-picker">
						<NcSelect
							v-model="orgOption"
							:input-label="t('hermiq', 'Organisation')"
							:options="orgOptions"
							:clearable="false"
							label="label"
							track-by="value" />
					</div>

					<NcNoteCard v-if="budgetError" type="error" :heading="t('hermiq', 'Budget error')">
						{{ budgetError }}
					</NcNoteCard>

					<div v-if="budgetsLoading" class="tenant-ops__loading">
						<NcLoadingIcon :size="24" />
					</div>

					<p v-else-if="budgets.length === 0" class="tenant-ops__note">
						{{ t('hermiq', 'No budgets configured yet for this organisation.') }}
					</p>

					<div v-else class="tenant-ops__cards">
						<div
							v-for="entry in budgets"
							:key="entry.id"
							class="tenant-ops__card tenant-ops__card--budget"
							:class="{ 'tenant-ops__card--warn': entry.status && (entry.status.hardCapReached || entry.status.softThresholdReached) }">
							<span class="tenant-ops__card-label">
								{{ entry.scope === 'agent' ? t('hermiq', 'Agent budget') : t('hermiq', 'Organisation budget') }}
								<span v-if="entry.scope === 'agent'" class="tenant-ops__card-sub">({{ entry.agentId }})</span>
							</span>
							<span class="tenant-ops__card-value">
								{{ budgetUsageLabel(entry) }}
							</span>
							<span v-if="entry.status && entry.status.hardCapReached" class="tenant-ops__card-warn">
								{{ t('hermiq', 'Hard cap reached — new runs are blocked') }}
							</span>
							<span v-else-if="entry.status && entry.status.softThresholdReached" class="tenant-ops__card-warn">
								{{ t('hermiq', 'Soft threshold crossed') }}
							</span>
							<div class="tenant-ops__card-actions">
								<NcButton type="tertiary" @click="openEditBudget(entry)">
									{{ t('hermiq', 'Edit') }}
								</NcButton>
								<NcButton type="tertiary" @click="removeBudget(entry)">
									{{ t('hermiq', 'Delete') }}
								</NcButton>
							</div>
						</div>
					</div>
				</section>

				<section class="tenant-ops__section">
					<h3 class="tenant-ops__subhead">
						{{ t('hermiq', 'EU AI Act audit export') }}
					</h3>
					<p class="tenant-ops__note">
						{{ t('hermiq', 'Download your organisation\'s governance records (runs, decisions) from the hash-chained audit trail — scoped to your tenant, produced entirely on this instance.') }}
					</p>
					<NcButton
						type="primary"
						:disabled="exporting"
						:aria-label="t('hermiq', 'Export AI Act audit trail')"
						@click="exportAudit">
						<template v-if="exporting" #icon>
							<NcLoadingIcon :size="18" />
						</template>
						{{ t('hermiq', 'Export AI Act audit trail') }}
					</NcButton>
					<p v-if="lastExportCount !== null" class="tenant-ops__export-result">
						{{ n('hermiq', 'Exported %n record.', 'Exported %n records.', lastExportCount) }}
					</p>
				</section>
			</template>
		</template>

		<BudgetFormModal
			:show="showBudgetForm"
			:organisation="selectedOrg"
			:budget="editingBudget"
			@close="showBudgetForm = false"
			@saved="onBudgetSaved" />
	</div>
</template>

<script>
import { NcButton, NcEmptyContent, NcLoadingIcon, NcNoteCard, NcSelect } from '@nextcloud/vue'
import { loadState } from '@nextcloud/initial-state'
import { showError, showSuccess } from '@nextcloud/dialogs'
import ShieldIcon from 'vue-material-design-icons/ShieldLockOutline.vue'
import { getAuditExport, getQuota } from '../api/tenantOps.js'
import { deleteBudget, getBudgetStatus, listBudgets } from '../api/budgets.js'
import BudgetFormModal from '../modals/BudgetFormModal.vue'

export default {
	name: 'TenantOps',

	components: {
		BudgetFormModal,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
		ShieldIcon,
	},

	data() {
		// Capability + manageable organisations come from the backend via
		// IInitialState (loadState) — never a DOM data-attribute read (ADR-004).
		const organisations = loadState('hermiq', 'managed_organisations', [])
		return {
			canManage: loadState('hermiq', 'can_manage_killswitch', false) === true,
			quota: {},
			loading: true,
			exporting: false,
			lastExportCount: null,
			error: '',
			// Cost-guardrails (cost-guardrails): budgets are org-scoped, so admins who
			// manage more than one organisation need an org picker (mirrors KillSwitchToggle).
			organisations: Array.isArray(organisations) ? organisations : [],
			selectedOrg: '',
			budgets: [],
			budgetsLoading: false,
			budgetError: '',
			showBudgetForm: false,
			editingBudget: null,
		}
	},

	computed: {
		/**
		 * The manageable organisations as NcSelect options.
		 *
		 * @return {Array<object>} The { label, value } options.
		 */
		orgOptions() {
			return this.organisations.map((org) => ({
				label: org.label || org.id,
				value: org.id,
			}))
		},

		/**
		 * Two-way bridge between the selected org id and the NcSelect option object.
		 */
		orgOption: {
			get() {
				return this.orgOptions.find((option) => option.value === this.selectedOrg) || this.orgOptions[0]
			},
			set(option) {
				this.selectedOrg = option ? option.value : ''
				this.loadBudgets()
			},
		},
	},

	created() {
		if (this.canManage) {
			this.load()
		} else {
			this.loading = false
		}
		if (this.canManage && this.organisations.length > 0) {
			this.selectedOrg = this.organisations[0].id
			this.loadBudgets()
		}
	},

	methods: {
		/**
		 * Load the quota status.
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

		/**
		 * Fetch the AI Act audit export and download it as a JSON file.
		 *
		 * @return {Promise<void>}
		 */
		async exportAudit() {
			this.exporting = true
			this.error = ''
			try {
				const data = await getAuditExport()
				this.lastExportCount = data.recordCount || 0
				this.downloadJson(data, 'hermiq-ai-act-audit.json')
			} catch (e) {
				this.error = e?.response?.data?.error || e?.message || this.t('hermiq', 'Unknown error')
			} finally {
				this.exporting = false
			}
		},

		/**
		 * Trigger a client-side download of a JSON object.
		 *
		 * @param {object} data The payload to download.
		 * @param {string} filename The download filename.
		 * @return {void}
		 */
		downloadJson(data, filename) {
			const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' })
			const url = URL.createObjectURL(blob)
			const a = document.createElement('a')
			a.href = url
			a.download = filename
			document.body.appendChild(a)
			a.click()
			document.body.removeChild(a)
			URL.revokeObjectURL(url)
		},

		/**
		 * Load the selected organisation's budgets plus each one's current-period
		 * status (cost-guardrails).
		 *
		 * @return {Promise<void>}
		 */
		async loadBudgets() {
			if (!this.selectedOrg) {
				this.budgets = []
				return
			}
			this.budgetsLoading = true
			this.budgetError = ''
			try {
				const list = await listBudgets(this.selectedOrg)
				const withStatus = await Promise.all(
					list.map(async (entry) => {
						try {
							const status = await getBudgetStatus(this.selectedOrg, entry.agentId || '')
							return { ...entry, status }
						} catch (e) {
							return { ...entry, status: null }
						}
					}),
				)
				this.budgets = withStatus
			} catch (e) {
				this.budgetError = e?.response?.data?.error || e?.message || this.t('hermiq', 'Unknown error')
			} finally {
				this.budgetsLoading = false
			}
		},

		/**
		 * Human label for a budget card's usage line: tokens used/limit/percent, or a
		 * plain "configured" note when the status could not be resolved.
		 *
		 * @param {object} entry The budget + its status.
		 * @return {string} The usage label.
		 */
		budgetUsageLabel(entry) {
			const tokens = entry.status && entry.status.tokens
			if (tokens && tokens.limit) {
				return `${tokens.used} / ${tokens.limit} ${this.t('hermiq', 'tokens')} (${tokens.percent}%)`
			}
			if (entry.tokenLimit) {
				return `${this.t('hermiq', 'Limit')}: ${entry.tokenLimit} ${this.t('hermiq', 'tokens')}`
			}
			if (entry.eurLimit) {
				return `${this.t('hermiq', 'Limit')}: €${entry.eurLimit}`
			}
			return this.t('hermiq', 'No limit configured')
		},

		/**
		 * Open the create-budget modal for the selected organisation.
		 *
		 * @return {void}
		 */
		openCreateBudget() {
			this.editingBudget = null
			this.showBudgetForm = true
		},

		/**
		 * Open the edit-budget modal for an existing budget.
		 *
		 * @param {object} entry The budget to edit.
		 * @return {void}
		 */
		openEditBudget(entry) {
			this.editingBudget = entry
			this.showBudgetForm = true
		},

		/**
		 * Reload after a budget is created/edited.
		 *
		 * @return {Promise<void>}
		 */
		async onBudgetSaved() {
			await this.loadBudgets()
		},

		/**
		 * Delete a budget and refresh the list.
		 *
		 * @param {object} entry The budget to delete.
		 * @return {Promise<void>}
		 */
		async removeBudget(entry) {
			try {
				await deleteBudget(entry.id)
				showSuccess(this.t('hermiq', 'Budget deleted.'))
				await this.loadBudgets()
			} catch (e) {
				showError(this.t('hermiq', 'Could not delete the budget.'))
			}
		},
	},
}
</script>

<style scoped>
.tenant-ops {
	padding: 20px;
	max-width: 800px;
	margin: 0 auto;
}

.tenant-ops__heading {
	margin: 0 0 16px;
	font-size: 22px;
	font-weight: 600;
}

.tenant-ops__loading {
	display: flex;
	justify-content: center;
	padding: 48px 0;
}

.tenant-ops__section {
	margin-bottom: 28px;
}

.tenant-ops__subhead {
	font-size: 16px;
	font-weight: 600;
	margin: 0 0 8px;
}

.tenant-ops__cards {
	display: flex;
	gap: 12px;
	flex-wrap: wrap;
	margin-bottom: 8px;
}

.tenant-ops__card {
	flex: 1 1 180px;
	display: flex;
	flex-direction: column;
	gap: 4px;
	padding: 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	background: var(--color-background-hover);
}

.tenant-ops__card--warn {
	border-color: var(--color-warning);
}

.tenant-ops__card-value {
	font-size: 24px;
	font-weight: 700;
}

.tenant-ops__card-label {
	color: var(--color-text-maxcontrast);
}

.tenant-ops__card-warn {
	color: var(--color-warning);
	font-weight: 600;
}

.tenant-ops__note {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	margin: 4px 0 12px;
}

.tenant-ops__export-result {
	margin-top: 8px;
	color: var(--color-success);
}

.tenant-ops__section-head {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	margin-bottom: 8px;
}

.tenant-ops__org-picker {
	max-width: 320px;
	margin-bottom: 12px;
}

.tenant-ops__card--budget {
	flex: 1 1 240px;
}

.tenant-ops__card-sub {
	font-weight: 400;
	font-size: 12px;
}

.tenant-ops__card-actions {
	display: flex;
	gap: 8px;
	margin-top: 4px;
}
</style>
