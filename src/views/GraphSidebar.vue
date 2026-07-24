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

			<div class="graph-sidebar__palette">
				<button
					v-for="type in nodeTypes"
					:key="type.key"
					class="graph-sidebar__palette-item"
					:class="`graph-sidebar__palette-item--${type.key}`"
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

			<div v-if="editor.selectedNode" class="graph-sidebar__pane">
				<p class="graph-sidebar__hint">
					{{ typeLabel(editor.selectedNode.type) }}
				</p>

				<!-- Trigger: what starts this graph. Register first, then schema —
				     the same cascade used elsewhere in the fleet. -->
				<template v-if="editor.selectedNode.type === 'trigger'">
					<CnRegisterSchemaSelect
						:register="editor.selectedNode.config.triggerRegister || ''"
						:schema="editor.selectedNode.config.triggerSchema || ''"
						@update:register="editor.setNodeConfig('triggerRegister', $event)"
						@update:schema="editor.setNodeConfig('triggerSchema', $event)" />
					<NcSelect
						:model-value="editor.selectedNode.config.event || 'object.updated'"
						:options="triggers"
						:input-label="t('hermiq', 'On event')"
						@update:model-value="editor.setNodeConfig('event', $event)" />
					<p class="graph-sidebar__hint">
						{{ t('hermiq', 'A trigger node is the graph’s entry point; its wiring is what the event listener matches on.') }}
					</p>
				</template>

				<template v-else-if="editor.selectedNode.type === 'agent-step'">
					<NcSelect
						:model-value="selectedAgent"
						:options="editor.agentOptions"
						label="label"
						:input-label="t('hermiq', 'Agent')"
						:placeholder="t('hermiq', 'Pick an agent')"
						@update:model-value="editor.setNodeConfig('agentId', $event ? $event.id : '')" />
					<NcTextArea
						:model-value="editor.selectedNode.config.prompt || ''"
						:label="t('hermiq', 'Prompt')"
						:placeholder="t('hermiq', 'Supports {{state}} placeholders')"
						@update:model-value="editor.setNodeConfig('prompt', $event)" />
					<NcTextField
						:model-value="editor.selectedNode.config.output || ''"
						:label="t('hermiq', 'Store answer as')"
						:placeholder="t('hermiq', 'result')"
						@update:model-value="editor.setNodeConfig('output', $event)" />
					<NcCheckboxRadioSwitch
						:model-value="editor.selectedNode.config.expectJson === true"
						type="switch"
						@update:model-value="editor.setNodeConfig('expectJson', $event)">
						{{ t('hermiq', 'Answer must be JSON') }}
					</NcCheckboxRadioSwitch>
					<p class="graph-sidebar__hint">
						{{ jsonHint }}
					</p>
				</template>

				<!-- Object write targets a register/schema too, so it gets the
				     same cascade rather than a free-text field. -->
				<template v-else-if="editor.selectedNode.type === 'object-write'">
					<NcTextField
						:model-value="editor.selectedNode.config.field || ''"
						:label="t('hermiq', 'Field')"
						:placeholder="t('hermiq', 'Property to write on the subject object')"
						@update:model-value="editor.setNodeConfig('field', $event)" />
					<NcTextField
						:model-value="editor.selectedNode.config.value || ''"
						:label="t('hermiq', 'Value')"
						:placeholder="t('hermiq', 'Supports {{state}} placeholders')"
						@update:model-value="editor.setNodeConfig('value', $event)" />
				</template>

				<template v-else-if="editor.selectedNode.type === 'condition'">
					<NcTextField
						:model-value="editor.selectedNode.config.left || ''"
						:label="t('hermiq', 'Left (state key)')"
						@update:model-value="editor.setNodeConfig('left', $event)" />
					<NcSelect
						:model-value="editor.selectedNode.config.operator || 'equals'"
						:options="operators"
						:input-label="t('hermiq', 'Operator')"
						@update:model-value="editor.setNodeConfig('operator', $event)" />
					<NcTextField
						:model-value="editor.selectedNode.config.right || ''"
						:label="t('hermiq', 'Right (value)')"
						@update:model-value="editor.setNodeConfig('right', $event)" />
				</template>

				<template v-else-if="editor.selectedNode.type === 'router'">
					<NcTextField
						:model-value="editor.selectedNode.config.on || ''"
						:label="t('hermiq', 'Route on (state key)')"
						@update:model-value="editor.setNodeConfig('on', $event)" />
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
			operators: ['equals', 'notEquals', 'contains', 'empty', 'notEmpty'],
			triggers: ['object.created', 'object.updated', 'object.deleted'],
			nodeTypes: [
				{ key: 'trigger', label: this.t('hermiq', 'Trigger'), hint: this.t('hermiq', 'Start the graph on an object create/update/delete event') },
				{ key: 'agent-step', label: this.t('hermiq', 'Agent step'), hint: this.t('hermiq', 'Run an agent turn and put its answer on the state') },
				{ key: 'object-write', label: this.t('hermiq', 'Object write'), hint: this.t('hermiq', 'Write a field back onto the subject object') },
				{ key: 'condition', label: this.t('hermiq', 'Condition'), hint: this.t('hermiq', 'Halt the graph unless the guard holds') },
				{ key: 'router', label: this.t('hermiq', 'Router'), hint: this.t('hermiq', 'Follow the outgoing edge matching a state value') },
			],
		}
	},

	computed: {
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
		'editor.selectedNodeId'(id) {
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
			const match = this.nodeTypes.find((candidate) => candidate.key === type)
			return match ? match.label : (type || '—')
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
