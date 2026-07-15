<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  AlgorithmRegister — the Hermiq Settings "Algorithm register" tab
  (inapp-settings-section, delta spec on algoritmeregister-publication).

  The first dedicated UI for the algoritmeregister-publication capability:
  lists every `riskCategory: "high"` AiFeature (the only category eligible
  for Dutch Algoritmeregister publication) with its DPO-acknowledgement
  state, lifecycle state, and Algoritmeregister publication status. For an
  instance admin with OpenCatalogi installed, each row offers Publish (when
  not yet published) or Withdraw (when published), calling the same,
  unmodified `publishAiFeature()`/`withdrawAiFeature()` endpoints
  (src/api/aiFeatures.js) the AI-feature governance register already calls —
  no new backend endpoint. The server-side AlgoritmekaderMapper readiness
  gate and PublicationGateway delegation remain the single source of truth;
  `src/utils/algoritmeregisterReadiness.js` only mirrors the gate client-side
  as a disabled-button UX hint.

  Mounted as a Settings-tab widget via
  {type:"component", componentName:"AlgorithmRegister"}
  (src/customComponents.js) — brings its own heading/empty-state chrome, the
  same contract McpTools.vue/ComplianceDashboard.vue already satisfy for
  their own Settings tabs.

  @spec openspec/changes/inapp-settings-section/specs/inapp-settings-section/spec.md#requirement-a-dedicated-algorithm-register-page-must-list-publish-eligible-ai-features
  @spec openspec/changes/inapp-settings-section/specs/algoritmeregister-publication/spec.md#requirement-the-algoritmeregister-publication-capability-must-be-discoverable-via-a-dedicated-settings-page
-->
<template>
	<div class="algorithm-register">
		<h2 class="algorithm-register__heading">
			{{ t('hermiq', 'Algorithm register') }}
		</h2>
		<p class="algorithm-register__intro">
			{{ t('hermiq', 'High-risk AI features eligible for Dutch Algoritmeregister publication. Publish a feature once its Data Protection Officer has acknowledged it and every mandatory Algoritmekader field is set.') }}
		</p>

		<NcNoteCard v-if="error" type="error" :heading="t('hermiq', 'Algorithm register error')">
			{{ error }}
		</NcNoteCard>

		<NcEmptyContent
			v-if="!loading && rows.length === 0 && !error"
			:name="t('hermiq', 'No high-risk AI features yet')"
			:description="t('hermiq', 'High-risk AI features you register will appear here once seeded.')">
			<template #icon>
				<RobotIcon :size="20" />
			</template>
		</NcEmptyContent>

		<CnDataTable
			v-else
			:columns="columns"
			:rows="rows"
			:loading="loading"
			row-key="id"
			:empty-text="t('hermiq', 'No high-risk AI features yet')">
			<template #column-acknowledged="{ row }">
				<span v-if="row.acknowledged" class="algorithm-register__ack">
					{{ t('hermiq', 'Acknowledged by {uid}', { uid: row.dpoAckBy || t('hermiq', 'DPO') }) }}
				</span>
				<span v-else class="algorithm-register__ack algorithm-register__ack--missing">
					{{ t('hermiq', 'Not acknowledged') }}
				</span>
			</template>
			<template #column-lifecycle="{ row }">
				<span
					class="algorithm-register__lifecycle"
					:class="`algorithm-register__lifecycle--${row.lifecycle}`">
					{{ lifecycleLabel(row.lifecycle) }}
				</span>
			</template>
			<template #column-algoritmeregisterStatus="{ row }">
				<span
					class="algorithm-register__status"
					:class="`algorithm-register__status--${row.algoritmeregisterStatus}`">
					{{ statusLabel(row.algoritmeregisterStatus) }}
				</span>
			</template>
			<template #row-actions="{ row }">
				<div v-if="canPublish" class="algorithm-register__actions">
					<NcButton
						v-if="row.algoritmeregisterStatus !== 'gepubliceerd'"
						type="secondary"
						:disabled="busy || !isPublishReady(row.feature)"
						:title="isPublishReady(row.feature) ? '' : publishBlockedReason(row.feature)"
						:aria-label="t('hermiq', 'Publish this AI feature to the Algoritmeregister')"
						@click="doPublish(row.feature)">
						{{ t('hermiq', 'Publish to Algoritmeregister') }}
					</NcButton>
					<NcButton
						v-else
						type="tertiary"
						:disabled="busy"
						:aria-label="t('hermiq', 'Withdraw this AI feature from the Algoritmeregister')"
						@click="doWithdraw(row.feature)">
						{{ t('hermiq', 'Withdraw from Algoritmeregister') }}
					</NcButton>
				</div>
			</template>
		</CnDataTable>
	</div>
