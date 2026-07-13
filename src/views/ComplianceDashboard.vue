<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  ComplianceDashboard — the Hermiq "Compliance" nav page (compliance-control-packs).

  Org-scoped compliance-control-pack mapping: per-framework (EU AI Act, ISO/IEC 42001,
  NIST AI RMF) coverage percentage and the gap list (every control not `satisfied`),
  computed live from Hermiq's own existing governance data — never a hand-ticked
  status. An auditor's-pack export button reuses TenantOps.vue's exact
  downloadJson()/Blob-download pattern. Gated server-side by
  `compliance.view-dashboard`/`compliance.export-pack` (ActionAuthService, ADR-023);
  the frontend detects a 403 from the dashboard read itself (no separate capability
  flag) and shows an "Organisation admins only" empty state, mirroring TenantOps.vue.

  @spec openspec/changes/compliance-control-packs/tasks.md#task-6-frontend-compliance-dashboard-page
  @spec openspec/changes/compliance-control-packs/specs/compliance-control-packs/spec.md#requirement-a-compliance-dashboard-shows-per-framework-coverage-and-the-gap-list
-->
<template>
	<div class="compliance-dashboard">
		<h2 class="compliance-dashboard__heading">
			{{ t('hermiq', 'Compliance') }}
		</h2>

		<NcEmptyContent
			v-if="forbidden"
			:name="t('hermiq', 'Organisation admins only')"
			:description="t('hermiq', 'The compliance dashboard and export are available to organisation owners and instance admins.')">
			<template #icon>
				<ShieldIcon :size="20" />
			</template>
		</NcEmptyContent>

		<template v-else>
			<NcNoteCard v-if="error" type="error" :heading="t('hermiq', 'Compliance dashboard error')">
				{{ error }}
			</NcNoteCard>

			<p class="compliance-dashboard__disclaimer">
				{{ t('hermiq', '"Satisfied" means Hermiq can evidence this control from its own governance data — it is not a certification of compliance by a qualified auditor.') }}
			</p>

			<div v-if="loading" class="compliance-dashboard__loading">
				<NcLoadingIcon :size="32" />
			</div>

			<template v-else>
				<section v-for="framework in frameworks" :key="framework.slug" class="compliance-dashboard__section">
					<div class="compliance-dashboard__section-head">
						<h3 class="compliance-dashboard__subhead">
							{{ framework.name }}
						</h3>
						<span class="compliance-dashboard__coverage">
							{{ t('hermiq', '{percent}% coverage', { percent: framework.coveragePercent }) }}
						</span>
					</div>

					<CnDataTable
						:columns="controlColumns"
						:rows="framework.controls"
						row-key="controlId"
						:empty-text="t('hermiq', 'No controls seeded for this framework yet.')">
						<template #column-status="{ row }">
							<span
								class="compliance-dashboard__status"
								:class="`compliance-dashboard__status--${row.status}`">
								{{ statusLabel(row.status) }}
							</span>
						</template>
						<template #column-sourceUrl="{ row }">
							<a
								v-if="row.sourceUrl"
								:href="row.sourceUrl"
								target="_blank"
								rel="noopener noreferrer">
								{{ t('hermiq', 'Source') }}
							</a>
							<span v-else>—</span>
						</template>
					</CnDataTable>
				</section>

				<section class="compliance-dashboard__section">
					<h3 class="compliance-dashboard__subhead">
						{{ t('hermiq', 'Gap list') }}
					</h3>
					<p v-if="gaps.length === 0" class="compliance-dashboard__note">
						{{ t('hermiq', 'Every seeded control is currently satisfied.') }}
					</p>
					<ul v-else class="compliance-dashboard__gap-list">
						<li v-for="gap in gaps" :key="`${gap.frameworkSlug}-${gap.controlId}`" class="compliance-dashboard__gap">
							<span
								class="compliance-dashboard__status"
								:class="`compliance-dashboard__status--${gap.status}`">
								{{ statusLabel(gap.status) }}
							</span>
							<strong>{{ gap.title }}</strong>
							<p class="compliance-dashboard__note">
								{{ gap.detail }}
							</p>
						</li>
					</ul>
				</section>

				<section class="compliance-dashboard__section">
					<h3 class="compliance-dashboard__subhead">
						{{ t('hermiq', "Auditor's pack") }}
					</h3>
					<p class="compliance-dashboard__note">
						{{ t('hermiq', "Download the EU AI Act audit export together with this organisation's compliance coverage, in one JSON file.") }}
					</p>
					<NcButton
						type="primary"
						:disabled="exporting"
						:aria-label="t('hermiq', 'Export auditor\'s pack')"
						@click="exportPack">
						<template v-if="exporting" #icon>
							<NcLoadingIcon :size="18" />
						</template>
						{{ t('hermiq', "Export auditor's pack") }}
					</NcButton>
				</section>
			</template>
		</template>
	</div>
