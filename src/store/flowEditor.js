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
//   node  = ACTION   `{id, type, config, exit?}` — this is what RUNS.
//   edge  = SEQUENCE `{id, from, to, title?, fromExit?}` — where it goes next.
//
// The inversion LANDED (`or-flow-action-nodes`). `FlowDefinitionBuilder` now
// refuses the opposite shape: a document where any EDGE carries a non-empty
// `type` is pre-inversion, and it is refused rather than reinterpreted, because
// a half-migrated flow would run, skip the step nobody claimed, and report
// success.
//
// `fromExit` names WHICH BRANCH of a routing node an edge leaves from. It is
// what `FlowTokenRouter::placesForExit()` matches on, and it is the reason the
// canvas draws one named out-port per branch: without it recorded, every branch
// of a route produces an identical edge and the author's choice is lost.
//
// `from`/`to` may each be a LIST: several `from` is a join (the transition
// waits for a token on every one), several `to` is a split (firing puts a token
// on each). That is how parallel branches are expressed, so endpoints are
// normalised to arrays here and fanned back out into drawable lines by
// `canvasEdges`.

import { defineStore } from 'pinia'
import { useAgentStore } from './store.js'
import { branchOfPort, branchesOfNode, orphanedBranchEdgeIds } from './flowBranches.js'
import {
	createFlow,
	getFlowRun,
	getNodeCatalog,
	listFlowRuns,
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
		annotations: [],
		limits: {},
	}
}

/**
 * The prefix that marks a canvas element as an ANNOTATION rather than a node.
 *
 * Annotations are drawn through the same canvas as nodes, because that is what
 * positions things in canvas space — but they are NOT nodes in the document,
 * and the two must never be confused. The canvas's `nodes` prop is a RENDER
 * list; `flow.nodes` is what the engine builds. An annotation that reached
 * `flow.nodes` would be lowered to a transition and become something the run
 * waits on: a comment able to deadlock a flow.
 *
 * The id prefix is how `@node-move` and `@node-select` — which the canvas fires
 * for anything it draws — are routed back to the right half.
 *
 * @type {string}
 */
export const ANNOTATION_ID_PREFIX = 'annotation:'

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

	// `type`/`config` are deliberately NOT defaulted onto the edge. They used
	// to be, and that seeded every loaded edge with the two keys the engine
	// treats as the pre-inversion marker — so a flow that merely passed through
	// the editor came back carrying them. Empty `type: ''` is not refused
	// (`assertNotPreInversion` tests for a NON-empty type), but writing keys
	// the engine does not read onto every edge is how the dialect drifted in
	// the first place. Anything already stored on the edge is preserved by the
	// spread; nothing new is invented here.
	return {
		...edge,
		id: String(edge.id || edge.name || `edge-${index}`),
		from: endpointList(source),
		to: endpointList(target),
	}
}

/**
 * Find a catalogue entry for a node type, following renames.
 *
 * A STORED flow may name a type by an id that has since been renamed. The
 * engine resolves those itself and publishes the old ids as `aliases` on the
 * catalogue entry, so this follows the engine's map rather than keeping a
 * second copy of it — a duplicated rename table is only correct until the next
 * rename.
 *
 * @param {Array<object>} catalogue The node catalogue.
 * @param {string}        type      The type id a node carries.
 *
 * @return {object|undefined} The entry, or undefined when nothing matches.
 */
function catalogueEntry(catalogue, type) {
	const id = String(type || '')
	if (id === '') {
		return undefined
	}

	return (catalogue || []).find((candidate) =>
		candidate.id === id || (candidate.aliases || []).includes(id),
	)
}

