// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Shared state for the flow editor.
//
// The canvas (FlowBuilder.vue) and the controls (FlowSidebar.vue) are rendered
// in two different places: the page body and Nextcloud's real app sidebar (via
// the manifest's `pages[].sidebarComponent`, which CnAppRoot mounts into its
// #sidebar slot). They therefore cannot pass props to one another, so the flow
// being edited lives here instead.
//
// ## Hermiq does not have flows of its own
//
// Persistence goes to OpenRegister's native flow store, and nowhere else. A
// flow authored here is a row in that store with `app = 'hermiq'` — not a
// hermiq entity, and not a hermiq word.
//
// This surface used to be called a "graph" and used to write `agentflow`
// OBJECTS through `useAgentFlowStore`, which the flow-storage spec forbids
// outright ("A flow definition SHALL NOT be stored as an OpenRegister object").
// The second name and the second store arrived together, which is the argument
// against the second name: it left two copies of every flow — the objects the
// editor read and the native rows the ENGINE ran — free to drift, and they had.
// The Hydra sequencer's prose lived in the object's `description` while the
// native row's `notes` was null, which is why the Notes tab rendered empty for
// a flow that documents itself at length.
//
// TRANSPORT IS TEMPORARY HERE. `src/api/flows.js` is a per-app HTTP client for
// a shared abstraction, which is the same duplication one layer up: every
// endpoint it calls is OpenRegister's, and openconnector and procest consume
// the same engine. It moves to `createFlowStore` in @conduction/nextcloud-vue
// (change `cn-flow-store-and-canvas-rename`), next to the `createObjectStore`
// that objects already have. This file then keeps only EDITOR state.
//
// ## The dialect
//
// A flow is a Petri net (ADR-065), and the two halves are not interchangeable:
//
//   node  = PLACE      `{id, name?}`  — a position. Carries NO behaviour.
//   edge  = TRANSITION `{id, from, to, type, config}` — this is what RUNS.
//
// `FlowDefinitionBuilder::extractPlaces()` THROWS on a node carrying `type` or
// `config`, because a step put on a node is a step nothing executes: dispatch
// returns items untouched, the run reports COMPLETED, and the trace is empty.
//
// NOTE: this is being inverted — see `or-flow-action-nodes`, where a node
// becomes the action and an edge becomes sequence. Until that lands and
// `or-flow-migrate-definitions` converts the stored flows, this file reads what
// the engine actually reads today.
//
// `from`/`to` may each be a LIST: several `from` is a join (the transition
// waits for a token on every one), several `to` is a split (firing puts a token
// on each). That is how parallel branches are expressed, so endpoints are
// normalised to arrays here and fanned back out into drawable lines by
// `canvasEdges`.

import { defineStore } from 'pinia'
import { useAgentStore } from './store.js'
import {
	createFlow,
	getFlowRun,
	getStepCatalog,
	listFlows,
	runFlow,
	updateFlow,
	validateFlow,
} from '../api/flows.js'

/**
 * A blank flow definition.
 *
 * @return {object} The empty flow.
 */
function emptyFlow() {
	return {
		name: '',
		description: '',
		notes: '',
		triggerRegister: '',
		triggerSchema: '',
		trigger: 'object.updated',
		cron: '',
		executionMode: 'async',
		enabled: false,
		nodes: [],
		edges: [],
		limits: {},
	}
}

/**
 * An edge endpoint as a list of place ids.
 *
 * Mirrors `FlowDefinitionBuilder::normaliseEndpoints()`: a scalar endpoint is an
 * ordinary edge, an array endpoint is a split or a join. Both shapes are valid
 * stored data, so the editor has to read both.
 *
 * @param {*} value The raw endpoint.
 *
 * @return {Array<string>} The place ids.
 */
function endpointList(value) {
	if (value === null || value === undefined) {
		return []
	}

	const list = Array.isArray(value) ? value : [value]

	return list
		.map((entry) => String(entry ?? '').trim())
		.filter((entry) => entry !== '')
}

