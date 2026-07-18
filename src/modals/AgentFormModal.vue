<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  AgentFormModal — create or edit an agent (agent-management-ui, extended by
  agent-engine-port, sub-agent-delegation).

  Own file per ADR-004 modal-isolation. Since agent-engine-schemas an Agent is
  a plain OR object in the hermiq register, so the modal persists via the
  createObjectStore agent store (src/store/store.js), not a bespoke resource
  helper (agent-engine-port task 5.2). On edit the existing agent payload is
  merged under the form fields so schema fields this form does not surface
  (views, groups, invitedUsers, quotas, …) survive the PUT.

  Fields cover what the ported engine actually reads (OR EditAgent parity where
  it matters): identity (name, description, icon), LLM config (provider, model,
  prompt, temperature, maxTokens), the tool whitelist (empty = every tool
  allowed, ADR-035), the delegation allowlist (empty = may delegate to no one,
  sub-agent-delegation default-deny), and RAG settings (enableRag,
  ragNumSources, searchFiles, searchObjects). Every NcSelect carries an
  `inputLabel` for the nc-input-labels accessibility gate (WCAG 2.1 AA).

  Icon (agent-icon-picker): a Material Design Icon name (e.g. "RobotOutline"),
  picked via the shared `CnIconPicker` in searchable+clearable mode (the full
  MDI range via @mdi/js, not the small curated dashboard-widget icon set) —
  matching the Agent schema's `icon` property description. Empty clears back
  to the default agent icon.

  @spec openspec/changes/agent-management-ui/tasks.md#task-4-1
  @spec openspec/changes/agent-engine-port/tasks.md#task-5-2
  @spec openspec/changes/agent-management-ui/specs/agent-management-ui/spec.md
  @spec openspec/changes/sub-agent-delegation/specs/agent-management-ui/spec.md#requirement-agent-detail-manages-the-delegation-allowlist-in-place-mvp
-->
<template>
	<NcModal
		:show="show"
		size="normal"
		:name="heading"
		@close="handleClose">
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

			<!-- Icon (agent-icon-picker): a Material Design Icon name shown for this
			     agent in lists and on its detail page. Searchable over the full MDI
			     range (not the small curated dashboard set) since agent icons are
			     free-form (e.g. "RobotOutline"); clearable — empty means the default
			     agent icon. -->
			<div class="agent-form__field">
				<label class="agent-form__icon-label">{{ t('hermiq', 'Icon') }}</label>
				<CnIconPicker
					v-model="form.icon"
					searchable
					clearable />
			</div>

			<!-- Provider/Model are policy-filtered pickers (tenant-model-policy):
			     only the caller's effective policy's providers are offered, and the
			     model list is scoped to the chosen provider. An empty models list on
			     the policy means "any model" → free entry. -->
			<div class="agent-form__field">
				<NcSelect
					v-model="providerOption"
					:input-label="t('hermiq', 'Provider')"
					:options="providerOptions"
					:clearable="false"
					label="label"
					track-by="value" />
			</div>

			<div v-if="allowedModelsForProvider.length > 0" class="agent-form__field">
				<NcSelect
					v-model="modelOption"
					:input-label="t('hermiq', 'Model')"
					:options="modelOptions"
					:clearable="false"
					label="label"
					track-by="value" />
			</div>
			<NcTextField
				v-else
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

			<!-- sub-agent-delegation: which OTHER agents (same organisation) this
			     agent's own turns may hand a bounded sub-task to via
			     hermiq.delegateAgent. Defaults to none (default-deny); the agent
			     being edited is never offered as its own delegation target. -->
			<div class="agent-form__field">
				<NcSelect
					v-model="form.delegationAllowlist"
					:input-label="t('hermiq', 'Delegation allowlist')"
					:options="delegationAllowlistOptions"
					:loading="agentCatalogLoading"
					:multiple="true"
					:close-on-select="false"
					label="label"
					track-by="value"
					:placeholder="t('hermiq', 'Select agents this agent may delegate to')" />
				<p class="agent-form__hint">
					{{ t('hermiq', 'Leave empty to disallow delegation entirely (default).') }}
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

			<div class="agent-form__field">
				<NcTextField
					:value.sync="form.uploadFolder"
					:label="t('hermiq', 'Upload folder')"
					:placeholder="'Hermiq/Attachments'" />
				<p class="agent-form__hint">
					{{ t('hermiq', 'Folder in your own Files where chat attachments to this agent are stored. Leave blank for the default (Hermiq/Attachments).') }}
				</p>
			</div>

			<div class="agent-form__actions">
				<NcButton :disabled="saving" @click="handleClose">
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
import { CnIconPicker } from '@conduction/nextcloud-vue'
import { listTools } from '../api/agents.js'
import { getEffectiveModelPolicy } from '../api/modelPolicy.js'
import { useAgentStore } from '../store/store.js'

