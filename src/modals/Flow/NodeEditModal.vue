<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  - SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<NcModal
		:show="show"
		size="normal"
		:name="heading"
		@close="$emit('close')">
		<div class="node-edit" data-testid="flow-node-edit">
			<h2 class="node-edit__title">
				{{ heading }}
			</h2>

			<p v-if="node" class="node-edit__hint">
				{{ t('hermiq', 'Id: {id}', { id: node.id }) }}
			</p>

			<template v-if="node">
				<NcTextField
					:model-value="node.name || ''"
					:label="t('hermiq', 'Name')"
					:placeholder="node.id"
					@update:model-value="editor.setNodeName($event)" />

				<!-- The engine's catalogue, and nothing else. A node type the
				     engine does not know resolves to nothing, runs, and reports
				     success — so there is deliberately no hard-coded fallback
				     list. An empty picker says the catalogue could not be read. -->
				<NcSelect
					v-if="typeOptions.length > 0"
					:model-value="selectedType"
					:options="typeOptions"
					label="label"
					:input-label="t('hermiq', 'Node type')"
					:placeholder="t('hermiq', 'Pick what this node does')"
					@update:model-value="editor.setNodeType($event ? $event.id : '')" />
				<p v-else class="node-edit__hint">
					{{ t('hermiq', 'Could not read the flow engine’s node types. Reload to try again — the list is deliberately empty rather than offering types the engine cannot run.') }}
				</p>

				<p v-if="typeDescription" class="node-edit__hint">
					{{ typeDescription }}
				</p>

				<!-- The one typed pane, and the only one verified against the
				     engine: HermiqAgentNode reads exactly agentId / prompt /
				     output / expectJson. -->
				<template v-if="node.type === 'hermiq.agent-step'">
					<NcSelect
						:model-value="selectedAgent"
						:options="editor.agentOptions"
						label="label"
						:input-label="t('hermiq', 'Agent')"
						:placeholder="t('hermiq', 'Pick an agent')"
						@update:model-value="editor.setNodeConfig('agentId', $event ? $event.id : '')" />
					<NcTextArea
						:model-value="config.prompt || ''"
						:label="t('hermiq', 'Prompt')"
						:placeholder="t('hermiq', 'Supports {{state}} placeholders')"
						@update:model-value="editor.setNodeConfig('prompt', $event)" />
					<NcTextField
						:model-value="config.output || ''"
						:label="t('hermiq', 'Store answer as')"
						:placeholder="t('hermiq', 'result')"
						@update:model-value="editor.setNodeConfig('output', $event)" />
					<NcCheckboxRadioSwitch
						:model-value="config.expectJson === true"
						type="switch"
						@update:model-value="editor.setNodeConfig('expectJson', $event)">
						{{ t('hermiq', 'Answer must be JSON') }}
					</NcCheckboxRadioSwitch>
					<p class="node-edit__hint">
						{{ jsonHint }}
					</p>
				</template>

				<!-- Every other engine node type, plus any an app contributes
				     later, is edited as raw config. Deliberately raw rather than
				     typed-and-wrong: the engine's keys differ per node (a router
				     takes `rules`/`default`, a filter takes `condition`, an
				     object-write takes eight), and a pane that edited invented
				     keys would look like it worked while the engine ignored every
				     value. The catalogue carries no config schema yet; when it
				     does, these become declarative. -->
				<template v-else>
					<p class="node-edit__hint">
						{{ t('hermiq', 'This node type has no guided form yet — edit its configuration directly. Keys must match what the node reads.') }}
					</p>
					<NcTextArea
						:model-value="rawConfig"
						:label="t('hermiq', 'Configuration (JSON)')"
						:error="rawConfigError !== ''"
						:helper-text="rawConfigError"
						rows="10"
						@update:model-value="onRawConfig" />
				</template>

				<!-- Notes ride on the NODE, next to what it does, rather than in
				     a tab of their own: a note about a step is unreadable when it
				     is filed somewhere other than the step. -->
				<NcTextArea
					:model-value="node.notes || ''"
					:label="t('hermiq', 'Notes')"
					:placeholder="t('hermiq', 'Why this node is here, what it assumes, anything the next person should know.')"
					rows="4"
					@update:model-value="editor.setNodeField('notes', $event)" />
			</template>

			<div class="node-edit__actions">
				<NcButton type="error" @click="onRemove">
					<template #icon>
						<Delete :size="20" />
					</template>
					{{ t('hermiq', 'Remove node') }}
				</NcButton>
				<NcButton type="primary" @click="$emit('close')">
					{{ t('hermiq', 'Done') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { NcButton, NcCheckboxRadioSwitch, NcModal, NcSelect, NcTextArea, NcTextField } from '@nextcloud/vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import { translate as t } from '@nextcloud/l10n'
import { useFlowEditorStore } from '../../store/flowEditor.js'

/**
 * NodeEditModal — edit one node's type, configuration and notes.
 *
 * A modal rather than a sidebar pane. Node configuration is the longest form
 * in the editor (a raw-JSON textarea is ten rows on its own), and the sidebar
 * is 346px wide and shares its height with the palette, the flow settings and
 * the run controls. Editing there meant scrolling a narrow column while the
 * canvas — the thing being configured — was pushed off to the side.
 *
 * The writes go straight to the store, so there is no Save here: the flow's
 * own Save is what persists, and a second Save button on a modal that only
 * edits a draft would imply the node was written separately.
 *
 * @spec openspec/specs/flow-canvas/spec.md
 */
export default {
	name: 'NodeEditModal',

	components: {
		Delete,
		NcButton,
		NcCheckboxRadioSwitch,
		NcModal,
		NcSelect,
		NcTextArea,
		NcTextField,
	},

	props: {
		/** Whether the modal is open. */
		show: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['close'],

	setup() {
		return { editor: useFlowEditorStore() }
	},

	data() {
		return {
			// Kept so an in-progress edit is not reformatted under the cursor on
			// every keystroke.
			rawConfigDraft: null,
			rawConfigError: '',
		}
	},

	computed: {
		/**
		 * The node being edited.
		 *
		 * @return {object|null} The selected node.
		 */
		node() {
			return this.editor.selectedNode
		},

		/**
		 * The modal heading.
		 *
		 * @return {string} The heading.
		 */
		heading() {
			return this.node?.name || this.t('hermiq', 'Edit node')
		},

		/**
		 * The node's config, always an object.
		 *
		 * A node is not obliged to carry a `config` key — one imported, or
		 * created before the seed was added, may have none. Reading
		 * `node.config.prompt` off such a node throws during render and takes
		 * the whole modal with it.
		 *
		 * @return {object} The config.
		 */
		config() {
			return this.node?.config || {}
		},

		/**
		 * The engine's node types as picker options.
		 *
		 * @return {Array<{id: string, label: string, description: string}>} The options.
		 */
		typeOptions() {
			return (this.editor.nodeCatalog || []).map((entry) => ({
				id: entry.id,
				label: entry.displayName || entry.id,
				description: entry.description || '',
			}))
		},

		/**
		 * The node's current type as an option.
		 *
		 * @return {object|null} The selected option.
		 */
		selectedType() {
			return this.typeOptions.find((option) => option.id === this.node?.type) || null
		},

		/**
		 * What the engine says this node type does.
		 *
		 * @return {string} The description, or ''.
		 */
		typeDescription() {
			return this.selectedType?.description || ''
		},

		/**
		 * The chosen agent, for the agent-step pane.
		 *
		 * @return {object|null} The selected agent option.
		 */
		selectedAgent() {
			return (this.editor.agentOptions || []).find((option) => option.id === this.config.agentId) || null
		},

		/**
		 * The node's config as editable JSON. Returns the DRAFT while one is
		 * being typed.
		 *
		 * @return {string} Pretty-printed JSON.
		 */
		rawConfig() {
			if (this.rawConfigDraft !== null) {
				return this.rawConfigDraft
			}

			return JSON.stringify(this.config, null, 2)
		},

		/**
		 * How to read an agent step's answer downstream. Built here rather than
		 * inline because it contains placeholder tokens in double braces, which
		 * the template compiler would try to interpolate.
		 *
		 * @return {string} The hint.
		 */
		jsonHint() {
			const key = this.config.output || 'result'
			if (this.config.expectJson === true) {
				return this.t(
					'hermiq',
					'The answer is parsed, so a later node can read one field with {field} — not just the whole reply.',
					{ field: `{{${key}.someField}}` },
				)
			}

			return this.t('hermiq', 'A later node reads this node’s answer with {token}.', { token: `{{${key}}}` })
		},
	},

	watch: {
		/**
		 * Drop the raw-JSON draft when the modal moves to a different node, so
		 * one node's half-typed configuration cannot be shown as another's.
		 *
		 * @return {void}
		 */
		'editor.selectedNodeId'() {
			this.rawConfigDraft = null
			this.rawConfigError = ''
		},
	},

	methods: {
		t,

		/**
		 * Accept raw-config edits, keeping invalid JSON out of the node.
		 *
		 * The draft is always kept so typing is uninterrupted; the node is only
		 * written when the text parses to an object. Anything else leaves the
		 * stored config alone and says why.
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
				this.rawConfigError = this.t('hermiq', 'Not valid JSON — the node keeps its previous configuration.')
				return
			}

			if (parsed === null || typeof parsed !== 'object' || Array.isArray(parsed) === true) {
				this.rawConfigError = this.t('hermiq', 'Configuration must be a JSON object.')
				return
			}

			this.rawConfigError = ''
			this.editor.setNodeConfigAll(parsed)
		},

		/**
		 * Remove the node and close — leaving the modal open on a node that no
		 * longer exists would render an empty form over the canvas.
		 *
		 * @return {void}
		 */
		onRemove() {
			const id = this.node?.id
			if (id) {
				this.editor.removeNode(id)
			}
			this.$emit('close')
		},
	},
}
</script>

<style scoped>
.node-edit {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 20px;
}

.node-edit__title {
	margin: 0;
}

.node-edit__hint {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	margin: 0;
}

.node-edit__actions {
	display: flex;
	justify-content: space-between;
	gap: 8px;
	margin-top: 8px;
}
</style>