/**
 * Read a stored edge into the shape the editor works in.
 *
 * Accepts `source`/`target` as well as `from`/`to` because the ENGINE does
 * (`$edge['from'] ?? $edge['source']`), so both are legitimately on disk. Only
 * `from`/`to` is ever written back.
 *
 * @param {object} edge  The stored edge.
 * @param {number} index Its position, used when it has no id.
 *
 * @return {object} The normalised edge.
 */
function normaliseEdge(edge, index) {
	const source = edge.from === undefined ? edge.source : edge.from
	const target = edge.to === undefined ? edge.target : edge.to

	return {
		...edge,
		id: String(edge.id || edge.name || `edge-${index}`),
		from: endpointList(source),
		to: endpointList(target),
		type: edge.type || '',
		config: edge.config || {},
	}
}

export const useFlowEditorStore = defineStore('flowEditor', {
	state: () => ({
		flow: emptyFlow(),
		flows: [],
		agents: [],
		selectedNodeId: null,
		selectedEdgeId: null,
		// The STEP types the engine can execute, from OpenRegister's catalogue
		// (ADR-065 owns the vocabulary). These belong on edges, never on nodes.
		stepCatalog: [],
		lastRun: null,
		loading: false,
		saving: false,
		dirty: false,
		showRun: false,
		// The sidebar is a panel the author can get out of the way: a canvas is
		// a spatial surface and 346px of permanent chrome is a third of a
		// laptop's width. Kept here rather than in FlowSidebar because the
		// re-open control lives on the CANVAS — once the sidebar is closed it
		// has no button of its own left to render.
		sidebarOpen: true,
		validation: null,
		// Node ids the last save reported as dead ends. Empty means "the save
		// said nothing"; it never means "not checked yet" — the array is only
		// ever written from a save response.
		deadEnds: [],
	}),

	getters: {
		/**
		 * @param {object} state The flow-editor store state.
		 * @return {Array<object>} Canvas nodes (places).
		 */
		nodes: (state) => state.flow.nodes || [],

		/**
		 * @param {object} state The flow-editor store state.
		 * @return {Array<object>} Canvas edges (transitions/steps).
		 */
		edges: (state) => state.flow.edges || [],

		/**
		 * Edges fanned out into individually drawable lines.
		 *
		 * One stored edge with two `to` places is one transition but two lines
		 * on screen, so the canvas cannot iterate `edges` directly. Each line
		 * keeps a reference back to the edge it came from, so clicking either
		 * half of a split selects the one step.
		 *
		 * `id` is the LINE's id, not the step's: the canvas keys its `v-for` on
		 * it, and reusing the step id would give both halves of every split the
		 * same key — Vue would reuse one DOM node for two lines and drop the
		 * other. The step is reached through `.edge`.
		 *
		 * @param {object} state The flow-editor store state.
		 * @return {Array<object>} `{id, source, target, edge}` per line.
		 */
		canvasEdges: (state) => {
			const lines = []
			for (const edge of (state.flow.edges || [])) {
				for (const source of edge.from) {
					for (const target of edge.to) {
						lines.push({
							id: `${edge.id}:${source}:${target}`,
							source,
							target,
							edge,
						})
					}
				}
			}

			return lines
		},

		/**
		 * @param {object} state The flow-editor store state.
		 * @return {object|null} The selected place.
		 */
		selectedNode: (state) => {
			if (state.selectedNodeId === null) {
				return null
			}

			return (state.flow.nodes || []).find((node) => node.id === state.selectedNodeId) || null
		},

		/**
		 * @param {object} state The flow-editor store state.
		 * @return {object|null} The selected step.
		 */
		selectedEdge: (state) => {
			if (state.selectedEdgeId === null) {
				return null
			}

			return (state.flow.edges || []).find((edge) => edge.id === state.selectedEdgeId) || null
		},

		/**
		 * The places a run starts on.
		 *
		 * Mirrors `FlowDefinitionBuilder::resolveInitialPlaces()` exactly, so
		 * what the canvas marks as a start is what the engine will actually
		 * start on: an explicit `initial` wins; otherwise the sources (places no
		 * edge points at); and a fully cyclic flow, which has no source, starts
		 * on the first declared place.
		 *
		 * @param {object} state The flow-editor store state.
		 * @return {Array<string>} The starting place ids.
		 */
		startNodeIds: (state) => {
			const places = (state.flow.nodes || []).map((node) => node.id)
			const declared = endpointList(state.flow.initial).filter((id) => places.includes(id))
			if (declared.length > 0) {
				return declared
			}

			const targeted = new Set()
			for (const edge of (state.flow.edges || [])) {
				edge.to.forEach((id) => targeted.add(id))
			}

			const sources = places.filter((id) => targeted.has(id) === false)

			return sources.length > 0 ? sources : places.slice(0, 1)
		},

		/**
		 * The places a run can finish on — those no edge leaves from.
		 *
		 * @param {object} state The flow-editor store state.
		 * @return {Array<string>} The terminal place ids.
		 */
		endNodeIds: (state) => {
			const leaving = new Set()
			for (const edge of (state.flow.edges || [])) {
				edge.from.forEach((id) => leaving.add(id))
			}

			return (state.flow.nodes || [])
				.map((node) => node.id)
				.filter((id) => leaving.has(id) === false)
		},

		/**
		 * Place id => where the last run's tokens are sitting, so a run is
		 * legible on the canvas itself.
		 *
		 * @param {object} state The flow-editor store state.
		 * @return {object} Place id => true.
		 */
		markingByNode: (state) => {
			const out = {}
			for (const place of (state.lastRun?.marking || [])) {
				out[String(place)] = true
			}

			return out
		},

		/**
		 * Transition name => that step's entry in the last run's log, so an edge
		 * can offer "what came out of here". Only steps that ran appear.
		 *
		 * Keyed on the TRANSITION NAME rather than the edge id because that is
		 * what the engine logs: `FlowDefinitionBuilder` names a transition
		 * `edge.name ?? edge.id`, so an edge carrying both would never match a
		 * map keyed on its id alone. `transitionName()` is the other half.
		 *
		 * @param {object} state The flow-editor store state.
		 * @return {object} Transition name => log entry.
		 */
		resultByEdge: (state) => {
			const out = {}
			for (const entry of (state.lastRun?.log || [])) {
				const name = entry.transition || entry.edge || entry.step
				if (name) {
					out[String(name)] = entry
				}
			}

			return out
		},

		/**
		 * The name the engine knows an edge by.
		 *
		 * @return {Function} `(edge) => string`.
		 */
		transitionName: () => (edge) => String(edge?.name || edge?.id || ''),

		/**
		 * Agents as dropdown options. The id is what the step stores; the
		 * label is what an author recognises.
		 *
		 * @param {object} state The flow-editor store state.
		 * @return {Array<object>} `{id, label, maxTokens}` per agent.
		 */
		agentOptions: (state) => state.agents.map((agent) => ({
			id: agent['@self']?.id || agent.id,
			label: agent.name || agent['@self']?.id || agent.id,
			maxTokens: agent.maxTokens ?? null,
		})).filter((option) => option.id),

		/**
		 * The document sent to save and to validate.
		 *
		 * Endpoints are written back as `from`/`to`, collapsing a single-entry
		 * list to a scalar: a plain edge should read as a plain edge on disk,
		 * and only a genuine split or join should look like one.
		 *
		 * @param {object} state The flow-editor store state.
		 * @return {object} The flow document.
		 */
		flowDocument: (state) => ({
			name: state.flow.name,
			description: state.flow.description || '',
			notes: state.flow.notes || '',
			enabled: state.flow.enabled === true,
			trigger: state.flow.trigger || '',
			triggerRegister: state.flow.triggerRegister || '',
			triggerSchema: state.flow.triggerSchema || '',
			cron: state.flow.cron || '',
			executionMode: state.flow.executionMode || 'async',
			limits: state.flow.limits || {},
			nodes: (state.flow.nodes || []).map((node) => {
				// A place carries no behaviour. `type`/`config` are stripped on
				// the way out rather than merely never added, because a document
				// imported or hand-edited into that shape would otherwise be
				// written straight back and refused by the engine.
				const place = { ...node }
				delete place.type
				delete place.config

				return place
			}),
			edges: (state.flow.edges || []).map((edge) => ({
				...edge,
				from: edge.from.length === 1 ? edge.from[0] : edge.from,
				to: edge.to.length === 1 ? edge.to[0] : edge.to,
			})),
		}),
	},

	actions: {
		/**
		 * Load every hermiq flow, then put the routed one on the canvas.
		 *
		 * @param {string} id The route id (`new` for a blank flow).
		 * @return {Promise<void>}
		 */
		async load(id) {
			this.loading = true
			try {
				this.flows = await listFlows()
			} catch (e) {
				// Logged, not swallowed: an empty flow list with no trace of why
				// is indistinguishable from "this instance has no flows".
				console.error('hermiq: could not load flows from OpenRegister', e)
				this.flows = []
			} finally {
				this.loading = false
			}

			this.loadAgents()
			this.loadStepCatalog()
			this.open(id)
		},

		/**
		 * Load the engine's step vocabulary.
		 *
		 * Kept off the critical path: the canvas is usable while this resolves,
		 * and a failure costs the real labels and the picker rather than the
		 * editor.
		 *
		 * @return {Promise<void>}
		 */
		async loadStepCatalog() {
			try {
				this.stepCatalog = await getStepCatalog()
			} catch (e) {
				this.stepCatalog = []
			}
		},

		/**
		 * Load the agents an agent step can choose from.
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
				console.error('hermiq: could not load agents for the flow editor', e)
				this.agents = []
			}
		},

		/**
		 * Put the flow named by the route onto the canvas.
		 *
		 * @param {string} id The route id.
		 * @return {void}
		 */
		open(id) {
			this.selectedNodeId = null
			this.selectedEdgeId = null
			this.lastRun = null
			this.validation = null

			if (!id || id === 'new') {
				this.flow = { ...emptyFlow(), name: 'New flow' }
				this.dirty = false
				return
			}

			const match = this.flows.find((flow) => String(flow.id) === String(id))
			if (!match) {
				return
			}

			const copy = JSON.parse(JSON.stringify(match))
			this.flow = {
				...emptyFlow(),
				...copy,
				nodes: Array.isArray(copy.nodes) ? copy.nodes : [],
				edges: (Array.isArray(copy.edges) ? copy.edges : []).map(normaliseEdge),
			}
			this.dirty = false
		},

		/**
		 * Add a place.
		 *
		 * Deliberately takes a NAME and not a type: a place has no type, and the
		 * palette that used to pass one here is now the step picker on an edge.
		 *
		 * Default placement stacks a vertical chain near the left, leaving a gap
		 * roughly the height of a card so the arrowhead and the step label that
		 * sit on the connecting edge both have room to read.
		 *
		 * @param {string} name The place's label.
		 * @param {number} x    Canvas x (optional).
		 * @param {number} y    Canvas y (optional).
		 * @return {void}
		 */
		addNode(name = '', x = null, y = null) {
			const index = this.nodes.length
			const node = {
				id: `node-${Date.now().toString(36)}-${index}`,
				name: name || `Step ${index + 1}`,
				x: x === null ? 80 : x,
				y: y === null ? (60 + index * 170) : y,
			}

			this.flow.nodes = [...this.nodes, node]
			this.selectNode(node.id)
			this.dirty = true
		},

		/**
		 * Rename a place.
		 *
		 * @param {string} name The new label.
		 * @return {void}
		 */
		setNodeName(name) {
			if (this.selectedNodeId === null) {
				return
			}

			this.flow.nodes = this.nodes.map((node) => {
				if (node.id !== this.selectedNodeId) {
					return node
				}

				return { ...node, name }
			})
			this.dirty = true
		},

		/**
		 * Persist a place's new position.
		 *
		 * @param {object} payload `{id, x, y}`.
		 * @param {string} payload.id The place id to move.
		 * @param {number} payload.x  New x-coordinate in canvas space.
		 * @param {number} payload.y  New y-coordinate in canvas space.
		 * @return {void}
		 */
		moveNode({ id, x, y }) {
			this.flow.nodes = this.nodes.map((node) => {
				if (node.id !== id) {
					return node
				}

				return { ...node, x, y }
			})
			this.dirty = true
		},

		/**
		 * Connect two places, creating a step between them.
		 *
		 * The new step is deliberately UNTYPED and immediately selected: an edge
		 * is where behaviour lives, so the next thing the author must do is say
		 * what it does. Creating it pre-typed would guess, and a step whose type
		 * nobody chose is exactly the pass-through-that-reports-success the
		 * engine's preflight exists to catch.
		 *
		 * @param {object} payload `{source, target}`.
		 * @param {string} payload.source The step's source place id.
		 * @param {string} payload.target The step's target place id.
		 * @return {void}
		 */
		connect({ source, target }) {
			if (!source || !target || source === target) {
				return
			}

			const exists = this.edges.some((edge) => edge.from.includes(source) && edge.to.includes(target))
			if (exists) {
				return
			}

			const edge = {
				id: `step-${Date.now().toString(36)}-${this.edges.length}`,
				from: [source],
				to: [target],
				type: '',
				config: {},
			}

			this.flow.edges = [...this.edges, edge]
			this.selectEdge(edge.id)
			this.dirty = true
		},

		/**
		 * Select a place, clearing any step selection.
		 *
		 * The two selections are mutually exclusive because the sidebar shows one
		 * editor at a time: leaving both set would show a place's pane over a
		 * step the author had just clicked.
		 *
		 * @param {string|null} id The place id.
		 * @return {void}
		 */
		selectNode(id) {
			this.selectedNodeId = id
			if (id !== null) {
				this.selectedEdgeId = null
			}
		},

		/**
		 * Select a step, clearing any place selection.
		 *
		 * @param {string|null} id The step id.
		 * @return {void}
		 */
		selectEdge(id) {
			this.selectedEdgeId = id
			if (id !== null) {
				this.selectedNodeId = null
			}
		},

		/** @return {void} Clear both selections. */
		clearSelection() {
			this.selectedNodeId = null
			this.selectedEdgeId = null
		},

		/**
		 * Remove a place and every step touching it.
		 *
		 * @param {string} id The place id.
		 * @return {void}
		 */
		removeNode(id) {
			this.flow.nodes = this.nodes.filter((node) => node.id !== id)
			this.flow.edges = this.edges.filter((edge) => {
				return edge.from.includes(id) === false && edge.to.includes(id) === false
			})
			this.clearSelection()
			this.dirty = true
		},

		/**
		 * Remove a step.
		 *
		 * @param {string} id The step id.
		 * @return {void}
		 */
		removeEdge(id) {
			this.flow.edges = this.edges.filter((edge) => edge.id !== id)
			this.clearSelection()
			this.dirty = true
		},

		/**
		 * Set the selected step's type.
		 *
		 * The config is CLEARED with the type, not carried over: config keys are
		 * per-node vocabulary, and keys the new node does not read are invisible
		 * to it by construction — the step would run, ignore them, and report
		 * success.
		 *
		 * @param {string} type The catalogue step type.
		 * @return {void}
		 */
		setEdgeType(type) {
			if (this.selectedEdgeId === null) {
				return
			}

			this.flow.edges = this.edges.map((edge) => {
				if (edge.id !== this.selectedEdgeId) {
					return edge
				}

				if (edge.type === type) {
					return edge
				}

				return { ...edge, type, config: {} }
			})
			this.dirty = true
		},

		/**
		 * Replace the selected step's whole config.
		 *
		 * The raw-config editor needs this: it edits the object as a document, so
		 * a per-key setter could never REMOVE a key, and a step type whose keys
		 * the builder does not know would accumulate stale ones.
		 *
		 * @param {object} config The new config object.
		 * @return {void}
		 */
		setEdgeConfigAll(config) {
			if (this.selectedEdgeId === null) {
				return
			}

			this.flow.edges = this.edges.map((edge) => {
				if (edge.id !== this.selectedEdgeId) {
					return edge
				}

				return { ...edge, config: { ...config } }
			})
			this.dirty = true
		},

		/**
		 * Write one config key on the selected step.
		 *
		 * @param {string} key   The config key.
		 * @param {*}      value The value.
		 * @return {void}
		 */
		setEdgeConfig(key, value) {
			if (this.selectedEdgeId === null) {
				return
			}

			this.flow.edges = this.edges.map((edge) => {
				if (edge.id !== this.selectedEdgeId) {
					return edge
				}

				return { ...edge, config: { ...(edge.config || {}), [key]: value } }
			})
			this.dirty = true
		},

		/**
		 * Write a flow-level field.
		 *
		 * @param {string} key   The field.
		 * @param {*}      value The value.
		 * @return {void}
		 */
		setFlowField(key, value) {
			this.flow = { ...this.flow, [key]: value }
			this.dirty = true
		},

		/**
		 * Check the current document against the live engine.
		 *
		 * @return {Promise<object>} The verdict.
		 */
		async validate() {
			try {
				this.validation = await validateFlow(this.flowDocument)
			} catch (e) {
				this.validation = null
			}

			return this.validation
		},

		/**
		 * Save the flow to OpenRegister's native store.
		 *
		 * There is no trigger NODE to mirror onto the flow fields: a trigger is a
		 * property of the flow (`trigger`, `triggerRegister`, `triggerSchema`,
		 * `cron`), and the engine reads it from there. The old mirroring existed
		 * only because the editor had invented a `trigger` node type the engine
		 * had never heard of.
		 *
		 * @return {Promise<object|null>} The saved flow, or null on failure.
		 */
		async save() {
			this.saving = true
			try {
				const id = this.flow.id
				const saved = id
					? await updateFlow(id, this.flowDocument)
					: await createFlow(this.flowDocument)

				this.flows = await listFlows()
				if (saved && saved.id) {
					this.flow = { ...this.flow, id: saved.id }
				}

				this.dirty = false

				// Validate AFTER the write, not instead of it: the verdict is
				// advice about whether the flow will do anything, and refusing to
				// save on it would strand a half-authored flow the author is in
				// the middle of building.
				this.validate()

				// The save response carries the connectivity verdict with it, so
				// the author is told about a dead end at the moment they save
				// rather than on the next run — which for a scheduled flow could
				// be hours later, and would present as "it ran and did nothing".
				// Kept separate from `validation` so dismissing this does not
				// discard the full report the sidebar shows.
				this.deadEnds = ((saved && saved.warnings) || [])
					.filter((warning) => warning.reason === 'node-dead-end')
					.map((warning) => warning.step)

				return saved
			} finally {
				this.saving = false
			}
		},

		/**
		 * Queue a run of the saved flow and read the result back.
		 *
		 * Runs are asynchronous by default, so the POST returns a queued run
		 * rather than a finished trace. One read-back follows so a synchronous
		 * flow shows its log immediately; an async one shows its queued status
		 * and the log fills in on the next read.
		 *
		 * @param {object} subject `{uuid, register, schema}`.
		 * @return {Promise<object>} The run.
		 */
		async run(subject) {
			const queued = await runFlow(this.flow.id, {
				uuid: subject.uuid,
				register: subject.register,
				schema: subject.schema,
			})

			this.lastRun = queued
			if (queued?.uuid) {
				try {
					this.lastRun = await getFlowRun(queued.uuid)
				} catch (e) {
					// The queued run is still a real result; a failed read-back
					// only costs the log.
				}
			}

			return this.lastRun
		},
	},
})
