<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  ComplianceOperations — Incidents, EU AI Act audit export, and Retention,
  resolved via `page.slots.below-header` on the Compliance index page's
  generic `type:"index"` config (register:"hermiq" schema:"agentcompliancecontrol",
  inapp-settings-section). Ported unchanged from TenantOps.vue, which now
  retains only the true per-organisation operational controls (Cost
  guardrails, Model policy, Access review) — these three sections are
  compliance-framework surfaces, not per-organisation ops, so they moved here
  (design.md, inapp-settings-section spec).

  Same capability gate as TenantOps (`can_manage_killswitch`, loadState —
  never a DOM read, ADR-004), same API calls (src/api/tenantOps.js), same
  guards and behaviour — only the host page changed.

  @spec openspec/specs/inapp-settings-section/spec.md#requirement-tenant-ops-must-retain-only-true-per-organisation-operational-controls
  @spec openspec/changes/agent-lifecycle-governance/specs/agent-lifecycle-governance/spec.md#requirement-incident-records-linked-to-runs-and-agents
  @spec openspec/changes/multi-tenant-ops/specs/multi-tenant-ops/spec.md
-->
<template>
	<div class="compliance-operations">
		<NcEmptyContent
			v-if="!canManage"
			:name="t('hermiq', 'Organisation admins only')"
			:description="
				t(
					'hermiq',
					'Compliance operations are available to organisation owners and instance admins.',
				)
			">
			<template #icon>
				<ShieldIcon :size="20" />
			</template>
		</NcEmptyContent>

		<template v-else>
			<!-- Incident records (agent-lifecycle-governance): human-authored incident
				     response, linked to an agent/run, included in the AI Act audit export. -->
			<section class="compliance-operations__section">
				<div class="compliance-operations__section-head">
					<h3 class="compliance-operations__subhead">
						{{ t('hermiq', 'Incidents') }}
					</h3>
					<NcButton type="secondary" @click="showIncidentDialog = true">
						{{ t('hermiq', 'Open incident') }}
					</NcButton>
				</div>

				<NcNoteCard
					v-if="incidentError"
					type="error"
					:heading="t('hermiq', 'Incident error')">
					{{ incidentError }}
				</NcNoteCard>

				<p
					v-if="
						!incidentsLoading && incidents.length === 0 && !incidentError
					"
					class="compliance-operations__note">
					{{ t('hermiq', 'No incidents recorded yet.') }}
				</p>

				<div v-if="incidentsLoading" class="compliance-operations__loading">
					<NcLoadingIcon :size="24" />
				</div>

				<ul v-else class="compliance-operations__incident-list">
					<li
						v-for="incident in incidents"
						:key="incident.uuid"
						class="compliance-operations__incident">
						<p class="compliance-operations__incident-description">
							{{ incident.description }}
						</p>
						<p class="compliance-operations__note">
							<strong>{{ t('hermiq', 'Impact') }}:</strong>
							{{ incident.impact }}
						</p>
						<p class="compliance-operations__note">
							<strong>{{ t('hermiq', 'Actions taken') }}:</strong>
							{{ incident.actionsTaken }}
						</p>
						<p class="compliance-operations__note">
							{{ formatDate(incident.createdAt) }} —
							{{ incident.createdBy }}
						</p>
					</li>
				</ul>
			</section>

			<section class="compliance-operations__section">
				<h3 class="compliance-operations__subhead">
					{{ t('hermiq', 'EU AI Act audit export') }}
				</h3>
				<p class="compliance-operations__note">
					{{
						t(
							'hermiq',
							"Download your organisation's governance records (runs, decisions) from the hash-chained audit trail — scoped to your tenant, produced entirely on this instance.",
						)
					}}
				</p>
				<NcNoteCard
					v-if="auditError"
					type="error"
					:heading="t('hermiq', 'Audit export error')">
					{{ auditError }}
				</NcNoteCard>
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
				<p
					v-if="lastExportCount !== null"
					class="compliance-operations__export-result">
					{{
						n(
							'hermiq',
							'Exported %n record.',
							'Exported %n records.',
							lastExportCount,
						)
					}}
				</p>
			</section>

			<!-- Retention statement (agent-lifecycle-governance / multi-tenant-ops):
				     a STATED policy value (EU AI Act Art. 12 minimum 6 months) — this does
				     NOT trigger automated purge/archive of audit records. -->
			<section class="compliance-operations__section">
				<h3 class="compliance-operations__subhead">
					{{ t('hermiq', 'Retention') }}
				</h3>
				<p class="compliance-operations__note">
					{{
						t(
							'hermiq',
							'How long your organisation states it keeps governance records for (EU AI Act Art. 12), at least 6 months. This is a stated policy, not automated deletion.',
						)
					}}
				</p>

				<NcNoteCard
					v-if="retentionError"
					type="error"
					:heading="t('hermiq', 'Retention error')">
					{{ retentionError }}
				</NcNoteCard>

				<div class="compliance-operations__retention-row">
					<NcTextField
						v-model="retentionDraft"
						type="number"
						:label="t('hermiq', 'Retention period (months)')" />
					<NcButton
						type="primary"
						:disabled="retentionSaving"
						@click="saveRetention">
						{{ t('hermiq', 'Save') }}
					</NcButton>
				</div>
			</section>
		</template>

		<CreateIncidentDialog
			:show="showIncidentDialog"
			@close="showIncidentDialog = false"
			@created="onIncidentCreated" />
	</div>
