<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  AgentFormModal — create or edit an OpenRegister agent (agent-management-ui).

  Own file per ADR-004 modal-isolation. Persists via the plain agents API helper
  (src/api/agents.js) — agents are a first-class OpenRegister resource, not a
  generic object-store object. Every NcSelect carries an `inputLabel` for the
  nc-input-labels accessibility gate (WCAG 2.1 AA).

  @spec openspec/changes/agent-management-ui/tasks.md#task-4-1
  @spec openspec/changes/agent-management-ui/specs/agent-management-ui/spec.md
-->
<template>
	<NcModal
		:show="show"
		size="normal"
		:name="heading"
		@close="$emit('close')">
		<div class="agent-form">
			<h2 class="agent-form__title">
				{{ heading }}
			</h2>

			<NcNoteCard v-if="error" type="error" :heading="t('hermiq', 'Could not save agent')">
				{{ error }}
			</NcNoteCard>

			<NcTextField
				:value.sync="form.name"
				:label="t('hermiq', 'Name')"
				:placeholder="t('hermiq', 'Morning briefing')"
				required />

			<NcTextField
				:value.sync="form.provider"
				:label="t('hermiq', 'Provider')"
				:placeholder="t('hermiq', 'ollama')" />

			<NcTextField
				:value.sync="form.model"
				:label="t('hermiq', 'Model')"
				:placeholder="t('hermiq', 'qwen2.5')" />

			<NcTextArea
				:value.sync="form.prompt"
				:label="t('hermiq', 'System prompt')"
				:placeholder="t('hermiq', 'You are a helpful assistant…')"
				resize="vertical" />

			<div class="agent-form__field">
				<NcSelect
					v-model="form.tools"
					:input-label="t('hermiq', 'Enabled tools')"
					:options="toolOptions"
					:loading="toolsLoading"
					:multiple="true"
					:close-on-select="false"
					label="label"
					track-by="value"
					:placeholder="t('hermiq', 'Select tools the agent may use')" />
			</div>

			<div class="agent-form__actions">
				<NcButton :disabled="saving" @click="$emit('close')">
					{{ t('hermiq', 'Cancel') }}
				</NcButton>
				<NcButton
					type="primary"
					:disabled="saving || !form.name"
					@click="save">
					<template v-if="saving" #icon>
						<NcLoadingIcon :size="20" />
					</template>
					{{ t('hermiq', 'Save') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { NcButton, NcLoadingIcon, NcModal, NcNoteCard, NcSelect, NcTextArea, NcTextField } from '@nextcloud/vue'
import { createAgent, listTools, updateAgent } from '../api/agents.js'

export default {
	name: 'AgentFormModal',

	components: {
		NcButton,
		NcLoadingIcon,
		NcModal,
		NcNoteCard,
		NcSelect,
		NcTextArea,
		NcTextField,
	},

	props: {
		/** Whether the modal is visible. */
		show: {
			type: Boolean,
			default: false,
		},
		/** The agent being edited, or null when creating. */
		agent: {
			type: Object,
			default: null,
		},
	},

	emits: ['close', 'saved'],

	data() {
		return {
			form: this.blankForm(),
			toolOptions: [],
			toolsLoading: false,
			saving: false,
			error: '',
		}
	},

	computed: {
		/**
		 * Modal heading — differs for create vs edit.
		 *
		 * @return {string} The localised heading.
		 */
		heading() {
			return this.agent ? this.t('hermiq', 'Edit agent') : this.t('hermiq', 'Create agent')
		},
	},

	watch: {
		show(open) {
			if (open) {
				this.resetForm()
				this.loadTools()
			}
		},
	},

	methods: {
		/**
		 * An empty agent form.
		 *
		 * @return {object} The blank form model.
		 */
		blankForm() {
			return { name: '', provider: '', model: '', prompt: '', tools: [] }
		},

		/**
		 * Seed the form from the `agent` prop (edit) or blank (create).
		 *
		 * @return {void}
		 */
		resetForm() {
			this.error = ''
			if (!this.agent) {
				this.form = this.blankForm()
				return
			}
			const tools = Array.isArray(this.agent.tools) ? this.agent.tools : []
			this.form = {
				name: this.agent.name || '',
				provider: this.agent.provider || '',
				model: this.agent.model || '',
				prompt: this.agent.prompt || '',
				tools: tools.map((tool) => ({ label: tool, value: tool })),
			}
		},

		/**
		 * Load the tool catalogue for the enabled-tools picker.
		 *
		 * @return {Promise<void>}
		 */
		async loadTools() {
			this.toolsLoading = true
			try {
				const tools = await listTools()
				this.toolOptions = tools.map((tool) => {
					// Agents reference a tool by its id (e.g. "opencatalogi.cms"); show the
					// human name in the label but persist the id as the value.
					const value = tool.id || tool.name || tool.key || String(tool)
					const label = tool.name || value
					const description = tool.description ? ` — ${tool.description}` : ''
					return { label: `${label}${description}`, value }
				})
			} catch (e) {
				// Non-fatal: the picker just stays empty; the agent can still be saved.
				this.toolOptions = []
			} finally {
				this.toolsLoading = false
			}
		},

		/**
		 * Persist the agent via OpenRegister (create or update) and notify the parent.
		 *
		 * @return {Promise<void>}
		 */
		async save() {
			this.saving = true
			this.error = ''
			const payload = {
				name: this.form.name,
				provider: this.form.provider,
				model: this.form.model,
				prompt: this.form.prompt,
				tools: (this.form.tools || []).map((tool) => tool.value),
			}
			try {
				if (this.agent && this.agent.id) {
					await updateAgent(this.agent.id, payload)
				} else {
					await createAgent(payload)
				}
				this.$emit('saved')
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
.agent-form {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 20px;
}

.agent-form__title {
	margin: 0 0 4px;
	font-size: 20px;
	font-weight: 600;
}

.agent-form__field {
	display: flex;
	flex-direction: column;
}

.agent-form__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 8px;
}
</style>
