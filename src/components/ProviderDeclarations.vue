<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  ProviderDeclarations: what an administrator states about one provider
  (models-no-training-guarantee). Two small sections under the provider form:
  "Where it runs" (the residency, through the existing provider-residency
  route, which had no screen) and "What it does with your data" (the data-use
  term and the terms or contract that says so). Both are statements an admin is
  held to; nothing here inspects the provider.

  The parent calls save() after its own save, so one Save button stores all three.
-->
<template>
	<div class="provider-declarations">
		<section class="provider-declarations__section">
			<h3 class="provider-declarations__heading">
				{{ t('hermiq', 'Where it runs') }}
			</h3>
			<NcSelect
				v-model="residency"
				:inputLabel="t('hermiq', 'Residency')"
				:options="residencyOptions"
				:placeholder="t('hermiq', 'Not declared yet')"
				label="label"
				trackBy="value" />
			<NcTextField
				v-model="location"
				:label="t('hermiq', 'Location')"
				placeholder="Frankfurt" />
		</section>

		<section class="provider-declarations__section">
			<h3 class="provider-declarations__heading">
				{{ t('hermiq', 'What it does with your data') }}
			</h3>
			<NcSelect
				v-model="dataUse"
				:inputLabel="t('hermiq', 'Data use')"
				:options="dataUseOptions"
				:placeholder="t('hermiq', 'Not declared yet')"
				label="label"
				trackBy="value" />
			<NcTextField
				v-model="termsReference"
				:label="t('hermiq', 'Terms or contract that says so')"
				placeholder="Anthropic commercial terms, checked 2026-09-01" />
			<p v-if="declaredBy" class="provider-declarations__hint">
				{{
					t('hermiq', 'Declared by {user} on {date}.', {
						user: declaredBy,
						date: declaredOn,
					})
				}}
			</p>
		</section>
	</div>
</template>

<script>
import { NcSelect, NcTextField } from '@nextcloud/vue'
import {
	declareProviderDataUse,
	declareProviderResidency,
	getProviderDataUse,
	getProviderResidency,
} from '../api/llm.js'

export default {
	name: 'ProviderDeclarations',

	components: {
		NcSelect,
		NcTextField,
	},

	props: {
		/**
		 * The provider id the declarations are about.
		 *
		 * @spec openspec/specs/provider-data-use/spec.md#requirement-a-configured-provider-states-what-it-does-with-data-req-notrain-001
		 */
		provider: {
			type: String,
			default: null,
		},
	},

	data() {
		return {
			residencies: {},
			dataUses: {},
			residency: null,
			location: '',
			dataUse: null,
			termsReference: '',
			declaredBy: '',
			declaredAt: '',
			loaded: false,
			residencyOptions: [
				{ value: 'on-premise', label: t('hermiq', 'On our own premises') },
				{ value: 'eu', label: t('hermiq', 'In the EU') },
				{ value: 'outside-eu', label: t('hermiq', 'Outside the EU') },
			],

			dataUseOptions: [
				{
					value: 'zero-retention',
					label: t('hermiq', 'Keeps nothing it is sent'),
				},
				{
					value: 'no-training',
					label: t('hermiq', 'Never trains on your data'),
				},
				{ value: 'may-train', label: t('hermiq', 'May train on your data') },
			],
		}
	},

	computed: {
		/**
		 * The declaration date, without the time.
		 *
		 * @return {string} The date.
		 *
		 * @spec exclude Trivial display helper; no behavioural spec.
		 */
		declaredOn() {
			return (this.declaredAt || '').slice(0, 10)
		},
	},

	watch: {
		/**
		 * Show the selected provider's declarations.
		 *
		 * @return {void}
		 *
		 * @spec exclude Trivial watch handler delegating to fill(); no behavioural spec.
		 */
		provider() {
			this.fill()
		},
	},

	/**
	 * Load both declaration maps when mounted.
	 *
	 * @return {Promise<void>}
	 *
	 * @spec exclude Trivial lifecycle hook delegating to load(); no behavioural spec.
	 */
	async mounted() {
		await this.load()
	},

	methods: {
		/**
		 * Load what has been declared for every provider.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/provider-data-use/spec.md#requirement-a-configured-provider-states-what-it-does-with-data-req-notrain-001
		 */
		async load() {
			try {
				const [residency, dataUse] = await Promise.all([
					getProviderResidency(),
					getProviderDataUse(),
				])
				this.residencies = residency.residencies || {}
				this.dataUses = dataUse.dataUse || {}
			} catch {
				this.residencies = {}
				this.dataUses = {}
			}
			this.loaded = true
			this.fill()
		},

		/**
		 * Put the selected provider's declarations into the fields.
		 *
		 * @return {void}
		 *
		 * @spec openspec/specs/provider-data-use/spec.md#requirement-a-configured-provider-states-what-it-does-with-data-req-notrain-001
		 */
		fill() {
			const residency = this.residencies[this.provider] || {}
			const dataUse = this.dataUses[this.provider] || {}
			this.residency =
				this.residencyOptions.find((o) => o.value === residency.residency)
				|| null
			this.location = residency.location || ''
			this.dataUse =
				this.dataUseOptions.find((o) => o.value === dataUse.dataUse) || null
			this.termsReference = dataUse.termsReference || ''
			this.declaredBy = dataUse.declaredBy || ''
			this.declaredAt = dataUse.declaredAt || ''
		},

		/**
		 * Store what was chosen. A section left undeclared is not written, so
		 * nothing reads as declared that nobody declared.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/provider-data-use/spec.md#requirement-a-configured-provider-states-what-it-does-with-data-req-notrain-001
		 */
		async save() {
			if (!this.provider) {
				return
			}
			if (this.residency) {
				await declareProviderResidency(this.provider, {
					residency: this.residency.value,
					location: this.location,
				})
			}
			if (this.dataUse) {
				await declareProviderDataUse(this.provider, {
					dataUse: this.dataUse.value,
					termsReference: this.termsReference,
				})
			}
		},
	},
}
</script>

<style scoped>
.provider-declarations {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.provider-declarations__section {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.provider-declarations__heading {
	margin: 0;
	font-size: 1rem;
}

.provider-declarations__hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
}
</style>