export const useFlowEditorStore = defineStore('flowEditor', {
	state: () => ({
		flow: emptyFlow(),
		flows: [],
		agents: [],
		selectedNodeId: null,
		selectedEdgeId: null,
		// A copied node, waiting to be placed. Held here rather than written
		// straight back onto the canvas so the author chooses WHERE it lands —
		// a copy that appears at a fixed offset is one they then have to drag.
		// It never carries connections: see `copyNode()`.
		clipboardNode: null,
		// The NODE types the engine can execute, from OpenRegister's catalogue
		// (ADR-065 owns the vocabulary). These belong on NODES: the engine
		// reads `type`/`config` off the node and refuses any document where an
		// edge carries a type. The comment here used to claim the reverse.
		nodeCatalog: [],
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
		// Whether the node editor is open. Kept in the store rather than in
		// FlowBuilder because both the canvas (double-click) and the sidebar
		// (the keyboard-reachable "Edit node" button) open the same modal, and
		// those two components cannot pass props to one another — the sidebar
		// is mounted into Nextcloud's own #sidebar slot.
		nodeEditOpen: false,
		// Whether the connection editor is open. The Connection TAB is gone:
		// a line's fields are three text areas an author opens rarely, and a
		// permanent tab for them cost a quarter of the sidebar's tab strip
		// while the thing being edited was selected on the canvas.
		edgeEditOpen: false,
		// The open context menu: `{kind: 'node'|'edge', id, x, y}` or null.
		// Held here because the canvas raises it and the menu is drawn over the
		// canvas, and because closing it is something several places do.
		contextMenu: null,
		// The node type currently being dragged out of the palette, or null.
		// Read by FlowBuilder's canvas-drop handler to decide what to create at
		// the drop point — without it a drag lands as a drop with no type and
		// creates a node the engine refuses.
		paletteDragType: null,
		validation: null,
		// Node ids the last save reported as dead ends. Empty means "the save
		// said nothing"; it never means "not checked yet" — the array is only
		// ever written from a save response.
		deadEnds: [],
		// Run history, loaded on demand from the Runs tab rather than on open:
		// a flow's history is a panel an operator asks for, and fetching it with
		// the editor would cost a request on every load to fill a list most
		// sessions never look at.
		runs: [],
		runsLoading: false,
		runsError: '',
		expandedRunId: null,
		runDetail: {},
		// The run whose path is drawn on the canvas, or null.
		replayRunId: null,
		// The connection whose payload is open in the JSON peek, or null.
		payloadEdgeId: null,
		// The run open in the full-size log modal, or null. A 346px sidebar
		// cannot hold a JSON payload — the pane is narrower than most single
		// lines of it — so the log is READ here and merely listed there.
		logModalRunId: null,
	}),

	getters: {
		/**
		 * The transition names the replayed run actually fired.
		 *
		 * Read from the run's LOG, which is the record of what happened —
		 * not from the flow, which only says what could.
		 *
		 * @param {object} state The flow-editor store state.
		 * @return {Array<string>} The node ids the run touched.
		 */
		replayedNodeIds: (state) => {
			const detail = state.replayRunId === null ? null : state.runDetail[state.replayRunId]

			return (detail?.log || [])
				.map((entry) => entry.transition)
				.filter(Boolean)
		},

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
		 * The branches each node exposes, keyed by node id.
		 *
		 * @param {object} state The flow-editor store state.
		 * @return {object} `{[nodeId]: string[]}`.
		 *
		 * @spec openspec/specs/flow-canvas/spec.md
		 */
		branchesByNode: (state) => {
			const map = {}
			for (const node of (state.flow.nodes || [])) {
				map[node.id] = branchesOfNode(node)
			}

			return map
		},

		/**
		 * Edges whose branch no longer exists on the node they leave.
		 *
		 * Editing a routing node's `rules[]` can remove a branch that edges were
		 * already drawn from. Those edges are NOT deleted: silently removing a
		 * connection the author drew — because a value changed in a different
		 * panel — loses work with no trace, and the author cannot tell an edge
		 * they forgot from one the editor took away.
		 *
		 * They are reported instead, so the canvas can draw them as unassigned
		 * and the author can repoint or remove them deliberately.
		 *
		 * An edge with no `fromExit` is never orphaned: it leaves an unbranched
		 * exit, which every node has.
		 *
		 * @param {object} state The flow-editor store state.
		 * @return {Array<string>} The offending edge ids.
		 *
		 * @spec openspec/specs/flow-canvas/spec.md
		 */
		orphanedBranchEdgeIds: (state) => orphanedBranchEdgeIds(state.flow.nodes || [], state.flow.edges || []),

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
		/**
		 * A node TYPE's declared role: `start`, `step` or `stop`.
		 *
		 * Read from the catalogue the ENGINE ships, never inferred from the id.
		 * OpenRegister decides this from the markers a node implements
		 * (`IFlowTriggerNode` / `IFlowEndNode`), so a trigger or end node
		 * contributed by any app is recognised whatever it is called — which a
		 * string match on `.trigger-` or `.end` cannot do.
		 *
		 * Note this is a different question from `startNodeIds`/`endNodeIds`,
		 * which are about where a node sits in THIS graph. A type can be a
		 * `step` and still be the first node drawn.
		 *
		 * @param {object} state The flow-editor store state.
		 * @return {Function} `(type: string) => 'trigger'|'step'|'end'`.
		 *
		 * @spec openspec/specs/flow-canvas/spec.md#requirement-the-node-palette-is-a-card-per-type-and-the-card-explains-itself
		 */
		roleOfNodeType: (state) => (type) => {
			const id = String(type || '')
			const entry = catalogueEntry(state.nodeCatalog, id)

			if (entry !== undefined && entry.role) {
				return entry.role
			}

			// The catalogue has not loaded, or an OpenRegister older than the
			// `role` field answered. Degrade to the naming convention rather
			// than calling everything a step, which would draw a trigger as an
			// ordinary node.
			if (id.includes('.trigger-')) {
				return 'trigger'
			}

			if (id.endsWith('.end') || id.endsWith('.stop')) {
				return 'end'
			}

			return 'step'
		},

		/**
		 * The catalogue entry a node's type resolves to, renames included.
		 *
		 * Exposed so the canvas resolves types the same way the store does —
		 * two lookups that disagree would draw a stored flow differently from
		 * the way the sidebar describes it.
		 *
		 * @param {object} state The flow-editor store state.
		 * @return {Function} `(type: string) => object|undefined`.
		 */
		catalogueEntryFor: (state) => (type) => catalogueEntry(state.nodeCatalog, type),

		/**
		 * What this flow is missing to be runnable at all: a trigger, an end.
		 *
		 * Decided by node TYPE, never by graph position. "Nothing points at this
		 * node" and "this node has no outgoing edge" are facts about one
		 * drawing — reading them as roles calls an unconnected step a trigger,
		 * which is how a flow that can never fire looks finished.
		 *
		 * An end may finish in SUCCESS or in ERROR: `openregister.end` carries
		 * an `error` flag, and failing is an outcome rather than the absence of
		 * one, so both count.
		 *
		 * Empty flows report nothing — a blank canvas is missing both by
		 * definition and the author can see that.
		 *
		 * @param {object} state The flow-editor store state.
		 * @return {{trigger: boolean, end: boolean}} Which ends are missing.
		 *
		 * @spec openspec/specs/flow-canvas/spec.md#requirement-a-flow-must-have-a-trigger-and-an-end
		 */
		missingEnds() {
			const nodes = (this.flow.nodes || [])
			if (nodes.length === 0) {
				return { trigger: false, end: false }
			}

			let hasTrigger = false
			let hasEnd = false
			for (const node of nodes) {
				const role = this.roleOfNodeType(node?.type)
				if (role === 'trigger') {
					hasTrigger = true
				}

				if (role === 'end') {
					hasEnd = true
				}
			}

			return { trigger: !hasTrigger, end: !hasEnd }
		},

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
			this.loadNodeCatalog()
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
		async loadNodeCatalog() {
			try {
				this.nodeCatalog = await getNodeCatalog()
			} catch (e) {
				// A failure costs the palette, not the editor. The list stays
				// empty rather than falling back to a hard-coded vocabulary:
				// a type the engine does not know resolves to nothing, runs,
				// and reports success having done no work.
				this.nodeCatalog = []
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
		 * Add a node OF A TYPE.
		 *
		 * This used to take a name and no type, with a comment saying "a place
		 * has no type, and the palette that used to pass one here is now the
		 * step picker on an edge". That was the pre-inversion model and it is
		 * the wrong way round: OpenRegister's `flow-engine` spec requires that
		 * "each node MUST carry the `type` and `config` of the step it
		 * performs; each edge MUST carry only `from`, `to` and optional display
		 * text", and it REFUSES any document in which an edge carries a type.
		 * So a typeless node cannot describe a step, and the editor that made
		 * them was building documents the engine rejects.
		 *
		 * `config` is seeded as an object rather than left absent: a node with
		 * no `config` key throws the moment a form reads `node.config.prompt`,
		 * and that takes the whole editor down with it.
		 *
		 * Default placement stacks a vertical chain near the left, leaving a gap
		 * roughly the height of a card so the arrowhead and the label that sit
		 * on the connecting edge both have room to read.
		 *
		 * @param {string} type The engine node type, e.g. `hermiq.agent-step`.
		 * @param {number} x    Canvas x (optional).
		 * @param {number} y    Canvas y (optional).
		 * @return {void}
		 *
		 * @spec openspec/specs/flow-canvas/spec.md
		 */
		addNode(type = '', x = null, y = null) {
			const index = this.nodes.length
			const node = {
				id: `node-${Date.now().toString(36)}-${index}`,
				type,
				name: this.nodeTypeLabel(type) || `Step ${index + 1}`,
				config: {},
				x: x === null ? 80 : x,
				y: y === null ? (60 + index * 170) : y,
			}

			this.flow.nodes = [...this.nodes, node]
			this.selectNode(node.id)
			this.dirty = true
		},

		/**
		 * The engine's display name for a node type, or '' when the catalogue
		 * cannot explain it.
		 *
		 * There is deliberately no local name table. A type the catalogue does
		 * not know is shown as its raw id, which is the truth, rather than as a
		 * guess from a list that may have drifted from the engine.
		 *
		 * @param {string} type The node type.
		 * @return {string} The label.
		 *
		 * @spec openspec/specs/flow-canvas/spec.md
		 */
		nodeTypeLabel(type) {
			const entry = catalogueEntry(this.nodeCatalog, type)

			return entry?.displayName || ''
		},

		/**
		 * Change the selected node's type.
		 *
		 * Clears `config` with it. The engine's keys differ per node — a router
		 * reads `rules`/`default`, a filter reads `condition`, an object-write
		 * reads eight — so carrying the old node's config across would leave
		 * keys the new node never reads. Those do not error: they are stored,
		 * ignored, and the step reports success having done nothing with them.
		 *
		 * @param {string} type The new node type.
		 * @return {void}
		 *
		 * @spec openspec/specs/flow-canvas/spec.md
		 */
		setNodeType(type) {
			if (this.selectedNodeId === null) {
				return
			}

			this.flow.nodes = this.nodes.map((node) => {
				if (node.id !== this.selectedNodeId || node.type === type) {
					return node
				}

				return { ...node, type, config: {} }
			})
			this.dirty = true
		},

		/**
		 * Write one key of the selected node's config.
		 *
		 * @param {string} key   The config key.
		 * @param {*}      value The new value.
		 * @return {void}
		 *
		 * @spec openspec/specs/flow-canvas/spec.md
		 */
		setNodeConfig(key, value) {
			if (this.selectedNodeId === null) {
				return
			}

			this.flow.nodes = this.nodes.map((node) => {
				if (node.id !== this.selectedNodeId) {
					return node
				}

				return { ...node, config: { ...(node.config || {}), [key]: value } }
			})
			this.dirty = true
		},

		/**
		 * Replace the selected node's config wholesale — the raw-JSON path.
		 *
		 * @param {object} config The new config object.
		 * @return {void}
		 *
		 * @spec openspec/specs/flow-canvas/spec.md
		 */
		setNodeConfigAll(config) {
			if (this.selectedNodeId === null) {
				return
			}

			this.flow.nodes = this.nodes.map((node) => {
				if (node.id !== this.selectedNodeId) {
					return node
				}

				return { ...node, config: { ...config } }
			})
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
		 * Open one run in the full-size log modal, fetching it if needed.
		 *
		 * @param {string} uuid The run uuid.
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/flow-engine/spec.md#requirement-a-run-records-what-each-node-received-returned-and-logged
		 */
		async openRunLog(uuid) {
			this.logModalRunId = uuid
			if (this.runDetail[uuid] === undefined) {
				await this.toggleRun(uuid)
			}
		},

		/**
		 * Show a run's path on the canvas, or clear it.
		 *
		 * Loads the run's detail if it is not already held, because the index
		 * endpoint returns runs WITHOUT their logs and the path is derived from
		 * the log — reading it off a list row yields an empty path, which draws
		 * as "this run touched nothing".
		 *
		 * @param {string|null} uuid The run to replay, or null to clear.
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/flow-canvas/spec.md#requirement-selecting-a-run-replays-its-path-on-the-canvas
		 */
		async replayRun(uuid) {
			if (uuid === null || this.replayRunId === uuid) {
				this.replayRunId = null
				return
			}

			this.replayRunId = uuid
			if (this.runDetail[uuid] === undefined) {
				await this.toggleRun(uuid)
			}
		},

		/**
		 * Duplicate a node beside itself.
		 *
		 * Copies the TYPE and the CONFIGURATION, and deliberately not the
		 * connections. A copy that arrived pre-wired would add paths to the
		 * flow the author never drew — and on a routing node it would duplicate
		 * branch targets, so two nodes would claim the same exits.
		 *
		 * Offset rather than placed on top: a copy at the same coordinates is
		 * invisible, and the author cannot tell whether the action worked.
		 *
		 * @param {string} id The node to copy.
		 * @return {void}
		 *
		 * @spec openspec/specs/flow-canvas/spec.md#requirement-the-canvas-offers-per-element-actions-reachable-two-ways
		 */
		copyNode(id) {
			const source = this.nodes.find((node) => node.id === id)
			if (source === undefined) {
				return
			}

			// A SNAPSHOT, not a reference: the original stays editable and the
			// clipboard keeps what was copied at the moment it was copied.
			// `config` is cloned for the same reason — a shared object would
			// let an edit to either one reach the other.
			this.clipboardNode = {
				type: source.type || '',
				name: source.name || source.id,
				config: { ...(source.config || {}) },
			}
		},

		/**
		 * Place the copied node at a point on the canvas.
		 *
		 * Carries the type and configuration and NO CONNECTIONS. A copy that
		 * arrived pre-wired would silently add paths to a flow the author never
		 * drew — which is why the clipboard holds three fields and not a node.
		 *
		 * @param {number} x Canvas x for the placed node.
		 * @param {number} y Canvas y for the placed node.
		 * @return {void}
		 *
		 * @spec openspec/specs/flow-canvas/spec.md#requirement-the-canvas-offers-per-element-actions-reachable-two-ways
		 */
		pasteNode(x = 80, y = 80) {
			if (this.clipboardNode === null) {
				return
			}

			const copy = {
				id: `${this.clipboardNode.type || 'node'}-${Date.now().toString(36)}-${this.nodes.length}`,
				type: this.clipboardNode.type,
				name: `${this.clipboardNode.name} (copy)`,
				config: { ...(this.clipboardNode.config || {}) },
				x,
				y,
			}

			this.flow.nodes = [...this.nodes, copy]
			this.selectNode(copy.id)
			this.dirty = true
		},

		/**
		 * Delete whatever is selected — a node, or a connection.
		 *
		 * The keyboard route to deletion, so the context menu is a shortcut
		 * rather than the only way (WCAG 2.1 AA 2.1.1). Deliberately does
		 * NOTHING when nothing is selected: a Delete key that removes something
		 * the author had not pointed at is worse than one that does nothing.
		 *
		 * @return {boolean} Whether something was deleted.
		 *
		 * @spec openspec/specs/flow-canvas/spec.md#requirement-the-canvas-offers-per-element-actions-reachable-two-ways
		 */
		deleteSelection() {
			if (this.selectedNodeId !== null) {
				this.removeNode(this.selectedNodeId)
				return true
			}

			if (this.selectedEdgeId !== null) {
				this.removeEdge(this.selectedEdgeId)
				return true
			}

			return false
		},

		/**
		 * Lay the graph out so it reads in one direction.
		 *
		 * Changes COORDINATES AND NOTHING ELSE. Not one node, connection, type,
		 * configuration or branch target differs before and after — that is the
		 * property that makes this safe to press on a flow that works, and it
		 * is the reason this builds new `{...node, x, y}` objects rather than
		 * touching anything else on them.
		 *
		 * The layout is a longest-path layering: a node sits one column right
		 * of the furthest-along thing that reaches it, so an edge always points
		 * forward and the eye can follow the flow without tracing arrowheads.
		 * Rows within a column are just the order encountered, which is stable
		 * and good enough — this is a reading aid, not a graph-drawing engine.
		 *
		 * Cycles cannot be layered, so the walk is depth-bounded by the node
		 * count: a flow that loops back still terminates and still gets a
		 * position for every node. Anything the walk never reaches — an
		 * unreachable island — is placed in a final column rather than dropped
		 * or left stacked at the origin, where it would be invisible under
		 * whatever else sits there.
		 *
		 * @return {void}
		 *
		 * @spec openspec/specs/flow-canvas/spec.md#requirement-auto-sort-arranges-the-drawing-and-never-the-flow
		 */
		autoSort() {
			const nodes = this.nodes
			if (nodes.length === 0) {
				return
			}

			const outgoing = new Map()
			for (const edge of this.edges) {
				for (const from of edge.from) {
					if (outgoing.has(from) === false) {
						outgoing.set(from, [])
					}
					outgoing.get(from).push(...edge.to)
				}
			}

			// Column per node: one past the furthest predecessor that reaches
			// it. Seeded from the entry points the store already derives, so
			// the drawing agrees with what the engine calls a start.
			const column = new Map()
			const seeds = this.startNodeIds.length > 0 ? this.startNodeIds : [nodes[0].id]
			const queue = seeds.map((id) => ({ id, depth: 0 }))
			let guard = nodes.length * nodes.length

			while (queue.length > 0 && guard > 0) {
				guard--
				const { id, depth } = queue.shift()
				if (column.has(id) === true && column.get(id) >= depth) {
					continue
				}

				column.set(id, depth)
				for (const next of (outgoing.get(id) || [])) {
					queue.push({ id: next, depth: depth + 1 })
				}
			}

			// Unreachable nodes go one column past everything placed, never at
			// the origin: stacked there they would sit under the entry points.
			const furthest = column.size > 0 ? Math.max(...column.values()) : 0
			for (const node of nodes) {
				if (column.has(node.id) === false) {
					column.set(node.id, furthest + 1)
				}
			}

			const COLUMN_WIDTH = 260
			const ROW_HEIGHT = 170
			const MARGIN = 60
			const rowsUsed = new Map()

			this.flow.nodes = nodes.map((node) => {
				const col = column.get(node.id)
				const row = rowsUsed.get(col) || 0
				rowsUsed.set(col, row + 1)

				return {
					...node,
					x: MARGIN + (col * COLUMN_WIDTH),
					y: MARGIN + (row * ROW_HEIGHT),
				}
			})
			this.dirty = true
		},

		/**
		 * Load this flow's run history.
		 *
		 * Never on open. A flow's history is a panel an operator asks for, and
		 * fetching it with the editor would put a request on every load of
		 * every flow to fill a list most sessions never look at.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/flow-canvas/spec.md
		 */
		async loadRuns() {
			if (!this.flow.id) {
				return
			}

			this.runsLoading = true
			try {
				this.runs = await listFlowRuns(this.flow.id)
			} catch (e) {
				// An empty list and a failed fetch must not look the same: the
				// first says "this flow has never run", which is a fact about
				// the flow, and the second says nothing about it at all.
				this.runs = []
				this.runsError = e?.response?.data?.error || e?.message || 'Could not load the run history'
			} finally {
				this.runsLoading = false
			}
		},

		/**
		 * Expand one run and fetch its step log the first time it is opened.
		 *
		 * Lazily, per run: a flow with a long history would otherwise issue one
		 * request per row before the operator has asked for any of them.
		 *
		 * @param {string} uuid The run uuid.
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/flow-canvas/spec.md
		 */
		async toggleRun(uuid) {
			if (this.expandedRunId === uuid) {
				this.expandedRunId = null
				return
			}

			this.expandedRunId = uuid
			if (this.runDetail[uuid] !== undefined) {
				return
			}

			try {
				this.runDetail = { ...this.runDetail, [uuid]: await getFlowRun(uuid) }
			} catch (e) {
				this.runDetail = { ...this.runDetail, [uuid]: null }
			}
		},

		/**
		 * Pin a new note to the canvas.
		 *
		 * @param {number} x Canvas x (optional).
		 * @param {number} y Canvas y (optional).
		 * @return {void}
		 *
		 * @spec openspec/specs/flow-canvas/spec.md
		 */
		addAnnotation(x = null, y = null) {
			const list = this.flow.annotations || []
			const annotation = {
				id: `note-${Date.now().toString(36)}-${list.length}`,
				x: x === null ? 80 : x,
				y: y === null ? (60 + list.length * 40) : y,
				text: '',
			}

			this.flow.annotations = [...list, annotation]
			this.dirty = true
		},

		/**
		 * Edit a note's text.
		 *
		 * @param {string} id   The annotation id.
		 * @param {string} text The new text.
		 * @return {void}
		 *
		 * @spec openspec/specs/flow-canvas/spec.md
		 */
		setAnnotationText(id, text) {
			this.flow.annotations = (this.flow.annotations || []).map((note) =>
				(note.id === id ? { ...note, text } : note),
			)
			this.dirty = true
		},

		/**
		 * Move a note.
		 *
		 * @param {object} payload `{id, x, y}`.
		 * @param {string} payload.id The annotation id.
		 * @param {number} payload.x  New canvas x.
		 * @param {number} payload.y  New canvas y.
		 * @return {void}
		 *
		 * @spec openspec/specs/flow-canvas/spec.md
		 */
		moveAnnotation({ id, x, y }) {
			this.flow.annotations = (this.flow.annotations || []).map((note) =>
				(note.id === id ? { ...note, x, y } : note),
			)
			this.dirty = true
		},

		/**
		 * Resize a note.
		 *
		 * A note holds prose, and prose is the one thing on a canvas whose
		 * needed size cannot be guessed — a sentence and a paragraph want very
		 * different boxes.
		 *
		 * @param {object} payload `{id, width, height}`.
		 * @return {void}
		 *
		 * @spec openspec/specs/flow-canvas/spec.md
		 */
		resizeAnnotation({ id, width, height }) {
			this.flow.annotations = (this.flow.annotations || []).map((note) =>
				(note.id === id ? { ...note, width, height } : note),
			)
			this.dirty = true
		},

		/**
		 * Remove a note.
		 *
		 * @param {string} id The annotation id.
		 * @return {void}
		 *
		 * @spec openspec/specs/flow-canvas/spec.md
		 */
		removeAnnotation(id) {
			this.flow.annotations = (this.flow.annotations || []).filter((note) => note.id !== id)
			this.dirty = true
		},

		/**
		 * Write one top-level field on the selected node.
		 *
		 * Used for the node's own prose — `notes` — which rides on the node
		 * next to what it does rather than in a tab of its own: a note about a
		 * step is unreadable when it is filed somewhere other than the step.
		 *
		 * Kept separate from `setNodeConfig`, which writes INSIDE `config`. A
		 * note is documentation for a reader; `config` is what the engine
		 * executes, and putting prose in there would hand the node a key it
		 * does not read.
		 *
		 * @param {string} key   The node field.
		 * @param {*}      value The new value.
		 * @return {void}
		 *
		 * @spec openspec/specs/flow-canvas/spec.md
		 */
		setNodeField(key, value) {
			if (this.selectedNodeId === null) {
				return
			}

			this.flow.nodes = this.nodes.map((node) => {
				if (node.id !== this.selectedNodeId) {
					return node
				}

				return { ...node, [key]: value }
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
		 * Resize a node.
		 *
		 * Coordinates and SIZE only — like `moveNode`, this must not be able to
		 * change what a node DOES. The engine never reads `width`/`height`;
		 * they are drawing, in the same class as `x`/`y`.
		 *
		 * @param {object} payload `{id, width, height}`.
		 * @return {void}
		 *
		 * @spec openspec/specs/flow-canvas/spec.md
		 */
		resizeNode({ id, width, height }) {
			this.flow.nodes = this.nodes.map((node) => {
				if (node.id !== id) {
					return node
				}

				return { ...node, width, height }
			})
			this.dirty = true
		},

		/**
		 * Connect two nodes, recording WHICH BRANCH the line leaves from.
		 *
		 * `sourcePort` is the port the author actually dragged. On a routing
		 * node the canvas draws one named out-port per branch, and the branch is
		 * carried to the engine as `edge.fromExit` — the field
		 * `FlowTokenRouter::placesForExit()` matches on when it decides which
		 * outgoing edges a token reaches. Without it every branch of a route
		 * produced an IDENTICAL edge: the ports were drawn, the author picked
		 * one, and the choice was dropped on the floor.
		 *
		 * The edge carries no `type`/`config` of its own. After
		 * or-flow-action-nodes the NODE is the action and the edge is sequence,
		 * so an edge carrying behaviour is the pre-inversion shape the engine
		 * refuses outright.
		 *
		 * @param {object} payload `{source, target, sourcePort}`.
		 * @param {string} payload.source     The originating node id.
		 * @param {string} payload.target     The receiving node id.
		 * @param {string} [payload.sourcePort] The port dragged from, e.g. `out:work`.
		 * @return {void}
		 *
		 * @spec openspec/specs/flow-canvas/spec.md
		 */
		connect({ source, target, sourcePort }) {
			if (!source || !target || source === target) {
				return
			}

			const fromExit = branchOfPort(sourcePort)

			// The branch is PART of the identity. Two branches of one route may
			// legitimately lead to the same node — "passed" and "failed" both
			// ending at `done` is ordinary — and keying the duplicate check on
			// from/to alone silently refused the second one.
			const exists = this.edges.some((edge) =>
				edge.from.includes(source)
				&& edge.to.includes(target)
				&& String(edge.fromExit || '') === fromExit,
			)
			if (exists) {
				return
			}

			const edge = {
				id: `step-${Date.now().toString(36)}-${this.edges.length}`,
				from: [source],
				to: [target],
			}

			// Only when it means something: an unbranched node has one exit, and
			// writing `fromExit: ''` on it would be a key the engine has to read
			// and ignore on every edge in every flow.
			if (fromExit !== '') {
				edge.fromExit = fromExit
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
		 * Write one field on the selected connection.
		 *
		 * A connection carries SEQUENCE and words for a reader — `title`,
		 * `description`, `notes` — and nothing the engine executes.
		 *
		 * `setEdgeType` and `setEdgeConfig`/`setEdgeConfigAll` used to live here
		 * and are deliberately gone. They wrote `type`/`config` onto the edge,
		 * which is the pre-inversion dialect:
		 * `FlowDefinitionBuilder::assertNotPreInversion()` refuses any document
		 * in which an edge carries a non-empty `type` — "an edge is sequence and
		 * a NODE is the action" — so a flow configured that way did not degrade,
		 * it stopped building at all. Behaviour is set through `setNodeType` /
		 * `setNodeConfig`.
		 *
		 * @param {string} key   The connection field.
		 * @param {*}      value The new value.
		 * @return {void}
		 *
		 * @spec openspec/specs/flow-canvas/spec.md
		 */
		setEdgeField(key, value) {
			if (this.selectedEdgeId === null) {
				return
			}

			this.flow.edges = this.edges.map((edge) => {
				if (edge.id !== this.selectedEdgeId) {
					return edge
				}

				return { ...edge, [key]: value }
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
		 *
		 * @spec openspec/specs/flow-canvas/spec.md
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
			// NO subject rather than a subject of empty strings. The engine
			// takes `array $subject = []` and a scheduled run passes none, so
			// sending `{uuid: '', register: '', schema: ''}` hands it three
			// blank fields to interpret where it expects nothing at all — the
			// two are not the same claim, and only one of them is what the
			// author meant by leaving the form empty.
			const named = String(subject?.uuid || '') !== ''
			const payload = named
				? {
					uuid: subject.uuid,
					register: subject.register,
					schema: subject.schema,
				}
				: {}

			const queued = await runFlow(this.flow.id, payload)

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
