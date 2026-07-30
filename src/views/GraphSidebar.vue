<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  - SPDX-License-Identifier: EUPL-1.2
-->

<template>
	<NcAppSidebar
		:name="editor.graph.name || t('hermiq', 'Untitled graph')"
		:subname="subname"
		:active="activeTab"
		@update:active="activeTab = $event">
		<!-- Nodes: add from the palette, then configure the selected one. -->
		<NcAppSidebarTab id="nodes" :name="t('hermiq', 'Nodes')" :order="1">
			<template #icon>
				<Sitemap :size="20" />
			</template>

			<p v-if="paletteTypes.length === 0" class="graph-sidebar__hint">
				{{ t('hermiq', 'Could not read the flow engine’s node types. Reload to try again — the palette is deliberately empty rather than offering types the engine cannot run.') }}
			</p>
			<div v-else class="graph-sidebar__palette">
				<button
					v-for="type in paletteTypes"
					:key="type.key"
					class="graph-sidebar__palette-item"
					:class="`graph-sidebar__palette-item--${typeSlug(type.key)}`"
					:title="type.hint"
					draggable="true"
					@dragstart="editor.paletteDragType = type.key"
					@dragend="editor.paletteDragType = null"
					@click="editor.addNode(type.key)">
					<span class="graph-sidebar__swatch" />
					<span>{{ type.label }}</span>
				</button>
			</div>
			<p class="graph-sidebar__hint">
				{{ t('hermiq', 'Click to add, or drag onto the canvas.') }}
			</p>

			<hr class="graph-sidebar__rule">

			<div v-if="editor.selectedNode" class="graph-sidebar__pane" data-testid="graph-node-pane">
				<p class="graph-sidebar__hint">
					{{ typeLabel(editor.selectedNode.type) }}
				</p>

				<!-- The one typed pane, and the only one verified against the
				     engine: HermiqAgentNode reads exactly agentId / prompt /
				     output / expectJson. -->
				<template v-if="editor.selectedNode.type === 'hermiq.agent-step'">
					<NcSelect
						:model-value="selectedAgent"
						:options="editor.agentOptions"
						label="label"
						:input-label="t('hermiq', 'Agent')"
						:placeholder="t('hermiq', 'Pick an agent')"
						@update:model-value="editor.setNodeConfig('agentId', $event ? $event.id : '')" />
					<NcTextArea
						:model-value="selectedConfig.prompt || ''"
						:label="t('hermiq', 'Prompt')"
						:placeholder="t('hermiq', 'Supports {{state}} placeholders')"
						@update:model-value="editor.setNodeConfig('prompt', $event)" />
					<NcTextField
						:model-value="selectedConfig.output || ''"
						:label="t('hermiq', 'Store answer as')"
						:placeholder="t('hermiq', 'result')"
						@update:model-value="editor.setNodeConfig('output', $event)" />
					<NcCheckboxRadioSwitch
						:model-value="selectedConfig.expectJson === true"
						type="switch"
						@update:model-value="editor.setNodeConfig('expectJson', $event)">
						{{ t('hermiq', 'Answer must be JSON') }}
					</NcCheckboxRadioSwitch>
					<p class="graph-sidebar__hint">
						{{ jsonHint }}
					</p>
				</template>

				<!-- Everything else — the eight engine node types with no typed
				     pane, plus any node an app contributes later — is edited as
				     raw config. Deliberately raw rather than typed-and-wrong: the
				     engine's keys differ per node (route takes `rules`/`default`,
				     filter takes `condition`, object-write takes eight), and a
				     pane that edited invented keys would look like it worked while
				     the engine ignored every value. The catalogue carries no
				     config schema yet; when it does, these become declarative. -->
				<template v-else>
					<p class="graph-sidebar__hint">
						{{ t('hermiq', 'This node type has no guided form yet — edit its configuration directly. Keys must match what the node reads.') }}
					</p>
					<NcTextArea
						:model-value="rawConfig"
						:label="t('hermiq', 'Configuration (JSON)')"
						:error="rawConfigError !== ''"
						:helper-text="rawConfigError"
						rows="10"
						@update:model-value="onRawConfig" />
					<p v-if="nodeDescription" class="graph-sidebar__hint">
						{{ nodeDescription }}
					</p>
				</template>

				<NcButton type="error" @click="editor.removeNode(editor.selectedNode.id)">
					<template #icon>
						<Delete :size="20" />
					</template>
					{{ t('hermiq', 'Remove node') }}
				</NcButton>
			</div>

			<p v-else class="graph-sidebar__hint">
				{{ t('hermiq', 'Select a node on the canvas to configure it.') }}
			</p>
		</NcAppSidebarTab>

		<!-- Graph: identity, trigger wiring and the two verbs. -->
		<NcAppSidebarTab id="graph" :name="t('hermiq', 'Graph')" :order="2">
			<template #icon>
				<Cog :size="20" />
			</template>

			<div class="graph-sidebar__pane">
				<div class="graph-sidebar__verbs">
					<NcButton type="primary" :disabled="editor.saving || !editor.graph.name" @click="save">
						<template #icon>
							<NcLoadingIcon v-if="editor.saving" :size="20" />
							<ContentSave v-else :size="20" />
						</template>
						{{ t('hermiq', 'Save') }}
					</NcButton>
					<NcButton type="secondary" :disabled="editor.nodes.length === 0" @click="editor.showRun = true">
						<template #icon>
							<Play :size="20" />
						</template>
						{{ t('hermiq', 'Run…') }}
					</NcButton>
				</div>
				<p v-if="editor.dirty" class="graph-sidebar__hint">
					{{ t('hermiq', 'Unsaved changes') }}
				</p>

				<NcTextField
					:model-value="editor.graph.name"
					:label="t('hermiq', 'Name')"
					required
					@update:model-value="editor.setGraphField('name', $event)" />
				<NcTextField
					:model-value="editor.graph.description || ''"
					:label="t('hermiq', 'Description')"
					@update:model-value="editor.setGraphField('description', $event)" />

				<CnRegisterSchemaSelect
					:register="editor.graph.triggerRegister || ''"
					:schema="editor.graph.triggerSchema || ''"
					@update:register="editor.setGraphField('triggerRegister', $event)"
					@update:schema="editor.setGraphField('triggerSchema', $event)" />

				<NcSelect
					:model-value="editor.graph.trigger || 'object.updated'"
					:options="triggers"
					:input-label="t('hermiq', 'Trigger')"
					@update:model-value="editor.setGraphField('trigger', $event)" />

				<NcCheckboxRadioSwitch
					:model-value="editor.graph.enabled === true"
					type="switch"
					@update:model-value="editor.setGraphField('enabled', $event)">
					{{ t('hermiq', 'Enabled') }}
				</NcCheckboxRadioSwitch>

				<p class="graph-sidebar__hint">
					{{ n('hermiq', '%n node', '%n nodes', editor.nodes.length) }} ·
					{{ n('hermiq', '%n connection', '%n connections', editor.edges.length) }}
				</p>
			</div>
		</NcAppSidebarTab>

		<NcAppSidebarTab id="notes" :name="t('hermiq', 'Notes')" :order="3">
			<template #icon>
				<NoteTextOutline :size="20" />
			</template>

			<div class="graph-sidebar__pane">
				<NcTextArea
					:model-value="editor.graph.notes || ''"
					:label="t('hermiq', 'Notes')"
					:placeholder="t('hermiq', 'Why this graph exists, what it assumes, anything the next person should know.')"
					rows="12"
					@update:model-value="editor.setGraphField('notes', $event)" />
				<p class="graph-sidebar__hint">
					{{ t('hermiq', 'Saved with the graph.') }}
				</p>
			</div>
		</NcAppSidebarTab>
	</NcAppSidebar>
