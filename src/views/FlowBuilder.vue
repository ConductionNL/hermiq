<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  - SPDX-License-Identifier: EUPL-1.2
-->

<template>
	<div class="flow-builder">
		<!-- Toolbar: which graph, and what to do with it. -->
		<div class="flow-builder__toolbar">
			<NcSelect
				v-model="selectedFlow"
				class="flow-builder__picker"
				:options="flowOptions"
				:input-label="t('hermiq', 'Agent graph')"
				:placeholder="t('hermiq', 'Select a graph')"
				:loading="loading"
				label="label"
				@update:model-value="onSelectFlow" />

			<NcButton type="secondary" @click="newFlow">
				<template #icon>
					<Plus :size="20" />
				</template>
				{{ t('hermiq', 'New graph') }}
			</NcButton>

			<NcButton type="primary" :disabled="saving || !graph.name" @click="save">
				<template #icon>
					<NcLoadingIcon v-if="saving" :size="20" />
					<ContentSave v-else :size="20" />
				</template>
				{{ t('hermiq', 'Save') }}
			</NcButton>

			<NcButton type="secondary" :disabled="running || nodes.length === 0" @click="showRun = true">
				<template #icon>
					<NcLoadingIcon v-if="running" :size="20" />
					<Play v-else :size="20" />
				</template>
				{{ t('hermiq', 'Run…') }}
			</NcButton>
		</div>

		<div class="flow-builder__body">
			<!-- Palette: drag or click to add a node of each executor-supported type. -->
			<div class="flow-builder__palette">
				<h4 class="flow-builder__palette-title">
					{{ t('hermiq', 'Nodes') }}
				</h4>
				<button
					v-for="type in nodeTypes"
					:key="type.key"
					class="flow-builder__palette-item"
					:title="type.hint"
					draggable="true"
					@dragstart="paletteDragType = type.key"
					@dragend="paletteDragType = null"
					@click="addNode(type.key)">
					<span class="flow-builder__palette-icon" :class="`flow-builder__node--${type.key}`" />
					<span class="flow-builder__palette-label">{{ type.label }}</span>
				</button>

				<p class="flow-builder__palette-hint">
					{{ t('hermiq', 'Click to add, or drag onto the canvas. Drag a node’s edge handle onto another node to connect them.') }}
				</p>
			</div>

			<!-- Canvas: geometry + interaction come from the shared CnGraphCanvas;
			     this view only supplies typed node bodies via its `node` slot. -->
			<div class="flow-builder__canvas">
				<CnGraphCanvas
					:nodes="nodes"
					:edges="edges"
					:selected-node-id="selectedNodeId"
					:node-width="200"
					:node-height="80"
					@node-select="selectedNodeId = $event"
					@canvas-click="selectedNodeId = null"
					@node-move="onNodeMove"
					@connect="onConnect"
					@canvas-drop="onCanvasDrop">
					<template #node="{ node, selected }">
						<div
							class="flow-builder__node"
							:class="[`flow-builder__node--${node.type}`, { 'flow-builder__node--selected': selected }]">
							<span class="flow-builder__node-type">{{ typeLabel(node.type) }}</span>
							<span class="flow-builder__node-label">{{ nodeLabel(node) }}</span>
							<span v-if="traceByNode[node.id]" class="flow-builder__node-badge">
								{{ traceByNode[node.id] }}
							</span>
						</div>
					</template>
				</CnGraphCanvas>

				<NcEmptyContent
					v-if="nodes.length === 0"
					class="flow-builder__empty"
					:name="t('hermiq', 'No nodes yet')"
					:description="t('hermiq', 'Add a node from the palette to start building this agent graph.')">
					<template #icon>
						<Sitemap :size="20" />
					</template>
				</NcEmptyContent>
			</div>

			<!-- Inspector: graph-level settings, or the selected node's config. -->
			<div class="flow-builder__inspector">
				<template v-if="selectedNode">
					<h4 class="flow-builder__inspector-title">
						{{ typeLabel(selectedNode.type) }}
					</h4>

					<NcTextField
						:model-value="selectedNode.id"
						:label="t('hermiq', 'Node id')"
						disabled />

					<!-- agent-step -->
					<template v-if="selectedNode.type === 'agent-step'">
						<NcTextField
							:model-value="selectedNode.config.agentId || ''"
							:label="t('hermiq', 'Agent UUID')"
							:placeholder="t('hermiq', 'The agent to run at this step')"
							@update:model-value="setConfig('agentId', $event)" />
						<NcTextArea
							:model-value="selectedNode.config.prompt || ''"
							:label="t('hermiq', 'Prompt')"
							:placeholder="t('hermiq', 'Supports {{state}} placeholders')"
							@update:model-value="setConfig('prompt', $event)" />
					</template>

					<!-- object-write -->
					<template v-else-if="selectedNode.type === 'object-write'">
						<NcTextField
							:model-value="selectedNode.config.field || ''"
							:label="t('hermiq', 'Field')"
							:placeholder="t('hermiq', 'Property to write on the subject object')"
							@update:model-value="setConfig('field', $event)" />
						<NcTextField
							:model-value="selectedNode.config.value || ''"
							:label="t('hermiq', 'Value')"
							:placeholder="t('hermiq', 'Supports {{state}} placeholders')"
							@update:model-value="setConfig('value', $event)" />
					</template>

					<!-- condition -->
					<template v-else-if="selectedNode.type === 'condition'">
						<NcTextField
							:model-value="selectedNode.config.left || ''"
							:label="t('hermiq', 'Left (state key)')"
							@update:model-value="setConfig('left', $event)" />
						<NcSelect
							v-model="selectedNode.config.operator"
							:options="operators"
							:input-label="t('hermiq', 'Operator')"
							@update:model-value="touch" />
						<NcTextField
							:model-value="selectedNode.config.right || ''"
							:label="t('hermiq', 'Right (value)')"
							@update:model-value="setConfig('right', $event)" />
					</template>

					<!-- router -->
					<template v-else-if="selectedNode.type === 'router'">
						<NcTextField
							:model-value="selectedNode.config.on || ''"
							:label="t('hermiq', 'Route on (state key)')"
							:placeholder="t('hermiq', 'Outgoing edges match this value via their `when`')"
							@update:model-value="setConfig('on', $event)" />
					</template>

					<NcButton type="error" @click="removeNode(selectedNode.id)">
						<template #icon>
							<Delete :size="20" />
						</template>
						{{ t('hermiq', 'Remove node') }}
					</NcButton>
				</template>

				<template v-else>
					<h4 class="flow-builder__inspector-title">
						{{ t('hermiq', 'Graph') }}
					</h4>
					<NcTextField
						:model-value="graph.name"
						:label="t('hermiq', 'Name')"
						required
						@update:model-value="graph.name = $event" />
					<NcTextField
						:model-value="graph.triggerSchema || ''"
						:label="t('hermiq', 'Trigger schema')"
						:placeholder="t('hermiq', 'e.g. case — which schema’s events start this graph')"
						@update:model-value="graph.triggerSchema = $event" />
					<NcSelect
						v-model="graph.trigger"
						:options="triggers"
						:input-label="t('hermiq', 'Trigger')" />
					<NcCheckboxRadioSwitch v-model="graph.enabled" type="switch">
						{{ t('hermiq', 'Enabled') }}
					</NcCheckboxRadioSwitch>

					<p v-if="edges.length > 0" class="flow-builder__edges-hint">
						{{ n('hermiq', '%n connection', '%n connections', edges.length) }}
					</p>
				</template>
			</div>
		</div>

		<!-- Run dialog: the executor needs a concrete subject object to walk against. -->
		<RunGraphDialog
			v-if="showRun"
			:graph="graphForRun"
			@close="showRun = false"
			@ran="onRan" />
	</div>
