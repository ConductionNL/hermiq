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

				<!-- Model policy (tenant-model-policy): which providers/models each
				     organisation's agents may use; the instance default applies when an
				     organisation has no policy of its own. -->
				<section class="tenant-ops__section">
					<div class="tenant-ops__section-head">
						<h3 class="tenant-ops__subhead">
							{{ t('hermiq', 'Model policy') }}
						</h3>
					</div>

					<NcNoteCard v-if="policyError" type="error" :heading="t('hermiq', 'Model policy error')">
						{{ policyError }}
					</NcNoteCard>

					<p v-if="modelPolicies.length === 0 && !policyError" class="tenant-ops__note">
						{{ t('hermiq', 'No model policies configured — agents fall back to the instance-wide LLM configuration.') }}
					</p>

					<div v-for="policy in modelPolicies" :key="policy.id" class="tenant-ops__policy">
						<div class="tenant-ops__policy-head">
							<strong>{{ policy.organisation ? policyOrgLabel(policy.organisation) : t('hermiq', 'Instance default') }}</strong>
							<NcButton type="tertiary" @click="togglePolicyEdit(policy)">
								{{ editingPolicyId === policy.id ? t('hermiq', 'Cancel') : t('hermiq', 'Edit') }}
							</NcButton>
						</div>
						<p v-if="editingPolicyId !== policy.id" class="tenant-ops__note">
							{{ policySummary(policy) }}
						</p>
						<div v-else class="tenant-ops__policy-edit">
							<NcTextArea
								:value.sync="policyDraft.allowedText"
								:label="t('hermiq', 'Allowed providers and models')"
								:placeholder="t('hermiq', 'One per line: provider or provider: model1, model2')"
								resize="vertical" />
							<NcTextField
								:value.sync="policyDraft.defaultModel"
								:label="t('hermiq', 'Default model (optional)')"
								placeholder="qwen2.5" />
							<div class="tenant-ops__card-actions">
								<NcButton type="primary" :disabled="policySaving" @click="savePolicy(policy)">
									{{ t('hermiq', 'Save policy') }}
								</NcButton>
							</div>
						</div>
					</div>
				</section>

				<!-- Periodic access review (agent-lifecycle-governance): every agent in the
				     org with owner/actingUser/last-run/capability summary, a "Mark reviewed"
				     attestation, and a "Reassign" action for agents flagged after offboarding. -->
				<section class="tenant-ops__section">
					<h3 class="tenant-ops__subhead">
						{{ t('hermiq', 'Access review') }}
					</h3>
					<p class="tenant-ops__note">
						{{ t('hermiq', 'Review who owns each agent and what it can do. Marking an agent reviewed records your user id and the current time, auditably.') }}
					</p>

					<NcNoteCard v-if="reviewError" type="error" :heading="t('hermiq', 'Access review error')">
						{{ reviewError }}
					</NcNoteCard>

					<CnDataTable
						:columns="reviewColumns"
						:rows="reviewRows"
						:loading="reviewLoading"
						row-key="uuid"
						:empty-text="t('hermiq', 'No agents yet.')">
						<template #column-reassignmentFlag="{ row }">
							<span v-if="row.reassignmentFlag" class="tenant-ops__card-warn">
								{{ t('hermiq', 'Flagged for reassignment') }}
							</span>
							<span v-else>—</span>
						</template>
						<template #row-actions="{ row }">
							<div class="tenant-ops__review-actions">
								<NcButton
									type="tertiary"
									:disabled="reviewBusyUuid === row.uuid"
									@click="markReviewed(row)">
									{{ t('hermiq', 'Mark reviewed') }}
								</NcButton>
								<template v-if="row.reassignmentFlag">
									<NcTextField
										:value.sync="reassignDrafts[row.uuid]"
										class="tenant-ops__reassign-input"
										:input-label="t('hermiq', 'New acting user id')"
										:placeholder="t('hermiq', 'New acting user id')" />
									<NcButton
										type="secondary"
										:disabled="!reassignDrafts[row.uuid] || reviewBusyUuid === row.uuid"
										@click="reassign(row)">
										{{ t('hermiq', 'Reassign') }}
									</NcButton>
								</template>
							</div>
						</template>
					</CnDataTable>
				</section>

				<!-- Incident records (agent-lifecycle-governance): human-authored incident
				     response, linked to an agent/run, included in the AI Act audit export. -->
				<section class="tenant-ops__section">
					<div class="tenant-ops__section-head">
						<h3 class="tenant-ops__subhead">
							{{ t('hermiq', 'Incidents') }}
						</h3>
						<NcButton type="secondary" @click="showIncidentDialog = true">
							{{ t('hermiq', 'Open incident') }}
						</NcButton>
					</div>

					<NcNoteCard v-if="incidentError" type="error" :heading="t('hermiq', 'Incident error')">
						{{ incidentError }}
					</NcNoteCard>

					<p v-if="!incidentsLoading && incidents.length === 0 && !incidentError" class="tenant-ops__note">
						{{ t('hermiq', 'No incidents recorded yet.') }}
					</p>

					<div v-if="incidentsLoading" class="tenant-ops__loading">
						<NcLoadingIcon :size="24" />
					</div>

					<ul v-else class="tenant-ops__incident-list">
						<li v-for="incident in incidents" :key="incident.uuid" class="tenant-ops__incident">
							<p class="tenant-ops__incident-description">
								{{ incident.description }}
							</p>
							<p class="tenant-ops__note">
								<strong>{{ t('hermiq', 'Impact') }}:</strong> {{ incident.impact }}
							</p>
							<p class="tenant-ops__note">
								<strong>{{ t('hermiq', 'Actions taken') }}:</strong> {{ incident.actionsTaken }}
							</p>
							<p class="tenant-ops__note">
								{{ formatDate(incident.createdAt) }} — {{ incident.createdBy }}
							</p>
						</li>
					</ul>
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

				<!-- Retention statement (agent-lifecycle-governance / multi-tenant-ops):
				     a STATED policy value (EU AI Act Art. 12 minimum 6 months) — this does
				     NOT trigger automated purge/archive of audit records. -->
				<section class="tenant-ops__section">
					<h3 class="tenant-ops__subhead">
						{{ t('hermiq', 'Retention') }}
					</h3>
					<p class="tenant-ops__note">
						{{ t('hermiq', 'How long your organisation states it keeps governance records for (EU AI Act Art. 12), at least 6 months. This is a stated policy, not automated deletion.') }}
					</p>

					<NcNoteCard v-if="retentionError" type="error" :heading="t('hermiq', 'Retention error')">
						{{ retentionError }}
					</NcNoteCard>

					<div class="tenant-ops__retention-row">
						<NcTextField
							:value.sync="retentionDraft"
							type="number"
							:label="t('hermiq', 'Retention period (months)')" />
						<NcButton type="primary" :disabled="retentionSaving" @click="saveRetention">
							{{ t('hermiq', 'Save') }}
						</NcButton>
					</div>
				</section>
			</template>
		</template>

		<BudgetFormModal
			:show="showBudgetForm"
			:organisation="selectedOrg"
			:budget="editingBudget"
			@close="showBudgetForm = false"
			@saved="onBudgetSaved" />

		<CreateIncidentDialog
			:show="showIncidentDialog"
			@close="showIncidentDialog = false"
			@created="onIncidentCreated" />
	</div>
