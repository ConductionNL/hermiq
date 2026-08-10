<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  - SPDX-License-Identifier: EUPL-1.2
-->

<template>
	<div class="flow-builder">
		<CnGraphCanvas
			:nodes="nodesWithPorts"
			:edges="editor.canvasEdges"
			:selected-node-id="editor.selectedNodeId"
			:node-width="nodeWidth"
			:node-height="nodeHeight"
			:zoom="zoom"
			:min-zoom="minZoom"
			:max-zoom="maxZoom"
			@update:zoom="zoom = $event"
			@node-select="editor.selectNode($event)"
			@canvas-click="editor.clearSelection()"
			@node-move="editor.moveNode($event)"
			@connect="editor.connect($event)"
			@canvas-drop="onCanvasDrop">
			<!-- Orthogonal routing plus an explicit arrowhead: a flow has to read
			     in one direction, which a plain line does not convey. The line
			     carries only its own title now — the behaviour moved to the
			     node — and when a run produced a result for this hop, the label
			     opens it. -->
			<!-- `edge` here is one drawable LINE; `edge.edge` is the connection
			     it came from. A split draws several lines from one node, so
			     selection always goes through the connection's own id. -->
			<template #edge="{ edge, from, to }">
				<g
					class="flow-builder__step"
					:class="{
						'flow-builder__step--selected': edge.edge.id === editor.selectedEdgeId,
						'flow-builder__step--unassigned': isUnassigned(edge.edge),
					}">
					<path
						class="flow-builder__edge"
						:d="edgePath(from, to)"
						fill="none"
						:marker-end="`url(#${arrowId})`"
						@click.stop="editor.selectEdge(edge.edge.id)" />

					<!-- The line's own label, and nothing else. Under the old
					     reading the step rode here and this chip named it; the
					     step now lives on the node, so a chip here would either
					     be blank on every edge of a migrated flow (which is
					     exactly the "No step type" that appeared 16 times) or
					     duplicate the card. An edge with no title draws no chip
					     at all rather than an empty one. -->
					<g
						v-if="edgeLabel(edge.edge)"
						class="flow-builder__step-label"
						:transform="`translate(${edgeMidpoint(from, to).x}, ${edgeMidpoint(from, to).y})`"
						role="button"
						tabindex="0"
						:aria-label="stepAriaLabel(edge.edge)"
						@click.stop="onStepClick(edge.edge)"
						@keydown.enter.stop="onStepClick(edge.edge)">
						<rect
							class="flow-builder__step-chip"
							:width="chipWidth(edge.edge)"
							:x="-chipWidth(edge.edge) / 2"
							y="-11"
							height="22"
							rx="11" />
						<text
							text-anchor="middle"
							dominant-baseline="central"
							class="flow-builder__step-text">
							{{ edgeLabel(edge.edge) }}
						</text>
						<circle
							v-if="resultFor(edge.edge)"
							class="flow-builder__step-result"
							:class="`flow-builder__step-result--${resultFor(edge.edge).status}`"
							:cx="(chipWidth(edge.edge) / 2) - 2"
							cy="-9"
							r="5" />
					</g>
				</g>
			</template>

			<!-- The card says what the node DOES; the line says only where it
			     goes. That is the inversion: the node is the action, so the
			     step's name is the card's headline rather than a chip on the
			     line. A node with no step type is called out as a warning
			     instead of being drawn as an ordinary card — the engine refuses
			     such a document, and drawing it normally is how it stayed
			     invisible. -->
			<template #node="{ node }">
				<!--
					Double-click opens the node's editor. It is a shortcut, NOT
					the only way in: a pointer gesture cannot be performed from
					the keyboard (WCAG 2.1 AA 2.1.1), so the Nodes tab carries an
					"Edit node" button for the selected node and that is the
					accessible path.
				-->
				<div
					class="flow-builder__node"
					:class="{
						[`flow-builder__node--${roleOf(node.id)}`]: true,
						'flow-builder__node--untyped': !node.type,
					}"
					@dblclick.stop="onNodeEdit(node)">
					<span class="flow-builder__node-step">{{ nodeStepLabel(node) }}</span>
					<span class="flow-builder__node-label">{{ nodeLabel(node) }}</span>
					<span v-if="nodeConfigSummary(node)" class="flow-builder__node-config">
						{{ nodeConfigSummary(node) }}
					</span>
					<span v-if="editor.markingByNode[node.id]" class="flow-builder__node-badge">
						{{ t('hermiq', 'Run is here') }}
					</span>
				</div>
			</template>
		</CnGraphCanvas>

		<!-- Arrowhead marker. Defined here (not relying on the canvas's own) so
		     the colour and size are ours to control. -->
		<svg class="flow-builder__defs" aria-hidden="true" focusable="false">
			<defs>
				<marker
					:id="arrowId"
					viewBox="0 0 10 10"
					refX="9"
					refY="5"
					markerWidth="5"
					markerHeight="5"
					orient="auto-start-reverse">
					<path d="M 0 0 L 10 5 L 0 10 z" class="flow-builder__arrowhead" />
				</marker>
			</defs>
		</svg>

		<!-- Canvas controls. Zoom first: a flow big enough to need a canvas is a
		     flow you cannot see all of, and the wheel — the canvas's own zoom
		     gesture — is mouse-only, so buttons are what make it reachable from
		     the keyboard (WCAG 2.1 AA 2.1.1). The reset is not a nicety either:
		     zoom has no floor at 1, so without it a canvas zoomed out is
		     laborious to bring back. -->
		<div class="flow-builder__controls">
			<div class="flow-builder__zoom" role="group" :aria-label="t('hermiq', 'Zoom')">
				<NcButton
					type="secondary"
					:disabled="zoom <= minZoom"
					:aria-label="t('hermiq', 'Zoom out')"
					@click="zoomBy(-zoomStep)">
					<template #icon>
						<Minus :size="20" />
					</template>
				</NcButton>
				<NcButton
					type="secondary"
					:aria-label="t('hermiq', 'Reset zoom to 100%')"
					@click="zoom = 1">
					{{ zoomPercent }}
				</NcButton>
				<NcButton
					type="secondary"
					:disabled="zoom >= maxZoom"
					:aria-label="t('hermiq', 'Zoom in')"
					@click="zoomBy(zoomStep)">
					<template #icon>
						<Plus :size="20" />
					</template>
				</NcButton>
			</div>

			<!-- Re-open control for the sidebar. It lives on the CANVAS because
			     once the sidebar is closed it has no chrome of its own left to
			     render a button in — a close with no way back is a one-way
			     door. -->
			<NcButton
				v-if="!editor.sidebarOpen"
				class="flow-builder__sidebar-toggle"
				type="secondary"
				:aria-label="t('hermiq', 'Open the flow sidebar')"
				@click="editor.sidebarOpen = true">
				<template #icon>
					<DockRight :size="20" />
				</template>
				{{ t('hermiq', 'Controls') }}
			</NcButton>
		</div>

		<!--
			While the flow is loading `nodes` is legitimately empty, so the
			empty state used to flash "No nodes yet" at every open — telling
			the operator their flow was blank a moment before drawing it.
			"Nothing here" and "not here yet" are different claims and only one
			of them is true during a fetch.
		-->
		<NcEmptyContent
			v-if="editor.loading"
			class="flow-builder__empty"
			:name="t('hermiq', 'Loading flow…')"
			:description="t('hermiq', 'Fetching the nodes and steps for this flow.')">
			<template #icon>
				<NcLoadingIcon :size="20" />
			</template>
		</NcEmptyContent>

		<NcEmptyContent
			v-else-if="editor.nodes.length === 0"
			class="flow-builder__empty"
			:name="t('hermiq', 'No nodes yet')"
			:description="t('hermiq', 'Add a node from the sidebar, then drag between two nodes to create a step.')">
			<template #icon>
				<Sitemap :size="20" />
			</template>
		</NcEmptyContent>

		<RunFlowDialog
			v-if="editor.showRun"
			@close="editor.showRun = false"
			@ran="onRan" />

		<StepResultDialog
			v-if="resultDialog !== null"
			:title="resultDialog.title"
			:result="resultDialog.result"
			@close="resultDialog = null" />

		<NodeEditModal
			:show="editor.nodeEditOpen"
			@close="editor.nodeEditOpen = false" />

		<DeadEndWarningDialog
			v-if="editor.deadEnds.length > 0"
			:node-ids="editor.deadEnds"
			@close="editor.deadEnds = []" />
	</div>
</template>

<script>
import { NcButton, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import { CnGraphCanvas } from '@conduction/nextcloud-vue'
import { showSuccess } from '@nextcloud/dialogs'
import DockRight from 'vue-material-design-icons/DockRight.vue'
import Minus from 'vue-material-design-icons/Minus.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Sitemap from 'vue-material-design-icons/Sitemap.vue'
import DeadEndWarningDialog from '../dialogs/DeadEndWarningDialog.vue'
import NodeEditModal from '../modals/Flow/NodeEditModal.vue'
import RunFlowDialog from '../dialogs/RunFlowDialog.vue'
import StepResultDialog from '../dialogs/StepResultDialog.vue'
import { useFlowEditorStore } from '../store/flowEditor.js'

/**
 * Step types that END a path deliberately.
 *
 * The engine's own answer is `IFlowTerminalNode`, resolved through its node
 * registry, and that is not reachable from the browser: the catalogue endpoint
 * returns a type's id and display name, not whether it is terminal. Listing
 * them here keeps the DRAWING in step with the engine for the types that exist
 * today, and a node can always say so itself with `exit: true` — which is the
 * answer that does not need this list at all.
 *
 * If a contributed terminal type is missing here, the cost is one extra
 * out-port on its card, not a wrong run: the engine still ends the path.
 */
const TERMINAL_STEP_TYPES = ['openregister.stop']

/** Step types that own a body of repeated nodes (IterateNode's `config.body`). */
const LOOP_STEP_TYPES = ['openregister.iterate', 'openregister.loop']

/**
 * FlowBuilder — the canvas half of the flow editor.
 *
 * This page is only the flow itself: geometry and interaction (pan, zoom,
 * drag, drag-to-connect) come from the shared canvas in nc-vue, and this
 * component supplies node cards, their connection ports, and directional edge
 * routing. Every control — node list, step config, flow settings, notes,
 * Save/Run — lives in FlowSidebar, rendered in Nextcloud's real app sidebar via
 * the manifest's `pages[].sidebarComponent`. The two halves share the
 * flow-editor store, since they sit in different parts of the tree.
 *
 * The shared canvas is still imported as `CnGraphCanvas`: it renames to
 * `CnFlowCanvas` in `cn-flow-store-and-canvas-rename`, which needs an nc-vue
 * release before this import can move. That is the last "graph" left here.
 *
 * ## What a node is, and what an edge is
 *
 * A flow is a Petri net (ADR-065), and `or-flow-action-nodes` INVERTED which
 * half of it carries behaviour. A NODE is the action: it holds the step type
 * and its config, and it is the thing that runs. An EDGE is sequence: `from`,
 * `to`, and an optional title.
 *
 * So the card says what the node DOES and the line says only where it GOES.
 * This canvas drew the opposite until now, which is why every line on a
 * migrated flow rendered the words "No step type": the type it was looking for
 * had moved to the node, and a document where no edge carries one is exactly
 * what a correctly migrated flow looks like.
 *
 * ## Ports
 *
 * Role is expressed by the ABSENCE of a port — a start has no in-port, an exit
 * has no out-port — so which end of the flow you are looking at survives
 * greyscale and does not depend on telling two hues apart (WCAG 1.4.1). A
 * routing node exposes one NAMED out-port per branch, which is what makes a
 * two-way route readable without opening its configuration.
 */
export default {
	name: 'FlowBuilder',

	components: {
		CnGraphCanvas,
		DockRight,
		Minus,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		Plus,
		DeadEndWarningDialog,
		NodeEditModal,
		RunFlowDialog,
		Sitemap,
		StepResultDialog,
	},

	props: {
		/**
		 * Flow id from the route (`/flows/:id`). The literal `new` starts a
		 * blank flow, so creating and editing share one page.
		 */
		id: {
			type: String,
			default: 'new',
		},
	},

	setup() {
		return { editor: useFlowEditorStore() }
	},

	data() {
		return {
			arrowId: 'flow-builder-arrow',
			resultDialog: null,
			// Node box size. Shared with the canvas and with edge trimming, which
			// has to know where a card ends to stop the arrowhead short of it.
			nodeWidth: 200,
			nodeHeight: 80,
			// Zoom is OWNED here. CnGraphCanvas takes it as a prop and reports
			// changes through `update:zoom` — it never mutates it — so a canvas
			// whose consumer does not bind it is pinned at 1 forever and the
			// wheel gesture does nothing at all, which is what this page did.
			zoom: 1,
			// Matched to the canvas's own defaults and to its wheel increment, so
			// the buttons and the wheel move in the same steps and hit the same
			// ends rather than disabling at a limit the wheel can still pass.
			zoomStep: 0.1,
			minZoom: 0.3,
			maxZoom: 2,
		}
	},

	computed: {
		/** @return {string} The current zoom, for the reset button's label. */
		zoomPercent() {
			return `${Math.round(this.zoom * 100)}%`
		},

		/**
		 * The nodes, each carrying the ports the canvas should draw for it.
		 *
		 * Built here rather than in the store because it is a presentation
		 * concern: the stored document has no `ports` key and must not gain
		 * one — ports are DERIVED from what the node is and what leaves it, so
		 * persisting them would let the drawing disagree with the graph.
		 *
		 * @return {Array<object>} The nodes with a `ports` array.
		 *
		 * @spec openspec/specs/flow-canvas/spec.md
		 */
		nodesWithPorts() {
			return (this.editor.nodes || []).map((node) => ({
				...node,
				ports: this.portsForNode(node),
			}))
		},
	},

	watch: {
		id() {
			this.editor.open(this.id)
		},
	},

	created() {
		this.editor.load(this.id)
	},

	methods: {
		/**
		 * Select a node and open its editor.
		 *
		 * Selects first: the modal reads `editor.selectedNode`, so opening
		 * without selecting would show whichever node happened to be selected
		 * before — and every write would land on that one.
		 *
		 * @param {object} node The node that was double-clicked.
		 * @return {void}
		 *
		 * @spec openspec/specs/flow-canvas/spec.md
		 */
		onNodeEdit(node) {
			this.editor.selectNode(node.id)
			this.editor.nodeEditOpen = true
		},

		/**
		 * The ports a node exposes, in render order.
		 *
		 * Role is expressed by the ABSENCE of a port, not by colour alone: a
		 * start node has no in-port and an exit node has no out-port, so which
		 * end of the flow you are looking at survives being printed in
		 * greyscale or read by someone who cannot distinguish the two hues
		 * (WCAG 1.4.1 — colour is never the only carrier).
		 *
		 * @param {object} node The node.
		 * @return {Array<object>} Its ports.
		 *
		 * @spec openspec/specs/flow-canvas/spec.md
		 */
		portsForNode(node) {
			const ports = []

			// Everything that is not a start receives. Drawn on the LEFT, which
			// is what makes an edge's direction readable without following the
			// arrowhead: lines land on the left and leave on the right.
			if (!this.editor.startNodeIds.includes(node.id)) {
				ports.push({ id: 'in', side: 'left', kind: 'in', label: this.t('hermiq', 'In') })
			}

			// A loop owns its body (IterateNode's `config.body` is a list of
			// node ids). Those hang off the TOP as a visible sub-list, kept
			// clear of the left-to-right run of the main chain — a loop body
			// drawn inline reads as a detour rather than as a repeat.
			if (this.isLoopNode(node)) {
				ports.push({ id: 'body-out', side: 'top', kind: 'out', label: this.t('hermiq', 'Loop body') })
				ports.push({ id: 'body-in', side: 'top', kind: 'in', label: this.t('hermiq', 'Loop body returns') })
			}

			const branches = this.branchesOf(node)
			if (branches.length > 0) {
				// One origin per branch, each named. This is the whole reason
				// ports beat a single handle: a two-way route drawn from one
				// point is only decipherable by opening its configuration.
				branches.forEach((branch) => {
					ports.push({
						id: `out:${branch}`,
						side: 'right',
						kind: 'out',
						label: branch,
					})
				})

				return ports
			}

			// A node that ends the flow deliberately has nothing to send on.
			// `exit: true` or a terminal type — the same two answers the engine
			// accepts, OR-ed, so the drawing agrees with what will actually run
			// (openregister: IFlowTerminalNode).
			if (!this.isExitNode(node)) {
				ports.push({ id: 'out', side: 'right', kind: 'out', label: this.t('hermiq', 'Out') })
			}

			return ports
		},

		/**
		 * Whether this node ends its path on purpose.
		 *
		 * @param {object} node The node.
		 * @return {boolean} True when it is an exit.
		 *
		 * @spec openspec/specs/flow-canvas/spec.md
		 */
		isExitNode(node) {
			if (node.exit === true) {
				return true
			}

			return TERMINAL_STEP_TYPES.includes(node.type)
		},

		/**
		 * Whether this node is a loop that owns a body.
		 *
		 * @param {object} node The node.
		 * @return {boolean} True for a loop node.
		 *
		 * @spec openspec/specs/flow-canvas/spec.md
		 */
		isLoopNode(node) {
			return LOOP_STEP_TYPES.includes(node.type)
		},

		/**
		 * The branch names a routing node sends items to.
		 *
		 * Read from `config.rules[].output` plus `config.default`, which is what
		 * RouterNode itself reads. Deliberately NOT from `config.routes` — that
		 * is the single most common way to author the node wrong, and honouring
		 * it here would draw ports for a configuration the engine ignores,
		 * making a broken flow look correct.
		 *
		 * A switch's conditions live on its EDGES rather than its config, so it
		 * has no branches to derive here and falls back to one out-port.
		 *
		 * @param {object} node The node.
		 * @return {Array<string>} The branch names, in order, deduplicated.
		 *
		 * @spec openspec/specs/flow-canvas/spec.md
		 */
		branchesOf(node) {
			// Derived in the STORE, not here. The orphaned-branch check reads the
			// same list, and two derivations would eventually disagree — marking
			// an edge unassigned while the port it points at is still drawn.
			return this.editor.branchesByNode[node.id] || []
		},

		/**
		 * What this NODE does: the catalogue's name for its step type.
		 *
		 * The node is the action (or-flow-action-nodes), so this is the card's
		 * headline. A node with no type is called out rather than left blank —
		 * an untyped node is refused by the engine, and rendering it as an
		 * ordinary card is how it stayed invisible.
		 *
		 * @param {object} node The node.
		 * @return {string} The step name.
		 *
		 * @spec openspec/specs/flow-canvas/spec.md
		 */
		nodeStepLabel(node) {
			if (!node.type) {
				return this.t('hermiq', 'No step type')
			}

			const entry = (this.editor.nodeCatalog || []).find((candidate) => candidate.id === node.type)

			// A type the catalogue cannot explain is shown as its raw id rather
			// than guessed at from a list that may not match the engine.
			return entry ? (entry.displayName || entry.id) : node.type
		},

		/**
		 * The one piece of configuration worth putting on the card.
		 *
		 * A card that shows everything is a config editor, and a card that
		 * shows nothing makes every node of a type look identical. This shows
		 * the first key the step actually reads, which is what distinguishes
		 * two `object-read`s from each other at a glance.
		 *
		 * Annotation keys (`$comment` and friends) are documentation the engine
		 * never reads, so they are skipped — putting one on the card would
		 * describe the flow's prose rather than its behaviour.
		 *
		 * @param {object} node The node.
		 * @return {string} A short summary, or an empty string.
		 *
		 * @spec openspec/specs/flow-canvas/spec.md
		 */
		nodeConfigSummary(node) {
			const config = node.config || {}
			const keys = Object.keys(config).filter((key) => key.startsWith('$') === false)
			if (keys.length === 0) {
				return ''
			}

			const key = keys[0]
			const value = config[key]

			let rendered = ''
			if (typeof value === 'string' || typeof value === 'number' || typeof value === 'boolean') {
				rendered = String(value)
			} else if (Array.isArray(value)) {
				rendered = this.n('hermiq', '%n entry', '%n entries', value.length)
			} else if (value && typeof value === 'object') {
				rendered = Object.keys(value).join(', ')
			}

			if (rendered.length > 42) {
				rendered = `${rendered.slice(0, 41)}…`
			}

			if (rendered === '') {
				return key
			}

			return `${key}: ${rendered}`
		},

		/**
		 * Step the zoom, clamped to the same range the canvas enforces.
		 *
		 * Rounded to two places because repeated float addition drifts —
		 * 0.1 added seven times is 0.7000000000000001, and that renders as a
		 * "70%" button that never quite equals any round number it is compared
		 * against.
		 *
		 * @param {number} delta The change to apply.
		 * @return {void}
		 */
		zoomBy(delta) {
			const next = Math.min(this.maxZoom, Math.max(this.minZoom, this.zoom + delta))
			this.zoom = Math.round(next * 100) / 100
		},

		/**
		 * Drop from the sidebar onto the canvas at the drop point.
		 *
		 * Only a NODE can be dropped: a step has to run between two places, so
		 * it is created by connecting them, never by dropping one on empty
		 * canvas where it would have no endpoints.
		 *
		 * @param {object} payload `{x, y}` in canvas space.
		 * @param {number} payload.x Drop x-coordinate in canvas space.
		 * @param {number} payload.y Drop y-coordinate in canvas space.
		 * @return {void}
		 */
		onCanvasDrop({ x, y }) {
			// Create the type that was dragged. A drop with no type in flight
			// is not a palette drag at all — creating an untyped node for it
			// would put a node the engine refuses on the canvas, silently.
			const type = this.editor.paletteDragType
			if (!type) {
				return
			}

			this.editor.addNode(type, x, y)
			this.editor.paletteDragType = null
		},

		/**
		 * A completed run is already on the store; close and notify.
		 *
		 * @return {void}
		 */
		onRan() {
			this.editor.showRun = false
			showSuccess(this.t('hermiq', 'Flow run queued.'))
		},

		/**
		 * Click a step's label: select it, and open its result when it has one.
		 *
		 * @param {object} edge The step.
		 * @return {void}
		 *
		 * @spec openspec/specs/flow-canvas/spec.md
		 */
		onStepClick(edge) {
			this.editor.selectEdge(edge.id)
			const entry = this.resultFor(edge)
			if (entry) {
				// Titled by the connection, since that is what was clicked. An
				// unlabelled line falls back to naming its endpoints rather
				// than opening a dialog with a blank heading.
				const label = this.edgeLabel(edge)
				const fallback = this.t('hermiq', '{from} → {to}', {
					from: edge.from.join(', '),
					to: edge.to.join(', '),
				})

				this.resultDialog = { title: (label || fallback), result: entry }
			}
		},

		/**
		 * Whether a place starts a run, ends one, or sits between.
		 *
		 * Both answers come from the store, which mirrors the engine's own
		 * inference — so a card marked "start" is a card the engine will really
		 * start on, not the editor's guess.
		 *
		 * @param {string} id The place id.
		 * @return {string} `'start'`, `'end'` or `'step'`.
		 */
		roleOf(id) {
			if (this.editor.startNodeIds.includes(id)) {
				return 'start'
			}

			if (this.editor.endNodeIds.includes(id)) {
				return 'end'
			}

			return 'step'
		},

		/**
		 * A place's label.
		 *
		 * Falls back to the id, which is what the engine calls it and what every
		 * edge references — never to a dash. A place that only has an id is
		 * completely ordinary (most of the ported hydra flows are written that
		 * way), so rendering those as "—" blanked whole flows.
		 *
		 * @param {object} node The place.
		 * @return {string} The label.
		 */
		nodeLabel(node) {
			return node.name || node.id
		},

		/**
		 * A connection's own label.
		 *
		 * The place a step used to arrive at became this line's title when the
		 * document was migrated, so the words authors wrote ("Gates passed",
		 * "work") survive here. Empty is a legitimate answer — an unlabelled
		 * connection draws no chip rather than an empty one.
		 *
		 * @param {object} edge The connection.
		 * @return {string} The label, or an empty string.
		 *
		 * @spec openspec/specs/flow-canvas/spec.md
		 */
		edgeLabel(edge) {
			if (!edge) {
				return ''
			}

			// An unassigned line ALWAYS gets a chip, even with no title. It is
			// the one case where a blank line would hide the problem: the author
			// changed a routing rule in another panel and this connection quietly
			// stopped meaning anything.
			if (this.isUnassigned(edge) === true) {
				return this.t('hermiq', 'Unassigned: branch “{branch}” no longer exists', {
					branch: String(edge.fromExit || '').trim(),
				})
			}

			return String(edge.title || edge.name || '').trim()
		},

		/**
		 * Whether this line leaves a branch its node no longer offers.
		 *
		 * The verdict comes from the store, which derives it from the same branch
		 * list the ports are drawn from — so a line can never be marked
		 * unassigned while the port it points at is still on screen.
		 *
		 * @param {object} edge The connection.
		 * @return {boolean} True when its branch is gone.
		 *
		 * @spec openspec/specs/flow-canvas/spec.md
		 */
		isUnassigned(edge) {
			if (!edge || !edge.id) {
				return false
			}

			return this.editor.orphanedBranchEdgeIds.includes(edge.id)
		},

		/**
		 * Accessible description of a connection.
		 *
		 * @param {object} edge The connection.
		 * @return {string} The label.
		 *
		 * @spec openspec/specs/flow-canvas/spec.md
		 */
		stepAriaLabel(edge) {
			const label = this.edgeLabel(edge)
			const from = edge.from.join(', ')
			const to = edge.to.join(', ')

			if (label === '') {
				return this.t('hermiq', 'Connection from {from} to {to}', { from, to })
			}

			return this.t('hermiq', '{label}, from {from} to {to}', { label, from, to })
		},

		/**
		 * Chip width for a step label, sized to its text.
		 *
		 * Measured from the character count rather than the DOM: an SVG text
		 * node has no width until it is laid out, so a chip sized after the fact
		 * flickers on every render.
		 *
		 * @param {object} edge The connection.
		 * @return {number} The width in canvas units.
		 *
		 * @spec openspec/specs/flow-canvas/spec.md
		 */
		chipWidth(edge) {
			return Math.max(56, (this.edgeLabel(edge).length * 6.5) + 20)
		},

		/**
		 * The last run's entry for this step, or null when it did not run.
		 *
		 * @param {object} edge The step.
		 * @return {object|null} The log entry.
		 */
		resultFor(edge) {
			if (!edge) {
				return null
			}

			return this.editor.resultByEdge[this.editor.transitionName(edge)] || null
		},

		/**
		 * Midpoint of an edge, where the step label sits. Taken from the same
		 * geometry the path uses, so the label always lands on the line.
		 *
		 * @param {{x: number, y: number}} from Source centre.
		 * @param {{x: number, y: number}} to   Target centre.
		 * @return {{x: number, y: number}} The midpoint.
		 */
		edgeMidpoint(from, to) {
			return this.edgeGeometry(from, to).mid
		},

		/**
		 * The SVG `d` for one edge.
		 *
		 * @param {{x: number, y: number}} from Source centre.
		 * @param {{x: number, y: number}} to   Target centre.
		 * @return {string} The path.
		 */
		edgePath(from, to) {
			return this.edgeGeometry(from, to).d
		},

		/**
		 * Route one edge and report where its middle is.
		 *
		 * Two decisions, in order:
		 *
		 * 1. Trim the endpoints from the node CENTRES (what the canvas hands the
		 *    slot) back to the node borders, plus a small gap. Drawn centre to
		 *    centre, the last stretch — the arrowhead included — sits under the
		 *    target card, so the flow reads as an undirected line.
		 *
		 * 2. Bend only when a straight run would not fit. Two cards whose boxes
		 *    still overlap across the run have a straight line available that
		 *    leaves one border and meets the other, so that is what they get.
		 *    Bending on any difference in centres produced a wide staircase for
		 *    a modest offset and, for a near-aligned pair, two corner arcs with
		 *    a zero-length leg between them — a visible wobble in place of a
		 *    line. A corner should mean "these nodes are not in line", not
		 *    "these nodes are a few pixels apart".
		 *
		 * @param {{x: number, y: number}} from Source centre.
		 * @param {{x: number, y: number}} to   Target centre.
		 * @return {{d: string, mid: {x: number, y: number}}} Path and midpoint.
		 */
		edgeGeometry(from, to) {
			const gap = 6
			// Keep a little of the shared span on either side of a straight run,
			// so it reads as leaving the card rather than clipping its corner.
			const margin = 24
			const vertical = Math.abs(to.y - from.y) >= Math.abs(to.x - from.x)

			const [a, b] = vertical
				? this.trimOn('y', this.nodeHeight, gap, from, to)
				: this.trimOn('x', this.nodeWidth, gap, from, to)

			const across = vertical ? Math.abs(to.x - from.x) : Math.abs(to.y - from.y)
			const span = vertical ? this.nodeWidth : this.nodeHeight
			if (across <= (span - margin)) {
				// Straight run down (or across) the middle of the shared span.
				const mid = { x: (a.x + b.x) / 2, y: (a.y + b.y) / 2 }
				const [start, end] = vertical
					? [{ x: mid.x, y: a.y }, { x: mid.x, y: b.y }]
					: [{ x: a.x, y: mid.y }, { x: b.x, y: mid.y }]

				return { d: `M ${start.x} ${start.y} L ${end.x} ${end.y}`, mid }
			}

			return this.elbow(a, b, vertical)
		},

		/**
		 * Pull both endpoints in along one axis by half a node plus a gap.
		 *
		 * @param {string} axis  `'x'` or `'y'`.
		 * @param {number} size  The node's extent on that axis.
		 * @param {number} gap   Clearance to leave beyond the border.
		 * @param {object} from  Source centre.
		 * @param {object} to    Target centre.
		 * @return {Array<object>} Trimmed `[from, to]`.
		 */
		trimOn(axis, size, gap, from, to) {
			const delta = to[axis] - from[axis]
			const inset = Math.min((size / 2) + gap, Math.abs(delta) / 2)
			const step = Math.sign(delta) * inset

			return [
				{ ...from, [axis]: from[axis] + step },
				{ ...to, [axis]: to[axis] - step },
			]
		},

		/**
		 * Orthogonal path between two already-trimmed points, with rounded
		 * corners: out along the run axis to the halfway line, across, then on
		 * to the target. Only reached when the nodes are genuinely out of line.
		 *
		 * @param {{x: number, y: number}} from     Trimmed source point.
		 * @param {{x: number, y: number}} to       Trimmed target point.
		 * @param {boolean}                vertical Whether the run is vertical.
		 * @return {{d: string, mid: {x: number, y: number}}} Path and midpoint.
		 */
		elbow(from, to, vertical) {
			const dx = to.x - from.x
			const dy = to.y - from.y
			// A corner radius must never eat more than half of either leg.
			const rad = Math.min(12, Math.abs(dx) / 2, Math.abs(dy) / 2)
			const sx = Math.sign(dx)
			const sy = Math.sign(dy)

			if (vertical) {
				const midY = from.y + (dy / 2)

				return {
					mid: { x: from.x + (dx / 2), y: midY },
					d: [
						`M ${from.x} ${from.y}`,
						`L ${from.x} ${midY - (rad * sy)}`,
						`Q ${from.x} ${midY} ${from.x + (rad * sx)} ${midY}`,
						`L ${to.x - (rad * sx)} ${midY}`,
						`Q ${to.x} ${midY} ${to.x} ${midY + (rad * sy)}`,
						`L ${to.x} ${to.y}`,
					].join(' '),
				}
			}

			const midX = from.x + (dx / 2)

			return {
				mid: { x: midX, y: from.y + (dy / 2) },
				d: [
					`M ${from.x} ${from.y}`,
					`L ${midX - (rad * sx)} ${from.y}`,
					`Q ${midX} ${from.y} ${midX} ${from.y + (rad * sy)}`,
					`L ${midX} ${to.y - (rad * sy)}`,
					`Q ${midX} ${to.y} ${midX + (rad * sx)} ${to.y}`,
					`L ${to.x} ${to.y}`,
				].join(' '),
			}
		},
	},
}
</script>

<style scoped>
.flow-builder {
	position: relative;
	height: 100%;
	min-height: 0;
	/* Clip so a node dragged past the edge can't paint over neighbouring chrome. */
	overflow: hidden;
}

/* Marker definitions only — never painted itself. */
.flow-builder__defs {
	position: absolute;
	width: 0;
	height: 0;
}

.flow-builder__empty {
	position: absolute;
	inset: 0;
	pointer-events: none;
}

.flow-builder__controls {
	position: absolute;
	top: 12px;
	inset-inline-end: 12px;
	z-index: 2;
	display: flex;
	gap: 8px;
	align-items: center;
}

.flow-builder__zoom {
	display: flex;
	gap: 4px;
	padding: 4px;
	border-radius: var(--border-radius-large, 8px);
	background-color: var(--color-main-background);
	box-shadow: 0 1px 4px var(--color-box-shadow, rgba(0, 0, 0, 0.2));
}

/* ---- Steps (edges) ---------------------------------------------------- */

.flow-builder__edge {
	stroke: var(--color-border-dark);
	stroke-width: 2;
	/* The SVG layer is transparent to clicks; a step opts back in so it can be
	   selected. Without this the only way to configure a step would be to draw
	   a new one. */
	pointer-events: stroke;
	cursor: pointer;
}

.flow-builder__arrowhead {
	fill: var(--color-border-dark);
}

.flow-builder__step--selected .flow-builder__edge {
	stroke: var(--color-primary-element);
	stroke-width: 3;
}

.flow-builder__step-label {
	cursor: pointer;
	pointer-events: all;
}

.flow-builder__step-chip {
	fill: var(--color-main-background);
	stroke: var(--color-border-dark);
	stroke-width: 2;
}

.flow-builder__step--selected .flow-builder__step-chip {
	stroke: var(--color-primary-element);
}

/* An untyped step runs nothing and reports success, so it is called out on the
   canvas rather than left to look like any other hop. */
.flow-builder__step--untyped .flow-builder__step-chip {
	stroke: var(--color-warning, #c28900);
	stroke-dasharray: 4, 3;
}

/* A connection whose branch was removed from its routing node. Dashed AND
   labelled: the line is drawn differently and the chip says what happened in
   words, so the state survives greyscale and does not depend on telling two
   line colours apart (WCAG 1.4.1). Never removed automatically — the author
   drew it, and deleting it because a value changed elsewhere would lose work
   with no trace. */
.flow-builder__step--unassigned .flow-builder__edge {
	stroke: var(--color-warning, #c28900);
	stroke-dasharray: 6, 4;
}

.flow-builder__step--unassigned .flow-builder__step-chip {
	stroke: var(--color-warning, #c28900);
	stroke-dasharray: 4, 3;
}

.flow-builder__step-label:hover .flow-builder__step-chip {
	stroke: var(--color-primary-element);
}

.flow-builder__step-text {
	font-size: 11px;
	font-weight: 600;
	fill: var(--color-main-text);
}

.flow-builder__step-result {
	fill: var(--color-success, #46ba61);
	stroke: var(--color-main-background);
	stroke-width: 2;
}

.flow-builder__step-result--failed,
.flow-builder__step-result--stopped {
	fill: var(--color-error, #e9322d);
}

.flow-builder__step-result--suspended {
	fill: var(--color-warning, #c28900);
}

/* ---- Places (nodes) --------------------------------------------------- */

/* ONE box — drawn HERE, on the wrapper.
 *
 * There are two elements per node and only one of them may carry chrome: the
 * wrapper CnGraphCanvas positions, and the card body in our slot. The wrapper
 * wins, because it is also what the canvas puts its own `--selected` state on;
 * styling the body instead would leave selection highlighting an element that
 * no longer looked like the card.
 *
 * Both failure modes are one line apart and both have shipped:
 *
 *   - body draws a frame TOO (its own radius over the wrapper's border) — a
 *     card inside a card, the nested chrome originally reported;
 *   - the wrapper's frame is reset away and the body draws none — no card at
 *     all, just an accent bar and floating text.
 *
 * So the frame is declared once, explicitly, on the wrapper. It is restated
 * rather than left to the canvas's own scoped rule because that rule ties on
 * specificity with anything written here — `:deep(.cn-graph-canvas__node)`
 * compiles to `[data-v-builder] .cn-graph-canvas__node`, (0,2,0), exactly
 * matching `.cn-graph-canvas__node[data-v-canvas]` — so which one won came down
 * to bundle order. Anchoring on `.flow-builder` settles it at (0,3,0). */
.flow-builder :deep(.cn-graph-canvas__node) {
	border: 2px solid var(--color-border);
	background-color: var(--color-main-background);
	border-radius: var(--border-radius-large, 8px);
	/* Clips the body's role accent to the card's curve, so the accent needs no
	   radius of its own — which is what drew the second frame. */
	overflow: hidden;
}

.flow-builder :deep(.cn-graph-canvas__node--selected) {
	border-color: var(--color-primary-element);
}

/* The connection handle is the node's OUTPUT PORT, and it is the one piece of
   node chrome the canvas renders outside our slot — so it is styled from here,
   selected structurally on what our slot rendered inside the same wrapper.
   Sized explicitly because Nextcloud's global button rules give every <button>
   a minimum height: the port is declared 16x16 round in the canvas and measured
   16x34 on screen, a bar rather than a dot. */
.flow-builder :deep(.cn-graph-canvas__handle) {
	width: 16px;
	height: 16px;
	min-height: 16px;
	min-width: 16px;
	border-radius: 50%;
}

/* Role, on the port: green where a run begins, red where it ends. The port is a
   sibling of our slot content, so it cannot be given a class from inside the
   slot — `:has()` reads the role off the card we DID render. */
.flow-builder :deep(.cn-graph-canvas__node:has(.flow-builder__node--start) .cn-graph-canvas__handle) {
	background-color: var(--color-success, #46ba61);
}

.flow-builder :deep(.cn-graph-canvas__node:has(.flow-builder__node--end) .cn-graph-canvas__handle) {
	background-color: var(--color-error, #e9322d);
}

/* No border, background or radius: the wrapper above owns the card, and this
   fills it. The role accent is an INSET shadow rather than a border for the
   same reason a table-row accent is — a border would add a second frame and
   take layout width from the body. It needs no radius of its own because the
   wrapper clips it. */
.flow-builder__node {
	display: flex;
	flex-direction: column;
	justify-content: center;
	gap: 2px;
	width: 100%;
	height: 100%;
	padding: 8px 10px 8px 14px;
	box-shadow: inset 6px 0 0 0 var(--color-border);
	box-sizing: border-box;
	overflow: hidden;
}

/* Selection is the wrapper's: CnGraphCanvas sets --selected on the element it
   positions, so restating it here would be a second, competing highlight. */

/* Role accents — NC variables only (ADR-010). Keyed on the place's ROLE in the
   flow, not on a node "type": a place has no type, and the per-type accents
   this replaced could never match anything for that reason. */
.flow-builder__node--start {
	box-shadow: inset 6px 0 0 0 var(--color-success, #46ba61);
}

.flow-builder__node--end {
	box-shadow: inset 6px 0 0 0 var(--color-error, #e9322d);
}

.flow-builder__node--step {
	box-shadow: inset 6px 0 0 0 var(--color-primary-element);
}

.flow-builder__node-role {
	font-size: 11px;
	text-transform: uppercase;
	letter-spacing: 0.04em;
	color: var(--color-text-maxcontrast);
}

/* The step is the headline now: it is what the node DOES, and it is what
   distinguishes two cards at a glance. The node's own name sits under it as
   the secondary line, because a name is an identifier and a step is a
   behaviour. */
.flow-builder__node-step {
	font-weight: 600;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.flow-builder__node-label {
	font-size: 11px;
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

/* One line of the configuration the step actually reads, so two nodes of the
   same type are told apart without opening either. */
.flow-builder__node-config {
	font-size: 11px;
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
	font-family: var(--font-face-monospace, monospace);
}

/* A node with no step type is refused by the engine. Called out with a border
   AND the word "No step type" in its headline — never colour alone, which
   would vanish in greyscale or for a viewer who cannot separate the two hues
   (WCAG 1.4.1). */
.flow-builder__node--untyped {
	outline: 2px dashed var(--color-warning, #c28900);
	outline-offset: -2px;
}

.flow-builder__node--untyped .flow-builder__node-step {
	color: var(--color-warning-text, #7a5800);
}

.flow-builder__node-badge {
	align-self: flex-start;
	font-size: 11px;
	padding: 0 6px;
	border-radius: var(--border-radius-pill, 12px);
	background-color: var(--color-primary-element);
	color: var(--color-primary-element-text);
}
</style>
