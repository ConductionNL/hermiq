<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  ComputerTargetFormModal — register or change a computer target
  (tools-browser-and-computer-use, REQ-COMPUTER-001).

  Own file per ADR-004 modal-isolation. A target names the machine, what it is for,
  the applications an agent may operate on it, and the integriq source that reaches
  it: the machine's key lives in the credential broker behind that source, never
  here. A new target is off until an admin switches it on. The server checks the
  name and the source again and its refusal is shown in place.

  @spec openspec/changes/tools-browser-and-computer-use/specs/computer-use/spec.md#requirement-an-admin-registers-a-machine-reached-only-through-integriq-req-computer-001
-->
<template>
	<NcModal :show="show" size="normal" :name="heading" @close="$emit('close')">
		<form class="computer-target-form" @submit.prevent="submit">
			<h2 class="computer-target-form__title">
				{{ heading }}
			</h2>

			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>

			<NcTextField v-model="form.name" :label="t('hermiq', 'Name')" required />
			<NcTextField v-model="form.description" :label="t('hermiq', 'What the machine is for')" />
			<NcTextField
				v-model="form.applications"
				:label="t('hermiq', 'Applications an agent may operate')"
				:helperText="t('hermiq', 'Shown to the reviewer of every action on this machine.')" />
			<NcSelect
				v-model="selectedSource"
				:options="sources"
				label="label"
				:inputLabel="t('hermiq', 'Integriq source that reaches the machine')"
				:loading="loadingSources"
				:clearable="false" />
			<p class="computer-target-form__hint">
				{{ t('hermiq', 'Operating system: Windows') }}
			</p>
			<NcCheckboxRadioSwitch v-model="form.enabled" type="switch">
				{{ t('hermiq', 'Agents may use this machine') }}
			</NcCheckboxRadioSwitch>

			<div class="computer-target-form__actions">
				<NcButton :disabled="saving" @click="$emit('close')">
					{{ t('hermiq', 'Cancel') }}
				</NcButton>
				<NcButton variant="primary" type="submit" :disabled="saving || !canSave">
					{{ t('hermiq', 'Save') }}
				</NcButton>
			</div>
		</form>
	</NcModal>
</template>

<script>
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcModal,
	NcNoteCard,
	NcSelect,
	NcTextField,
} from '@nextcloud/vue'
import { listIntegriqSources, saveComputerTarget } from '../api/computerTargets.js'
import { errorMessage } from '../utils/errorMessage.js'

/**
 * The form state for a target, or for a new one.
 *
 * @param {object|null} target The target being changed, or null.
 * @return {object} The form fields.
 */
function formOf(target) {
	return {
		id: target?.id ?? '',
		name: target?.name ?? '',
		description: target?.description ?? '',
		applications: target?.applications ?? '',
		source: target?.source ?? '',
		enabled: target?.enabled === true,
	}
}

export default {
	name: 'ComputerTargetFormModal',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcModal,
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

		/** The target being changed, or null for a new one. */
		target: {
			type: Object,
			default: null,
		},
	},

	emits: ['close', 'saved'],

	data() {
		return {
			form: formOf(null),
			sources: [],
			loadingSources: false,
			saving: false,
			error: '',
		}
	},

	computed: {
		/**
		 * The modal heading.
		 *
		 * @return {string} The heading.
		 * @spec openspec/changes/tools-browser-and-computer-use/specs/computer-use/spec.md#requirement-an-admin-registers-a-machine-reached-only-through-integriq-req-computer-001
		 */
		heading() {
			return this.form.id ? this.t('hermiq', 'Edit computer target') : this.t('hermiq', 'Add computer target')
		},

		/**
		 * The chosen source as a select option, kept in step with `form.source`.
		 *
		 * @spec openspec/changes/tools-browser-and-computer-use/specs/computer-use/spec.md#requirement-an-admin-registers-a-machine-reached-only-through-integriq-req-computer-001
		 */
		selectedSource: {
			get() {
				return this.sources.find((source) => source.id === this.form.source) ?? null
			},

			set(option) {
				this.form.source = option?.id ?? ''
			},
		},

		/**
		 * Whether the form names the machine and a source.
		 *
		 * @return {boolean} True when it may be saved.
		 * @spec openspec/changes/tools-browser-and-computer-use/specs/computer-use/spec.md#requirement-an-admin-registers-a-machine-reached-only-through-integriq-req-computer-001
		 */
		canSave() {
			return this.form.name.trim() !== '' && this.form.source !== ''
		},
	},

	watch: {
		/**
		 * Fill the form and read the sources each time the modal opens.
		 *
		 * @param {boolean} open Whether it opened.
		 * @spec openspec/changes/tools-browser-and-computer-use/specs/computer-use/spec.md#requirement-an-admin-registers-a-machine-reached-only-through-integriq-req-computer-001
		 */
		show: {
			handler(open) {
				if (open) {
					this.form = formOf(this.target)
					this.error = ''
					this.loadSources()
				}
			},

			immediate: true,
		},
	},

	methods: {
		/**
		 * Read the integriq sources to choose from.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/tools-browser-and-computer-use/specs/computer-use/spec.md#requirement-an-admin-registers-a-machine-reached-only-through-integriq-req-computer-001
		 */
		async loadSources() {
			this.loadingSources = true
			try {
				this.sources = await listIntegriqSources()
			} catch (e) {
				this.error = errorMessage(e, this.t('hermiq', 'The integriq sources could not be read.'))
			} finally {
				this.loadingSources = false
			}
		},

		/**
		 * Save the target and hand it to the parent.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/tools-browser-and-computer-use/specs/computer-use/spec.md#requirement-an-admin-registers-a-machine-reached-only-through-integriq-req-computer-001
		 */
		async submit() {
			if (!this.canSave) {
				return
			}
			this.saving = true
			this.error = ''
			try {
				const saved = await saveComputerTarget({ ...this.form, name: this.form.name.trim() })
				this.$emit('saved', saved)
				this.$emit('close')
			} catch (e) {
				this.error = errorMessage(e, this.t('hermiq', 'Unknown error'))
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.computer-target-form {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 20px;
}

.computer-target-form__title {
	margin: 0;
	font-size: 20px;
	font-weight: 600;
}

.computer-target-form__hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.computer-target-form__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
}
</style>
