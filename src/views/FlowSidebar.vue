<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  - SPDX-License-Identifier: EUPL-1.2
-->

<template>
	<NcAppSidebar
		v-if="editor.sidebarOpen"
		:name="editor.flow.name || t('hermiq', 'Untitled flow')"
		:subname="subname"
		:active="activeTab"
		@update:active="activeTab = $event"
		@close="editor.sidebarOpen = false">
		<!-- Nodes: the places a run moves between. -->
		<NcAppSidebarTab id="nodes" :name="t('hermiq', 'Nodes')" :order="1">
			<template #icon>
				<Sitemap :size="20" />
			</template>

			<div class="flow-sidebar__pane">
				<NcButton type="secondary" @click="editor.addNode()">
					<template #icon>
						<Plus :size="20" />
					</template>
					{{ t('hermiq', 'Add node') }}
				</NcButton>
				<p class="flow-sidebar__hint">
					{{ t('hermiq', 'A node is a position in the flow — it holds no configuration. Drag from one node’s handle to another to create the step between them; that step is what runs.') }}
				</p>

				<hr class="flow-sidebar__rule">

				<template v-if="editor.selectedNode">
					<NcTextField
						:model-value="editor.selectedNode.name || ''"
						:label="t('hermiq', 'Name')"
						:placeholder="editor.selectedNode.id"
						@update:model-value="editor.setNodeName($event)" />
					<p class="flow-sidebar__hint">
						{{ t('hermiq', 'Id: {id}', { id: editor.selectedNode.id }) }} · {{ roleLabel }}
					</p>

					<NcButton type="error" @click="editor.removeNode(editor.selectedNode.id)">
						<template #icon>
							<Delete :size="20" />
						</template>
						{{ t('hermiq', 'Remove node') }}
					</NcButton>
				</template>

				<p v-else class="flow-sidebar__hint">
					{{ t('hermiq', 'Select a node on the canvas to rename it.') }}
				</p>
			</div>
		</NcAppSidebarTab>

		<!-- Step: the selected edge. This is where behaviour is authored. -->
		<NcAppSidebarTab id="step" :name="t('hermiq', 'Step')" :order="2">
			<template #icon>
				<ArrowRightBold :size="20" />
			</template>

			<div v-if="editor.selectedEdge" class="flow-sidebar__pane" data-testid="flow-step-pane">
				<p class="flow-sidebar__hint">
					{{ t('hermiq', '{from} → {to}', { from: editor.selectedEdge.from.join(', '), to: editor.selectedEdge.to.join(', ') }) }}
				</p>

				<!-- The engine's catalogue, and nothing else. A step type the
				     engine does not know is a step that resolves to nothing,
				     runs, and reports success — so there is deliberately no
				     hard-coded fallback list. An empty picker says the
				     catalogue could not be read. -->
				<NcSelect
					v-if="stepTypes.length > 0"
					:model-value="selectedStepType"
					:options="stepTypes"
					label="label"
					:input-label="t('hermiq', 'Step type')"
					:placeholder="t('hermiq', 'Pick what this step does')"
					@update:model-value="editor.setEdgeType($event ? $event.id : '')" />
				<p v-else class="flow-sidebar__hint">
					{{ t('hermiq', 'Could not read the flow engine’s step types. Reload to try again — the list is deliberately empty rather than offering types the engine cannot run.') }}
				</p>

				<p v-if="stepDescription" class="flow-sidebar__hint">
					{{ stepDescription }}
				</p>

				<!-- The one typed pane, and the only one verified against the
				     engine: HermiqAgentNode reads exactly agentId / prompt /
				     output / expectJson. -->
				<template v-if="editor.selectedEdge.type === 'hermiq.agent-step'">
					<NcSelect
						:model-value="selectedAgent"
						:options="editor.agentOptions"
						label="label"
						:input-label="t('hermiq', 'Agent')"
						:placeholder="t('hermiq', 'Pick an agent')"
						@update:model-value="editor.setEdgeConfig('agentId', $event ? $event.id : '')" />
					<NcTextArea
						:model-value="selectedConfig.prompt || ''"
						:label="t('hermiq', 'Prompt')"
						:placeholder="t('hermiq', 'Supports {{state}} placeholders')"
						@update:model-value="editor.setEdgeConfig('prompt', $event)" />
					<NcTextField
						:model-value="selectedConfig.output || ''"
						:label="t('hermiq', 'Store answer as')"
						:placeholder="t('hermiq', 'result')"
						@update:model-value="editor.setEdgeConfig('output', $event)" />
					<NcCheckboxRadioSwitch
						:model-value="selectedConfig.expectJson === true"
						type="switch"
						@update:model-value="editor.setEdgeConfig('expectJson', $event)">
						{{ t('hermiq', 'Answer must be JSON') }}
					</NcCheckboxRadioSwitch>
					<p class="flow-sidebar__hint">
						{{ jsonHint }}
					</p>
				</template>

				<!-- Everything else — the eighteen other engine step types, plus
				     any step an app contributes later — is edited as raw config.
				     Deliberately raw rather than typed-and-wrong: the engine's
				     keys differ per step (route takes `rules`/`default`, filter
				     takes `condition`, object-write takes eight), and a pane that
				     edited invented keys would look like it worked while the
				     step ignored every value. The catalogue carries no config
				     schema yet; when it does, these become declarative. -->
				<template v-else>
					<NcTextArea
						:model-value="rawConfig"
						:label="t('hermiq', 'Configuration (JSON)')"
						:error="rawConfigError !== ''"
						:helper-text="rawConfigError"
						rows="10"
						@update:model-value="onRawConfig" />
				</template>

				<NcButton type="error" @click="editor.removeEdge(editor.selectedEdge.id)">
					<template #icon>
						<Delete :size="20" />
					</template>
					{{ t('hermiq', 'Remove step') }}
				</NcButton>
			</div>

			<p v-else class="flow-sidebar__hint">
				{{ t('hermiq', 'Select a step on the canvas — the line between two nodes — to configure what it does.') }}
			</p>
		</NcAppSidebarTab>

		<!-- Flow: identity, trigger wiring and the two verbs. -->
		<NcAppSidebarTab id="flow" :name="t('hermiq', 'Flow')" :order="3">
			<template #icon>
				<Cog :size="20" />
			</template>

			<div class="flow-sidebar__pane">
				<div class="flow-sidebar__verbs">
					<NcButton type="primary" :disabled="editor.saving || !editor.flow.name" @click="save">
						<template #icon>
							<NcLoadingIcon v-if="editor.saving" :size="20" />
							<ContentSave v-else :size="20" />
						</template>
						{{ t('hermiq', 'Save') }}
					</NcButton>
					<NcButton type="secondary" :disabled="!editor.flow.id" @click="editor.showRun = true">
						<template #icon>
							<Play :size="20" />
						</template>
						{{ t('hermiq', 'Run…') }}
					</NcButton>
				</div>
				<p v-if="editor.dirty" class="flow-sidebar__hint">
					{{ t('hermiq', 'Unsaved changes') }}
				</p>

				<!-- The engine's own preflight, not a second opinion: it builds
				     the same definition the run builds and calls each step's
				     validateConfig(), so a step written in another step's
				     dialect — which resolves, runs and reports COMPLETED while
				     doing nothing — is reported here instead of at 03:00. -->
				<NcNoteCard v-if="validationMessage" :type="editor.validation.valid ? 'success' : 'error'">
					{{ validationMessage }}
				</NcNoteCard>
				<NcButton type="tertiary" @click="editor.validate()">
					<template #icon>
						<CheckDecagram :size="20" />
					</template>
					{{ t('hermiq', 'Check this flow') }}
				</NcButton>

				<NcTextField
					:model-value="editor.flow.name"
					:label="t('hermiq', 'Name')"
					required
					@update:model-value="editor.setFlowField('name', $event)" />
				<NcTextField
					:model-value="editor.flow.description || ''"
					:label="t('hermiq', 'Description')"
					@update:model-value="editor.setFlowField('description', $event)" />

				<!--
					What starts this flow, and nothing else, in one group.

					`triggerRegister`/`triggerSchema` used to render ABOVE the
					trigger picker, unconditionally, labelled only "Register"
					and "Schema" — so two fields that exist solely to say WHICH
					OBJECTS fire an object trigger read as general flow
					settings, and appeared even on a schedule or manual flow
					that has no subject at all. They belong to the trigger, so
					they follow it and appear only when one is selected.
				-->
				<NcSelect
					:model-value="editor.flow.trigger || ''"
					:options="triggers"
					:input-label="t('hermiq', 'Trigger')"
					@update:model-value="editor.setFlowField('trigger', $event)" />

				<template v-if="triggerIsObjectEvent">
					<p class="flow-sidebar__hint">
						{{ t('hermiq', 'Which objects fire this trigger.') }}
					</p>
					<CnRegisterSchemaSelect
						:register="editor.flow.triggerRegister || ''"
						:schema="editor.flow.triggerSchema || ''"
						@update:register="editor.setFlowField('triggerRegister', $event)"
						@update:schema="editor.setFlowField('triggerSchema', $event)" />
				</template>

				<!-- Only meaningful on a schedule trigger, and shown only then:
				     a cron field on an event-driven flow reads as a second,
				     competing way to fire it. -->
				<NcTextField
					v-if="editor.flow.trigger === 'schedule'"
					:model-value="editor.flow.cron || ''"
					:label="t('hermiq', 'Schedule (cron)')"
					placeholder="*/5 * * * *"
					@update:model-value="editor.setFlowField('cron', $event)" />

				<NcSelect
					:model-value="editor.flow.executionMode || 'async'"
					:options="executionModes"
					:input-label="t('hermiq', 'Execution')"
					@update:model-value="editor.setFlowField('executionMode', $event)" />

				<NcCheckboxRadioSwitch
					:model-value="editor.flow.enabled === true"
					type="switch"
					@update:model-value="editor.setFlowField('enabled', $event)">
					{{ t('hermiq', 'Enabled') }}
				</NcCheckboxRadioSwitch>

				<p class="flow-sidebar__hint">
					{{ n('hermiq', '%n node', '%n nodes', editor.nodes.length) }} ·
					{{ n('hermiq', '%n step', '%n steps', editor.edges.length) }}
				</p>
			</div>
		</NcAppSidebarTab>

		<NcAppSidebarTab id="notes" :name="t('hermiq', 'Notes')" :order="4">
			<template #icon>
				<NoteTextOutline :size="20" />
			</template>

			<div class="flow-sidebar__pane">
				<NcTextArea
					:model-value="editor.flow.notes || ''"
					:label="t('hermiq', 'Notes')"
					:placeholder="t('hermiq', 'Why this flow exists, what it assumes, anything the next person should know.')"
					rows="12"
					@update:model-value="editor.setFlowField('notes', $event)" />
				<p class="flow-sidebar__hint">
					{{ t('hermiq', 'Saved with the flow.') }}
				</p>
			</div>
		</NcAppSidebarTab>
	</NcAppSidebar>