</template>

<script>
import { NcButton, NcCheckboxRadioSwitch, NcEmptyContent, NcLoadingIcon, NcSelect, NcTextArea, NcTextField } from '@nextcloud/vue'
import { CnGraphCanvas } from '@conduction/nextcloud-vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import ContentSave from 'vue-material-design-icons/ContentSave.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import Play from 'vue-material-design-icons/Play.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Sitemap from 'vue-material-design-icons/Sitemap.vue'
import RunGraphDialog from '../dialogs/RunGraphDialog.vue'
import { useAgentFlowStore } from '../store/store.js'

/**
 * FlowBuilder — author the agent graphs that GraphExecutor walks.
 *
 * The graph definition is an `agentflow` OpenRegister object; this view is the
 * visual editor over it. Geometry and interaction (pan/zoom/drag/connect) come
 * from the shared `CnGraphCanvas`; everything here is hermiq-specific: the node
 * palette matching the executor's four node types, per-type config, persistence
 * through the object store, and a run that renders the executor's trace back
 * onto the nodes.
 */
export default {
	name: 'FlowBuilder',

	components: {
		CnGraphCanvas,
		ContentSave,
		Delete,
		NcButton,
		NcCheckboxRadioSwitch,
		NcEmptyContent,
		NcLoadingIcon,
		NcSelect,
		NcTextArea,
		NcTextField,
		Play,
		Plus,
		RunGraphDialog,
		Sitemap,
	},

	data() {
		return {
			loading: false,
			saving: false,
			running: false,
			showRun: false,
			flows: [],
			selectedFlow: null,
			selectedNodeId: null,
			paletteDragType: null,
			lastTrace: [],
			graph: this.emptyGraph(),
			operators: ['equals', 'notEquals', 'contains', 'empty', 'notEmpty'],
			triggers: ['object.created', 'object.updated', 'object.deleted'],
			nodeTypes: [
				{ key: 'agent-step', label: this.t('hermiq', 'Agent step'), hint: this.t('hermiq', 'Run an agent turn and put its answer on the state') },
				{ key: 'object-write', label: this.t('hermiq', 'Object write'), hint: this.t('hermiq', 'Write a field back onto the subject object') },
				{ key: 'condition', label: this.t('hermiq', 'Condition'), hint: this.t('hermiq', 'Halt the graph unless the guard holds') },
				{ key: 'router', label: this.t('hermiq', 'Router'), hint: this.t('hermiq', 'Follow the outgoing edge matching a state value') },
			],
		}
	},

	computed: {
		/**
		 * The graph's nodes, shaped for CnGraphCanvas (needs id + x/y).
		 *
		 * @return {Array<object>} Canvas nodes.
		 */
		nodes() {
			return this.graph.nodes || []
		},

		/**
		 * The graph's edges ({source, target, when?}).
		 *
		 * @return {Array<object>} Canvas edges.
		 */
		edges() {
			return this.graph.edges || []
		},

		/**
		 * The currently-selected node, or null.
		 *
		 * @return {object|null} The selected node.
		 */
		selectedNode() {
			if (this.selectedNodeId === null) {
				return null
			}

			return this.nodes.find((node) => node.id === this.selectedNodeId) || null
		},

		/**
		 * Picker options for the saved graphs.
		 *
		 * @return {Array<object>} Options with a label + the raw object.
		 */
		flowOptions() {
			return this.flows.map((flow) => ({ label: flow.name || flow.id, value: flow }))
		},

		/**
		 * The definition posted to the executor — the canvas state, not the
		 * last-saved object, so a graph can be tried before it is persisted.
		 *
		 * @return {object} The graph definition.
		 */
		graphForRun() {
			return {
				name: this.graph.name,
				nodes: this.nodes,
				edges: this.edges,
				limits: this.graph.limits || {},
			}
		},

		/**
		 * Map of node id => outcome label from the last run's trace, rendered as
		 * a badge on the node so a run is legible on the canvas itself.
		 *
		 * @return {object} Node id => short outcome.
		 */
		traceByNode() {
			const out = {}
			for (const entry of this.lastTrace) {
				if (entry.event === 'ran' && entry.node) {
					out[entry.node] = entry.continue === false ? this.t('hermiq', 'halted') : this.t('hermiq', 'ran')
				}
			}

			return out
		},
	},

	created() {
		this.flowStore = useAgentFlowStore()
		this.flowStore.registerObjectType('agentflow', 'agentflow', 'hermiq')
		this.load()
	},

	methods: {
		/**
		 * A blank graph definition.
		 *
		 * @return {object} The empty graph.
		 */
		emptyGraph() {
			return {
				name: '',
				triggerSchema: '',
				trigger: 'object.updated',
				enabled: false,
				nodes: [],
				edges: [],
				limits: {},
			}
		},

		/**
		 * Load the saved graphs.
		 *
		 * @return {Promise<void>}
		 */
		async load() {
			this.loading = true
			try {
				const rows = await this.flowStore.fetchCollection('agentflow')
				this.flows = Array.isArray(rows) ? rows : []
			} catch (e) {
				this.flows = []
			} finally {
				this.loading = false
			}
		},

		/**
		 * Load the picked graph onto the canvas.
		 *
		 * @param {object} option The picker option.
		 * @return {void}
		 */
		onSelectFlow(option) {
			const flow = option?.value
			if (!flow) {
				return
			}

			this.graph = {
				...this.emptyGraph(),
				...flow,
				nodes: Array.isArray(flow.nodes) ? JSON.parse(JSON.stringify(flow.nodes)) : [],
				edges: Array.isArray(flow.edges) ? JSON.parse(JSON.stringify(flow.edges)) : [],
			}
			this.selectedNodeId = null
			this.lastTrace = []
		},

		/**
		 * Start a fresh graph.
		 *
		 * @return {void}
		 */
		newFlow() {
			this.graph = this.emptyGraph()
			this.graph.name = this.t('hermiq', 'New graph')
			this.selectedFlow = null
			this.selectedNodeId = null
			this.lastTrace = []
		},

		/**
		 * Add a node of `type`, at an explicit canvas point when dropped.
		 *
		 * @param {string} type The node type.
		 * @param {number} x    Canvas x (optional).
		 * @param {number} y    Canvas y (optional).
		 * @return {void}
		 */
		addNode(type, x = null, y = null) {
			const index = this.nodes.length
			const node = {
				id: `${type}-${Date.now().toString(36)}-${index}`,
				type,
				x: x === null ? (120 + (index % 3) * 260) : x,
				y: y === null ? (100 + Math.floor(index / 3) * 160) : y,
				config: {},
			}
			if (index === 0) {
				node.start = true
			}

			this.graph.nodes = [...this.nodes, node]
			this.selectedNodeId = node.id
		},

		/**
		 * Drop from the palette onto the canvas at the drop point.
		 *
		 * @param {object} payload `{x, y}` in canvas space.
		 * @return {void}
		 */
		onCanvasDrop({ x, y }) {
			if (this.paletteDragType === null) {
				return
			}

			this.addNode(this.paletteDragType, x, y)
			this.paletteDragType = null
		},

		/**
		 * Persist a node's new position.
		 *
		 * @param {object} payload `{id, x, y}`.
		 * @return {void}
		 */
		onNodeMove({ id, x, y }) {
			this.graph.nodes = this.nodes.map((node) => {
				if (node.id !== id) {
					return node
				}

				return { ...node, x, y }
			})
		},

		/**
		 * Connect two nodes (no duplicates, no self-edges).
		 *
		 * @param {object} payload `{source, target}`.
		 * @return {void}
		 */
		onConnect({ source, target }) {
			if (!source || !target || source === target) {
				return
			}

			const exists = this.edges.some((edge) => edge.source === source && edge.target === target)
			if (exists === true) {
				return
			}

			this.graph.edges = [...this.edges, { source, target }]
		},

		/**
		 * Remove a node and every edge touching it.
		 *
		 * @param {string} id The node id.
		 * @return {void}
		 */
		removeNode(id) {
			this.graph.nodes = this.nodes.filter((node) => node.id !== id)
			this.graph.edges = this.edges.filter((edge) => edge.source !== id && edge.target !== id)
			this.selectedNodeId = null
		},

		/**
		 * Write a config key on the selected node.
		 *
		 * @param {string} key   The config key.
		 * @param {*}      value The value.
		 * @return {void}
		 */
		setConfig(key, value) {
			if (this.selectedNode === null) {
				return
			}

			this.graph.nodes = this.nodes.map((node) => {
				if (node.id !== this.selectedNodeId) {
					return node
				}

				return { ...node, config: { ...(node.config || {}), [key]: value } }
			})
		},

		/**
		 * Force a re-render after an in-place NcSelect write.
		 *
		 * @return {void}
		 */
		touch() {
			this.graph = { ...this.graph }
		},

		/**
		 * Save the graph as an `agentflow` object.
		 *
		 * @return {Promise<void>}
		 */
		async save() {
			this.saving = true
			try {
				const saved = await this.flowStore.saveObject('agentflow', { ...this.graph })
				showSuccess(this.t('hermiq', 'Graph saved.'))
				await this.load()
				if (saved && saved.id) {
					this.graph = { ...this.graph, id: saved.id }
				}
			} catch (e) {
				showError(e?.response?.data?.error || this.t('hermiq', 'Could not save the graph.'))
			} finally {
				this.saving = false
			}
		},

		/**
		 * Render a completed run's trace onto the canvas.
		 *
		 * @param {object} result The run result (`{state, trace}`).
		 * @return {void}
		 */
		onRan(result) {
			this.lastTrace = Array.isArray(result?.trace) ? result.trace : []
			this.showRun = false
			showSuccess(this.t('hermiq', 'Graph run finished.'))
		},

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
		 * Short summary of what a node does, shown on the canvas card.
		 *
		 * @param {object} node The node.
		 * @return {string} The label.
		 */
		nodeLabel(node) {
			const config = node.config || {}
			if (node.type === 'agent-step') {
				return config.agentId || this.t('hermiq', 'no agent set')
			}

			if (node.type === 'object-write') {
				return config.field || this.t('hermiq', 'no field set')
			}

			if (node.type === 'condition') {
				return `${config.left || '?'} ${config.operator || 'equals'} ${config.right || '?'}`
			}

			if (node.type === 'router') {
				return config.on || this.t('hermiq', 'no state key set')
			}

			return ''
		},
	},
}
</script>