</template>

<script>
import { NcAppSidebar, NcAppSidebarTab, NcButton, NcCheckboxRadioSwitch, NcLoadingIcon, NcSelect, NcTextArea, NcTextField } from '@nextcloud/vue'
import { CnRegisterSchemaSelect } from '@conduction/nextcloud-vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import Cog from 'vue-material-design-icons/Cog.vue'
import ContentSave from 'vue-material-design-icons/ContentSave.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import NoteTextOutline from 'vue-material-design-icons/NoteTextOutline.vue'
import Play from 'vue-material-design-icons/Play.vue'
import Sitemap from 'vue-material-design-icons/Sitemap.vue'
import { useGraphEditorStore } from '../store/graphEditor.js'

/**
 * GraphSidebar — the graph editor's controls, in Nextcloud's real app sidebar.
 *
 * Declared as the GraphDetail page's `sidebarComponent`, so CnPageRenderer
 * hands it to CnAppRoot's #sidebar slot and it renders as a genuine
 * NcAppSidebar (same place CnObjectSidebar renders) rather than a panel drawn
 * inside the page. State is shared with the canvas through the graph-editor
 * store, since the two halves live in different parts of the tree.
 */
export default {
	name: 'GraphSidebar',

	components: {
		CnRegisterSchemaSelect,
		Cog,
		ContentSave,
		Delete,
		NcAppSidebar,
		NcAppSidebarTab,
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcSelect,
		NcTextArea,
		NcTextField,
		NoteTextOutline,
		Play,
		Sitemap,
	},

	setup() {
		return { editor: useGraphEditorStore() }
	},

	data() {
		return {
			activeTab: 'nodes',
			// Raw-config editing is a DRAFT: the textarea holds whatever is typed
			// (including a half-finished object) and only valid JSON reaches the
			// node, so a stray keystroke cannot wipe a node's configuration.
			rawConfigDraft: null,
			rawConfigError: '',
			triggers: ['object.created', 'object.updated', 'object.deleted'],
		}
	},

	computed: {
		/**
		 * The palette: the engine's node catalogue, and nothing else.
		 *
		 * The catalogue is authoritative (ADR-065): a node dropped from it carries
		 * a type OpenRegister's engine can execute. There is deliberately NO
		 * hard-coded fallback — the builder used to keep its own list of five type
		 * keys, none of which the engine knows, so every node created from it was
		 * unrunnable. An empty palette says the catalogue could not be read; a
		 * fallback palette would hide it behind types that quietly do not work.
		 *
		 * @return {Array<{key: string, label: string, hint: string}>} Palette entries.
		 */
		paletteTypes() {
			return (this.editor.nodeCatalog || []).map((entry) => ({
				key: entry.id,
				label: entry.displayName || entry.id,
				hint: entry.description || '',
			}))
		},

		/**
		 * The selected node's config, always an object.
		 *
		 * A node is NOT obliged to carry a `config` key: one created from the
		 * palette before it was seeded, or imported, may have none. Reading
		 * `selectedNode.config.prompt` off such a node throws during render and
		 * takes the whole sidebar with it — palette included — which looks exactly
		 * like "the sidebar does not work". The panes only stopped hitting this
		 * because their type keys never matched a node in the first place.
		 *
		 * @return {object} The config, or an empty object.
		 */
		selectedConfig() {
			return this.editor.selectedNode?.config || {}
		},

		/**
		 * The selected node's config as editable JSON.
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
		 * What the engine says this node type does, when the catalogue knows it.
		 *
		 * @return {string} The description, or ''.
		 */
		nodeDescription() {
			const type = this.editor.selectedNode?.type
			const entry = (this.editor.nodeCatalog || []).find((candidate) => candidate.id === type)

			return entry?.description || ''
		},

		/** @return {string} Sidebar subtitle: what this graph reacts to. */
		subname() {
			if (!this.editor.graph.triggerSchema) {
				return this.t('hermiq', 'No trigger schema set')
			}

			return `${this.editor.graph.trigger || 'object.updated'} · ${this.editor.graph.triggerSchema}`
		},

		/**
		 * The dropdown option matching the selected node's stored agent id.
		 *
		 * @return {object|null} The option, or null when nothing is chosen.
		 */
		selectedAgent() {
			const id = this.editor.selectedNode?.config?.agentId || ''
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
			const key = this.editor.selectedNode?.config?.output || 'result'
			if (this.editor.selectedNode?.config?.expectJson === true) {
				return this.t(
					'hermiq',
					'The answer is parsed, so a later node can read one field with {field} — not just the whole reply.',
					{ field: `{{${key}.someField}}` },
				)
			}

			return this.t('hermiq', 'A later node reads this step’s answer with {token}.', { token: `{{${key}}}` })
		},
	},

	watch: {
		/**
		 * Follow the selection: show the Nodes tab, and drop any raw-config draft.
		 *
		 * Dropping the draft matters — without it the textarea would keep showing
		 * the PREVIOUS node's configuration, and the next valid keystroke would
		 * write it onto the newly-selected node.
		 *
		 * @param {string|null} id The newly selected node id.
		 */
		'editor.selectedNodeId'(id) {
			this.rawConfigDraft = null
			this.rawConfigError = ''
			if (id !== null) {
				this.activeTab = 'nodes'
			}
		},
	},

	methods: {
		/**
		 * Human label for a node type.
		 *
		 * @param {string} type The node type.
		 * @return {string} The label.
		 */
		typeLabel(type) {
			const entry = (this.editor.nodeCatalog || []).find((candidate) => candidate.id === type)
			if (entry) {
				return entry.displayName || entry.id
			}

			// No local name table: a type the catalogue cannot explain is shown as
			// its raw id, which is the truth, rather than a guess from a list that
			// may not match the engine.
			return type || '—'
		},

		/**
		 * A node type turned into a usable CSS class suffix.
		 *
		 * Engine ids are namespaced (`hermiq.agent-step`), and a dot in the middle
		 * of a class name is a compound selector, not a name — so the per-type
		 * accent silently matched nothing for every catalogue type.
		 *
		 * @param {string} type The node type.
		 * @return {string} The slug.
		 */
		typeSlug(type) {
			return String(type || '').replace(/[^a-zA-Z0-9]+/g, '-')
		},

		/**
		 * Accept raw-config edits, keeping invalid JSON out of the node.
		 *
		 * The draft is always kept so typing is uninterrupted; the node is only
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
		 * Persist the graph, keeping the route in step when it gains an id.
		 *
		 * @return {Promise<void>}
		 */
		async save() {
			try {
				const saved = await this.editor.save()
				showSuccess(this.t('hermiq', 'Graph saved.'))
				if (saved && saved.id && String(this.$route.params.id) !== String(saved.id)) {
					this.$router.replace({ name: 'GraphDetail', params: { id: String(saved.id) } })
				}
			} catch (e) {
				showError(e?.response?.data?.error || this.t('hermiq', 'Could not save the graph.'))
			}
		},
	},
}
</script>

