<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  RemoteAgentFormModal — register a remote agent, or change one
  (flows-hand-off-and-wait D5, REQ-A2A-001).

  Own file per ADR-004 modal-isolation. Registering names the remote agent, its
  handle for grants (hermiq.askRemoteAgent?remoteAgent=<handle>) and the integriq
  source that reaches the remote server; the server then fetches the agent card once
  through that source and keeps it as a snapshot. The source and the handle are
  fixed after that; the name and description can be changed. The credential lives
  behind the source in the broker, never here. A refusal is shown in place.

  @spec openspec/changes/flows-hand-off-and-wait/specs/remote-agents/spec.md#requirement-an-admin-registers-a-remote-agent-through-an-integriq-source-req-a2a-001
-->
<template>
	<NcModal :show="show" size="normal" :name="heading" @close="$emit('close')">
		<form class="remote-agent-form" @submit.prevent="submit">
			<h2 class="remote-agent-form__title">
				{{ heading }}
			</h2>

			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>

			<NcTextField v-model="form.name" :label="t('hermiq', 'Name')" required />
			<NcTextField
				v-if="!form.id"
				v-model="form.slug"
				:label="t('hermiq', 'Handle')"
				:helperText="t('hermiq', 'Used in a grant to name this remote agent. Empty makes one from the name.')" />
			<NcTextField v-model="form.description" :label="t('hermiq', 'What the remote agent does')" />
			<NcSelect
				v-if="!form.id"
				v-model="selectedSource"
				:options="sources"
				label="label"
				:inputLabel="t('hermiq', 'Integriq source that reaches the remote server')"
				:loading="loadingSources"
				:clearable="false" />
			<p v-if="!form.id" class="remote-agent-form__hint">
				{{ t('hermiq', 'Saving fetches the agent card once through this source. The remote agent stays off until you switch it on.') }}
			</p>

			<div class="remote-agent-form__actions">
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
import { NcButton, NcModal, NcNoteCard, NcSelect, NcTextField } from '@nextcloud/vue'
import { listIntegriqSources, registerRemoteAgent, updateRemoteAgent } from '../api/remoteAgents.js'
import { errorMessage } from '../utils/errorMessage.js'

/**
 * The form state for a remote agent, or for a new one.
 *
 * @param {object|null} agent The remote agent being changed, or null.
 * @return {object} The form fields.
 */
function formOf(agent) {
	return {
		id: agent?.id ?? '',
		name: agent?.name ?? '',
		slug: agent?.slug ?? '',
		description: agent?.description ?? '',
		source: agent?.source ?? '',
	}
}

export default {
	name: 'RemoteAgentFormModal',

	components: {
		NcButton,
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

		/** The remote agent being changed, or null for a new one. */
		agent: {
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
		 * @spec openspec/changes/flows-hand-off-and-wait/specs/remote-agents/spec.md#requirement-an-admin-registers-a-remote-agent-through-an-integriq-source-req-a2a-001
		 */
		heading() {
			return this.form.id ? this.t('hermiq', 'Edit remote agent') : this.t('hermiq', 'Add remote agent')
		},

		/**
		 * The chosen source as a select option, kept in step with `form.source`.
		 *
		 * @spec openspec/changes/flows-hand-off-and-wait/specs/remote-agents/spec.md#requirement-an-admin-registers-a-remote-agent-through-an-integriq-source-req-a2a-001
		 */
		selectedSource: {
			/**
			 * The option whose id is `form.source`, or null.
			 *
			 * @return {object|null} The selected source option.
			 * @spec openspec/changes/flows-hand-off-and-wait/specs/remote-agents/spec.md#requirement-an-admin-registers-a-remote-agent-through-an-integriq-source-req-a2a-001
			 */
			get() {
				return this.sources.find((source) => source.id === this.form.source) ?? null
			},

			/**
			 * Keep `form.source` in step with the chosen option.
			 *
			 * @param {object|null} option The chosen source option.
			 * @spec openspec/changes/flows-hand-off-and-wait/specs/remote-agents/spec.md#requirement-an-admin-registers-a-remote-agent-through-an-integriq-source-req-a2a-001
			 */
			set(option) {
				this.form.source = option?.id ?? ''
			},
		},

		/**
		 * Whether the form may be saved: a name, and a source for a new one.
		 *
		 * @return {boolean} True when it may be saved.
		 * @spec openspec/changes/flows-hand-off-and-wait/specs/remote-agents/spec.md#requirement-an-admin-registers-a-remote-agent-through-an-integriq-source-req-a2a-001
		 */
		canSave() {
			return this.form.name.trim() !== '' && (this.form.id !== '' || this.form.source !== '')
		},
	},

	watch: {
		/**
		 * Fill the form, and read the sources for a new one, each time the modal opens.
		 *
		 * @param {boolean} open Whether it opened.
		 * @spec openspec/changes/flows-hand-off-and-wait/specs/remote-agents/spec.md#requirement-an-admin-registers-a-remote-agent-through-an-integriq-source-req-a2a-001
		 */
		show: {
			/**
			 * Fill the form when the modal opens.
			 *
			 * @param {boolean} open Whether it opened.
			 * @spec openspec/changes/flows-hand-off-and-wait/specs/remote-agents/spec.md#requirement-an-admin-registers-a-remote-agent-through-an-integriq-source-req-a2a-001
			 */
			handler(open) {
				if (open) {
					this.form = formOf(this.agent)
					this.error = ''
					if (!this.form.id) {
						this.loadSources()
					}
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
		 * @spec openspec/changes/flows-hand-off-and-wait/specs/remote-agents/spec.md#requirement-an-admin-registers-a-remote-agent-through-an-integriq-source-req-a2a-001
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
		 * Register the remote agent, or save its changes, and hand it to the parent.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/flows-hand-off-and-wait/specs/remote-agents/spec.md#requirement-an-admin-registers-a-remote-agent-through-an-integriq-source-req-a2a-001
		 */
		async submit() {
			if (!this.canSave) {
				return
			}
			this.saving = true
			this.error = ''
			try {
				const name = this.form.name.trim()
				const saved = this.form.id
					? await updateRemoteAgent(this.form.id, { name, description: this.form.description })
					: await registerRemoteAgent({
						name,
						slug: this.form.slug.trim(),
						description: this.form.description,
						source: this.form.source,
					})
				this.$emit('saved', saved)
				this.$emit('close')
			} catch (e) {
				// RemoteAgentController answers a refusal as {message}.
				this.error = e?.response?.data?.message || errorMessage(e, this.t('hermiq', 'Unknown error'))
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.remote-agent-form {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 20px;
}

.remote-agent-form__title {
	margin: 0;
	font-size: 20px;
}

.remote-agent-form__hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.remote-agent-form__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
}
</style>