</template>

<script>
import { NcButton, NcEmptyContent, NcLoadingIcon, NcNoteCard, NcSelect, NcTextArea, NcTextField } from '@nextcloud/vue'
import { CnDataTable } from '@conduction/nextcloud-vue'
import { loadState } from '@nextcloud/initial-state'
import { showError, showSuccess } from '@nextcloud/dialogs'
import ShieldIcon from 'vue-material-design-icons/ShieldLockOutline.vue'
import {
	attestReviewed,
	getAccessReview,
	getAuditExport,
	getIncidents,
	getQuota,
	getRetention,
	reassignAgent,
	setRetention,
} from '../api/tenantOps.js'
import { deleteBudget, getBudgetStatus, listBudgets } from '../api/budgets.js'
import { listModelPolicies, updateModelPolicy } from '../api/modelPolicy.js'
import BudgetFormModal from '../modals/BudgetFormModal.vue'
import CreateIncidentDialog from '../dialogs/CreateIncidentDialog.vue'

export default {
	name: 'TenantOps',

	components: {
		BudgetFormModal,
		CnDataTable,
		CreateIncidentDialog,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
		NcTextArea,
		NcTextField,
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
			// Model policy (tenant-model-policy): caller-visible policies + inline editor.
			modelPolicies: [],
			policyError: '',
			editingPolicyId: null,
			policyDraft: { allowedText: '', defaultModel: '' },
			policySaving: false,
			// Access review (agent-lifecycle-governance): agent inventory + attestation + reassignment.
			reviewAgents: [],
			reviewLoading: false,
			reviewError: '',
			reviewBusyUuid: '',
			reassignDrafts: {},
			// Incidents (agent-lifecycle-governance).
			incidents: [],
			incidentsLoading: false,
			incidentError: '',
			showIncidentDialog: false,
			// Retention (agent-lifecycle-governance / multi-tenant-ops).
			retentionDraft: 6,
			retentionSaving: false,
			retentionError: '',
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

		/**
		 * Column definitions for the access-review CnDataTable.
		 *
		 * @return {Array<object>} CnDataTable column descriptors.
		 */
		reviewColumns() {
			return [
				{ key: 'name', label: this.t('hermiq', 'Agent') },
				{ key: 'owner', label: this.t('hermiq', 'Owner') },
				{ key: 'actingUser', label: this.t('hermiq', 'Acting user') },
				{ key: 'lastRunAt', label: this.t('hermiq', 'Last run') },
				{ key: 'capabilities', label: this.t('hermiq', 'Capabilities') },
				{ key: 'reviewState', label: this.t('hermiq', 'Reviewed') },
				{ key: 'reassignmentFlag', label: this.t('hermiq', 'Status') },
			]
		},

		/**
		 * Agents projected onto flat rows for the access-review table.
		 *
		 * @return {Array<object>} The table rows.
		 */
		reviewRows() {
			return this.reviewAgents.map((agent) => ({
				uuid: agent.uuid,
				name: agent.name || agent.uuid,
				owner: agent.owner || '—',
				actingUser: agent.actingUser || '—',
				lastRunAt: agent.lastRunAt ? this.formatDate(agent.lastRunAt) : this.t('hermiq', 'Never'),
				capabilities: this.capabilitySummary(agent),
				reviewState: agent.reviewedAt
					? `${this.formatDate(agent.reviewedAt)} (${agent.reviewedBy})`
					: this.t('hermiq', 'Not yet reviewed'),
				reassignmentFlag: agent.reassignmentFlag === true,
			}))
		},
	},

	created() {
		if (this.canManage) {
			this.load()
			this.loadAccessReview()
			this.loadIncidents()
			this.loadRetention()
		} else {
			this.loading = false
		}
		if (this.canManage && this.organisations.length > 0) {
			this.selectedOrg = this.organisations[0].id
			this.loadBudgets()
		}
		if (this.canManage) {
			this.loadModelPolicies()
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
		 * Load the caller-visible model policies (tenant-model-policy).
		 *
		 * @return {Promise<void>}
		 */
		async loadModelPolicies() {
			this.policyError = ''
			try {
				this.modelPolicies = await listModelPolicies()
			} catch (e) {
				this.policyError = e?.response?.data?.error || e?.message || this.t('hermiq', 'Unknown error')
			}
		},

		/**
		 * Human label for a policy's organisation id.
		 *
		 * @param {string} orgId The organisation identifier.
		 * @return {string} The organisation label, or the raw id.
		 */
		policyOrgLabel(orgId) {
			const org = this.organisations.find((candidate) => candidate.id === orgId)
			return org ? (org.label || org.id) : orgId
		},

		/**
		 * One-line summary of a policy's allowed providers/models.
		 *
		 * @param {object} policy The ModelPolicy record.
		 * @return {string} The summary line.
		 */
		policySummary(policy) {
			const allowed = Array.isArray(policy.allowed) ? policy.allowed : []
			if (allowed.length === 0) {
				return this.t('hermiq', 'No providers allowed (fail closed).')
			}
			const parts = allowed.map((entry) => entry.models && entry.models.length > 0
				? `${entry.provider}: ${entry.models.join(', ')}`
				: entry.provider)
			const suffix = policy.defaultModel
				? ` — ${this.t('hermiq', 'default')}: ${policy.defaultModel}`
				: ''
			return parts.join(' · ') + suffix
		},

		/**
		 * Open/close the inline editor for a policy, seeding the draft from it.
		 * Draft format: one line per provider — `provider` (any model) or
		 * `provider: model1, model2` (allowlisted models).
		 *
		 * @param {object} policy The ModelPolicy record.
		 * @return {void}
		 */
		togglePolicyEdit(policy) {
			if (this.editingPolicyId === policy.id) {
				this.editingPolicyId = null
				return
			}
			const allowed = Array.isArray(policy.allowed) ? policy.allowed : []
			this.policyDraft = {
				allowedText: allowed.map((entry) => entry.models && entry.models.length > 0
					? `${entry.provider}: ${entry.models.join(', ')}`
					: entry.provider).join('\n'),
				defaultModel: policy.defaultModel || '',
			}
			this.editingPolicyId = policy.id
		},

		/**
		 * Persist the inline policy draft via PUT /api/model-policy/{uuid}.
		 *
		 * @param {object} policy The ModelPolicy record being edited.
		 * @return {Promise<void>}
		 */
		async savePolicy(policy) {
			const allowed = this.policyDraft.allowedText
				.split('\n')
				.map((line) => line.trim())
				.filter((line) => line !== '')
				.map((line) => {
					const [provider, models] = line.split(':')
					return {
						provider: provider.trim(),
						models: models
							? models.split(',').map((model) => model.trim()).filter((model) => model !== '')
							: [],
					}
				})
			this.policySaving = true
			try {
				await updateModelPolicy(policy.id, {
					allowed,
					defaultModel: this.policyDraft.defaultModel || null,
				})
				showSuccess(this.t('hermiq', 'Model policy saved.'))
				this.editingPolicyId = null
				await this.loadModelPolicies()
			} catch (e) {
				showError(e?.response?.data?.error || this.t('hermiq', 'Could not save the model policy.'))
			} finally {
				this.policySaving = false
			}
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

		/**
		 * Human-readable date, or an em-dash when absent/unparseable.
		 *
		 * @param {string} value An ISO-8601 timestamp.
		 * @return {string} The formatted date.
		 */
		formatDate(value) {
			if (!value) {
				return '—'
			}
			const date = new Date(value)
			return Number.isNaN(date.getTime()) ? '—' : date.toLocaleString()
		},

		/**
		 * One-line capability summary for an access-review row: the tool
		 * allowlist plus whether RAG is enabled (agent-lifecycle-governance).
		 *
		 * @param {object} agent The access-review agent entry.
		 * @return {string} The summary line.
		 */
		capabilitySummary(agent) {
			const tools = Array.isArray(agent.tools) ? agent.tools : []
			const toolsLabel = tools.length > 0 ? tools.join(', ') : this.t('hermiq', 'No tools')
			if (!agent.enableRag) {
				return toolsLabel
			}
			const mode = agent.ragSearchMode || this.t('hermiq', 'default')
			return `${toolsLabel} — ${this.t('hermiq', 'RAG')} (${mode})`
		},

		/**
		 * Load the organisation's periodic access-review list (agent-lifecycle-governance).
		 *
		 * @return {Promise<void>}
		 */
		async loadAccessReview() {
			this.reviewLoading = true
			this.reviewError = ''
			try {
				const data = await getAccessReview()
				this.reviewAgents = Array.isArray(data.agents) ? data.agents : []
				this.reviewAgents.forEach((agent) => {
					if (!(agent.uuid in this.reassignDrafts)) {
						this.$set(this.reassignDrafts, agent.uuid, '')
					}
				})
			} catch (e) {
				this.reviewError = e?.response?.data?.error || e?.message || this.t('hermiq', 'Unknown error')
			} finally {
				this.reviewLoading = false
			}
		},

		/**
		 * Attest that an agent has been reviewed.
		 *
		 * @param {object} row The access-review row.
		 * @return {Promise<void>}
		 */
		async markReviewed(row) {
			this.reviewBusyUuid = row.uuid
			try {
				await attestReviewed(row.uuid)
				showSuccess(this.t('hermiq', 'Agent marked as reviewed.'))
				await this.loadAccessReview()
			} catch (e) {
				showError(e?.response?.data?.error || this.t('hermiq', 'Could not record the review.'))
			} finally {
				this.reviewBusyUuid = ''
			}
		},

		/**
		 * Reassign a flagged agent's acting user to the drafted target user id.
		 *
		 * @param {object} row The access-review row.
		 * @return {Promise<void>}
		 */
		async reassign(row) {
			const target = (this.reassignDrafts[row.uuid] || '').trim()
			if (!target) {
				return
			}
			this.reviewBusyUuid = row.uuid
			try {
				await reassignAgent(row.uuid, target)
				showSuccess(this.t('hermiq', 'Agent reassigned.'))
				this.$set(this.reassignDrafts, row.uuid, '')
				await this.loadAccessReview()
			} catch (e) {
				showError(e?.response?.data?.error || this.t('hermiq', 'Could not reassign the agent.'))
			} finally {
				this.reviewBusyUuid = ''
			}
		},

		/**
		 * Load the organisation's incident records (agent-lifecycle-governance).
		 *
		 * @return {Promise<void>}
		 */
		async loadIncidents() {
			this.incidentsLoading = true
			this.incidentError = ''
			try {
				const data = await getIncidents()
				this.incidents = Array.isArray(data.incidents) ? data.incidents : []
			} catch (e) {
				this.incidentError = e?.response?.data?.error || e?.message || this.t('hermiq', 'Unknown error')
			} finally {
				this.incidentsLoading = false
			}
		},

		/**
		 * Refresh the incident list after CreateIncidentDialog creates one.
		 *
		 * @return {Promise<void>}
		 */
		async onIncidentCreated() {
			showSuccess(this.t('hermiq', 'Incident recorded.'))
			await this.loadIncidents()
		},

		/**
		 * Load the organisation's currently configured retention period.
		 *
		 * @return {Promise<void>}
		 */
		async loadRetention() {
			this.retentionError = ''
			try {
				const data = await getRetention()
				this.retentionDraft = data.retentionMonths || 6
			} catch (e) {
				this.retentionError = e?.response?.data?.error || e?.message || this.t('hermiq', 'Unknown error')
			}
		},

		/**
		 * Persist the drafted retention period; a rejected (<6) value shows an
		 * inline error and leaves the displayed value unchanged.
		 *
		 * @return {Promise<void>}
		 */
		async saveRetention() {
			this.retentionSaving = true
			this.retentionError = ''
			try {
				const data = await setRetention(Number(this.retentionDraft))
				this.retentionDraft = data.retentionMonths
				showSuccess(this.t('hermiq', 'Retention period saved.'))
			} catch (e) {
				this.retentionError = e?.response?.data?.error || this.t('hermiq', 'Retention period must be at least 6 months.')
				await this.loadRetention()
			} finally {
				this.retentionSaving = false
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

.tenant-ops__policy {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 12px;
	margin-bottom: 8px;
}

.tenant-ops__policy-head {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
}

.tenant-ops__policy-edit {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin-top: 8px;
}

.tenant-ops__review-actions {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
}

.tenant-ops__reassign-input {
	max-width: 180px;
}

.tenant-ops__incident-list {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.tenant-ops__incident {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 12px;
}

.tenant-ops__incident-description {
	margin: 0 0 4px;
	font-weight: 600;
}

.tenant-ops__retention-row {
	display: flex;
	align-items: flex-end;
	gap: 12px;
	max-width: 320px;
}
</style>