</template>

<script>
import { NcButton, NcEmptyContent, NcNoteCard } from '@nextcloud/vue'
import { CnDataTable } from '@conduction/nextcloud-vue'
import { loadState } from '@nextcloud/initial-state'
import RobotIcon from 'vue-material-design-icons/RobotOutline.vue'
import { listAiFeatures, publishAiFeature, withdrawAiFeature } from '../api/aiFeatures.js'
import { isPublishReady, missingConditions, CONDITION_DPO_ACK, CONDITION_ENABLED } from '../utils/algoritmeregisterReadiness.js'

export default {
	name: 'AlgorithmRegister',

	components: {
		CnDataTable,
		NcButton,
		NcEmptyContent,
		NcNoteCard,
		RobotIcon,
	},

	data() {
		return {
			features: [],
			loading: true,
			busy: false,
			error: '',
			// Server-provided capability flags (loadState — never a DOM read, ADR-004).
			// Mirrors the same gate the (relocating) AI-feature governance register uses.
			isAdmin: loadState('hermiq', 'is_admin', false) === true,
			opencatalogiAvailable: loadState('hermiq', 'opencatalogi_available', false) === true,
		}
	},

	computed: {
		/**
		 * Whether Publish/Withdraw actions should render at all — instance
		 * admin AND OpenCatalogi installed (mirrors AiFeatureRegister.vue's
		 * client-side UX gate; the server-side ActionAuthService gate is
		 * authoritative either way).
		 *
		 * @return {boolean}
		 */
		canPublish() {
			return this.isAdmin && this.opencatalogiAvailable
		},

		/**
		 * Column definitions for the shared index table.
		 *
		 * @return {Array<object>} CnDataTable column descriptors.
		 */
		columns() {
			return [
				{ key: 'name', label: this.t('hermiq', 'Name') },
				{ key: 'acknowledged', label: this.t('hermiq', 'DPO acknowledgement') },
				{ key: 'lifecycle', label: this.t('hermiq', 'Lifecycle') },
				{ key: 'algoritmeregisterStatus', label: this.t('hermiq', 'Algoritmeregister status') },
			]
		},

		/**
		 * Only `riskCategory: "high"` features, projected onto flat rows.
		 *
		 * @return {Array<object>} The table rows.
		 */
		rows() {
			return this.features
				.filter((feature) => feature.riskCategory === 'high')
				.map((feature) => ({
					id: feature.uuid || feature.slug,
					name: feature.name || feature.slug,
					lifecycle: feature.lifecycle || 'disabled',
					acknowledged: Boolean(feature.dpoAckAt),
					dpoAckBy: feature.dpoAckBy || '',
					algoritmeregisterStatus: feature.algoritmeregisterStatus || 'niet-gepubliceerd',
					feature,
				}))
		},
	},

	created() {
		this.load()
	},

	methods: {
		// Pure readiness-gate logic (src/utils/algoritmeregisterReadiness.js) —
		// registered as plain methods so the template can call them directly.
		isPublishReady,

		/**
		 * Load the tenant's AI features (client-filtered to high-risk in `rows`).
		 *
		 * @return {Promise<void>}
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				this.features = await listAiFeatures()
			} catch (e) {
				this.error = e?.response?.data?.error || e?.message || this.t('hermiq', 'Unknown error')
			} finally {
				this.loading = false
			}
		},

		/**
		 * The localised label for a lifecycle state.
		 *
		 * @param {string} state The lifecycle state.
		 * @return {string} The localised label.
		 */
		lifecycleLabel(state) {
			const labels = {
				disabled: this.t('hermiq', 'Disabled'),
				enabled: this.t('hermiq', 'Enabled'),
			}
			return labels[state] || state
		},

		/**
		 * The localised label for an Algoritmeregister publication status.
		 *
		 * @param {string} status The publication status.
		 * @return {string} The localised label.
		 */
		statusLabel(status) {
			const labels = {
				'niet-gepubliceerd': this.t('hermiq', 'Not published'),
				gepubliceerd: this.t('hermiq', 'Published'),
				ingetrokken: this.t('hermiq', 'Withdrawn'),
			}
			return labels[status] || status
		},

		/**
		 * Translated labels for a feature's missing publish-readiness
		 * conditions (UX hint; the server-side AlgoritmekaderMapper gate is
		 * authoritative).
		 *
		 * @param {object} feature The AiFeature record.
		 * @return {Array<string>} The failing condition labels.
		 */
		missingConditionLabels(feature) {
			const labels = {
				[CONDITION_ENABLED]: this.t('hermiq', 'feature must be enabled'),
				[CONDITION_DPO_ACK]: this.t('hermiq', 'DPO acknowledgement'),
			}
			return missingConditions(feature).map((id) => labels[id] || id)
		},

		/**
		 * A human-readable reason why publishing is blocked, for the
		 * disabled-button tooltip.
		 *
		 * @param {object} feature The AiFeature record.
		 * @return {string} The explanation.
		 */
		publishBlockedReason(feature) {
			return this.t('hermiq', 'Cannot publish yet — missing: {conditions}', {
				conditions: this.missingConditionLabels(feature).join(', '),
			})
		},

		/**
		 * Publish a feature to the national Algoritmeregister (delegated to OpenCatalogi).
		 *
		 * @param {object} feature The feature record.
		 * @return {Promise<void>}
		 */
		async doPublish(feature) {
			this.busy = true
			this.error = ''
			try {
				await publishAiFeature(feature.uuid)
				await this.load()
			} catch (e) {
				const missing = e?.response?.data?.missing
				if (Array.isArray(missing) && missing.length > 0) {
					this.error = this.t('hermiq', 'Cannot publish yet — missing: {conditions}', { conditions: missing.join(', ') })
				} else {
					this.error = e?.response?.data?.error || e?.message || this.t('hermiq', 'Unknown error')
				}
			} finally {
				this.busy = false
			}
		},

		/**
		 * Withdraw a feature from the national Algoritmeregister (intrekken).
		 *
		 * @param {object} feature The feature record.
		 * @return {Promise<void>}
		 */
		async doWithdraw(feature) {
			this.busy = true
			this.error = ''
			try {
				await withdrawAiFeature(feature.uuid)
				await this.load()
			} catch (e) {
				this.error = e?.response?.data?.error || e?.message || this.t('hermiq', 'Unknown error')
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.algorithm-register {
	padding: 0;
}

.algorithm-register__heading {
	margin: 0 0 8px;
	font-size: 22px;
	font-weight: 600;
}

.algorithm-register__intro {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	margin: 0 0 16px;
}

.algorithm-register__lifecycle,
.algorithm-register__status {
	font-size: 12px;
	text-transform: uppercase;
	width: fit-content;
	padding: 1px 6px;
	border-radius: 4px;
	background: var(--color-background-dark);
	display: inline-block;
}

.algorithm-register__lifecycle--enabled {
	color: var(--color-success);
}

.algorithm-register__lifecycle--disabled {
	color: var(--color-text-maxcontrast);
}

.algorithm-register__status--gepubliceerd {
	color: var(--color-success);
}

.algorithm-register__status--ingetrokken {
	color: var(--color-warning);
}

.algorithm-register__ack {
	font-size: 13px;
	color: var(--color-success);
}

.algorithm-register__ack--missing {
	color: var(--color-text-maxcontrast);
}

.algorithm-register__actions {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
}
</style>
