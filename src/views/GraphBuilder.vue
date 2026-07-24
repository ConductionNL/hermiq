<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  - SPDX-License-Identifier: EUPL-1.2
-->

<template>
	<div class="graph-builder">
		<div class="graph-builder__body">
			<!-- Canvas is the page: name, actions and the palette all live in the
			     sidebar, so nothing but the graph itself occupies this space. -->
			<div class="graph-builder__canvas">
				<NcButton
					v-if="!sidebarOpen"
					class="graph-builder__reopen"
					type="secondary"
					:aria-label="t('hermiq', 'Show graph panel')"
					:title="t('hermiq', 'Show graph panel')"
					@click="sidebarOpen = true">
					<template #icon>
						<DockRight :size="20" />
					</template>
				</NcButton>

				<CnGraphCanvas
					:nodes="nodes"
					:edges="edges"
					:selected-node-id="selectedNodeId"
					:node-width="200"
					:node-height="80"
					@node-select="onNodeSelect"
					@canvas-click="selectedNodeId = null"
					@node-move="onNodeMove"
					@connect="onConnect"
					@canvas-drop="onCanvasDrop">
					<template #node="{ node, selected }">
						<div
							class="graph-builder__node"
							:class="[`graph-builder__node--${node.type}`, { 'graph-builder__node--selected': selected }]">
							<span class="graph-builder__node-type">{{ typeLabel(node.type) }}</span>
							<span class="graph-builder__node-label">{{ nodeLabel(node) }}</span>
							<span v-if="traceByNode[node.id]" class="graph-builder__node-badge">
								{{ traceByNode[node.id] }}
							</span>
						</div>
					</template>
				</CnGraphCanvas>

				<NcEmptyContent
					v-if="nodes.length === 0"
					class="graph-builder__empty"
					:name="t('hermiq', 'No nodes yet')"
					:description="t('hermiq', 'Add a node from the palette to start building this agent graph.')">
					<template #icon>
						<Sitemap :size="20" />
					</template>
				</NcEmptyContent>
			</div>

			<!-- Controls panel: a flex sibling of the canvas, not NcAppSidebar.
			     NcAppSidebar is positioned by NcContent as a sibling of NcAppContent;
			     rendering one inside a page makes it an overlay that swallows pointer
			     events across the whole page (it covered the palette). This keeps the
			     same tabbed side-panel behaviour with predictable layout. -->
			<aside v-if="sidebarOpen" class="graph-builder__sidebar">
				<div class="graph-builder__sidebar-head">
					<span class="graph-builder__sidebar-title">{{ graph.name || t('hermiq', 'Untitled graph') }}</span>
					<span class="graph-builder__sidebar-sub">{{ sidebarSubname }}</span>
				</div>

				<div class="graph-builder__tabs" role="tablist">
					<button
						v-for="tab in tabs"
						:key="tab.id"
						class="graph-builder__tab"
						:class="{ 'graph-builder__tab--active': activeTab === tab.id }"
						role="tab"
						:aria-selected="activeTab === tab.id"
						@click="activeTab = tab.id">
						{{ tab.label }}
					</button>
				</div>

				<div class="graph-builder__tabpanel">
					<!-- Nodes: add from the palette, then configure the selected one. -->
					<div v-if="activeTab === 'nodes'" class="graph-builder__pane">
						<div class="graph-builder__palette">
							<button
								v-for="type in nodeTypes"
								:key="type.key"
								class="graph-builder__palette-item"
								:class="`graph-builder__palette-item--${type.key}`"
								:title="type.hint"
								draggable="true"
								@dragstart="paletteDragType = type.key"
								@dragend="paletteDragType = null"
								@click="addNode(type.key)">
								<span class="graph-builder__palette-swatch" />
								<span class="graph-builder__palette-label">{{ type.label }}</span>
							</button>
						</div>
						<p class="graph-builder__pane-hint">
							{{ t('hermiq', 'Click to add, or drag onto the canvas.') }}
						</p>

						<hr class="graph-builder__rule">

						<template v-if="selectedNode">
							<p class="graph-builder__pane-hint">{{ typeLabel(selectedNode.type) }}</p>

							<template v-if="selectedNode.type === 'agent-step'">
								<NcTextField
									:model-value="selectedNode.config.agentId || ''"
									:label="t('hermiq', 'Agent UUID')"
									@update:model-value="setConfig('agentId', $event)" />
								<NcTextArea
									:model-value="selectedNode.config.prompt || ''"
									:label="t('hermiq', 'Prompt')"
									@update:model-value="setConfig('prompt', $event)" />
							</template>

							<template v-else-if="selectedNode.type === 'object-write'">
								<NcTextField
									:model-value="selectedNode.config.field || ''"
									:label="t('hermiq', 'Field')"
									@update:model-value="setConfig('field', $event)" />
								<NcTextField
									:model-value="selectedNode.config.value || ''"
									:label="t('hermiq', 'Value')"
									@update:model-value="setConfig('value', $event)" />
							</template>

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

							<template v-else-if="selectedNode.type === 'router'">
								<NcTextField
									:model-value="selectedNode.config.on || ''"
									:label="t('hermiq', 'Route on (state key)')"
									@update:model-value="setConfig('on', $event)" />
							</template>

							<NcButton type="error" @click="removeNode(selectedNode.id)">
								{{ t('hermiq', 'Remove node') }}
							</NcButton>
						</template>

						<p v-else class="graph-builder__pane-hint">
							{{ t('hermiq', 'Select a node on the canvas to configure it.') }}
						</p>
					</div>

					<!-- Graph: identity, trigger wiring and the two verbs. -->
					<div v-else-if="activeTab === 'settings'" class="graph-builder__pane">
						<div class="graph-builder__verbs">
							<NcButton type="primary" :disabled="saving || !graph.name" @click="save">
								<template #icon>
									<NcLoadingIcon v-if="saving" :size="20" />
									<ContentSave v-else :size="20" />
								</template>
								{{ t('hermiq', 'Save') }}
							</NcButton>
							<NcButton type="secondary" :disabled="nodes.length === 0" @click="showRun = true">
								<template #icon>
									<Play :size="20" />
								</template>
								{{ t('hermiq', 'Run…') }}
							</NcButton>
						</div>
						<p v-if="dirty" class="graph-builder__pane-hint">
							{{ t('hermiq', 'Unsaved changes') }}
						</p>

						<NcTextField
							:model-value="graph.name"
							:label="t('hermiq', 'Name')"
							required
							@update:model-value="graph.name = $event" />
						<NcTextField
							:model-value="graph.description || ''"
							:label="t('hermiq', 'Description')"
							@update:model-value="graph.description = $event" />
						<NcTextField
							:model-value="graph.triggerSchema || ''"
							:label="t('hermiq', 'Trigger schema')"
							:placeholder="t('hermiq', 'e.g. case')"
							@update:model-value="graph.triggerSchema = $event" />
						<NcSelect
							v-model="graph.trigger"
							:options="triggers"
							:input-label="t('hermiq', 'Trigger')" />
						<NcCheckboxRadioSwitch v-model="graph.enabled" type="switch">
							{{ t('hermiq', 'Enabled') }}
						</NcCheckboxRadioSwitch>
						<p class="graph-builder__pane-hint">
							{{ n('hermiq', '%n node', '%n nodes', nodes.length) }} ·
							{{ n('hermiq', '%n connection', '%n connections', edges.length) }}
						</p>
					</div>

					<!-- Notes -->
					<div v-else-if="activeTab === 'notes'" class="graph-builder__pane">
						<NcTextArea
							:model-value="graph.notes || ''"
							:label="t('hermiq', 'Notes')"
							:placeholder="t('hermiq', 'Why this graph exists, what it assumes, anything the next person should know.')"
							rows="12"
							@update:model-value="graph.notes = $event" />
						<p class="graph-builder__pane-hint">{{ t('hermiq', 'Saved with the graph.') }}</p>
					</div>
				</div>
			</aside>
		</div>

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
import DockRight from 'vue-material-design-icons/DockRight.vue'
import Play from 'vue-material-design-icons/Play.vue'
import Sitemap from 'vue-material-design-icons/Sitemap.vue'
import RunGraphDialog from '../dialogs/RunGraphDialog.vue'
import { useAgentFlowStore } from '../store/store.js'

