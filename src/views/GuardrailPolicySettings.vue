<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  GuardrailPolicySettings — the Hermiq Settings "Guardrail policy" tab
  (agent-guardrails, inapp-settings-section).

  Per-organisation input/output content filters (PII/secret redaction or
  block, prompt-injection block) and per-tool risk classification
  (auto/confirm/deny); the instance default applies when an organisation has
  no policy of its own, and a fully-open fallback applies when neither
  exists. Extracted from TenantOps.vue's own "Guardrail policy" section —
  administration of this policy now lives in exactly one place (Settings),
  not duplicated on Tenant ops. Reuses the unmodified src/api/guardrailPolicy.js;
  the underlying GuardrailPolicyController authorization (mayAdminister()) is
  unchanged by this move.

  Mounted as a Settings-tab widget via
  {type:"component", componentName:"GuardrailPolicySettings"}
  (src/customComponents.js) — brings its own heading/empty-state chrome, the
  same contract McpTools.vue/ComplianceDashboard.vue already satisfy for
  their own Settings tabs.

  @spec openspec/changes/inapp-settings-section/specs/inapp-settings-section/spec.md#requirement-guardrail-policy-administration-must-exist-in-exactly-one-place
  @spec openspec/changes/agent-guardrails/specs/agent-guardrails/spec.md
-->
<template>
	<div class="guardrail-policy-settings">
		<h2 class="guardrail-policy-settings__heading">
			{{ t('hermiq', 'Guardrail policy') }}
		</h2>

		<NcEmptyContent
			v-if="!canManage"
			:name="t('hermiq', 'Organisation admins only')"
			:description="t('hermiq', 'Guardrail policy administration is available to organisation owners and instance admins.')">
			<template #icon>
				<ShieldIcon :size="20" />
			</template>
		</NcEmptyContent>

		<template v-else>
			<p class="guardrail-policy-settings__intro">
				{{ t('hermiq', 'Per-organisation content filters (PII/secret redaction or block, prompt-injection block) and per-tool risk classification for agent input and output.') }}
			</p>

			<NcNoteCard v-if="guardrailPolicyError" type="error" :heading="t('hermiq', 'Guardrail policy error')">
				{{ guardrailPolicyError }}
			</NcNoteCard>

			<p v-if="guardrailPolicies.length === 0 && !guardrailPolicyError" class="guardrail-policy-settings__note">
				{{ t('hermiq', 'No guardrail policies configured — agents run with every filter off and every tool auto-approved.') }}
			</p>

			<div v-for="policy in guardrailPolicies" :key="policy.id" class="guardrail-policy-settings__policy">
				<div class="guardrail-policy-settings__policy-head">
					<strong>{{ policy.organisation ? organisationLabel(policy.organisation, organisations) : t('hermiq', 'Instance default') }}</strong>
					<NcButton type="tertiary" @click="toggleGuardrailPolicyEdit(policy)">
						{{ editingGuardrailPolicyId === policy.id ? t('hermiq', 'Cancel') : t('hermiq', 'Edit') }}
					</NcButton>
				</div>
				<p v-if="editingGuardrailPolicyId !== policy.id" class="guardrail-policy-settings__note">
					{{ guardrailPolicySummary(policy) }}
				</p>
				<div v-else class="guardrail-policy-settings__policy-edit">
					<NcSelect
						:value="guardrailActionOption(guardrailPolicyDraft.inputPiiAction, piiActionOptions)"
						:options="piiActionOptions"
						:input-label="t('hermiq', 'Input: PII/secret action')"
						:clearable="false"
						@input="(option) => { guardrailPolicyDraft.inputPiiAction = option ? option.value : 'off' }" />
					<NcSelect
						:value="guardrailActionOption(guardrailPolicyDraft.inputPromptInjectionAction, injectionActionOptions)"
						:options="injectionActionOptions"
						:input-label="t('hermiq', 'Input: prompt-injection action')"
						:clearable="false"
						@input="(option) => { guardrailPolicyDraft.inputPromptInjectionAction = option ? option.value : 'off' }" />
					<NcSelect
						:value="guardrailActionOption(guardrailPolicyDraft.outputPiiAction, piiActionOptions)"
						:options="piiActionOptions"
						:input-label="t('hermiq', 'Output: PII/secret action')"
						:clearable="false"
						@input="(option) => { guardrailPolicyDraft.outputPiiAction = option ? option.value : 'off' }" />
					<NcTextArea
						:value.sync="guardrailPolicyDraft.toolPolicyText"
						:label="t('hermiq', 'Per-tool classification')"
						:placeholder="t('hermiq', 'One per line: toolId: auto|confirm|deny')"
						resize="vertical" />
					<div class="guardrail-policy-settings__actions">
						<NcButton type="primary" :disabled="guardrailPolicySaving" @click="saveGuardrailPolicy(policy)">
							{{ t('hermiq', 'Save policy') }}
						</NcButton>
					</div>
				</div>
			</div>
		</template>
	</div>
