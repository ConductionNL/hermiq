<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  BudgetFormModal — create or edit a Budget guardrail (cost-guardrails).

  Own file per ADR-004 modal-isolation, using NcDialog (design.md). Persists via
  src/api/budgets.js's thin, admin/owner-gated BudgetController endpoints — NOT the
  generic createObjectStore object path (write access needs the org-owner/instance-
  admin check TenantControlController already established, which the generic OR
  objects path does not express). Every NcSelect carries an `inputLabel`
  (nc-input-labels accessibility gate; WCAG 2.1 AA). Numeric fields use NcTextField
  type="number", mirroring the existing AgentFormModal/ScheduleFormModal convention
  in this app (a documented, defensible substitute for design.md's NcInputField —
  no NcInputField usage exists elsewhere in Hermiq's frontend).

  @spec openspec/specs/multi-tenant-ops/spec.md#requirement-per-scope-budget-guardrails-soft-threshold-and-hard-cap
-->
<template>
	<NcDialog
		:name="heading"
		:open="show"
		size="normal"
		@update:open="$emit('close')">
		<div class="budget-form">
			<NcNoteCard v-if="error" type="error" :heading="t('hermiq', 'Could not save budget')">
				{{ error }}
			</NcNoteCard>

			<div class="budget-form__field">
				<NcSelect
					v-model="scopeOption"
					:input-label="t('hermiq', 'Scope')"
					:options="scopeOptions"
					:clearable="false"
					:disabled="isEdit"
					label="label"
					track-by="value" />
			</div>

			<NcTextField
				v-if="form.scope === 'agent'"
				v-model="form.agentId"
				:label="t('hermiq', 'Agent UUID')"
				:disabled="isEdit"
				:placeholder="t('hermiq', 'The agent this budget guards')"
				required />

			<div class="budget-form__field">
				<NcSelect
					v-model="periodOption"
					:input-label="t('hermiq', 'Period')"
					:options="periodOptions"
					:clearable="false"
					label="label"
					track-by="value" />
			</div>

			<div class="budget-form__row">
				<NcTextField
					v-model="form.tokenLimit"
					type="number"
					:label="t('hermiq', 'Token limit')"
					placeholder="2000000" />
				<NcTextField
					v-model="form.eurLimit"
					type="number"
					:label="t('hermiq', 'EUR limit (needs an instance-wide rate)')"
					placeholder="" />
			</div>
			<p class="budget-form__hint">
				{{ t('hermiq', 'At least one of token limit or EUR limit is required.') }}
			</p>

			<NcTextField
				v-model="form.softThresholdPercent"
				type="number"
				:label="t('hermiq', 'Soft-threshold warning (%)')"
				placeholder="80" />

			<NcCheckboxRadioSwitch v-model="form.enabled" type="switch">
				{{ t('hermiq', 'Enabled') }}
			</NcCheckboxRadioSwitch>
		</div>

		<template #actions>
			<NcButton :disabled="saving" @click="$emit('close')">
				{{ t('hermiq', 'Cancel') }}
			</NcButton>
			<NcButton
				type="primary"
				:disabled="saving || !canSave"
				@click="save">
				<template v-if="saving" #icon>
					<NcLoadingIcon :size="20" />
				</template>
				{{ t('hermiq', 'Save') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcCheckboxRadioSwitch, NcDialog, NcLoadingIcon, NcNoteCard, NcSelect, NcTextField } from '@nextcloud/vue'
import { createBudget, updateBudget } from '../api/budgets.js'

export default {
	name: 'BudgetFormModal',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcDialog,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
		NcTextField,
	},

	props: {
		/** Whether the modal is visible. */
		show: {
			type: Boolean,
			default: false,
		},
		/** The organisation this budget belongs to (required to create). */
		organisation: {
			type: String,
			required: true,
		},
		/** The budget being edited, or null when creating. */
		budget: {
			type: Object,
			default: null,
		},
	},

	emits: ['close', 'saved'],

	data() {
		return {
			form: this.blankForm(),
			saving: false,
			error: '',
			scopeOptions: [
				{ label: this.t('hermiq', 'Organisation'), value: 'organisation' },
				{ label: this.t('hermiq', 'Agent'), value: 'agent' },
			],
			periodOptions: [
				{ label: this.t('hermiq', 'Daily'), value: 'daily' },
				{ label: this.t('hermiq', 'Weekly'), value: 'weekly' },
				{ label: this.t('hermiq', 'Monthly'), value: 'monthly' },
			],
		}
	},

	computed: {
		/**
		 * Whether this modal is editing an existing budget (scope/agentId become
		 * immutable — changing them is a delete + recreate, not an edit).
		 *
		 * @return {boolean}
		 */
		isEdit() {
			return this.budget !== null
		},

		/**
		 * Modal heading — differs for create vs edit.
		 *
		 * @return {string} The localised heading.
		 */
		heading() {
			return this.isEdit ? this.t('hermiq', 'Edit budget') : this.t('hermiq', 'Create budget')
		},

		/**
		 * Whether the form has enough to submit: a period, and at least one of
		 * tokenLimit/eurLimit, and (when scope=agent) an agentId.
		 *
		 * @return {boolean}
		 */
		canSave() {
			const hasLimit = (this.form.tokenLimit !== '' && this.form.tokenLimit !== null)
				|| (this.form.eurLimit !== '' && this.form.eurLimit !== null)
			const hasAgent = this.form.scope !== 'agent' || !!this.form.agentId
			return hasLimit && hasAgent
		},

		/**
		 * Two-way bridge between the `scope` string and the NcSelect option object.
		 */
		scopeOption: {
			get() {
				return this.scopeOptions.find((option) => option.value === this.form.scope) || this.scopeOptions[0]
			},
			set(option) {
				this.form.scope = option ? option.value : 'organisation'
			},
		},

		/**
		 * Two-way bridge between the `period` string and the NcSelect option object.
		 */
		periodOption: {
			get() {
				return this.periodOptions.find((option) => option.value === this.form.period) || this.periodOptions[2]
			},
			set(option) {
				this.form.period = option ? option.value : 'monthly'
			},
		},
	},

	watch: {
		show(open) {
			if (open) {
				this.resetForm()
			}
		},
	},

	methods: {
		/**
		 * An empty budget form.
		 *
		 * @return {object} The blank form model.
		 */
		blankForm() {
			return {
				scope: 'organisation',
				agentId: '',
				period: 'monthly',
				tokenLimit: '',
				eurLimit: '',
				softThresholdPercent: 80,
				enabled: true,
			}
		},

		/**
		 * Seed the form from the `budget` prop (edit) or blank (create).
		 *
		 * @return {void}
		 */
		resetForm() {
			this.error = ''
			if (!this.budget) {
				this.form = this.blankForm()
				return
			}
			const source = this.budget
			this.form = {
				scope: source.scope || 'organisation',
				agentId: source.agentId || '',
				period: source.period || 'monthly',
				tokenLimit: source.tokenLimit ?? '',
				eurLimit: source.eurLimit ?? '',
				softThresholdPercent: source.softThresholdPercent ?? 80,
				enabled: source.enabled !== false,
			}
		},

		/**
		 * Build the budget payload from the form model.
		 *
		 * @return {object} The budget fields for create/update.
		 */
		buildPayload() {
			const payload = {
				scope: this.form.scope,
				period: this.form.period,
				softThresholdPercent: Number(this.form.softThresholdPercent) || 80,
				enabled: this.form.enabled,
			}
			if (this.form.scope === 'agent') {
				payload.agentId = this.form.agentId
			}
			if (this.form.tokenLimit !== '' && this.form.tokenLimit !== null) {
				payload.tokenLimit = Number(this.form.tokenLimit)
			}
			if (this.form.eurLimit !== '' && this.form.eurLimit !== null) {
				payload.eurLimit = Number(this.form.eurLimit)
			}
			return payload
		},

		/**
		 * Persist the budget (create or update) and notify the parent.
		 *
		 * @return {Promise<void>}
		 */
		async save() {
			this.saving = true
			this.error = ''
			try {
				const payload = this.buildPayload()
				const saved = this.isEdit
					? await updateBudget(this.budget.id, payload)
					: await createBudget({ ...payload, organisation: this.organisation })
				this.$emit('saved', saved)
				this.$emit('close')
			} catch (e) {
				this.error = e?.response?.data?.error || e?.message || this.t('hermiq', 'Unknown error')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.budget-form {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.budget-form__field {
	display: flex;
	flex-direction: column;
}

.budget-form__row {
	display: flex;
	gap: 12px;
}

.budget-form__row > * {
	flex: 1;
}

.budget-form__hint {
	margin: -4px 0 0;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}
</style>