</template>

<script>
import { NcAppSidebar, NcAppSidebarTab, NcButton, NcCheckboxRadioSwitch, NcLoadingIcon, NcNoteCard, NcSelect, NcTextArea, NcTextField } from '@nextcloud/vue'
import { CnRegisterSchemaSelect } from '@conduction/nextcloud-vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import ArrowRightBold from 'vue-material-design-icons/ArrowRightBold.vue'
import CheckDecagram from 'vue-material-design-icons/CheckDecagram.vue'
import Cog from 'vue-material-design-icons/Cog.vue'
import ContentSave from 'vue-material-design-icons/ContentSave.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import NoteTextOutline from 'vue-material-design-icons/NoteTextOutline.vue'
import Play from 'vue-material-design-icons/Play.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Sitemap from 'vue-material-design-icons/Sitemap.vue'
import { useFlowEditorStore } from '../store/flowEditor.js'

/**
 * FlowSidebar — the flow editor's controls, in Nextcloud's real app sidebar.
 *
 * Declared as the FlowDetail page's `sidebarComponent`, so CnPageRenderer
 * hands it to CnAppRoot's #sidebar slot and it renders as a genuine
 * NcAppSidebar (same place CnObjectSidebar renders) rather than a panel drawn
 * inside the page. State is shared with the canvas through the flow-editor
 * store, since the two halves live in different parts of the tree — including
 * whether this sidebar is open at all, because the re-open control has to live
 * on the canvas once this component has stopped rendering.
 *
 * ## Two editors, because there are two things to edit
 *
 * A flow is a Petri net: a NODE is a place and carries no configuration, an
 * EDGE is a transition and carries the step. So the Nodes tab renames places
 * and the Step tab configures the selected edge. The step palette used to live
 * on the Nodes tab and put catalogue step types onto nodes, which the engine
 * refuses outright (`FlowDefinitionBuilder::extractPlaces()` throws on a node
 * carrying `type`) — every flow authored through it was unrunnable.
 */
