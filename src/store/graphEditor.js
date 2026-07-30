// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Shared state for the graph editor.
//
// The canvas (GraphBuilder.vue) and the controls (GraphSidebar.vue) are
// rendered in two different places: the page body and Nextcloud's real app
// sidebar (via the manifest's `pages[].sidebarComponent`, which CnAppRoot
// mounts into its #sidebar slot). They therefore cannot pass props to one
// another, so the graph being edited lives here instead.
//
// Persistence still goes through `useAgentFlowStore` (the canonical
// OpenRegister object store) — this store only holds the in-flight editing
// state and the actions both halves need to trigger.

import { defineStore } from 'pinia'
import { useAgentFlowStore, useAgentStore } from './store.js'
import { runGraph } from '../api/graph.js'

/**
 * A blank graph definition.
 *
 * @return {object} The empty graph.
 */
function emptyGraph() {
	return {
		name: '',
		description: '',
		notes: '',
		triggerRegister: '',
		triggerSchema: '',
		trigger: 'object.updated',
		enabled: false,
		nodes: [],
		edges: [],
		limits: {},
	}
}

export const useGraphEditorStore = defineStore('graphEditor', {
	state: () => ({
		graph: emptyGraph(),
		graphs: [],
		agents: [],
		selectedNodeId: null,
		paletteDragType: null,
		lastTrace: [],
		loading: false,
		saving: false,
		dirty: false,
		showRun: false,
	}),

	getters: {
		/**
		 * @param {object} state The graph-editor store state.
		 * @return {Array<object>} Canvas nodes.
		 */
		nodes: (state) => state.graph.nodes || [],

		/**
		 * @param {object} state The graph-editor store state.
		 * @return {Array<object>} Canvas edges.
		 */
		edges: (state) => state.graph.edges || [],

		/**
		 * @param {object} state The graph-editor store state.
		 * @return {object|null} The selected node.
		 */
		selectedNode: (state) => {
			if (state.selectedNodeId === null) {
				return null
			}

			return (state.graph.nodes || []).find((node) => node.id === state.selectedNodeId) || null
		},

		/**
		 * Node id => short outcome from the last run's trace, so a run is
		 * legible on the canvas itself.
		 *
		 * @param {object} state The graph-editor store state.
		 * @return {object} Node id => outcome.
		 */
		traceByNode: (state) => {
			const out = {}
			for (const entry of state.lastTrace) {
				if (entry.event === 'ran' && entry.node) {
					out[entry.node] = entry.continue === false ? 'halted' : 'ran'
				}
			}

			return out
		},

		/**
		 * Node id => that node's trace entry from the last run, so an edge can
		 * offer "what came out of here". Only nodes that actually ran appear.
		 *
		 * @param {object} state The graph-editor store state.
		 * @return {object} Node id => trace entry.
		 */
		resultByNode: (state) => {
			const out = {}
			for (const entry of state.lastTrace) {
				if (entry.event === 'ran' && entry.node) {
					out[entry.node] = entry
				}
			}

			return out
		},

		/**
		 * Agents as dropdown options. The id is what the node stores; the
		 * label is what an author recognises.
		 *
		 * @param {object} state The graph-editor store state.
		 * @return {Array<object>} `{id, label, maxTokens}` per agent.
		 */
		agentOptions: (state) => state.agents.map((agent) => ({
			id: agent['@self']?.id || agent.id,
			label: agent.name || agent['@self']?.id || agent.id,
			maxTokens: agent.maxTokens ?? null,
		})).filter((option) => option.id),

		/**
		 * @param {object} state The graph-editor store state.
		 * @return {object} The definition posted to the executor.
		 */
		graphForRun: (state) => ({
			name: state.graph.name,
			nodes: state.graph.nodes || [],
			edges: state.graph.edges || [],
			limits: state.graph.limits || {},
		}),
	},

	actions: {
		/**
		 * Load every saved graph, then put the routed one on the canvas.
		 *
		 * @param {string} id The route id (`new` for a blank graph).
		 * @return {Promise<void>}
		 */
		async load(id) {
			const flowStore = useAgentFlowStore()
			flowStore.registerObjectType('agentflow', 'agentflow', 'hermiq')

			this.loading = true
			try {
				const rows = await flowStore.fetchCollection('agentflow')
				this.graphs = Array.isArray(rows) ? rows : []
			} catch (e) {
				this.graphs = []
			} finally {
				this.loading = false
			}

			this.loadAgents()
			this.open(id)
		},

		/**
		 * Load the agents an agent-step node can choose from.
		 *
		 * Kept off the critical path: the canvas is usable while this resolves,
		 * and a failure only costs the dropdown, not the editor.
		 *
		 * @return {Promise<void>}
		 */
		async loadAgents() {
			try {
				const agentStore = useAgentStore()
				agentStore.registerObjectType('agent', 'agent', 'hermiq')
				const rows = await agentStore.fetchCollection('agent')
				this.agents = Array.isArray(rows) ? rows : []
			} catch (e) {
				// Logged, not swallowed: an empty agent dropdown with no trace of
				// why is indistinguishable from "this instance has no agents".
				console.error('hermiq: could not load agents for the graph editor', e)
				this.agents = []
			}
		},

		/**
		 * Put the graph named by the route onto the canvas.
		 *
		 * @param {string} id The route id.
		 * @return {void}
		 */
		open(id) {
			if (!id || id === 'new') {
				this.graph = { ...emptyGraph(), name: 'New graph' }
				this.selectedNodeId = null
				this.lastTrace = []
				this.dirty = false
				return
			}

			const match = this.graphs.find((flow) => String(flow.id) === String(id))
			if (!match) {
				return
			}

			this.graph = {
				...emptyGraph(),
				...match,
				nodes: Array.isArray(match.nodes) ? JSON.parse(JSON.stringify(match.nodes)) : [],
				edges: Array.isArray(match.edges) ? JSON.parse(JSON.stringify(match.edges)) : [],
			}
			this.selectedNodeId = null
			this.lastTrace = []
			this.dirty = false
		},

		/**
		 * Add a node of `type`, at an explicit canvas point when dropped.
		 *
		 * Default placement stacks a vertical chain near the left: wide default
		 * columns pushed later nodes past the visible canvas, where they could
		 * neither be read nor used as a connection target. The step leaves a
		 * gap roughly the height of a card, so the arrowhead and the result
		 * badge that sit on the connecting edge both have room to read.
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
				y: y === null ? (60 + index * 170) : y,
				config: {},
			}
			if (index === 0) {
				node.start = true
			}

			this.graph.nodes = [...this.nodes, node]
			this.selectedNodeId = node.id
			this.dirty = true
		},

		/**
		 * Persist a node's new position.
		 *
		 * @param {object} payload `{id, x, y}`.
		 * @param {string} payload.id The node id to move.
		 * @param {number} payload.x New x-coordinate in canvas space.
		 * @param {number} payload.y New y-coordinate in canvas space.
		 * @return {void}
		 */
		moveNode({ id, x, y }) {
			this.graph.nodes = this.nodes.map((node) => {
				if (node.id !== id) {
					return node
				}

				return { ...node, x, y }
			})
			this.dirty = true
		},

		/**
		 * Connect two nodes (no duplicates, no self-edges).
		 *
		 * @param {object} payload `{source, target}`.
		 * @param {string} payload.source The edge's source node id.
		 * @param {string} payload.target The edge's target node id.
		 * @return {void}
		 */
		connect({ source, target }) {
			if (!source || !target || source === target) {
				return
			}

			if (this.edges.some((edge) => edge.source === source && edge.target === target)) {
				return
			}

			this.graph.edges = [...this.edges, { source, target }]
			this.dirty = true
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
			this.dirty = true
		},

		/**
		 * Write a config key on the selected node.
		 *
		 * @param {string} key   The config key.
		 * @param {*}      value The value.
		 * @return {void}
		 */
		setNodeConfig(key, value) {
			if (this.selectedNodeId === null) {
				return
			}

			this.graph.nodes = this.nodes.map((node) => {
				if (node.id !== this.selectedNodeId) {
					return node
				}

				return { ...node, config: { ...(node.config || {}), [key]: value } }
			})
			this.dirty = true
		},

		/**
		 * Write a graph-level field.
		 *
		 * @param {string} key   The field.
		 * @param {*}      value The value.
		 * @return {void}
		 */
		setGraphField(key, value) {
			this.graph = { ...this.graph, [key]: value }
			this.dirty = true
		},

		/**
		 * Save the graph as an `agentflow` object.
		 *
		 * A trigger node is the authored entry point, so its config is mirrored
		 * onto the graph fields the event listener actually matches on — the
		 * canvas stays the single place triggering is configured.
		 *
		 * @return {Promise<object|null>} The saved object, or null on failure.
		 */
		async save() {
			const flowStore = useAgentFlowStore()
			this.saving = true
			try {
				const payload = { ...this.graph }
				const triggerNode = this.nodes.find((node) => node.type === 'trigger')
				if (triggerNode) {
					const config = triggerNode.config || {}
					payload.triggerRegister = config.triggerRegister || payload.triggerRegister || ''
					payload.triggerSchema = config.triggerSchema || payload.triggerSchema || ''
					payload.trigger = config.event || payload.trigger || 'object.updated'
				}

				const saved = await flowStore.saveObject('agentflow', payload)
				const rows = await flowStore.fetchCollection('agentflow')
				this.graphs = Array.isArray(rows) ? rows : []
				if (saved && saved.id) {
					this.graph = { ...this.graph, id: saved.id }
				}

				this.dirty = false
				return saved
			} finally {
				this.saving = false
			}
		},

		/**
		 * Execute the current canvas against a subject object.
		 *
		 * @param {object} subject `{uuid, register, schema}`.
		 * @return {Promise<object>} The run result (`{state, trace}`).
		 */
		async run(subject) {
			const result = await runGraph(this.graphForRun, subject.uuid, subject.register, subject.schema)
			this.lastTrace = Array.isArray(result?.trace) ? result.trace : []
			return result
		},
	},
})
