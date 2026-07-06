<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  AiFeatureRegister — the Hermiq "AI features" nav page (ai-feature-governance-register).

  A design-time inventory of the platform's high-risk AI features (EU AI Act
  registration/oversight): each row shows the feature name, its risk-category badge
  (minimal/limited/high/unacceptable), the lifecycle state (disabled/enabled), and the
  DPO acknowledgement. Per-row governance actions run through the action-auth-gated
  AiFeatureController (src/api/aiFeatures.js) — no new write path, no custom Pinia store:
    - "Acknowledge (DPO)" records the DPO acknowledgement.
    - "Enable" drives the OpenRegister lifecycle transition; it is disabled until the DPO
      has acknowledged the feature, and the server-side guard refuses it (409) otherwise.
    - "Disable" is the unguarded reverse transition.

  A standard nav page — NOT a dashboard (dashboard-antipattern gate). All strings via
  t(); server data is fetched through the tenant-scoped API (no DOM reads).

  @spec openspec/changes/ai-feature-governance-register/tasks.md#task-4-2
  @spec openspec/changes/ai-feature-governance-register/specs/ai-feature-governance-register/spec.md
-->
<template>
	<div class="ai-feature-register">
		<div class="ai-feature-register__header">
			<h2 class="ai-feature-register__heading">
				{{ t('hermiq', 'AI features') }}
			</h2>
			<p class="ai-feature-register__intro">
				{{ t('hermiq', 'The design-time inventory of high-risk AI features this platform provides. A feature can only be enabled after the Data Protection Officer has acknowledged it.') }}
			</p>
		</div>

		<NcNoteCard v-if="error" type="error" :heading="t('hermiq', 'AI features error')">
			{{ error }}
		</NcNoteCard>

		<section class="ai-feature-register__section">
			<h3 class="ai-feature-register__subhead">
				{{ t('hermiq', 'AI features') }} ({{ features.length }})
			</h3>

			<NcEmptyContent
				v-if="!loading && features.length === 0"
				:name="t('hermiq', 'No AI features yet')"
				:description="t('hermiq', 'AI features are registered by the platform and appear here once seeded.')">
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
				:empty-text="t('hermiq', 'No AI features yet')">
				<template #column-riskCategory="{ row }">
					<span
						class="ai-feature-register__risk"
						:class="`ai-feature-register__risk--${row.riskCategory}`">
						{{ riskLabel(row.riskCategory) }}
					</span>
				</template>
				<template #column-lifecycle="{ row }">
					<span
						class="ai-feature-register__lifecycle"
						:class="`ai-feature-register__lifecycle--${row.lifecycle}`">
						{{ lifecycleLabel(row.lifecycle) }}
					</span>
				</template>
				<template #column-acknowledged="{ row }">
					<span v-if="row.acknowledged" class="ai-feature-register__ack">
						{{ t('hermiq', 'Acknowledged by {uid}', { uid: row.dpoAckBy || t('hermiq', 'DPO') }) }}
					</span>
					<span v-else class="ai-feature-register__ack ai-feature-register__ack--missing">
						{{ t('hermiq', 'Not acknowledged') }}
					</span>
				</template>
				<template #row-actions="{ row }">
					<div class="ai-feature-register__actions">
						<NcButton
							type="secondary"
							:disabled="busy || row.acknowledged"
							:aria-label="t('hermiq', 'Acknowledge this AI feature as DPO')"
							@click="doAcknowledge(row.feature)">
							{{ t('hermiq', 'Acknowledge (DPO)') }}
						</NcButton>
						<NcButton
							type="primary"
							:disabled="busy || !row.acknowledged || row.lifecycle === 'enabled'"
							:aria-label="t('hermiq', 'Enable this AI feature')"
							@click="doEnable(row.feature)">
							{{ t('hermiq', 'Enable') }}
						</NcButton>
						<NcButton
							type="tertiary"
							:disabled="busy || row.lifecycle !== 'enabled'"
							:aria-label="t('hermiq', 'Disable this AI feature')"
							@click="doDisable(row.feature)">
							{{ t('hermiq', 'Disable') }}
						</NcButton>
					</div>
				</template>
			</CnDataTable>
		</section>
	</div>
</template>

<script>
import { NcButton, NcEmptyContent, NcNoteCard } from '@nextcloud/vue'
import { CnDataTable } from '@conduction/nextcloud-vue'
import RobotIcon from 'vue-material-design-icons/RobotOutline.vue'
import { acknowledgeAiFeature, disableAiFeature, enableAiFeature, listAiFeatures } from '../api/aiFeatures.js'