</template>

<script>
import {
	NcButton,
	NcEmptyContent,
	NcLoadingIcon,
	NcNoteCard,
	NcTextField,
} from '@nextcloud/vue'
import { loadState } from '@nextcloud/initial-state'
import { showSuccess } from '@nextcloud/dialogs'
import ShieldIcon from 'vue-material-design-icons/ShieldLockOutline.vue'
import {
	getAuditExport,
	getIncidents,
	getRetention,
	setRetention,
} from '../api/tenantOps.js'
import CreateIncidentDialog from '../dialogs/CreateIncidentDialog.vue'

export default {
	name: 'ComplianceOperations',

	components: {
		CreateIncidentDialog,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		NcTextField,
		ShieldIcon,
	},

	data() {
		// Capability comes from the backend via IInitialState (loadState) —
		// never a DOM data-attribute read (ADR-004). Same capability TenantOps
		// gates on: these three sections were org-operational controls there.
		return {
			canManage: loadState('hermiq', 'can_manage_killswitch', false) === true,
			// EU AI Act audit export (multi-tenant-ops).
			exporting: false,
			lastExportCount: null,
			auditError: '',
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

	created() {
		if (this.canManage) {
			this.loadIncidents()
			this.loadRetention()
		}
	},

	methods: {
		/**
		 * Fetch the AI Act audit export and download it as a JSON file.
		 *
		 * @return {Promise<void>}
		 */
		async exportAudit() {
			this.exporting = true
			this.auditError = ''
			try {
				const data = await getAuditExport()
				this.lastExportCount = data.recordCount || 0
				this.downloadJson(data, 'hermiq-ai-act-audit.json')
			} catch (e) {
				this.auditError =
					e?.response?.data?.error
					|| e?.message
					|| this.t('hermiq', 'Unknown error')
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
			const blob = new Blob([JSON.stringify(data, null, 2)], {
				type: 'application/json',
			})
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
				this.incidentError =
					e?.response?.data?.error
					|| e?.message
					|| this.t('hermiq', 'Unknown error')
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
				this.retentionError =
					e?.response?.data?.error
					|| e?.message
					|| this.t('hermiq', 'Unknown error')
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
				this.retentionError =
					e?.response?.data?.error
					|| this.t(
						'hermiq',
						'Retention period must be at least 6 months.',
					)
				await this.loadRetention()
			} finally {
				this.retentionSaving = false
			}
		},
	},
}
</script>

<style scoped>
.compliance-operations {
	margin-bottom: 20px;
}

.compliance-operations__loading {
	display: flex;
	justify-content: center;
	padding: 48px 0;
}

.compliance-operations__section {
	margin-bottom: 28px;
}

.compliance-operations__subhead {
	font-size: 16px;
	font-weight: 600;
	margin: 0 0 8px;
}

/* This widget renders in the Compliance index page's below-header slot, so its
   FIRST section heading is the topmost content under the Nextcloud nav toggle
   (44px). Clear only that first heading (body + later sections keep full
   width), mirroring nc-vue's dashboard-header rule. */
.compliance-operations__section:first-of-type .compliance-operations__subhead {
	padding-inline-start: 56px;
}

.compliance-operations__note {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	margin: 4px 0 12px;
}

.compliance-operations__export-result {
	margin-top: 8px;
	color: var(--color-success);
}

.compliance-operations__section-head {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	margin-bottom: 8px;
}

.compliance-operations__incident-list {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.compliance-operations__incident {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 12px;
}

.compliance-operations__incident-description {
	margin: 0 0 4px;
	font-weight: 600;
}

.compliance-operations__retention-row {
	display: flex;
	align-items: flex-end;
	gap: 12px;
	max-width: 320px;
}
</style>
