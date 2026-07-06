<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  AgentFormModal — create or edit an agent (agent-management-ui, extended by
  agent-engine-port).

  Own file per ADR-004 modal-isolation. Since agent-engine-schemas an Agent is
  a plain OR object in the hermiq register, so the modal persists via the
  createObjectStore agent store (src/store/store.js), not a bespoke resource
  helper (agent-engine-port task 5.2). On edit the existing agent payload is
  merged under the form fields so schema fields this form does not surface
  (views, groups, invitedUsers, quotas, …) survive the PUT.

  Fields cover what the ported engine actually reads (OR EditAgent parity where
  it matters): identity (name, description), LLM config (provider, model,
  prompt, temperature, maxTokens), the tool whitelist (empty = every tool
  allowed, ADR-035), and RAG settings (enableRag, ragNumSources, searchFiles,
  searchObjects). Every NcSelect carries an `inputLabel` for the
  nc-input-labels accessibility gate (WCAG 2.1 AA).

  @spec openspec/changes/agent-management-ui/tasks.md#task-4-1
  @spec openspec/changes/agent-engine-port/tasks.md#task-5-2
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
				:value.sync="form.description"
				:label="t('hermiq', 'Description')"
				:placeholder="t('hermiq', 'What does this agent do?')" />

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

			<div class="agent-form__row">
				<NcTextField
					:value.sync="form.temperature"
					type="number"
					:label="t('hermiq', 'Temperature (0–2)')"
					:placeholder="'0.7'" />
				<NcTextField
					:value.sync="form.maxTokens"
					type="number"
					:label="t('hermiq', 'Max tokens per response')"
					:placeholder="'2048'" />
			</div>

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
				<p class="agent-form__hint">
					{{ t('hermiq', 'Leave empty to allow every available tool.') }}
				</p>
			</div>

			<div class="agent-form__field">
				<NcCheckboxRadioSwitch
					:checked="form.enableRag"
					@update:checked="form.enableRag = $event">
					{{ t('hermiq', 'Ground responses in your data (RAG)') }}
				</NcCheckboxRadioSwitch>
			</div>

			<template v-if="form.enableRag">
				<div class="agent-form__row">
					<NcCheckboxRadioSwitch
						:checked="form.searchObjects"
						@update:checked="form.searchObjects = $event">
						{{ t('hermiq', 'Search in objects') }}
					</NcCheckboxRadioSwitch>
					<NcCheckboxRadioSwitch
						:checked="form.searchFiles"
						@update:checked="form.searchFiles = $event">
						{{ t('hermiq', 'Search in files') }}
					</NcCheckboxRadioSwitch>
				</div>
				<NcTextField
					:value.sync="form.ragNumSources"
					type="number"
					:label="t('hermiq', 'Number of RAG sources')"
					:placeholder="'5'" />
			</template>

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
import { NcButton, NcCheckboxRadioSwitch, NcLoadingIcon, NcModal, NcNoteCard, NcSelect, NcTextArea, NcTextField } from '@nextcloud/vue'
import { listTools } from '../api/agents.js'
import { useAgentStore } from '../store/store.js'

export default {
	name: 'AgentFormModal',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
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

	created() {
		this.store = useAgentStore()
		this.store.registerObjectType('agent', 'agent', 'hermiq')
	},

	methods: {
		/**
		 * An empty agent form.
		 *
		 * @return {object} The blank form model.
		 */
		blankForm() {
			return {
				name: '',
				description: '',
				provider: '',
				model: '',
				prompt: '',
				temperature: '',
				maxTokens: '',
				tools: [],
				enableRag: false,
				searchObjects: true,
				searchFiles: true,
				ragNumSources: '',
			}
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
				description: this.agent.description || '',
				provider: this.agent.provider || '',
				model: this.agent.model || '',
				prompt: this.agent.prompt || '',
				temperature: this.agent.temperature ?? '',
				maxTokens: this.agent.maxTokens ?? '',
				tools: tools.map((tool) => ({ label: tool, value: tool })),
				enableRag: this.agent.enableRag === true,
				searchObjects: this.agent.searchObjects !== false,
				searchFiles: this.agent.searchFiles !== false,
				ragNumSources: this.agent.ragNumSources ?? '',
			}
		},

		/**
		 * Load the tool catalogue for the enabled-tools picker (Hermiq's
		 * facade-backed /api/agents/tools endpoint).
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
		 * Build the save payload. On edit, spread the existing agent payload
		 * first so schema fields this form does not surface survive the PUT
		 * (the generic objects path replaces the payload wholesale); `@self`
		 * metadata is stripped so it is never written back.
		 *
		 * @return {object} The agent payload for saveObject().
		 */
		buildPayload() {
			const base = this.agent ? { ...this.agent } : {}
			delete base['@self']

			const payload = {
				...base,
				name: this.form.name,
				description: this.form.description,
				provider: this.form.provider,
				model: this.form.model,
				prompt: this.form.prompt,
				tools: (this.form.tools || []).map((tool) => tool.value),
				enableRag: this.form.enableRag,
				searchObjects: this.form.searchObjects,
				searchFiles: this.form.searchFiles,
			}

			const temperature = Number(this.form.temperature)
			if (this.form.temperature !== '' && !Number.isNaN(temperature)) {
				payload.temperature = temperature
			}
			const maxTokens = Number(this.form.maxTokens)
			if (this.form.maxTokens !== '' && Number.isInteger(maxTokens)) {
				payload.maxTokens = maxTokens
			}
			const ragNumSources = Number(this.form.ragNumSources)
			if (this.form.ragNumSources !== '' && Number.isInteger(ragNumSources)) {
				payload.ragNumSources = ragNumSources
			}

			// Preserve the object id on edit so saveObject issues a PUT.
			if (this.agent && this.agent.id) {
				payload.id = this.agent.id
			}
			return payload
		},

		/**
		 * Persist the agent via the createObjectStore and notify the parent.
		 *
		 * @return {Promise<void>}
		 */
		async save() {
			this.saving = true
			this.error = ''
			try {
				const saved = await this.store.saveObject('agent', this.buildPayload())
				if (saved === null) {
					this.error = this.store.errors?.agent?.message
						|| this.t('hermiq', 'Could not save agent')
					return
				}
				this.$emit('saved', saved)
				this.$emit('close')
			} catch (e) {
				this.error = e?.message || this.t('hermiq', 'Unknown error')
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

.agent-form__row {
	display: flex;
	gap: 12px;
}

.agent-form__row > * {
	flex: 1;
}

.agent-form__hint {
	margin: 4px 0 0;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.agent-form__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 8px;
}
</style>