</template>

<script>
import { NcButton, NcEmptyContent, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import { CnDataTable } from '@conduction/nextcloud-vue'
import ShieldIcon from 'vue-material-design-icons/ShieldLockOutline.vue'
import { getComplianceDashboard, getComplianceExport } from '../api/compliance.js'

export default {
	name: 'ComplianceDashboard',

	components: {
		CnDataTable,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		ShieldIcon,
	},

	data() {
		return {
			frameworks: [],
			gaps: [],
			loading: true,
			forbidden: false,
			error: '',
			exporting: false,
		}
	},

	computed: {
		/**
		 * Column definitions for each framework's control CnDataTable.
		 *
		 * @return {Array<object>} CnDataTable column descriptors.
		 */
		controlColumns() {
			return [
				{ key: 'controlId', label: this.t('hermiq', 'Control') },
				{ key: 'title', label: this.t('hermiq', 'Title') },
				{ key: 'status', label: this.t('hermiq', 'Status') },
				{ key: 'detail', label: this.t('hermiq', 'Evidence') },
				{ key: 'sourceUrl', label: this.t('hermiq', 'Source') },
			]
		},
	},

	created() {
		this.load()
	},

	methods: {
		/**
		 * Load the compliance dashboard; a 403 renders the "admins only" empty
		 * state rather than a raw error (the real gate is server-side).
		 *
		 * @return {Promise<void>}
		 */
		async load() {
			this.loading = true
			this.error = ''
			this.forbidden = false
			try {
				const data = await getComplianceDashboard()
				this.frameworks = Array.isArray(data.frameworks) ? data.frameworks : []
				this.gaps = Array.isArray(data.gaps) ? data.gaps : []
			} catch (e) {
				if (e?.response?.status === 403) {
					this.forbidden = true
				} else {
					this.error = e?.response?.data?.error || e?.message || this.t('hermiq', 'Unknown error')
				}
			} finally {
				this.loading = false
			}
		},

		/**
		 * Fetch the auditor's-pack export and download it as a JSON file (same
		 * Blob-download pattern as TenantOps.vue's exportAudit()).
		 *
		 * @return {Promise<void>}
		 */
		async exportPack() {
			this.exporting = true
			this.error = ''
			try {
				const data = await getComplianceExport()
				this.downloadJson(data, 'hermiq-compliance-auditor-pack.json')
			} catch (e) {
				this.error = e?.response?.data?.error || e?.message || this.t('hermiq', 'Unknown error')
			} finally {
				this.exporting = false
			}
		},

		/**
		 * Trigger a client-side download of a JSON object (mirrors TenantOps.vue).
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
		 * Human label for a computed control status.
		 *
		 * @param {string} status One of satisfied/partial/unevidenced.
		 * @return {string} The label.
		 */
		statusLabel(status) {
			const labels = {
				satisfied: this.t('hermiq', 'Satisfied'),
				partial: this.t('hermiq', 'Partial'),
				unevidenced: this.t('hermiq', 'Unevidenced'),
			}
			return labels[status] || status
		},
	},
}
</script>

<style scoped>
.compliance-dashboard {
	padding: 20px;
	max-width: 900px;
	margin: 0 auto;
}

.compliance-dashboard__heading {
	margin: 0 0 16px;
	font-size: 22px;
	font-weight: 600;
}

.compliance-dashboard__disclaimer {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	margin: 0 0 16px;
}

.compliance-dashboard__loading {
	display: flex;
	justify-content: center;
	padding: 48px 0;
}

.compliance-dashboard__section {
	margin-bottom: 28px;
}

.compliance-dashboard__section-head {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	margin-bottom: 8px;
}

.compliance-dashboard__subhead {
	font-size: 16px;
	font-weight: 600;
	margin: 0;
}

.compliance-dashboard__coverage {
	color: var(--color-text-maxcontrast);
	font-weight: 600;
}

.compliance-dashboard__note {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	margin: 4px 0 12px;
}

.compliance-dashboard__status {
	font-size: 12px;
	text-transform: uppercase;
	width: fit-content;
	padding: 1px 6px;
	border-radius: 4px;
	background: var(--color-background-dark);
	display: inline-block;
}

.compliance-dashboard__status--satisfied {
	color: var(--color-success);
}

.compliance-dashboard__status--partial {
	color: var(--color-warning);
}

.compliance-dashboard__status--unevidenced {
	color: var(--color-error);
}

.compliance-dashboard__gap-list {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.compliance-dashboard__gap {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 12px;
}
</style>