export default {
	name: 'AgentFormModal',

	components: {
		CnIconPicker,
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
		/**
		 * The item being edited, or null in create mode (agent-form-slot):
		 * supplied by CnIndexPage's `#form-dialog` scoped slot ({ show, item,
		 * schema, close }) when this modal is wired via
		 * AgentCatalog's `slots.form-dialog`. Folded into `effectiveAgent`
		 * below `agent` but above `routeAgent`.
		 */
		item: {
			type: Object,
			default: null,
		},
		/**
		 * Closes the host dialog (agent-form-slot): the `close` binding from
		 * CnIndexPage's `#form-dialog` scoped slot. Called in ADDITION to
		 * `$emit('close')` on cancel/save-success so both the slot path and
		 * the existing registry `agent-form` open-modal path keep working.
		 */
		close: {
			type: Function,
			default: null,
		},
		/**
		 * The effective JSON schema driving the form (agent-form-slot): the
		 * `schema` binding from CnIndexPage's `#form-dialog` scoped slot.
		 * Not currently consumed — this form's fields are hand-authored
		 * rather than schema-driven — but accepted so the slot binding lands
		 * without a Vue "extraneous non-prop attribute" warning.
		 */
		schema: {
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
			// Effective model policy (tenant-model-policy); null until loaded.
			policy: null,
			// sub-agent-delegation: the caller's visible agent catalog, used to
			// populate the delegation-allowlist picker and resolve its selected
			// ids to human-readable names.
			agentCatalog: [],
			agentCatalogLoading: false,
			// manifest-driven-pages: when opened as the registry `agent-form`
			// open-modal target from AgentDetail's "Edit agent" header action,
			// no `agent` prop is available (open-modal action props are static
			// JSON, not resolved against the current object) — self-fetched
			// here from the route's `:id` param instead. Stays null on
			// AgentCatalog's "Create agent" route (no `:id` param), so create
			// mode is unaffected.
			routeAgent: null,
		}
	},

	computed: {
		/**
		 * The agent being edited — the explicit `agent` prop wins (tests /
		 * direct usage), then the `item` prop (agent-form-slot: CnIndexPage's
		 * `#form-dialog` scoped slot — the row being edited, or null in create
		 * mode), then the route-fetched agent (the registry `agent-form`
		 * open-modal path used by AgentDetail's "Edit agent" action, which
		 * supplies no `agent`/`item` prop).
		 *
		 * @return {object|null} The effective agent, or null when creating.
		 */
		effectiveAgent() {
			return this.agent || this.item || this.routeAgent
		},

		/**
		 * Modal heading — differs for create vs edit.
		 *
		 * @return {string} The localised heading.
		 */
		heading() {
			return this.effectiveAgent ? this.t('hermiq', 'Edit agent') : this.t('hermiq', 'Create agent')
		},

		/**
		 * The effective policy's providers as NcSelect options.
		 *
		 * @return {Array<object>} The { label, value } options.
		 */
		providerOptions() {
			const allowed = this.policy?.allowed || []
			return allowed.map((entry) => ({ label: entry.provider, value: entry.provider }))
		},

		/**
		 * Two-way bridge between form.provider and the NcSelect option object.
		 */
		providerOption: {
			get() {
				return this.providerOptions.find((option) => option.value === this.form.provider) || null
			},
			set(option) {
				this.form.provider = option ? option.value : ''
				// Changing provider invalidates a model outside its allowlist.
				if (this.allowedModelsForProvider.length > 0
					&& !this.allowedModelsForProvider.includes(this.form.model)) {
					this.form.model = this.policy?.defaultModel
						&& this.allowedModelsForProvider.includes(this.policy.defaultModel)
						? this.policy.defaultModel
						: (this.allowedModelsForProvider[0] || '')
				}
			},
		},

		/**
		 * The chosen provider's allowed models ([] means "any model" → free entry).
		 *
		 * @return {Array<string>} The allowed model ids.
		 */
		allowedModelsForProvider() {
			const allowed = this.policy?.allowed || []
			const entry = allowed.find((candidate) => candidate.provider === this.form.provider)
			return Array.isArray(entry?.models) ? entry.models : []
		},

		/**
		 * The allowed models as NcSelect options.
		 *
		 * @return {Array<object>} The { label, value } options.
		 */
		modelOptions() {
			return this.allowedModelsForProvider.map((model) => ({ label: model, value: model }))
		},

		/**
		 * Two-way bridge between form.model and the NcSelect option object.
		 */
		modelOption: {
			get() {
				return this.modelOptions.find((option) => option.value === this.form.model) || null
			},
			set(option) {
				this.form.model = option ? option.value : ''
			},
		},

		/**
		 * The caller's visible agent catalog as delegation-allowlist NcSelect
		 * options, EXCLUDING the agent currently being edited (sub-agent-delegation:
		 * an agent may never delegate to itself).
		 *
		 * @return {Array<object>} The { label, value } options.
		 */
		delegationAllowlistOptions() {
			const editingId = this.effectiveAgent?.uuid || this.effectiveAgent?.id || null
			return this.agentCatalog
				.filter((candidate) => (candidate.uuid || candidate.id) !== editingId)
				.map((candidate) => ({
					label: candidate.name || candidate.uuid || candidate.id,
					value: candidate.uuid || candidate.id,
				}))
		},
	},

	watch: {
		// `immediate: true`: when opened via the registry `agent-form`
		// open-modal action, CnAppRoot mounts this component FRESH with
		// `show` already `true` (from the action's static `props: {show:
		// true}`) — a plain watcher only fires on a CHANGE, so it would
		// never run for that mount path without `immediate`.
		show: {
			immediate: true,
			async handler(open) {
				if (!open) {
					return
				}
				await this.loadRouteAgent()
				this.resetForm()
				this.loadTools()
				this.loadPolicy()
				this.loadAgentCatalog()
			},
		},
	},

	created() {
		this.store = useAgentStore()
		this.store.registerObjectType('agent', 'agent', 'hermiq')
	},

	methods: {
		/**
		 * Close the modal (agent-form-slot). Always emits `close` (the
		 * existing registry `agent-form` open-modal path — AgentDetail's
		 * "Edit agent" action — listens for this event), and ADDITIONALLY
		 * invokes the `close` prop when supplied (CnIndexPage's
		 * `#form-dialog` scoped slot passes its own `close` function to hide
		 * the dialog). Called on Cancel, the modal's own close (X / overlay),
		 * and after a successful save.
		 *
		 * @return {void}
		 */
		handleClose() {
			this.$emit('close')
			this.close?.()
		},

		/**
		 * When no `agent` prop is supplied (the registry `agent-form`
		 * open-modal path), self-fetch the agent from the route's `:id` param
		 * — present on AgentDetail's "Edit agent" action, absent on
		 * AgentCatalog's "Create agent" action (leaving `routeAgent` null, so
		 * create mode proceeds unaffected). Uses a fresh `useAgentStore()`
		 * call (idempotent — the same Pinia singleton `created()` also uses)
		 * rather than `this.store`, since this can run before `created()` due
		 * to the `show` watcher's `immediate: true`.
		 *
		 * @return {Promise<void>}
		 */
		async loadRouteAgent() {
			this.routeAgent = null
			if (this.agent) {
				return
			}
			const routeAgentId = this.$route?.params?.id
			if (!routeAgentId) {
				return
			}
			const store = useAgentStore()
			store.registerObjectType('agent', 'agent', 'hermiq')
			this.routeAgent = await store.fetchObject('agent', routeAgentId).catch(() => null)
		},

		/**
		 * An empty agent form.
		 *
		 * @return {object} The blank form model.
		 */
		blankForm() {
			return {
				name: '',
				description: '',
				icon: '',
				provider: '',
				model: '',
				prompt: '',
				temperature: '',
				maxTokens: '',
				tools: [],
				delegationAllowlist: [],
				enableRag: false,
				searchObjects: true,
				searchFiles: true,
				ragNumSources: '',
				uploadFolder: '',
			}
		},

		/**
		 * Seed the form from `effectiveAgent` (edit) or blank (create).
		 *
		 * @return {void}
		 */
		resetForm() {
			this.error = ''
			if (!this.effectiveAgent) {
				this.form = this.blankForm()
				return
			}
			const source = this.effectiveAgent
			const tools = Array.isArray(source.tools) ? source.tools : []
			const delegationAllowlist = Array.isArray(source.delegationAllowlist) ? source.delegationAllowlist : []
			this.form = {
				name: source.name || '',
				description: source.description || '',
				icon: source.icon || '',
				provider: source.provider || '',
				model: source.model || '',
				prompt: source.prompt || '',
				temperature: source.temperature ?? '',
				maxTokens: source.maxTokens ?? '',
				tools: tools.map((tool) => ({ label: tool, value: tool })),
				delegationAllowlist: this.mapDelegationAllowlistToOptions(delegationAllowlist),
				enableRag: source.enableRag === true,
				searchObjects: source.searchObjects !== false,
				searchFiles: source.searchFiles !== false,
				ragNumSources: source.ragNumSources ?? '',
				uploadFolder: source.uploadFolder ?? '',
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
		 * Load the caller's effective model policy for the pickers
		 * (tenant-model-policy). Non-fatal: with no policy loaded the form keeps
		 * free entry and the server still enforces at run time.
		 *
		 * @return {Promise<void>}
		 */
		async loadPolicy() {
			this.policy = await getEffectiveModelPolicy().catch(() => null)
		},

		/**
		 * Load the caller's visible agent catalog for the delegation-allowlist
		 * picker (sub-agent-delegation). Non-fatal: with no catalog loaded the
		 * picker just stays empty; the agent can still be saved with whatever
		 * was already selected. Once loaded, re-derives the already-selected
		 * options' labels so an edit form opened before the catalog arrived
		 * still shows human names, not bare ids.
		 *
		 * @return {Promise<void>}
		 */
		async loadAgentCatalog() {
			this.agentCatalogLoading = true
			try {
				const agents = await this.store.fetchCollection('agent')
				this.agentCatalog = Array.isArray(agents) ? agents : []
			} catch (e) {
				this.agentCatalog = []
			} finally {
				this.agentCatalogLoading = false
			}
			if (this.show) {
				this.form.delegationAllowlist = this.mapDelegationAllowlistToOptions(
					(this.form.delegationAllowlist || []).map((option) => option.value),
				)
			}
		},

		/**
		 * Map delegation-allowlist agent ids to NcSelect options, resolving each
		 * id's human name from the loaded agent catalog when available (falls
		 * back to the bare id before the catalog has loaded).
		 *
		 * @param {Array<string>} ids The selected agent ids.
		 * @return {Array<object>} The { label, value } options.
		 */
		mapDelegationAllowlistToOptions(ids) {
			return (ids || []).map((id) => {
				const match = this.agentCatalog.find((candidate) => (candidate.uuid || candidate.id) === id)
				return { label: match?.name || id, value: id }
			})
		},

		/**
		 * Whether the current provider/model pair violates the loaded policy
		 * (client-side mirror of the ProviderFactory enforcement).
		 *
		 * @return {boolean} True when the pair is out of policy.
		 */
		violatesPolicy() {
			const allowed = this.policy?.allowed
			if (!Array.isArray(allowed) || allowed.length === 0 || !this.form.provider) {
				return false
			}
			const entry = allowed.find((candidate) => candidate.provider === this.form.provider)
			if (!entry) {
				return true
			}
			return entry.models.length > 0 && !!this.form.model && !entry.models.includes(this.form.model)
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
			const base = this.effectiveAgent ? { ...this.effectiveAgent } : {}
			delete base['@self']

			const payload = {
				...base,
				name: this.form.name,
				description: this.form.description,
				icon: this.form.icon || '',
				provider: this.form.provider,
				model: this.form.model,
				prompt: this.form.prompt,
				tools: (this.form.tools || []).map((tool) => tool.value),
				delegationAllowlist: (this.form.delegationAllowlist || []).map((option) => option.value),
				enableRag: this.form.enableRag,
				searchObjects: this.form.searchObjects,
				searchFiles: this.form.searchFiles,
				uploadFolder: (this.form.uploadFolder || '').trim(),
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
			if (this.effectiveAgent && this.effectiveAgent.id) {
				payload.id = this.effectiveAgent.id
			}
			return payload
		},

		/**
		 * Persist the agent via the createObjectStore and notify the parent.
		 *
		 * @return {Promise<void>}
		 */
		async save() {
			if (this.violatesPolicy()) {
				this.error = this.t('hermiq', 'The chosen provider/model is not allowed by your organisation\'s model policy.')
				return
			}
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
				this.handleClose()
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

.agent-form__icon-label {
	font-weight: bold;
	margin-bottom: 4px;
}

.agent-form__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 8px;
}
</style>
