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

  Icon (agent-icon-picker): a Material Design Icon name (e.g. "Creation"),
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
	<NcModal :show="show" size="normal" :name="heading" @close="handleClose">
		<div class="agent-form">
			<h2 class="agent-form__title">
				{{ heading }}
			</h2>

			<NcNoteCard
				v-if="error"
				type="error"
				:heading="t('hermiq', 'Could not save agent')">
				{{ error }}
			</NcNoteCard>

			<!-- Vue 3 / @nextcloud/vue 9: v-model (modelValue) — the old Vue-2
			     `:value.sync` modifier is silently IGNORED by the Vue 3 compiler,
			     leaving a one-way binding: typing never reached form.name and the
			     Save button stayed disabled forever. -->
			<NcTextField
				v-model="form.name"
				:label="t('hermiq', 'Name')"
				:placeholder="t('hermiq', 'Morning briefing')"
				required />

			<NcTextField
				v-model="form.description"
				:label="t('hermiq', 'Description')"
				:placeholder="t('hermiq', 'What does this agent do?')" />

			<!-- Icon (agent-icon-picker): a Material Design Icon name shown for this
			     agent in lists and on its detail page. Searchable over the full MDI
			     range (not the small curated dashboard set) since agent icons are
			     free-form (e.g. "Creation"); clearable — empty means the default
			     agent icon. -->
			<div class="agent-form__field">
				<label class="agent-form__icon-label">{{
					t('hermiq', 'Icon')
				}}</label>
				<!--
					BOTH sources. The picker offered MDI alone because this
					passed no `catalogues` at all — the library deliberately
					bundles no icon pack, so a consumer that names none gets the
					one it can lazy-load. OpenGemeenten is the set a Dutch
					municipal agent is actually named from ("Paspoort",
					"Afvalcontainer"), and it is CC0.
				-->
				<CnIconPicker
					v-model="form.icon"
					searchable
					clearable
					:sources="['mdi', 'opengemeenten']"
					:catalogues="iconCatalogues" />
			</div>

			<!-- Provider/Model are policy-filtered pickers (tenant-model-policy):
			     only the caller's effective policy's providers are offered, and the
			     model list is scoped to the chosen provider. An empty models list on
			     the policy means "any model" → free entry. -->
			<div class="agent-form__field">
				<NcSelect
					v-model="providerOption"
					:inputLabel="t('hermiq', 'Provider')"
					:options="providerOptions"
					:clearable="false"
					label="label"
					trackBy="value" />
			</div>

			<!--
				A DROPDOWN even with no tenant policy. The list is the policy's
				when one exists and the provider's known models otherwise —
				previously that second case was an empty text box, so an author
				had to type "claude-opus-4-8" from memory with nothing on screen
				saying what the provider serves.

				`taggable` because a provider ships new models faster than this
				app is released: an unlisted model is typed and accepted, so the
				list can never become the reason a new model cannot be used.
			-->
			<div class="agent-form__field">
				<NcSelect
					v-model="modelOption"
					:inputLabel="t('hermiq', 'Model')"
					:options="modelOptions"
					:clearable="false"
					:taggable="true"
					:createOption="(value) => ({ label: value, value })"
					label="label"
					trackBy="value" />
				<p class="agent-form__hint">
					{{ modelHint }}
				</p>
			</div>

			<NcTextArea
				v-model="form.prompt"
				:label="t('hermiq', 'System prompt')"
				:placeholder="t('hermiq', 'You are a helpful assistant…')"
				resize="vertical" />

			<div class="agent-form__row">
				<NcTextField
					v-model="form.temperature"
					type="number"
					:label="t('hermiq', 'Temperature (0–2)')"
					placeholder="0.7" />
				<NcTextField
					v-model="form.maxTokens"
					type="number"
					:label="t('hermiq', 'Max tokens per response')"
					placeholder="2048" />
			</div>

			<div class="agent-form__field">
				<NcSelect
					v-model="form.tools"
					:inputLabel="t('hermiq', 'Enabled tools')"
					:options="toolOptions"
					:loading="toolsLoading"
					:multiple="true"
					:closeOnSelect="false"
					label="label"
					trackBy="value"
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
					:inputLabel="t('hermiq', 'Delegation allowlist')"
					:options="delegationAllowlistOptions"
					:loading="agentCatalogLoading"
					:multiple="true"
					:closeOnSelect="false"
					label="label"
					trackBy="value"
					:placeholder="
						t('hermiq', 'Select agents this agent may delegate to')
					" />
				<p class="agent-form__hint">
					{{
						t(
							'hermiq',
							'Leave empty to disallow delegation entirely (default).',
						)
					}}
				</p>
			</div>

			<div class="agent-form__field">
				<!-- v9 NcCheckboxRadioSwitch is modelValue-based; the old
				     :checked/@update:checked pair no longer round-trips. -->
				<NcCheckboxRadioSwitch v-model="form.enableRag">
					{{ t('hermiq', 'Ground responses in your data (RAG)') }}
				</NcCheckboxRadioSwitch>
			</div>

			<template v-if="form.enableRag">
				<div class="agent-form__row">
					<NcCheckboxRadioSwitch v-model="form.searchObjects">
						{{ t('hermiq', 'Search in objects') }}
					</NcCheckboxRadioSwitch>
					<NcCheckboxRadioSwitch v-model="form.searchFiles">
						{{ t('hermiq', 'Search in files') }}
					</NcCheckboxRadioSwitch>
				</div>
				<NcTextField
					v-model="form.ragNumSources"
					type="number"
					:label="t('hermiq', 'Number of RAG sources')"
					placeholder="5" />
			</template>

			<!--
			  Speech (speech-services). The engine choice is a PRIVACY choice, so
			  the options say where the audio goes rather than naming an API —
			  the person configuring an agent for a case file cannot be expected
			  to know that "browser" means Google's servers in Chrome.
			-->
			<div class="agent-form__field">
				<NcSelect
					v-model="form.voiceInputEngine"
					:options="voiceEngineOptions"
					:clearable="false"
					label="label"
					:inputLabel="t('hermiq', 'Dictation (speech to text)')" />
				<p class="agent-form__hint">
					{{
						t(
							'hermiq',
							'Where this agent’s dictated audio is transcribed. “On this instance” never leaves the server and is slower; “browser” is instant and, in most browsers, sends the audio to the browser vendor.',
						)
					}}
				</p>
			</div>

			<div class="agent-form__field">
				<NcSelect
					v-model="form.voiceOutputEngine"
					:options="voiceEngineOptions"
					:clearable="false"
					label="label"
					:inputLabel="t('hermiq', 'Spoken replies (text to speech)')" />
			</div>

			<div class="agent-form__field">
				<NcTextField
					v-model="form.voiceSilenceTimeout"
					type="number"
					:label="t('hermiq', 'Silence before the microphone closes (ms)')"
					placeholder="2500" />
				<p class="agent-form__hint">
					{{
						t(
							'hermiq',
							'How long a pause may last before dictation stops. The text stays in the message box — dictation never sends by itself. 0 keeps the microphone open until you stop it.',
						)
					}}
				</p>
			</div>

			<div class="agent-form__field">
				<NcCheckboxRadioSwitch v-model="form.voiceConversationEnabled">
					{{ t('hermiq', 'Allow spoken conversation') }}
				</NcCheckboxRadioSwitch>
				<p class="agent-form__hint">
					{{
						t(
							'hermiq',
							'Adds a hands-free control beside the microphone: your turn is sent when you stop speaking and the reply is spoken back. Off by default, because auto-sending on a pause can post a half-finished thought.',
						)
					}}
				</p>
			</div>

			<div class="agent-form__actions">
				<NcButton :disabled="saving" @click="handleClose">
					{{ t('hermiq', 'Cancel') }}
				</NcButton>
				<NcButton
					variant="primary"
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
import { CnIconPicker, fromOpenGemeenten } from '@conduction/nextcloud-vue'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcLoadingIcon,
	NcModal,
	NcNoteCard,
	NcSelect,
	NcTextArea,
	NcTextField,
} from '@nextcloud/vue'
import { listTools } from '../api/agents.js'
import { getEffectiveModelPolicy } from '../api/modelPolicy.js'
import { updateToolGrants } from '../api/toolOversight.js'
import { OPEN_GEMEENTEN_ICONS } from '../icons/openGemeentenIcons.js'
import { KNOWN_MODELS, knownModelsFor } from '../llm/knownModels.js'
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
		 * The speech-engine choices, labelled by WHERE THE AUDIO GOES.
		 *
		 * 🔴 Not "Browser / Local / Auto". The person configuring an agent for a
		 * case file has no way to know that the browser's speech API streams the
		 * microphone to Google in Chrome and to Apple in Safari — it is called
		 * "browser", which reads as "on this device". A label that hides the
		 * destination makes the privacy decision impossible to take, so the
		 * destination IS the label.
		 *
		 * @return {Array<{value: string, label: string}>} The options.
		 * @spec openspec/specs/speech-services/spec.md#requirement-speech-policy-is-per-agent
		 */
		voiceEngineOptions() {
			return [
				{
					value: 'auto',
					label: t('hermiq', 'Automatic — fastest available'),
				},
				{
					value: 'browser',
					label: t(
						'hermiq',
						'Browser — instant, audio goes to the browser vendor',
					),
				},
				{
					value: 'local',
					label: t('hermiq', 'On this instance — private, slower'),
				},
				{
					value: 'off',
					label: t('hermiq', 'Off — no speech for this agent'),
				},
			]
		},

		/**
		 * The icon sources this form offers.
		 *
		 * MDI is absent from the map on purpose: the picker lazy-loads
		 * `@mdi/js` itself when the `mdi` source is enabled and no catalogue is
		 * supplied, so listing it here would load the whole set eagerly for a
		 * dialog that is usually opened to type a name.
		 *
		 * @return {object} The catalogues, by source key.
		 * @spec openspec/changes/agent-management-ui/tasks.md#task-4-1
		 */
		iconCatalogues() {
			return { opengemeenten: fromOpenGemeenten(OPEN_GEMEENTEN_ICONS) }
		},

		/**
		 * The agent being edited — the explicit `agent` prop wins (tests /
		 * direct usage), then the `item` prop (agent-form-slot: CnIndexPage's
		 * `#form-dialog` scoped slot — the row being edited, or null in create
		 * mode), then the route-fetched agent (the registry `agent-form`
		 * open-modal path used by AgentDetail's "Edit agent" action, which
		 * supplies no `agent`/`item` prop).
		 *
		 * @return {object|null} The effective agent, or null when creating.
		 * @spec openspec/changes/agent-management-ui/tasks.md#task-4-1
		 */
		effectiveAgent() {
			return this.agent || this.item || this.routeAgent
		},

		/**
		 * Modal heading — differs for create vs edit.
		 *
		 * @return {string} The localised heading.
		 * @spec openspec/changes/agent-management-ui/tasks.md#task-4-1
		 */
		heading() {
			return this.effectiveAgent
				? this.t('hermiq', 'Edit agent')
				: this.t('hermiq', 'Create agent')
		},

		/**
		 * The effective policy's providers as NcSelect options.
		 *
		 * @return {Array<object>} The { label, value } options.
		 * @spec openspec/changes/agent-management-ui/tasks.md#task-4-1
		 */
		providerOptions() {
			const allowed = this.policy?.allowed || []
			if (allowed.length > 0) {
				return allowed.map((entry) => ({
					label: entry.provider,
					value: entry.provider,
				}))
			}

			// No policy is the normal state of a fresh instance, and an empty
			// provider list left the picker with nothing to choose — the agent
			// could not name a provider at all until someone wrote a policy.
			return Object.keys(KNOWN_MODELS).map((provider) => ({
				label: provider,
				value: provider,
			}))
		},

		/**
		 * Two-way bridge between form.provider and the NcSelect option object.
		 */
		providerOption: {
			/**
			 * @spec openspec/changes/agent-management-ui/tasks.md#task-4-1
			 */
			get() {
				return (
					this.providerOptions.find(
						(option) => option.value === this.form.provider,
					) || null
				)
			},

			/**
			 * @param {{value: string, label: string}|null} option The picked provider, or null when cleared.
			 *
			 * @return {void}
			 * @spec openspec/changes/agent-management-ui/tasks.md#task-4-1
			 */
			set(option) {
				this.form.provider = option ? option.value : ''
				// Changing provider invalidates a model outside its allowlist.
				if (
					this.allowedModelsForProvider.length > 0
					&& !this.allowedModelsForProvider.includes(this.form.model)
				) {
					this.form.model =
						this.policy?.defaultModel
						&& this.allowedModelsForProvider.includes(
							this.policy.defaultModel,
						)
							? this.policy.defaultModel
							: this.allowedModelsForProvider[0] || ''
				}
			},
		},

		/**
		 * The chosen provider's allowed models ([] means "any model" → free entry).
		 *
		 * @return {Array<string>} The allowed model ids.
		 * @spec openspec/changes/agent-management-ui/tasks.md#task-4-1
		 */
		allowedModelsForProvider() {
			const allowed = this.policy?.allowed || []
			const entry = allowed.find(
				(candidate) => candidate.provider === this.form.provider,
			)
			return Array.isArray(entry?.models) ? entry.models : []
		},

		/**
		 * The allowed models as NcSelect options.
		 *
		 * @return {Array<object>} The { label, value } options.
		 * @spec openspec/changes/agent-management-ui/tasks.md#task-4-1
		 */
		modelOptions() {
			return this.offeredModels.map((model) => ({
				label: model,
				value: model,
			}))
		},

		/**
		 * The models to offer: the POLICY's when it names any, else the
		 * provider's known ones.
		 *
		 * A policy is a constraint and wins where it exists. The known list is
		 * only a starting point for an instance that has never written one.
		 *
		 * @return {Array<string>} The model ids.
		 * @spec openspec/changes/agent-management-ui/tasks.md#task-4-1
		 */
		offeredModels() {
			if (this.allowedModelsForProvider.length > 0) {
				return this.allowedModelsForProvider
			}

			return knownModelsFor(this.form.provider)
		},

		/**
		 * Where this model list came from, and where the KEY comes from.
		 *
		 * The key question is asked constantly and answered nowhere: this form
		 * has no credential field because an agent never carries one. Every
		 * call is resolved at RUN time through OpenRegister's credential
		 * broker — the caller's personal credential first, then the
		 * organisation's — so the agent names a provider and the broker finds
		 * the key. Saying so here is cheaper than the support question.
		 *
		 * @return {string} The hint.
		 * @spec openspec/changes/agent-management-ui/tasks.md#task-4-1
		 */
		modelHint() {
			const source =
				this.allowedModelsForProvider.length > 0
					? this.t(
							'hermiq',
							"Models allowed by your organisation's policy.",
						)
					: this.t(
							'hermiq',
							'Known models for this provider — type any other model to use it.',
						)

			return (
				source
				+ ' '
				+ this.t(
					'hermiq',
					"The API key is not set here: it is resolved when the agent runs, from your personal credential or your organisation's, under Settings → Agent credentials.",
				)
			)
		},

		/**
		 * Two-way bridge between form.model and the NcSelect option object.
		 */
		modelOption: {
			get() {
				return (
					this.modelOptions.find(
						(option) => option.value === this.form.model,
					) || null
				)
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
		 * @spec openspec/changes/agent-management-ui/tasks.md#task-4-1
		 */
		delegationAllowlistOptions() {
			const editingId =
				this.effectiveAgent?.uuid || this.effectiveAgent?.id || null
			return this.agentCatalog
				.filter(
					(candidate) => (candidate.uuid || candidate.id) !== editingId,
				)
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
			/**
			 * @param {boolean} open Whether the modal is now open.
			 *
			 * @return {Promise<void>}
			 * @spec openspec/changes/agent-management-ui/tasks.md#task-4-1
			 */
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

	/**
	 * @spec openspec/changes/agent-management-ui/tasks.md#task-4-1
	 */
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
		 * @spec openspec/changes/agent-management-ui/tasks.md#task-4-1
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
		 * @spec openspec/changes/agent-management-ui/tasks.md#task-4-1
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
			this.routeAgent = await store
				.fetchObject('agent', routeAgentId)
				.catch(() => null)
		},

		/**
		 * An empty agent form.
		 *
		 * @return {object} The blank form model.
		 * @spec openspec/changes/agent-management-ui/tasks.md#task-4-1
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
				voiceInputEngine: this.voiceEngineOption('auto'),
				voiceOutputEngine: this.voiceEngineOption('auto'),
				voiceSilenceTimeout: '',
				voiceConversationEnabled: false,
			}
		},

		/**
		 * One engine option object for the pickers.
		 *
		 * @param {string} value The stored value (`auto`, `browser`, `local`, `off`).
		 *
		 * @return {{value: string, label: string}} The matching option, or the first.
		 * @spec openspec/specs/speech-services/spec.md#requirement-speech-policy-is-per-agent
		 */
		voiceEngineOption(value) {
			return (
				this.voiceEngineOptions.find((option) => option.value === value)
				|| this.voiceEngineOptions[0]
			)
		},

		/**
		 * Seed the form from `effectiveAgent` (edit) or blank (create).
		 *
		 * @return {void}
		 * @spec openspec/changes/agent-management-ui/tasks.md#task-4-1
		 */
		resetForm() {
			this.error = ''
			if (!this.effectiveAgent) {
				this.form = this.blankForm()
				return
			}
			const source = this.effectiveAgent
			const tools = Array.isArray(source.tools) ? source.tools : []
			const delegationAllowlist = Array.isArray(source.delegationAllowlist)
				? source.delegationAllowlist
				: []
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
				delegationAllowlist:
					this.mapDelegationAllowlistToOptions(delegationAllowlist),

				enableRag: source.enableRag === true,
				searchObjects: source.searchObjects !== false,
				searchFiles: source.searchFiles !== false,
				ragNumSources: source.ragNumSources ?? '',
				voiceInputEngine: this.voiceEngineOption(
					source.voiceInputEngine || 'auto',
				),

				voiceOutputEngine: this.voiceEngineOption(
					source.voiceOutputEngine || 'auto',
				),

				// '' rather than 2500: an empty field means "unset, use the
				// default", and pre-filling the default would silently write it
				// onto every agent that is edited for an unrelated reason.
				voiceSilenceTimeout: source.voiceSilenceTimeout ?? '',
				voiceConversationEnabled: source.voiceConversationEnabled === true,
			}
		},

		/**
		 * Load the tool catalogue for the enabled-tools picker (Hermiq's
		 * facade-backed /api/agents/tools endpoint).
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/agent-management-ui/tasks.md#task-4-1
		 */
		async loadTools() {
			this.toolsLoading = true
			try {
				const tools = await listTools()

				// `App | tool | right` — the three things an author actually
				// chooses on. The label used to be the raw function id plus its
				// whole description, so a list of 98 read as
				// "cms_create_page — Create a new page with title and…", which
				// says neither which app is being granted access nor what the
				// tool DOES to the data.
				//
				// The app and the right come from the ENGINE
				// (`ToolRegistryFacade::describeTools()`), never from parsing
				// the id here: only the registry knows which app contributed a
				// tool, and a mapping invented in this file would be a second
				// answer free to drift from it.
				this.toolOptions = tools.map((tool) => {
					// The VALUE is unchanged — an agent still references a tool
					// by the same id it always did, so existing agents keep
					// their grants.
					const value = tool.id || tool.name || tool.key || String(tool)
					const parts = [tool.app, tool.tool, tool.right].filter(Boolean)

					return {
						label:
							parts.length === 3
								? parts.join(' | ')
								: tool.name || value,
						value,
						description: tool.description || '',
					}
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
		 * @spec openspec/changes/agent-management-ui/tasks.md#task-4-1
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
		 * @spec openspec/changes/agent-management-ui/tasks.md#task-4-1
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
					(this.form.delegationAllowlist || []).map(
						(option) => option.value,
					),
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
		 * @spec openspec/changes/agent-management-ui/tasks.md#task-4-1
		 */
		mapDelegationAllowlistToOptions(ids) {
			return (ids || []).map((id) => {
				const match = this.agentCatalog.find(
					(candidate) => (candidate.uuid || candidate.id) === id,
				)
				return { label: match?.name || id, value: id }
			})
		},

		/**
		 * Whether the current provider/model pair violates the loaded policy
		 * (client-side mirror of the ProviderFactory enforcement).
		 *
		 * @return {boolean} True when the pair is out of policy.
		 * @spec openspec/changes/agent-management-ui/tasks.md#task-4-1
		 */
		violatesPolicy() {
			const allowed = this.policy?.allowed
			if (
				!Array.isArray(allowed)
				|| allowed.length === 0
				|| !this.form.provider
			) {
				return false
			}
			const entry = allowed.find(
				(candidate) => candidate.provider === this.form.provider,
			)
			if (!entry) {
				return true
			}
			return (
				entry.models.length > 0
				&& !!this.form.model
				&& !entry.models.includes(this.form.model)
			)
		},

		/**
		 * Build the save payload. On edit, spread the existing agent payload
		 * first so schema fields this form does not surface survive the PUT
		 * (the generic objects path replaces the payload wholesale); `@self`
		 * metadata is stripped so it is never written back.
		 *
		 * 🔴 `tools` is deliberately NOT taken from the form on an EDIT
		 * (agent-capability-reach Task 6). A grant list is an authorization
		 * boundary, and this payload goes to the generic OpenRegister objects
		 * endpoint — the path a reproduced IDOR used to rewrite another user's
		 * agent grants. Changes to it go through the owner-guarded, audited
		 * tool-grants endpoint in `save()` instead, which is what makes that
		 * endpoint the single write path its docblock claims to be.
		 *
		 * The STORED list is still carried forward verbatim rather than omitted:
		 * `saveObject` is PUT-semantic and nulls anything absent, so dropping
		 * the key would clear every grant the agent has for as long as it takes
		 * the follow-up call to land — and permanently if that call failed.
		 *
		 * On CREATE the selection rides along, because the creator is the owner
		 * by definition and there is no prior object to escalate against.
		 *
		 * @return {object} The agent payload for saveObject().
		 * @spec openspec/changes/agent-management-ui/tasks.md#task-4-1
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
				tools: this.isEdit()
					? Array.isArray(base.tools)
						? base.tools
						: []
					: this.selectedGrants(),

				delegationAllowlist: (this.form.delegationAllowlist || []).map(
					(option) => option.value,
				),

				enableRag: this.form.enableRag,
				searchObjects: this.form.searchObjects,
				searchFiles: this.form.searchFiles,

				// The pickers hold `{value, label}` objects; the schema holds
				// the value. Sending the object would store `[object Object]`
				// and the agent would read as an unrecognised engine — which
				// normalises to `auto`, i.e. an agent pinned to the private
				// engine would quietly become one that may use the browser's.
				voiceInputEngine: (this.form.voiceInputEngine || {}).value || 'auto',
				voiceOutputEngine:
					(this.form.voiceOutputEngine || {}).value || 'auto',

				voiceConversationEnabled: this.form.voiceConversationEnabled,
			}

			const voiceSilenceTimeout = Number(this.form.voiceSilenceTimeout)
			if (
				this.form.voiceSilenceTimeout !== ''
				&& Number.isInteger(voiceSilenceTimeout)
				&& voiceSilenceTimeout >= 0
			) {
				payload.voiceSilenceTimeout = voiceSilenceTimeout
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
		 * Whether this modal is editing an existing agent (as opposed to creating one).
		 *
		 * @return {boolean} True when an agent id is already assigned.
		 */
		isEdit() {
			return !!(this.effectiveAgent && this.effectiveAgent.id)
		},

		/**
		 * The grant list the user has selected, as plain strings.
		 *
		 * @return {Array<string>} The selected tool grant entries.
		 * @spec openspec/changes/agent-management-ui/tasks.md#task-4-1
		 */
		selectedGrants() {
			return (this.form.tools || []).map((tool) => tool.value)
		},

		/**
		 * Persist the agent via the createObjectStore and notify the parent.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/agent-management-ui/tasks.md#task-4-1
		 */
		async save() {
			if (this.violatesPolicy()) {
				this.error = this.t(
					'hermiq',
					"The chosen provider/model is not allowed by your organisation's model policy.",
				)
				return
			}
			this.saving = true
			this.error = ''
			try {
				const wasEdit = this.isEdit()
				const saved = await this.store.saveObject(
					'agent',
					this.buildPayload(),
				)
				if (saved === null) {
					this.error =
						this.store.errors?.agent?.message
						|| this.t('hermiq', 'Could not save agent')
					return
				}

				// Grants change through the owner-guarded, audited endpoint —
				// never through the object write above. Only on edit: a create
				// already carried the selection, and the creator is the owner.
				if (wasEdit) {
					const agentId =
						saved?.['@self']?.id || saved?.id || this.effectiveAgent?.id
					try {
						await updateToolGrants(agentId, this.selectedGrants())
					} catch (grantError) {
						// The agent itself saved. Say so, rather than letting a
						// refused grant write read as "nothing was saved" — the
						// two have very different next steps for the user.
						this.error = this.t(
							'hermiq',
							"The agent was saved, but its tool grants were not updated. Only the agent's owner may change them.",
						)
						return
					}
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