<style scoped>
.graph-sidebar__pane {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 4px 0 16px;
}

.graph-sidebar__palette {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 6px;
	padding-top: 4px;
}

.graph-sidebar__palette-item {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	background-color: var(--color-main-background);
	cursor: grab;
	text-align: start;
	font-size: 13px;
}

.graph-sidebar__palette-item:hover {
	background-color: var(--color-background-hover);
}

.graph-sidebar__swatch {
	width: 10px;
	height: 10px;
	border-radius: 3px;
	flex: 0 0 auto;
}

.graph-sidebar__palette-item--trigger .graph-sidebar__swatch {
	background-color: var(--color-warning, #c28900);
}

.graph-sidebar__palette-item--agent-step .graph-sidebar__swatch {
	background-color: var(--color-primary-element);
}

.graph-sidebar__palette-item--object-write .graph-sidebar__swatch {
	background-color: var(--color-success, #46ba61);
}

.graph-sidebar__palette-item--condition .graph-sidebar__swatch {
	background-color: var(--color-warning, #c28900);
}

.graph-sidebar__palette-item--router .graph-sidebar__swatch {
	background-color: var(--color-info, #4271b6);
}

.graph-sidebar__hint {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	margin: 0;
}

.graph-sidebar__rule {
	border: none;
	border-top: 1px solid var(--color-border);
	margin: 12px 0;
}

.graph-sidebar__verbs {
	display: flex;
	gap: 8px;
}
</style>