/**
 * GraphBuilder — author the agent graphs GraphExecutor walks.
 *
 * The definition is an `agentflow` OpenRegister object; this page is the visual
 * editor over one of them, reached from the Graphs index. Geometry and
 * interaction come from the shared `CnGraphCanvas`; everything here is
 * hermiq-specific — the palette matching the executor's node types, per-node
 * config, persistence, and a run that renders the executor's trace back onto
 * the nodes.
 *
 * Layout: the canvas is the page. Only the palette sits beside it (placing
 * nodes is the primary gesture); every other control lives in the app sidebar,
 * so nothing permanently occupies canvas width.
 */
export default {
	name: 'GraphBuilder',

	components: {
		CnGraphCanvas,
		ContentSave,
		Delete,
		DockRight,
		NcButton,
		NcCheckboxRadioSwitch,
		NcEmptyContent,
		NcLoadingIcon,
		NcSelect,
		NcTextArea,
		NcTextField,
		Play,
		RunGraphDialog,
		Sitemap,
	},

	props: {
		/**
		 * Graph id from the route (`/graphs/:id`). The literal `new` starts a
		 * blank graph, so creating and editing share one page.
		 */
		id: {
			type: String,
			default: 'new',
		},
	},

	data() {
		return {
			loading: false,
			saving: false,
			showRun: false,
			dirty: false,
			sidebarOpen: true,
			activeTab: 'nodes',
			tabs: [
				{ id: 'nodes', label: this.t('hermiq', 'Nodes') },
				{ id: 'settings', label: this.t('hermiq', 'Graph') },
				{ id: 'notes', label: this.t('hermiq', 'Notes') },
			],
			graphs: [],
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
		/** @return {Array<object>} Canvas nodes. */
		nodes() {
			return this.graph.nodes || []
		},

		/** @return {Array<object>} Canvas edges. */
		edges() {
			return this.graph.edges || []
		},

		/** @return {object|null} The selected node. */
		selectedNode() {
			if (this.selectedNodeId === null) {
				return null
			}

			return this.nodes.find((node) => node.id === this.selectedNodeId) || null
		},


		/** @return {string} Sidebar subtitle: what this graph reacts to. */
		sidebarSubname() {
			if (!this.graph.triggerSchema) {
				return this.t('hermiq', 'No trigger schema set')
			}

			return `${this.graph.trigger || 'object.updated'} · ${this.graph.triggerSchema}`
		},

		/** @return {object} The definition posted to the executor. */
		graphForRun() {
			return {
				name: this.graph.name,
				nodes: this.nodes,
				edges: this.edges,
				limits: this.graph.limits || {},
			}
		},

		/** @return {object} Node id => short outcome from the last run's trace. */
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

	watch: {
		graph: {
			deep: true,
			handler() {
				this.dirty = true
			},
		},
		id() {
			this.loadCurrent()
		},
	},

	created() {
		this.flowStore = useAgentFlowStore()
		this.flowStore.registerObjectType('agentflow', 'agentflow', 'hermiq')
		this.load()
	},

	methods: {
		/** @return {object} A blank graph definition. */
		emptyGraph() {
			return {
				name: '',
				description: '',
				notes: '',
				triggerSchema: '',
				trigger: 'object.updated',
				enabled: false,
				nodes: [],
				edges: [],
				limits: {},
			}
		},

		/**
		 * Load every saved graph (for the sidebar switcher), then the routed one.
		 *
		 * @return {Promise<void>}
		 */
		async load() {
			this.loading = true
			try {
				const rows = await this.flowStore.fetchCollection('agentflow')
				this.graphs = Array.isArray(rows) ? rows : []
			} catch (e) {
				this.graphs = []
			} finally {
				this.loading = false
			}

			this.loadCurrent()
		},

		/**
		 * Put the graph named by the route onto the canvas (`new` = blank).
		 *
		 * @return {void}
		 */
		loadCurrent() {
			if (!this.id || this.id === 'new') {
				this.graph = this.emptyGraph()
				this.graph.name = this.t('hermiq', 'New graph')
				this.selectedNodeId = null
				this.lastTrace = []
				this.$nextTick(() => { this.dirty = false })
				return
			}

			const match = this.graphs.find((flow) => String(flow.id) === String(this.id))
			if (match) {
				this.applyGraph(match)
			}
		},

		/**
		 * Copy a stored graph onto the canvas (deep-cloned so edits stay local
		 * until saved).
		 *
		 * @param {object} flow The stored graph object.
		 * @return {void}
		 */
		applyGraph(flow) {
			this.graph = {
				...this.emptyGraph(),
				...flow,
				nodes: Array.isArray(flow.nodes) ? JSON.parse(JSON.stringify(flow.nodes)) : [],
				edges: Array.isArray(flow.edges) ? JSON.parse(JSON.stringify(flow.edges)) : [],
			}
			this.selectedNodeId = null
			this.lastTrace = []
			this.$nextTick(() => { this.dirty = false })
		},


		/**
		 * Add a node of `type`, at an explicit canvas point when dropped.
		 *
		 * Default placement stacks a vertical chain near the left of the canvas:
		 * wide default columns pushed later nodes past the visible area, where
		 * they could be neither read nor used as a connection target.
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
				x: x === null ? 80 : x,
				y: y === null ? (60 + index * 130) : y,
				config: {},
			}
			if (index === 0) {
				node.start = true
			}

			this.graph.nodes = [...this.nodes, node]
			this.selectedNodeId = node.id
			this.activeTab = 'nodes'
		},

		/**
		 * Select a node and reveal its config.
		 *
		 * @param {string} nodeId The node id.
		 * @return {void}
		 */
		onNodeSelect(nodeId) {
			this.selectedNodeId = nodeId
			if (nodeId !== null) {
				this.activeTab = 'nodes'
				this.sidebarOpen = true
			}
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
				const rows = await this.flowStore.fetchCollection('agentflow')
				this.graphs = Array.isArray(rows) ? rows : []
				if (saved && saved.id) {
					this.graph = { ...this.graph, id: saved.id }
					if (String(this.id) !== String(saved.id)) {
						this.$router.replace({ name: 'GraphDetail', params: { id: String(saved.id) } })
					}
				}

				this.$nextTick(() => { this.dirty = false })
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
.graph-builder {
	display: flex;
	flex-direction: column;
	height: 100%;
	min-height: 0;
}

.graph-builder__body {
	display: flex;
	flex: 1 1 auto;
	min-height: 0;
}

/* Compact palette — narrow enough to leave the canvas dominant. */
.graph-builder__palette {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 6px;
}

.graph-builder__rule {
	border: none;
	border-top: 1px solid var(--color-border);
	margin: 4px 0;
}

.graph-builder__palette-item {
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

.graph-builder__palette-item:hover {
	background-color: var(--color-background-hover);
}

.graph-builder__palette-swatch {
	width: 10px;
	height: 10px;
	border-radius: 3px;
	flex: 0 0 auto;
}

.graph-builder__canvas {
	position: relative;
	flex: 1 1 auto;
	min-width: 0;
	/* Clip so a node dragged past the edge can't paint over neighbouring chrome. */
	overflow: hidden;
}

.graph-builder__empty {
	position: absolute;
	inset: 0;
	pointer-events: none;
}

.graph-builder__reopen {
	position: absolute;
	top: 12px;
	inset-inline-end: 12px;
	z-index: 2;
}

.graph-builder__verbs {
	display: flex;
	gap: 8px;
}

/* Controls panel — a flex sibling, so it never overlays the canvas or palette. */
.graph-builder__sidebar {
	width: 320px;
	flex: 0 0 auto;
	display: flex;
	flex-direction: column;
	min-height: 0;
	border-inline-start: 1px solid var(--color-border);
	background-color: var(--color-main-background);
}

.graph-builder__sidebar-head {
	display: flex;
	flex-direction: column;
	gap: 2px;
	padding: 12px 12px 8px;
}

.graph-builder__sidebar-title {
	font-weight: 600;
	font-size: 16px;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.graph-builder__sidebar-sub {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.graph-builder__tabs {
	display: flex;
	gap: 2px;
	padding: 0 8px;
	border-bottom: 1px solid var(--color-border);
}

.graph-builder__tab {
	flex: 1 1 auto;
	padding: 8px 4px;
	border: none;
	border-bottom: 2px solid transparent;
	background-color: transparent;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.graph-builder__tab:hover {
	background-color: var(--color-background-hover);
}

.graph-builder__tab--active {
	color: var(--color-main-text);
	border-bottom-color: var(--color-primary-element);
	font-weight: 600;
}

.graph-builder__tabpanel {
	flex: 1 1 auto;
	min-height: 0;
	overflow-y: auto;
	padding: 8px 12px 16px;
}

/* Sidebar panes */
.graph-builder__pane {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 8px 4px;
}

.graph-builder__pane-hint {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	margin: 0;
}

/* Node card rendered into CnGraphCanvas's `node` slot. */
.graph-builder__node {
	display: flex;
	flex-direction: column;
	justify-content: center;
	gap: 2px;
	width: 100%;
	height: 100%;
	padding: 8px 10px;
	border: 2px solid var(--color-border);
	border-inline-start-width: 6px;
	border-radius: var(--border-radius-large, 8px);
	background-color: var(--color-main-background);
	box-sizing: border-box;
	overflow: hidden;
}

.graph-builder__node--selected {
	border-color: var(--color-primary-element);
}

.graph-builder__node-type {
	font-size: 11px;
	text-transform: uppercase;
	letter-spacing: 0.04em;
	color: var(--color-text-maxcontrast);
}

.graph-builder__node-label {
	font-weight: 600;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.graph-builder__node-badge {
	align-self: flex-start;
	font-size: 11px;
	padding: 0 6px;
	border-radius: var(--border-radius-pill, 12px);
	background-color: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

/* Type accents — NC variables only (ADR-010). */
.graph-builder__node--agent-step,
.graph-builder__palette-item--agent-step .graph-builder__palette-swatch {
	border-inline-start-color: var(--color-primary-element);
	background-color: var(--color-primary-element);
}

.graph-builder__node--object-write,
.graph-builder__palette-item--object-write .graph-builder__palette-swatch {
	border-inline-start-color: var(--color-success, #46ba61);
	background-color: var(--color-success, #46ba61);
}

.graph-builder__node--condition,
.graph-builder__palette-item--condition .graph-builder__palette-swatch {
	border-inline-start-color: var(--color-warning, #c28900);
	background-color: var(--color-warning, #c28900);
}

.graph-builder__node--router,
.graph-builder__palette-item--router .graph-builder__palette-swatch {
	border-inline-start-color: var(--color-info, #4271b6);
	background-color: var(--color-info, #4271b6);
}

/* The node card must not take the palette swatch's solid fill. */
.graph-builder__node--agent-step,
.graph-builder__node--object-write,
.graph-builder__node--condition,
.graph-builder__node--router {
	background-color: var(--color-main-background);
}
</style>