export default {
	name: 'FlowSidebar',

	components: {
		ArrowRightBold,
		CheckDecagram,
		CnRegisterSchemaSelect,
		Cog,
		ContentSave,
		Delete,
		NcAppSidebar,
		NcAppSidebarTab,
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
		NcTextArea,
		NcTextField,
		NoteTextOutline,
		Play,
		Plus,
		Sitemap,
	},

	setup() {
		return { editor: useFlowEditorStore() }
	},

	data() {
		return {
			activeTab: 'nodes',
			// Raw-config editing is a DRAFT: the textarea holds whatever is typed
			// (including a half-finished object) and only valid JSON reaches the
			// step, so a stray keystroke cannot wipe a step's configuration.
			rawConfigDraft: null,
			rawConfigError: '',
			triggers: ['object.created', 'object.updated', 'object.deleted', 'schedule', 'manual'],
			executionModes: ['async', 'sync'],
		}
	},

	computed: {
		/**
		 * Whether the selected trigger fires on an OBJECT event, and therefore
		 * has a subject to narrow.
		 *
		 * `schedule` and `manual` have no subject: a register/schema pair on
		 * either is dead configuration that is saved, never read, and reads to
		 * the next person as though it scoped something.
		 *
		 * @return {boolean} Whether to offer the register/schema pair.
		 *
		 * @spec openspec/specs/flow-canvas/spec.md
		 */
		triggerIsObjectEvent() {
			return String(this.editor.flow.trigger || '').startsWith('object.')
		},

		/**
		 * The engine's step catalogue as picker options.
		 *
		 * @return {Array<{id: string, label: string, description: string}>} The options.
		 */
		stepTypes() {
			return (this.editor.stepCatalog || []).map((entry) => ({
				id: entry.id,
				label: entry.displayName || entry.id,
				description: entry.description || '',
			}))
		},

		/**
		 * The option matching the selected step's stored type.
		 *
		 * @return {object|null} The option, or null when the step has no type.
		 */
		selectedStepType() {
			const type = this.editor.selectedEdge?.type || ''

			return this.stepTypes.find((option) => option.id === type) || null
		},

		/**
		 * What the engine says this step type does.
		 *
		 * @return {string} The description, or ''.
		 */
		stepDescription() {
			return this.selectedStepType?.description || ''
		},

		/**
		 * The selected step's config, always an object.
		 *
		 * A step is NOT obliged to carry a `config` key: one created by drawing a
		 * connection has none until a type is chosen. Reading
		 * `selectedEdge.config.prompt` off such a step throws during render and
		 * takes the whole sidebar with it.
		 *
		 * @return {object} The config, or an empty object.
		 */
		selectedConfig() {
			return this.editor.selectedEdge?.config || {}
		},

		/**
		 * The selected step's config as editable JSON.
		 *
		 * Returns the DRAFT while one is being typed, so an in-progress edit is
		 * not reformatted under the cursor on every keystroke.
		 *
		 * @return {string} Pretty-printed JSON.
		 */
		rawConfig() {
			if (this.rawConfigDraft !== null) {
				return this.rawConfigDraft
			}

			return JSON.stringify(this.selectedConfig, null, 2)
		},

		/**
		 * The selected place's role in the flow, in words.
		 *
		 * @return {string} The role.
		 */
		roleLabel() {
			const id = this.editor.selectedNodeId
			if (this.editor.startNodeIds.includes(id)) {
				return this.t('hermiq', 'A run starts here')
			}

			if (this.editor.endNodeIds.includes(id)) {
				return this.t('hermiq', 'A run ends here')
			}

			return this.t('hermiq', 'A run passes through here')
		},

		/**
		 * The preflight verdict, in words.
		 *
		 * @return {string} The message, or '' when nothing has been checked.
		 */
		validationMessage() {
			if (this.editor.validation === null) {
				return ''
			}

			if (this.editor.validation.valid) {
				return this.t('hermiq', 'The flow engine accepts this flow.')
			}

			return this.editor.validation.message
				|| this.t('hermiq', 'The flow engine will not run this flow.')
		},

		/** @return {string} Sidebar subtitle: what this flow reacts to. */
		subname() {
			const trigger = this.editor.flow.trigger || ''
			if (trigger === 'schedule') {
				return this.t('hermiq', 'Schedule · {cron}', { cron: this.editor.flow.cron || '—' })
			}

			if (!this.editor.flow.triggerSchema) {
				return trigger || this.t('hermiq', 'No trigger set')
			}

			return `${trigger || 'object.updated'} · ${this.editor.flow.triggerSchema}`
		},

		/**
		 * The dropdown option matching the selected step's stored agent id.
		 *
		 * @return {object|null} The option, or null when nothing is chosen.
		 */
		selectedAgent() {
			const id = this.editor.selectedEdge?.config?.agentId || ''

			return this.editor.agentOptions.find((option) => option.id === id) || null
		},

		/**
		 * How to read an agent step's answer downstream. Built here rather than
		 * inline because it contains placeholder tokens in double braces, which
		 * the template compiler would try to interpolate.
		 *
		 * @return {string} The hint.
		 */
		jsonHint() {
			const key = this.editor.selectedEdge?.config?.output || 'result'
			if (this.editor.selectedEdge?.config?.expectJson === true) {
				return this.t(
					'hermiq',
					'The answer is parsed, so a later step can read one field with {field} — not just the whole reply.',
					{ field: `{{${key}.someField}}` },
				)
			}

			return this.t('hermiq', 'A later step reads this step’s answer with {token}.', { token: `{{${key}}}` })
		},
	},

	watch: {
		/**
		 * Follow the selection: show the Nodes tab, and drop any raw-config draft.
		 *
		 * @param {string|null} id The newly selected place id.
		 */
		'editor.selectedNodeId'(id) {
			this.rawConfigDraft = null
			this.rawConfigError = ''
			if (id !== null) {
				this.activeTab = 'nodes'
			}
		},

		/**
		 * Follow the step selection onto the Step tab, and drop any draft.
		 *
		 * Dropping the draft matters — without it the textarea would keep showing
		 * the PREVIOUS step's configuration, and the next valid keystroke would
		 * write it onto the newly-selected step.
		 *
		 * @param {string|null} id The newly selected step id.
		 */
		'editor.selectedEdgeId'(id) {
			this.rawConfigDraft = null
			this.rawConfigError = ''
			if (id !== null) {
				this.activeTab = 'step'
			}
		},
	},

	methods: {
		/**
		 * Accept raw-config edits, keeping invalid JSON out of the step.
		 *
		 * The draft is always kept so typing is uninterrupted; the step is only
		 * written when the text parses to an object. Anything else leaves the
		 * stored config alone and reports why.
		 *
		 * @param {string} value The textarea contents.
		 * @return {void}
		 */
		onRawConfig(value) {
			this.rawConfigDraft = value

			let parsed = null
			try {
				parsed = JSON.parse(value)
			} catch (e) {
				this.rawConfigError = this.t('hermiq', 'Not valid JSON — the step keeps its previous configuration.')
				return
			}

			if (parsed === null || typeof parsed !== 'object' || Array.isArray(parsed) === true) {
				this.rawConfigError = this.t('hermiq', 'Configuration must be a JSON object.')
				return
			}

			this.rawConfigError = ''
			this.editor.setEdgeConfigAll(parsed)
		},

		/**
		 * Persist the flow, keeping the route in step when it gains an id.
		 *
		 * @return {Promise<void>}
		 */
		async save() {
			try {
				const saved = await this.editor.save()
				showSuccess(this.t('hermiq', 'Flow saved.'))
				if (saved && saved.id && String(this.$route.params.id) !== String(saved.id)) {
					this.$router.replace({ name: 'FlowDetail', params: { id: String(saved.id) } })
				}
			} catch (e) {
				showError(e?.response?.data?.error || this.t('hermiq', 'Could not save the flow.'))
			}
		},
	},
}
</script>

<style scoped>
.flow-sidebar__pane {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 4px 0 16px;
}

.flow-sidebar__hint {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	margin: 0;
}

.flow-sidebar__rule {
	border: none;
	border-top: 1px solid var(--color-border);
	margin: 12px 0;
}

.flow-sidebar__verbs {
	display: flex;
	gap: 8px;
}
</style>
