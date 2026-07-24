<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  - SPDX-License-Identifier: EUPL-1.2
-->

<template>
	<div class="graph-builder">
		<CnGraphCanvas
			:nodes="editor.nodes"
			:edges="editor.edges"
			:selected-node-id="editor.selectedNodeId"
			:node-width="nodeWidth"
			:node-height="nodeHeight"
			@node-select="editor.selectedNodeId = $event"
			@canvas-click="editor.selectedNodeId = null"
			@node-move="editor.moveNode($event)"
			@connect="editor.connect($event)"
			@canvas-drop="onCanvasDrop">
			<!-- Orthogonal routing plus an explicit arrowhead: a flow has to read
			     in one direction, which a plain line does not convey. When a run
			     has produced a result for this hop, a badge sits on the midpoint
			     and opens that step's output. -->
			<template #edge="{ edge, from, to }">
				<g>
					<path
						class="graph-builder__edge"
						:d="edgePath(from, to)"
						fill="none"
						:marker-end="`url(#${arrowId})`" />

					<g
						v-if="resultFor(edge)"
						class="graph-builder__edge-badge"
						:transform="`translate(${edgeMidpoint(from, to).x}, ${edgeMidpoint(from, to).y})`"
						role="button"
						tabindex="0"
						:aria-label="t('hermiq', 'Show this step’s result')"
						@click.stop="openResult(edge)"
						@keydown.enter.stop="openResult(edge)">
						<circle r="11" class="graph-builder__edge-badge-bg" />
						<text text-anchor="middle" dominant-baseline="central" class="graph-builder__edge-badge-text">
							{}
						</text>
					</g>
				</g>
			</template>

			<template #node="{ node, selected }">
				<div
					class="graph-builder__node"
					:class="[`graph-builder__node--${node.type}`, { 'graph-builder__node--selected': selected }]">
					<span class="graph-builder__node-type">{{ typeLabel(node.type) }}</span>
					<span class="graph-builder__node-label">{{ nodeLabel(node) }}</span>
					<span v-if="editor.traceByNode[node.id]" class="graph-builder__node-badge">
						{{ editor.traceByNode[node.id] }}
					</span>
				</div>
			</template>
		</CnGraphCanvas>

		<!-- Arrowhead marker. Defined here (not relying on the canvas's own) so
		     the colour and size are ours to control. -->
		<svg class="graph-builder__defs" aria-hidden="true" focusable="false">
			<defs>
				<marker
					:id="arrowId"
					viewBox="0 0 10 10"
					refX="9"
					refY="5"
					markerWidth="5"
					markerHeight="5"
					orient="auto-start-reverse">
					<path d="M 0 0 L 10 5 L 0 10 z" class="graph-builder__arrowhead" />
				</marker>
			</defs>
		</svg>

		<NcEmptyContent
			v-if="editor.nodes.length === 0"
			class="graph-builder__empty"
			:name="t('hermiq', 'No nodes yet')"
			:description="t('hermiq', 'Add a node from the sidebar to start building this agent graph.')">
			<template #icon>
				<Sitemap :size="20" />
			</template>
		</NcEmptyContent>

		<RunGraphDialog
			v-if="editor.showRun"
			@close="editor.showRun = false"
			@ran="onRan" />

		<StepResultDialog
			v-if="resultDialog !== null"
			:title="resultDialog.title"
			:result="resultDialog.result"
			@close="resultDialog = null" />
	</div>
</template>

<script>
import { NcEmptyContent } from '@nextcloud/vue'
import { CnGraphCanvas } from '@conduction/nextcloud-vue'
import { showSuccess } from '@nextcloud/dialogs'
import Sitemap from 'vue-material-design-icons/Sitemap.vue'
import RunGraphDialog from '../dialogs/RunGraphDialog.vue'
import StepResultDialog from '../dialogs/StepResultDialog.vue'
import { useGraphEditorStore } from '../store/graphEditor.js'

/**
 * GraphBuilder — the canvas half of the graph editor.
 *
 * This page is only the graph itself: geometry and interaction (pan, zoom,
 * drag, drag-to-connect) come from the shared CnGraphCanvas, and this component
 * supplies typed node cards, directional edge routing, and per-hop result
 * badges. Every control — palette, node config, graph settings, notes,
 * Save/Run — lives in GraphSidebar, rendered in Nextcloud's real app sidebar
 * via the manifest's `pages[].sidebarComponent`. The two halves share the
 * graph-editor store, since they sit in different parts of the tree.
 */
export default {
	name: 'GraphBuilder',

	components: {
		CnGraphCanvas,
		NcEmptyContent,
		RunGraphDialog,
		Sitemap,
		StepResultDialog,
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

	setup() {
		return { editor: useGraphEditorStore() }
	},

	data() {
		return {
			arrowId: 'graph-builder-arrow',
			resultDialog: null,
			// Node box size. Shared with the canvas and with edge trimming, which
			// has to know where a card ends to stop the arrowhead short of it.
			nodeWidth: 200,
			nodeHeight: 80,
			nodeTypes: [
				{ key: 'trigger', label: this.t('hermiq', 'Trigger') },
				{ key: 'agent-step', label: this.t('hermiq', 'Agent step') },
				{ key: 'object-write', label: this.t('hermiq', 'Object write') },
				{ key: 'condition', label: this.t('hermiq', 'Condition') },
				{ key: 'router', label: this.t('hermiq', 'Router') },
			],
		}
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
		 * Drop from the sidebar palette onto the canvas at the drop point.
		 *
		 * @param {object} payload `{x, y}` in canvas space.
		 * @return {void}
		 */
		onCanvasDrop({ x, y }) {
			if (this.editor.paletteDragType === null) {
				return
			}

			this.editor.addNode(this.editor.paletteDragType, x, y)
			this.editor.paletteDragType = null
		},

		/**
		 * A completed run's trace is already on the store; close and notify.
		 *
		 * @return {void}
		 */
		onRan() {
			this.editor.showRun = false
			showSuccess(this.t('hermiq', 'Graph run finished.'))
		},

		/**
		 * Midpoint of an edge, where the result badge sits. Taken from the same
		 * geometry the path uses, so the badge always lands on the line.
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

		/**
		 * The last run's result for the step an edge leaves from, or null when
		 * this hop did not run.
		 *
		 * @param {object} edge The edge.
		 * @return {object|null} That step's trace entry.
		 */
		resultFor(edge) {
			if (!edge || !edge.source) {
				return null
			}

			return this.editor.resultByNode[edge.source] || null
		},

		/**
		 * Show a step's output as JSON.
		 *
		 * @param {object} edge The edge whose source produced the result.
		 * @return {void}
		 */
		openResult(edge) {
			const entry = this.resultFor(edge)
			if (!entry) {
				return
			}

			const node = this.editor.nodes.find((candidate) => candidate.id === edge.source)
			this.resultDialog = {
				title: node ? this.typeLabel(node.type) : edge.source,
				result: entry,
			}
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
			if (node.type === 'trigger') {
				const schema = config.triggerSchema || this.t('hermiq', 'any schema')
				return `${config.event || 'object.updated'} · ${schema}`
			}

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
	position: relative;
	height: 100%;
	min-height: 0;
	/* Clip so a node dragged past the edge can't paint over neighbouring chrome. */
	overflow: hidden;
}

/* Marker definitions only — never painted itself. */
.graph-builder__defs {
	position: absolute;
	width: 0;
	height: 0;
}

.graph-builder__empty {
	position: absolute;
	inset: 0;
	pointer-events: none;
}

.graph-builder__edge {
	stroke: var(--color-border-dark);
	stroke-width: 2;
}

.graph-builder__arrowhead {
	fill: var(--color-border-dark);
}

.graph-builder__edge-badge {
	cursor: pointer;
	pointer-events: all;
}

.graph-builder__edge-badge-bg {
	fill: var(--color-main-background);
	stroke: var(--color-border-dark);
	stroke-width: 2;
}

.graph-builder__edge-badge:hover .graph-builder__edge-badge-bg {
	stroke: var(--color-primary-element);
}

.graph-builder__edge-badge-text {
	font-size: 11px;
	font-weight: 600;
	fill: var(--color-main-text);
}

/* The canvas gives every node wrapper its own border/background/radius. This
   card supplies the real chrome (type accent, padding), so neutralise the
   wrapper's — otherwise every node renders as a box inside a box. */
:deep(.cn-graph-canvas__node) {
	border: none;
	background-color: transparent;
	border-radius: 0;
}

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
.graph-builder__node--trigger {
	border-inline-start-color: var(--color-warning, #c28900);
}

.graph-builder__node--agent-step {
	border-inline-start-color: var(--color-primary-element);
}

.graph-builder__node--object-write {
	border-inline-start-color: var(--color-success, #46ba61);
}

.graph-builder__node--condition {
	border-inline-start-color: var(--color-warning, #c28900);
}

.graph-builder__node--router {
	border-inline-start-color: var(--color-info, #4271b6);
}
</style>