</template>

<script>
import { NcButton, NcEmptyContent, NcNoteCard, NcSelect, NcTextArea } from '@nextcloud/vue'
import { loadState } from '@nextcloud/initial-state'
import { showError, showSuccess } from '@nextcloud/dialogs'
import ShieldIcon from 'vue-material-design-icons/ShieldLockOutline.vue'
import { listGuardrailPolicies, updateGuardrailPolicy } from '../api/guardrailPolicy.js'
import { organisationLabel } from '../utils/organisationLabel.js'

export default {
	name: 'GuardrailPolicySettings',

	components: {
		NcButton,
		NcEmptyContent,
		NcNoteCard,
		NcSelect,
		NcTextArea,
		ShieldIcon,
	},

	data() {
		// Capability + manageable organisations come from the backend via
		// IInitialState (loadState) — never a DOM data-attribute read (ADR-004).
		const organisations = loadState('hermiq', 'managed_organisations', [])
		return {
			canManage: loadState('hermiq', 'can_manage_killswitch', false) === true,
			organisations: Array.isArray(organisations) ? organisations : [],
			guardrailPolicies: [],
			guardrailPolicyError: '',
			editingGuardrailPolicyId: null,
			guardrailPolicyDraft: {
				inputPiiAction: 'off',
				inputPromptInjectionAction: 'off',
				outputPiiAction: 'off',
				toolPolicyText: '',
			},
			guardrailPolicySaving: false,
		}
	},

	computed: {
		/**
		 * The `piiAction` NcSelect options shared by the input and output
		 * PII/secret action pickers.
		 *
		 * @return {Array<object>} The { label, value } options.
		 */
		piiActionOptions() {
			return [
				{ label: this.t('hermiq', 'Off'), value: 'off' },
				{ label: this.t('hermiq', 'Redact'), value: 'redact' },
				{ label: this.t('hermiq', 'Block'), value: 'block' },
			]
		},

		/**
		 * The `promptInjectionAction` NcSelect options (no `redact` — an
		 * override attempt can only be refused, never partially masked).
		 *
		 * @return {Array<object>} The { label, value } options.
		 */
		injectionActionOptions() {
			return [
				{ label: this.t('hermiq', 'Off'), value: 'off' },
				{ label: this.t('hermiq', 'Block'), value: 'block' },
			]
		},
	},

	created() {
		if (this.canManage) {
			this.loadGuardrailPolicies()
		}
	},

	methods: {
		// Shared org-id → label lookup (src/utils/organisationLabel.js) —
		// registered as a plain method so the template can call it directly.
		organisationLabel,

		/**
		 * Load the caller-visible guardrail policies (agent-guardrails).
		 *
		 * @return {Promise<void>}
		 */
		async loadGuardrailPolicies() {
			this.guardrailPolicyError = ''
			try {
				this.guardrailPolicies = await listGuardrailPolicies()
			} catch (e) {
				this.guardrailPolicyError = e?.response?.data?.error || e?.message || this.t('hermiq', 'Unknown error')
			}
		},

		/**
		 * Resolve the NcSelect option object matching a stored action value.
		 *
		 * @param {string} value The stored action value.
		 * @param {Array<object>} options The option list to search.
		 * @return {object} The matching option, or the first option as a fallback.
		 */
		guardrailActionOption(value, options) {
			return options.find((option) => option.value === value) || options[0]
		},

		/**
		 * One-line summary of a guardrail policy's active filters and tool rules.
		 *
		 * @param {object} policy The GuardrailPolicy record.
		 * @return {string} The summary line.
		 */
		guardrailPolicySummary(policy) {
			const input = policy.inputFilters || {}
			const output = policy.outputFilters || {}
			const toolPolicy = Array.isArray(policy.toolPolicy) ? policy.toolPolicy : []
			const parts = [
				`${this.t('hermiq', 'Input')}: ${this.t('hermiq', 'PII')} ${input.piiAction || 'off'}, ${this.t('hermiq', 'prompt injection')} ${input.promptInjectionAction || 'off'}`,
				`${this.t('hermiq', 'Output')}: ${this.t('hermiq', 'PII')} ${output.piiAction || 'off'}`,
			]
			if (toolPolicy.length > 0) {
				parts.push(`${toolPolicy.length} ${this.t('hermiq', 'tool rules')}`)
			}
			if (policy.enabled === false) {
				return `${this.t('hermiq', 'Disabled')} — ${parts.join(' · ')}`
			}
			return parts.join(' · ')
		},

		/**
		 * Open/close the inline editor for a guardrail policy, seeding the draft
		 * from it. Tool-policy draft format: one line per rule —
		 * `toolId: classification`.
		 *
		 * @param {object} policy The GuardrailPolicy record.
		 * @return {void}
		 */
		toggleGuardrailPolicyEdit(policy) {
			if (this.editingGuardrailPolicyId === policy.id) {
				this.editingGuardrailPolicyId = null
				return
			}
			const toolPolicy = Array.isArray(policy.toolPolicy) ? policy.toolPolicy : []
			this.guardrailPolicyDraft = {
				inputPiiAction: (policy.inputFilters && policy.inputFilters.piiAction) || 'off',
				inputPromptInjectionAction: (policy.inputFilters && policy.inputFilters.promptInjectionAction) || 'off',
				outputPiiAction: (policy.outputFilters && policy.outputFilters.piiAction) || 'off',
				toolPolicyText: toolPolicy.map((entry) => `${entry.toolId}: ${entry.classification}`).join('\n'),
			}
			this.editingGuardrailPolicyId = policy.id
		},

		/**
		 * Persist the inline guardrail-policy draft via
		 * PUT /api/guardrail-policies/{uuid}.
		 *
		 * @param {object} policy The GuardrailPolicy record being edited.
		 * @return {Promise<void>}
		 */
		async saveGuardrailPolicy(policy) {
			const toolPolicy = this.guardrailPolicyDraft.toolPolicyText
				.split('\n')
				.map((line) => line.trim())
				.filter((line) => line !== '')
				.map((line) => {
					const [toolId, classification] = line.split(':')
					return {
						toolId: (toolId || '').trim(),
						classification: (classification || 'auto').trim(),
					}
				})
				.filter((entry) => entry.toolId !== '')
			this.guardrailPolicySaving = true
			try {
				await updateGuardrailPolicy(policy.id, {
					inputFilters: {
						piiAction: this.guardrailPolicyDraft.inputPiiAction,
						promptInjectionAction: this.guardrailPolicyDraft.inputPromptInjectionAction,
					},
					outputFilters: {
						piiAction: this.guardrailPolicyDraft.outputPiiAction,
					},
					toolPolicy,
					enabled: policy.enabled !== false,
				})
				showSuccess(this.t('hermiq', 'Guardrail policy saved.'))
				this.editingGuardrailPolicyId = null
				await this.loadGuardrailPolicies()
			} catch (e) {
				showError(e?.response?.data?.error || this.t('hermiq', 'Could not save the guardrail policy.'))
			} finally {
				this.guardrailPolicySaving = false
			}
		},
	},
}
</script>

<style scoped>
.guardrail-policy-settings {
	padding: 0;
}

.guardrail-policy-settings__heading {
	margin: 0 0 8px;
	font-size: 22px;
	font-weight: 600;
}

.guardrail-policy-settings__intro {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	margin: 0 0 16px;
}

.guardrail-policy-settings__note {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	margin: 4px 0 12px;
}

.guardrail-policy-settings__policy {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 12px;
	margin-bottom: 8px;
}

.guardrail-policy-settings__policy-head {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
}

.guardrail-policy-settings__policy-edit {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin-top: 8px;
}

.guardrail-policy-settings__actions {
	display: flex;
	gap: 8px;
	margin-top: 4px;
}
</style>
