<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  - SPDX-License-Identifier: EUPL-1.2
-->

<!--
  AiFeatureBindingModal: the administrator chooses which provider and model one
  AI feature runs on, and where that provider must run
  (models-bind-a-provider-per-feature).

  Only the providers and models the organisation's model policy allows are
  offered. The server checks again on save, because the policy can change while
  the dialog is open; its refusal is shown here in its own words.

  @spec openspec/changes/models-bind-a-provider-per-feature/specs/ai-feature-governance/spec.md#requirement-an-administrator-chooses-the-provider-of-one-ai-feature-req-aibind-001
-->
<template>
	<NcDialog
		:name="
			t('hermiq', 'Change provider for {feature}', { feature: featureName })
		"
		:open="true"
		size="normal"
		@update:open="$emit('close')">
		<div class="ai-feature-binding">
			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>

			<NcCheckboxRadioSwitch v-model="useDefault" type="switch">
				{{ t('hermiq', 'Use the organisation default') }}
			</NcCheckboxRadioSwitch>

			<template v-if="!useDefault">
				<NcSelect
					v-model="providerOption"
					:inputLabel="t('hermiq', 'Provider')"
					:options="providerOptions"
					:clearable="false"
					label="label"
					trackBy="value" />
				<NcSelect
					v-if="!anyModel"
					v-model="modelOption"
					:inputLabel="t('hermiq', 'Model')"
					:options="modelOptions"
					:clearable="false"
					:disabled="!providerOption"
					label="label"
					trackBy="value" />
				<NcTextField
					v-else
					v-model="freeModel"
					:label="t('hermiq', 'Model')"
					:helperText="
						t('hermiq', 'The policy allows any model of this provider.')
					" />
			</template>

			<NcSelect
				v-model="residencyOption"
				:inputLabel="t('hermiq', 'Where the provider must run')"
				:options="residencyOptions"
				:clearable="false"
				label="label"
				trackBy="value" />
		</div>

		<template #actions>
			<NcButton variant="tertiary" :disabled="busy" @click="$emit('close')">
				{{ t('hermiq', 'Cancel') }}
			</NcButton>
			<NcButton variant="primary" :disabled="busy || !canSave" @click="save">
				{{ t('hermiq', 'Save') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcDialog,
	NcNoteCard,
	NcSelect,
	NcTextField,
} from '@nextcloud/vue'
import { bindAiFeature } from '../api/aiFeatures.js'
import { getEffectiveModelPolicy } from '../api/modelPolicy.js'

export default {
	name: 'AiFeatureBindingModal',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcDialog,
		NcNoteCard,
		NcSelect,
		NcTextField,
	},

	props: {
		/** The AiFeature UUID to bind. */
		featureId: {
			type: String,
			required: true,
		},

		/** The feature's name, for the heading. */
		featureName: {
			type: String,
			default: '',
		},

		/** The current binding: { provider, model, requiredResidency }. */
		current: {
			type: Object,
			default: () => ({}),
		},
	},

	emits: ['close', 'saved'],

	data() {
		return {
			allowed: [],
			useDefault: !this.current.provider,
			providerOption: null,
			modelOption: null,
			freeModel: '',
			residencyOption: null,
			busy: false,
			error: '',
		}
	},

	computed: {
		/**
		 * The providers the model policy allows.
		 *
		 * @return {Array<object>} Select options.
		 * @spec openspec/changes/models-bind-a-provider-per-feature/specs/ai-feature-governance/spec.md#requirement-an-administrator-chooses-the-provider-of-one-ai-feature-req-aibind-001
		 */
		providerOptions() {
			return this.allowed.map((entry) => ({
				value: entry.provider,
				label: entry.provider,
			}))
		},

		/**
		 * The models the policy allows for the chosen provider.
		 *
		 * @return {Array<object>} Select options.
		 * @spec openspec/changes/models-bind-a-provider-per-feature/specs/ai-feature-governance/spec.md#requirement-an-administrator-chooses-the-provider-of-one-ai-feature-req-aibind-001
		 */
		modelOptions() {
			const entry = this.allowed.find(
				(a) => a.provider === this.providerOption?.value,
			)
			const models = Array.isArray(entry?.models) ? entry.models : []
			return models.map((m) => ({ value: m, label: m }))
		},

		/**
		 * Whether the policy allows any model of the chosen provider (an empty
		 * `models` list), so the model is typed rather than picked.
		 *
		 * @return {boolean} True when any model is allowed.
		 * @spec openspec/changes/models-bind-a-provider-per-feature/specs/ai-feature-governance/spec.md#requirement-an-administrator-chooses-the-provider-of-one-ai-feature-req-aibind-001
		 */
		anyModel() {
			return Boolean(this.providerOption) && this.modelOptions.length === 0
		},

		/**
		 * The model the form holds.
		 *
		 * @return {string} The model id, or ''.
		 * @spec openspec/changes/models-bind-a-provider-per-feature/specs/ai-feature-governance/spec.md#requirement-an-administrator-chooses-the-provider-of-one-ai-feature-req-aibind-001
		 */
		chosenModel() {
			return this.anyModel
				? this.freeModel.trim()
				: this.modelOption?.value || ''
		},

		/**
		 * The residencies a feature can require.
		 *
		 * @return {Array<object>} Select options.
		 * @spec openspec/changes/models-bind-a-provider-per-feature/specs/ai-feature-governance/spec.md#requirement-an-administrator-chooses-the-provider-of-one-ai-feature-req-aibind-001
		 */
		residencyOptions() {
			return [
				{ value: '', label: this.t('hermiq', 'No requirement') },
				{ value: 'on-premise', label: this.t('hermiq', 'On premise') },
				{ value: 'eu', label: this.t('hermiq', 'In the EU') },
				{ value: 'outside-eu', label: this.t('hermiq', 'Outside the EU') },
			]
		},

		/**
		 * Whether the form holds a complete binding.
		 *
		 * @return {boolean} True when it can be saved.
		 * @spec openspec/changes/models-bind-a-provider-per-feature/specs/ai-feature-governance/spec.md#requirement-an-administrator-chooses-the-provider-of-one-ai-feature-req-aibind-001
		 */
		canSave() {
			return (
				this.useDefault || Boolean(this.providerOption && this.chosenModel)
			)
		},
	},

	watch: {
		providerOption: {
			/**
			 * A model of another provider is never kept.
			 *
			 * @spec openspec/changes/models-bind-a-provider-per-feature/specs/ai-feature-governance/spec.md#requirement-an-administrator-chooses-the-provider-of-one-ai-feature-req-aibind-001
			 */
			handler() {
				if (
					this.modelOption
					&& !this.modelOptions.some(
						(o) => o.value === this.modelOption.value,
					)
				) {
					this.modelOption = null
				}
			},
		},
	},

	async created() {
		this.residencyOption =
			this.residencyOptions.find(
				(o) => o.value === (this.current.requiredResidency || ''),
			) || this.residencyOptions[0]
		try {
			const policy = await getEffectiveModelPolicy()
			this.allowed = Array.isArray(policy?.allowed) ? policy.allowed : []
		} catch (e) {
			this.error =
				e?.response?.data?.error
				|| e?.message
				|| this.t('hermiq', 'Unknown error')
		}
		this.providerOption =
			this.providerOptions.find((o) => o.value === this.current.provider)
			|| null
		this.modelOption =
			this.modelOptions.find((o) => o.value === this.current.model) || null
		this.freeModel = this.anyModel ? this.current.model || '' : ''
	},

	methods: {
		/**
		 * Save the binding; the caller reloads the register on `saved`.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/models-bind-a-provider-per-feature/specs/ai-feature-governance/spec.md#requirement-an-administrator-chooses-the-provider-of-one-ai-feature-req-aibind-001
		 */
		async save() {
			this.busy = true
			this.error = ''
			try {
				await bindAiFeature(this.featureId, {
					provider: this.useDefault ? '' : this.providerOption.value,
					model: this.useDefault ? '' : this.chosenModel,
					requiredResidency: this.residencyOption?.value || '',
				})
				this.$emit('saved')
			} catch (e) {
				this.error =
					e?.response?.data?.error
					|| e?.message
					|| this.t('hermiq', 'Unknown error')
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.ai-feature-binding {
	display: flex;
	flex-direction: column;
	gap: 12px;
}
</style>