export default {
	name: 'AiFeatureRegister',

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
		}
	},

	computed: {
		/**
		 * Column definitions for the shared index table.
		 *
		 * @return {Array<object>} CnDataTable column descriptors.
		 */
		columns() {
			return [
				{ key: 'name', label: this.t('hermiq', 'Name') },
				{ key: 'riskCategory', label: this.t('hermiq', 'Risk category') },
				{ key: 'lifecycle', label: this.t('hermiq', 'State') },
				{ key: 'acknowledged', label: this.t('hermiq', 'DPO acknowledgement') },
			]
		},

		/**
		 * Features projected onto flat rows for the index table.
		 *
		 * @return {Array<object>} The table rows.
		 */
		rows() {
			return this.features.map((feature) => ({
				id: feature.uuid || feature.slug,
				name: feature.name || feature.slug,
				riskCategory: feature.riskCategory || 'minimal',
				lifecycle: feature.lifecycle || 'disabled',
				acknowledged: Boolean(feature.dpoAckAt),
				dpoAckBy: feature.dpoAckBy || '',
				feature,
			}))
		},
	},

	created() {
		this.load()
	},

	methods: {
		/**
		 * Load the tenant's AI features.
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
		 * The localised label for a risk category.
		 *
		 * @param {string} category The risk category.
		 * @return {string} The localised label.
		 */
		riskLabel(category) {
			const labels = {
				minimal: this.t('hermiq', 'Minimal'),
				limited: this.t('hermiq', 'Limited'),
				high: this.t('hermiq', 'High'),
				unacceptable: this.t('hermiq', 'Unacceptable'),
			}
			return labels[category] || category
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
		 * Record the DPO acknowledgement for a feature.
		 *
		 * @param {object} feature The feature record.
		 * @return {Promise<void>}
		 */
		async doAcknowledge(feature) {
			this.busy = true
			this.error = ''
			try {
				await acknowledgeAiFeature(feature.slug)
				await this.load()
			} catch (e) {
				this.error = e?.response?.data?.error || e?.message || this.t('hermiq', 'Unknown error')
			} finally {
				this.busy = false
			}
		},

		/**
		 * Enable a feature (refused until the DPO has acknowledged it).
		 *
		 * @param {object} feature The feature record.
		 * @return {Promise<void>}
		 */
		async doEnable(feature) {
			this.busy = true
			this.error = ''
			try {
				await enableAiFeature(feature.uuid)
				await this.load()
			} catch (e) {
				this.error = e?.response?.data?.error || e?.message || this.t('hermiq', 'Unknown error')
			} finally {
				this.busy = false
			}
		},

		/**
		 * Disable a feature (unguarded reverse transition).
		 *
		 * @param {object} feature The feature record.
		 * @return {Promise<void>}
		 */
		async doDisable(feature) {
			this.busy = true
			this.error = ''
			try {
				await disableAiFeature(feature.uuid)
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
.ai-feature-register {
	padding: 20px;
	max-width: 960px;
	margin: 0 auto;
}

.ai-feature-register__heading {
	margin: 0 0 8px;
	font-size: 22px;
	font-weight: 600;
}

.ai-feature-register__intro {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	margin: 0 0 16px;
}

.ai-feature-register__subhead {
	font-size: 16px;
	font-weight: 600;
	margin: 0 0 8px;
}

.ai-feature-register__section {
	margin-bottom: 24px;
}

.ai-feature-register__risk,
.ai-feature-register__lifecycle {
	font-size: 12px;
	text-transform: uppercase;
	width: fit-content;
	padding: 1px 6px;
	border-radius: 4px;
	background: var(--color-background-dark);
}

.ai-feature-register__risk--minimal {
	color: var(--color-success);
}

.ai-feature-register__risk--limited {
	color: var(--color-warning);
}

.ai-feature-register__risk--high {
	color: var(--color-error);
}

.ai-feature-register__risk--unacceptable {
	color: var(--color-error);
	background: var(--color-error-hover, var(--color-background-dark));
}

.ai-feature-register__lifecycle--enabled {
	color: var(--color-success);
}

.ai-feature-register__lifecycle--disabled {
	color: var(--color-text-maxcontrast);
}

.ai-feature-register__ack {
	font-size: 13px;
	color: var(--color-success);
}

.ai-feature-register__ack--missing {
	color: var(--color-text-maxcontrast);
}

.ai-feature-register__actions {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
}
</style>