<style scoped>
.flow-builder {
	display: flex;
	flex-direction: column;
	height: 100%;
	min-height: 0;
}

.flow-builder__toolbar {
	display: flex;
	align-items: flex-end;
	gap: 12px;
	padding: 12px;
	flex-wrap: wrap;
	border-bottom: 1px solid var(--color-border);
}

.flow-builder__picker {
	min-width: 260px;
}

.flow-builder__body {
	display: flex;
	flex: 1 1 auto;
	min-height: 0;
}

.flow-builder__palette {
	width: 190px;
	flex: 0 0 auto;
	padding: 12px;
	border-right: 1px solid var(--color-border);
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.flow-builder__palette-title,
.flow-builder__inspector-title {
	margin: 0 0 4px;
}

.flow-builder__palette-item {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	background-color: var(--color-main-background);
	cursor: grab;
	text-align: left;
}

.flow-builder__palette-item:hover {
	background-color: var(--color-background-hover);
}

.flow-builder__palette-icon {
	width: 12px;
	height: 12px;
	border-radius: 3px;
	flex: 0 0 auto;
}

.flow-builder__palette-hint,
.flow-builder__edges-hint {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	margin: 8px 0 0;
}

.flow-builder__canvas {
	position: relative;
	flex: 1 1 auto;
	min-width: 0;
}

.flow-builder__empty {
	position: absolute;
	inset: 0;
	pointer-events: none;
}

.flow-builder__inspector {
	width: 300px;
	flex: 0 0 auto;
	padding: 12px;
	border-left: 1px solid var(--color-border);
	display: flex;
	flex-direction: column;
	gap: 12px;
	overflow-y: auto;
}

/* Node card rendered into CnGraphCanvas's `node` slot. */
.flow-builder__node {
	display: flex;
	flex-direction: column;
	justify-content: center;
	gap: 2px;
	width: 100%;
	height: 100%;
	padding: 8px 10px;
	border: 2px solid var(--color-border);
	border-left-width: 6px;
	border-radius: var(--border-radius-large, 8px);
	background-color: var(--color-main-background);
	box-sizing: border-box;
	overflow: hidden;
}

.flow-builder__node--selected {
	border-color: var(--color-primary-element);
}

.flow-builder__node-type {
	font-size: 11px;
	text-transform: uppercase;
	letter-spacing: 0.04em;
	color: var(--color-text-maxcontrast);
}

.flow-builder__node-label {
	font-weight: 600;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.flow-builder__node-badge {
	align-self: flex-start;
	font-size: 11px;
	padding: 0 6px;
	border-radius: var(--border-radius-pill, 12px);
	background-color: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

/* Type accents — semantic, using NC variables only (ADR-010). */
.flow-builder__node--agent-step {
	border-left-color: var(--color-primary-element);
}

.flow-builder__node--object-write {
	border-left-color: var(--color-success, #46ba61);
}

.flow-builder__node--condition {
	border-left-color: var(--color-warning, #c28900);
}

.flow-builder__node--router {
	border-left-color: var(--color-info, #4271b6);
}

span.flow-builder__node--agent-step {
	background-color: var(--color-primary-element);
}

span.flow-builder__node--object-write {
	background-color: var(--color-success, #46ba61);
}

span.flow-builder__node--condition {
	background-color: var(--color-warning, #c28900);
}

span.flow-builder__node--router {
	background-color: var(--color-info, #4271b6);
}
</style>
