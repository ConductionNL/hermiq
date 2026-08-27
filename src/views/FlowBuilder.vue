<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  - SPDX-License-Identifier: EUPL-1.2
-->

<template>
	<div class="flow-builder">
		<!-- DRIVEN BY VUE FLOW, NOT AROUND IT.
		     This passed six props the canvas no longer has (`selectedNodeId`,
		     `nodeWidth`, `nodeHeight`, `zoom`, `showGrid`, `resizable`) and
		     listened for three events it no longer emits (`nodeMove`,
		     `nodeResize`, `update:zoom`) — so node drags reached nothing and
		     the zoom controls drove a prop that had been deleted.

		     `nodeWidth`/`nodeHeight` went when the canvas moved to Vue Flow,
		     which MEASURES the rendered node; guessing its size is the class of
		     bug that removal was for. `fitView` is what frames a graph on load,
		     and nothing was asking for it. -->
		<CnGraphCanvas
			ref="graph"
			:nodes="nodesWithPorts"
			:edges="canvasEdges"
			:minZoom="minZoom"
			:maxZoom="maxZoom"
			fitView
			showBackground
			showControls
			showMiniMap
			@nodesChange="onCanvasNodesChange"
			@nodeSelect="onCanvasSelect($event)"
			@edgeSelect="onCanvasEdgeSelect($event)"
			@edgeLabelClick="onEdgeLabelClick"
			@edgeLabelMove="onEdgeLabelMove"
			@edgeLabelContext="onEdgeLabelContext"
			@canvasClick="onCanvasClick"
			@connect="editor.connect($event)"
			@canvasDrop="onCanvasDrop"
			@contextmenu.prevent="onCanvasContext">
			<!-- THE LINE IS DRAWN BY VUE FLOW; ONLY THE LABEL IS OURS.

			     What stood here was a `#edge` slot returning SVG: an orthogonal
			     router, an arrowhead marker, midpoint arithmetic and a chip,
			     around 120 lines of it. The slot was removed when the canvas
			     moved to Vue Flow and **nothing said so** — Vue drops a slot the
			     child does not render, silently — so all of it compiled, passed
			     review, and drew nothing at all.

			     Everything that vanished with it is restored here: the title on
			     the line, moving that title along the line (by pointer AND by
			     arrow key, WCAG 2.1 AA 2.1.1), the context menu, and the payload
			     control that is the entire point of a replay.

			     Routing, arrowheads and label geometry are no longer ours to
			     write. `edge.data.edge` is the stored connection; `edge.id` is
			     the LINE's id, because a split draws several lines from one
			     connection. -->
			<template #edge-label="{ edge }">
				<!-- The line's own title, and nothing else. Under the old
				     reading the step rode here and this chip named it; the step
				     now lives on the node, so a chip here would either be blank
				     on every edge of a migrated flow (which is exactly the "No
				     step type" that appeared 16 times) or duplicate the card.

				     INERT ON PURPOSE. The canvas wraps this in the focusable
				     control and owns the arrow keys that slide it along its
				     line, so an author gets that alternative to dragging
				     whether or not this view remembers to offer it. An edge
				     with no title renders nothing here, and the canvas then
				     draws no chip at all rather than an empty one. -->
				<template v-if="edgeLabel(edge.data.edge)">
					<span class="flow-builder__step-text">{{
						edgeLabel(edge.data.edge)
					}}</span>
					<span
						v-if="resultFor(edge.data.edge)"
						class="flow-builder__step-result"
						:class="`flow-builder__step-result--${resultFor(edge.data.edge).status}`" />
				</template>
			</template>

			<!--
				The payload control: the JSON that passed along this line — the
				output of the node it leaves, which is the input of the node it
				reaches. Only on a line the replayed run actually followed,
				because on any other line there is nothing to show.

				This is the point of the replay. A status says a flow "ran
				fine"; when it ran fine and produced the wrong answer, what an
				operator needs is what actually moved.

				BESIDE the label rather than inside it: the label's wrapper is a
				button, and this has to be activatable in its own right.
			-->
			<template #edge-adornment="{ edge }">
				<NcButton
					v-if="wasFollowed(edge.data.edge)"
					variant="tertiary"
					class="flow-builder__payload"
					:aria-label="
						t('hermiq', 'Show what passed along this connection')
					"
					@click.stop="editor.payloadEdgeId = edge.data.edge.id"
					@mouseenter="onPayloadHover(edge.data.edge, $event)"
					@mouseleave="hoverPayload = null"
					@focus="onPayloadHover(edge.data.edge, $event)"
					@blur="hoverPayload = null">
					{}
				</NcButton>
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
					An annotation: a note pinned to the canvas, belonging to no
					node and no edge. Drawn through this slot because the canvas
					is what positions things, never because it is a node — it is
					stored in `annotations[]` and the engine never sees it.

					Edited in place. A note is one field, and sending an author
					to a modal to type one line is more chrome than the content.
				-->
				<!-- `node` here is VUE FLOW's node: `id` stays at the top level,
				     but everything the document owns lives in `data` — see
				     nodesWithPorts(). -->
				<div v-if="node.data.isAnnotation" class="flow-builder__annotation">
					<textarea
						class="flow-builder__annotation-text"
						:value="node.data.text"
						:aria-label="t('hermiq', 'Note')"
						:placeholder="t('hermiq', 'Write a note…')"
						@mousedown.stop
						@input="
							editor.setAnnotationText(
								node.id.slice(annotationPrefix.length),
								$event.target.value,
							)
						" />
					<NcButton
						variant="tertiary"
						class="flow-builder__annotation-remove"
						:aria-label="t('hermiq', 'Remove note')"
						@mousedown.stop
						@click.stop="
							editor.removeAnnotation(
								node.id.slice(annotationPrefix.length),
							)
						">
						<template #icon>
							<Close :size="16" />
						</template>
					</NcButton>
				</div>

				<!--
					Double-click opens the node's editor. It is a shortcut, NOT
					the only way in: a pointer gesture cannot be performed from
					the keyboard (WCAG 2.1 AA 2.1.1), so the Nodes tab carries an
					"Edit node" button for the selected node and that is the
					accessible path.

					How a node is DRAWN comes from its type, never from where it
					sits: a trigger wired into the middle of a flow is still a
					trigger, and an unconnected step is not one.
				-->
				<!--
					`v-else`, and load-bearing: without it an annotation drew
					BOTH — its sticky note and, underneath, an ordinary node card
					reading "No step type" over the annotation's own id. That
					second card is the container-in-a-container on the board. A
					note is not a node, so the node body must not render for one.
				-->
				<div
					v-else
					class="flow-builder__node"
					:class="{
						// The node's TYPE decides how it is drawn, never its
						// place in the drawing. This keyed off `roleOf(node.id)`
						// — nothing points at it, so paint it as a start —
						// which colours an unconnected step as an entry point
						// and makes a flow that can never fire look finished.
						[`flow-builder__node--${editor.roleOfNodeType(node.data.type)}`]: true,
						'flow-builder__node--untyped': !node.data.type,
						'flow-builder__node--replayed':
							editor.replayedNodeIds.includes(node.id),
					}"
					@dblclick.stop="onNodeEdit(node.data)"
					@contextmenu.prevent.stop="onNodeContext(node.data, $event)">
					<span class="flow-builder__node-step">{{
						nodeStepLabel(node.data)
					}}</span>
					<span class="flow-builder__node-label">{{
						/**
						 * @spec openspec/specs/flow-canvas/spec.md
						 */
						nodeLabel(node.data)
					}}</span>
					<span
						v-if="nodeConfigSummary(node.data)"
						class="flow-builder__node-config">
						{{ nodeConfigSummary(node.data) }}
					</span>
					<span
						v-if="editor.markingByNode[node.id]"
						class="flow-builder__node-badge">
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
					<path
						d="M 0 0 L 10 5 L 0 10 z"
						class="flow-builder__arrowhead" />
				</marker>
				<!-- The other end symbol an author can choose. `none` needs no
				     marker at all — it is the absence of one. -->
				<marker
					id="flow-builder-dot"
					viewBox="0 0 10 10"
					refX="5"
					refY="5"
					markerWidth="4"
					markerHeight="4">
					<circle cx="5" cy="5" r="4" class="flow-builder__arrowhead" />
				</marker>
			</defs>
		</svg>

		<!-- Canvas controls. Zoom first: a flow big enough to need a canvas is a
		     flow you cannot see all of, and the wheel — the canvas's own zoom
		     gesture — is mouse-only, so buttons are what make it reachable from
		     the keyboard (WCAG 2.1 AA 2.1.1). The reset is not a nicety either:
		     zoom has no floor at 1, so without it a canvas zoomed out is
		     laborious to bring back. -->
		<!--
			The hover preview for a connection's `{}`. In VIEWPORT coordinates,
			like the context menu and for the same reason: it is anchored to
			where the pointer is, and anchoring it to the canvas would move it
			the moment the canvas is panned or zoomed.

			`aria-hidden`: the same payload is reachable by activating the `{}`,
			which is the accessible path — announcing a hover card would read the
			JSON twice to a screen reader.
		-->
		<div
			v-if="hoverPayload"
			class="flow-builder__payload-preview"
			aria-hidden="true"
			:style="{ left: `${hoverPayload.x}px`, top: `${hoverPayload.y}px` }">
			<span class="flow-builder__payload-preview-head">
				{{
					t('hermiq', 'What {node} received', { node: hoverPayload.node })
				}}
			</span>
			<pre class="flow-builder__payload-preview-json">{{
				hoverPayload.json
			}}</pre>
		</div>

		<div class="flow-builder__controls">
			<!--
				The flow's VERBS, on the canvas rather than three tabs deep in the
				sidebar. Save, Run and Check are what an author does to the thing in
				front of them, and they were reachable only from the Flow tab — so
				the two most-used actions on the page were invisible from the page.
				Beside the zoom cluster: both are canvas controls, and the top-right
				is the corner auto-sort never lays a node into.
			-->
			<div
				class="flow-builder__verbs"
				role="group"
				:aria-label="t('hermiq', 'Flow actions')">
				<NcButton
					variant="primary"
					:disabled="editor.saving || !editor.flow.name"
					@click="onSave">
					<template #icon>
						<NcLoadingIcon v-if="editor.saving" :size="20" />
						<ContentSave v-else :size="20" />
					</template>
					{{ t('hermiq', 'Save') }}
				</NcButton>
				<NcButton
					variant="secondary"
					:disabled="!editor.flow.id"
					@click="editor.showRun = true">
					<template #icon>
						<Play :size="20" />
					</template>
					{{ t('hermiq', 'Run…') }}
				</NcButton>
				<NcButton variant="secondary" @click="editor.validate()">
					<template #icon>
						<CheckDecagram :size="20" />
					</template>
					{{ t('hermiq', 'Check') }}
				</NcButton>
				<NcButton
					variant="secondary"
					:disabled="editor.nodes.length === 0"
					:aria-label="t('hermiq', 'Auto sort')"
					@click="editor.autoSort()">
					<template #icon>
						<SortVariant :size="20" />
					</template>
				</NcButton>
			</div>

			<div
				class="flow-builder__zoom"
				role="group"
				:aria-label="t('hermiq', 'Zoom')">
				<NcButton
					variant="secondary"
					:disabled="zoom <= minZoom"
					:aria-label="t('hermiq', 'Zoom out')"
					@click="zoomBy(-zoomStep)">
					<template #icon>
						<Minus :size="20" />
					</template>
				</NcButton>
				<NcButton
					variant="secondary"
					:aria-label="t('hermiq', 'Reset zoom to 100%')"
					@click="zoom = 1">
					{{ zoomPercent }}
				</NcButton>
				<NcButton
					variant="secondary"
					:disabled="zoom >= maxZoom"
					:aria-label="t('hermiq', 'Zoom in')"
					@click="zoomBy(zoomStep)">
					<template #icon>
						<Plus :size="20" />
					</template>
				</NcButton>
			</div>

			<!-- Pinning a note moved to the canvas's own context menu: a note's
			     POSITION is the point of it, and a toolbar button has no point
			     to give it — every note arrived at the same spot and had to be
			     dragged. Right-clicking where the note belongs carries that
			     point with it. -->

			<!-- Re-open control for the sidebar. It lives on the CANVAS because
			     once the sidebar is closed it has no chrome of its own left to
			     render a button in — a close with no way back is a one-way
			     door. -->
			<NcButton
				v-if="!editor.sidebarOpen"
				class="flow-builder__sidebar-toggle"
				variant="secondary"
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
			:description="
				t('hermiq', 'Fetching the nodes and steps for this flow.')
			">
			<template #icon>
				<NcLoadingIcon :size="20" />
			</template>
		</NcEmptyContent>

		<NcEmptyContent
			v-else-if="editor.nodes.length === 0"
			class="flow-builder__empty"
			:name="t('hermiq', 'No nodes yet')"
			:description="
				t(
					'hermiq',
					'Add a node from the sidebar, then drag between two nodes to create a step.',
				)
			">
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

		<!--
			The context menu. A pointer gesture opens it, so every entry it
			offers is ALSO reachable from the Nodes tab (WCAG 2.1 AA 2.1.1) —
			this is a shortcut, never the only route.

			Positioned in viewport coordinates because that is where the
			right-click happened; anchoring it to the canvas would put it
			somewhere else the moment the canvas is zoomed or panned.
		-->
		<div
			v-if="editor.contextMenu"
			class="flow-builder__context"
			:style="{
				left: `${editor.contextMenu.x}px`,
				top: `${editor.contextMenu.y}px`,
			}"
			role="menu">
			<!--
				The EMPTY canvas offers what applies to a place rather than to a
				thing: put the copied node here, or pin a note here. Both need a
				point, which is what right-clicking the background provides —
				that is why they live here and not in a toolbar.
			-->
			<template v-if="editor.contextMenu.kind === 'canvas'">
				<button
					role="menuitem"
					:disabled="editor.clipboardNode === null"
					@click="onContextPaste">
					{{ t('hermiq', 'Paste') }}
				</button>
				<button role="menuitem" @click="onContextAddNote">
					{{ t('hermiq', 'Add note') }}
				</button>
			</template>

			<template v-else>
				<button role="menuitem" @click="onContextEdit">
					{{ t('hermiq', 'Edit') }}
				</button>
				<!--
					What this node actually received and returned, in the run
					being replayed. The recording is per NODE — a run records
					transitions — so the node is where an operator looks for it,
					and until now the only way in was the `{}` on a line, which
					answers for the node the line LEAVES rather than the one you
					clicked.
				-->
				<button
					v-if="editor.contextMenu.kind === 'node'"
					role="menuitem"
					@click="onContextData">
					{{ t('hermiq', 'View data') }}
				</button>
				<button
					v-if="editor.contextMenu.kind === 'node'"
					role="menuitem"
					@click="onContextCopy">
					{{ t('hermiq', 'Copy') }}
				</button>
				<button
					role="menuitem"
					class="flow-builder__context-destructive"
					@click="onContextDelete">
					{{ t('hermiq', 'Delete') }}
				</button>
			</template>
		</div>

		<NodeEditModal
			:show="editor.nodeEditOpen"
			@close="editor.nodeEditOpen = false" />

		<ConnectionEditModal
			:show="editor.edgeEditOpen"
			@close="editor.edgeEditOpen = false" />

		<PayloadModal
			:show="editor.payloadEdgeId !== null || editor.payloadNodeId !== null"
			@close="onPayloadModalClose" />

		<RunLogModal
			:show="editor.logModalRunId !== null"
			@close="editor.logModalRunId = null" />

		<DeadEndWarningDialog
			v-if="editor.deadEnds.length > 0"
			:nodeIds="editor.deadEnds"
			@close="editor.deadEnds = []" />
	</div>
</template>

<script>
import { CnGraphCanvas } from '@conduction/nextcloud-vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { NcButton, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import CheckDecagram from 'vue-material-design-icons/CheckDecagram.vue'
import Close from 'vue-material-design-icons/Close.vue'
import ContentSave from 'vue-material-design-icons/ContentSave.vue'
import DockRight from 'vue-material-design-icons/DockRight.vue'
import Minus from 'vue-material-design-icons/Minus.vue'
import Play from 'vue-material-design-icons/Play.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Sitemap from 'vue-material-design-icons/Sitemap.vue'
import SortVariant from 'vue-material-design-icons/SortVariant.vue'
import DeadEndWarningDialog from '../dialogs/DeadEndWarningDialog.vue'
import RunFlowDialog from '../dialogs/RunFlowDialog.vue'
import StepResultDialog from '../dialogs/StepResultDialog.vue'
import ConnectionEditModal from '../modals/Flow/ConnectionEditModal.vue'
import NodeEditModal from '../modals/Flow/NodeEditModal.vue'
import PayloadModal from '../modals/Flow/PayloadModal.vue'
import RunLogModal from '../modals/Flow/RunLogModal.vue'
import { ANNOTATION_ID_PREFIX, useFlowEditorStore } from '../store/flowEditor.js'

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
 *
 * ⚠️ BOTH SPELLINGS, and `openregister.end` is the canonical one.
 * `EndNode::getId()` returns `openregister.end`; `openregister.stop` survives only
 * as an alias in `FlowNodeRegistry` and is on its way out (hydra#533 moved all
 * eleven of its flows off it "before the alias expires"). The alias is kept here
 * because STORED documents still carry it — a flow written before the rename is
 * still drawn by this canvas.
 */
const TERMINAL_STEP_TYPES = ['openregister.end', 'openregister.stop']

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
		Close,
		CnGraphCanvas,
		DockRight,
		Minus,
		NcButton,
		NcEmptyContent,
		CheckDecagram,
		ContentSave,
		NcLoadingIcon,
		Play,
		SortVariant,
		Plus,
		DeadEndWarningDialog,
		ConnectionEditModal,
		NodeEditModal,
		PayloadModal,
		RunLogModal,
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

	/**
	 * @spec openspec/specs/flow-canvas/spec.md
	 */
	setup() {
		return { editor: useFlowEditorStore() }
	},

	data() {
		return {
			arrowId: 'flow-builder-arrow',
			resultDialog: null,
			// `nodeWidth`/`nodeHeight` were here so that hand-drawn edges could
			// work out where a card ended and stop the arrowhead short of it.
			// Vue Flow MEASURES the rendered node, so the guess — and the whole
			// class of bug where the guess disagreed with what the slot drew —
			// went with the geometry.
			// Zoom is OWNED here. CnGraphCanvas takes it as a prop and reports
			// changes through `update:zoom` — it never mutates it — so a canvas
			// whose consumer does not bind it is pinned at 1 forever and the
			// wheel gesture does nothing at all, which is what this page did.
			// The connection whose payload is previewed on hover, with the
			// viewport point to anchor the card to: `{id, x, y, node, json}`.
			hoverPayload: null,
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
		/**
		 * The annotation id prefix, for the template.
		 *
		 * @return {string} The prefix.
		 * @spec openspec/specs/flow-canvas/spec.md
		 */
		annotationPrefix() {
			return ANNOTATION_ID_PREFIX
		},

		/** @return {string} The current zoom, for the reset button's label. */
		/**
		 * @spec openspec/specs/flow-canvas/spec.md
		 */
		zoomPercent() {
			return `${Math.round(this.zoom * 100)}%`
		},

		/**
		 * The lines, each carrying how it should be DRAWN.
		 *
		 * Same division as `nodesWithPorts` below: the store owns the document,
		 * the view owns the drawing. Markers name SVG elements this template
		 * defines, so the mapping belongs on this side of the line.
		 *
		 * Selection, the unassigned warning and the replay marking are `class`
		 * on the edge rather than classes on a wrapper we draw ourselves —
		 * there is no wrapper any more. Vue Flow puts the class on the element
		 * it renders for the line.
		 *
		 * @return {Array<object>} The lines, ready for the canvas.
		 *
		 * @spec openspec/specs/flow-canvas/spec.md
		 */
		canvasEdges() {
			return this.editor.canvasEdges.map((line) => {
				const edge = line.data.edge

				return {
					...line,
					// `arrow` is the fallback rather than a hard default, so
					// that `none` stays a real choice: an author who removed an
					// arrowhead did not ask for it back.
					//
					// ⚠️ ABSENT IS `undefined`, NEVER `null`. Vue Flow's
					// getMarkerId() guards `typeof marker === 'undefined'` and
					// `'string'`, then falls through to `Object.keys(marker)` —
					// and `typeof null === 'object'`, so a null marker sails
					// past both guards and throws. That throw happens while the
					// edge renders, so it takes the LINE down with it: 93 edges
					// in the store, 187 TypeErrors, and not one line drawn.
					// `markerRef` returns null because null is what removes an
					// SVG attribute; this is the boundary where that spelling
					// stops being right.
					markerEnd:
						this.markerRef(edge.endMarker, this.arrowId) ?? undefined,
					markerStart: this.markerRef(edge.startMarker) ?? undefined,
					style: this.edgeStyle(edge),
					class: {
						'flow-builder__step--selected':
							edge.id === this.editor.selectedEdgeId,
						'flow-builder__step--unassigned': this.isUnassigned(edge),
						'flow-builder__step--replayed': this.wasFollowed(edge),
					},
					data: {
						...line.data,
						labelAriaLabel: this.stepAriaLabel(edge),
					},
				}
			})
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
			// VUE FLOW'S `type` SELECTS A COMPONENT, NOT A DOMAIN KIND.
			//
			// This used to spread the document node straight onto the canvas,
			// which was right for the old bespoke canvas but is not for Vue
			// Flow: it read `type: 'openregister.set-fields'` as the name of a
			// node COMPONENT, found none registered, and fell back to its own
			// built-in node. The result was a canvas of empty boxes — the
			// wrappers were in the DOM at the correct coordinates, with nothing
			// drawn inside them, which reads as "the flow has no nodes".
			//
			// `type: 'default'` routes every step through CnFlowNode, which is
			// what renders the `#node` slot below. The domain node travels in
			// `data`, so a new step type still draws instead of vanishing.
			//
			// `position` accepts both spellings: the server stores
			// `position: {x, y}` while the editor writes flat `x`/`y` in memory.
			const toCanvasNode = (node) => ({
				id: node.id,
				type: 'default',
				position: {
					x: Number(node.x ?? node.position?.x) || 0,
					y: Number(node.y ?? node.position?.y) || 0,
				},

				data: node,
			})

			const nodes = (this.editor.nodes || []).map((node) =>
				toCanvasNode({
					...node,
					ports: this.portsForNode(node),
				}),
			)

			// Annotations ride the same render list, because the canvas is what
			// positions things in canvas space — but they are NOT nodes in the
			// document and never enter `flow.nodes`. An annotation lowered as a
			// node would become a transition the run waits on: a comment able
			// to deadlock a flow.
			//
			// No ports: nothing connects to a note.
			const notes = (this.editor.flow.annotations || []).map((note) =>
				toCanvasNode({
					...note,
					id: `${ANNOTATION_ID_PREFIX}${note.id}`,
					ports: [],
					isAnnotation: true,
				}),
			)

			return [...nodes, ...notes]
		},
	},

	watch: {
		/**
		 * @spec openspec/specs/flow-canvas/spec.md
		 */
		id() {
			this.editor.open(this.id)
		},
	},

	created() {
		this.editor.load(this.id)
	},

	/**
	 * @spec openspec/specs/flow-canvas/spec.md
	 */
	mounted() {
		// The label drag used to be tracked here, on the WINDOW, because a drag
		// that leaves the chip has to keep tracking and has to end even if the
		// pointer is released outside the canvas. That is still true — it is
		// just no longer this view's problem: the canvas owns the label and
		// reports where it was put.
		window.addEventListener('keydown', this.onKeydown)
	},

	/**
	 * @spec openspec/specs/flow-canvas/spec.md
	 */
	beforeUnmount() {
		window.removeEventListener('keydown', this.onKeydown)
	},

	methods: {
		/**
		 * Keyboard actions on the current selection.
		 *
		 * This is the accessible route to the context menu's actions: a
		 * right-click is a pointer gesture and cannot be the only way to reach
		 * an action (WCAG 2.1 AA 2.1.1). Delete removes the selection, Enter
		 * opens its editor.
		 *
		 * IGNORED WHILE TYPING. A note's textarea, a search field and every
		 * modal input live inside this view, and a Backspace that deleted the
		 * selected node while the author was correcting a typo would be the
		 * worst possible reading of the key. The check is on the event target,
		 * so it holds for inputs this component does not know about.
		 *
		 * @param {KeyboardEvent} event The key event.
		 * @return {void}
		 *
		 * @spec openspec/specs/flow-canvas/spec.md#requirement-the-canvas-offers-per-element-actions-reachable-two-ways
		 */
		onKeydown(event) {
			if (this.isTypingTarget(event.target)) {
				return
			}

			// A modal owns the keyboard while it is open — Delete there belongs
			// to whatever the author is editing, not to the canvas behind it.
			if (this.editor.nodeEditOpen || this.editor.edgeEditOpen) {
				return
			}

			if (event.key === 'Escape') {
				this.editor.contextMenu = null
				return
			}

			if (event.key === 'Delete' || event.key === 'Backspace') {
				// Only swallow the key when something was actually deleted, so
				// Backspace keeps its ordinary meaning the rest of the time.
				if (this.editor.deleteSelection()) {
					event.preventDefault()
					this.editor.contextMenu = null
				}

				return
			}

			if (event.key === 'Enter') {
				if (this.editor.selectedNodeId !== null) {
					this.editor.nodeEditOpen = true
					event.preventDefault()
					return
				}

				if (this.editor.selectedEdgeId !== null) {
					this.editor.edgeEditOpen = true
					event.preventDefault()
				}
			}
		},

		/**
		 * Whether a key event came from somewhere the author is typing.
		 *
		 * @param {EventTarget|null} target The event target.
		 * @return {boolean} Whether to leave the key alone.
		 * @spec openspec/specs/flow-canvas/spec.md
		 */
		isTypingTarget(target) {
			if (
				target === null
				|| target === undefined
				|| target.tagName === undefined
			) {
				return false
			}

			if (target.isContentEditable === true) {
				return true
			}

			return ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName)
		},

		/**
		 * Route a canvas selection to the node or the annotation it names.
		 *
		 * The canvas fires `node-select` for everything it draws, annotations
		 * included, so without this an annotation id would be handed to
		 * `selectNode()` and select nothing — leaving the sidebar showing the
		 * previously selected node while a note appeared highlighted.
		 *
		 * @param {string} id The canvas element's id.
		 * @return {void}
		 *
		 * @spec openspec/specs/flow-canvas/spec.md
		 */
		onCanvasSelect(id) {
			if (String(id).startsWith(ANNOTATION_ID_PREFIX)) {
				this.editor.clearSelection()
				return
			}

			this.editor.selectNode(id)
		},

		/**
		 * Dismiss the context menu and drop the current selection.
		 *
		 * Extracted from an inline
		 * `@canvas-click="editor.contextMenu = null; editor.clearSelection()"`.
		 * Vue's template compiler only treats a handler as raw STATEMENTS when the
		 * expression contains a `;`, and prettier's `semi: false` strips it —
		 * leaving two newline-separated statements the compiler then tries to parse
		 * as one expression and rejects. No behaviour change.
		 *
		 * @return {void}
		 *
		 * @spec exclude formatting-only extraction of an existing inline handler — no behaviour change
		 */
		onCanvasClick() {
			this.editor.contextMenu = null
			this.editor.clearSelection()
		},

		/**
		 * Close the payload modal, clearing both of the ids that open it.
		 * Extracted from an inline multi-statement handler, same reason as
		 * `onCanvasClick()`.
		 *
		 * @return {void}
		 *
		 * @spec exclude formatting-only extraction of an existing inline handler — no behaviour change
		 */
		onPayloadModalClose() {
			this.editor.payloadEdgeId = null
			this.editor.payloadNodeId = null
		},

		/**
		 * Route a canvas move to the node or the annotation it names.
		 *
		 * @param {object} payload `{id, x, y}` from the canvas.
		 * @return {void}
		 *
		 * @spec openspec/specs/flow-canvas/spec.md
		 */
		/**
		 * Vue Flow reports node changes as a STREAM; this picks the settled
		 * moves out of it.
		 *
		 * The canvas emits `nodes-change` for selection, dimension measurement,
		 * removal and dragging alike, and a drag reports continuously with
		 * `dragging: true` until the pointer is released. Persisting every one
		 * of those would write a row per animation frame.
		 *
		 * This replaces the old `@nodeMove`, which the canvas stopped emitting
		 * when it moved to Vue Flow — so until now a dragged node moved on
		 * screen and the document never heard about it.
		 *
		 * @param {Array<object>} changes Vue Flow's change stream.
		 * @return {void}
		 *
		 * @spec openspec/specs/flow-canvas/spec.md#requirement-a-node-dragged-on-the-canvas-keeps-where-it-was-put
		 */
		onCanvasNodesChange(changes) {
			for (const change of changes || []) {
				if (change.type !== 'position' || change.dragging === true) {
					continue
				}

				if (change.position === undefined || change.position === null) {
					continue
				}

				this.onCanvasMove({
					id: change.id,
					x: change.position.x,
					y: change.position.y,
				})
			}
		},

		/**
		 * Route a canvas move to the node or the annotation it names.
		 *
		 * The canvas moves anything it draws, and nodes and annotations share
		 * one id space on it — but the editor keeps them apart. An annotation
		 * id handed to `moveNode()` would move nothing while the note appeared
		 * to travel under the pointer, so the prefix decides which store the
		 * new position belongs to, and is stripped before the id crosses over.
		 *
		 * @param {object} payload `{id, x, y}` from the canvas.
		 * @return {void}
		 *
		 * @spec openspec/specs/flow-canvas/spec.md
		 */
		onCanvasMove(payload) {
			const id = String(payload?.id || '')
			if (id.startsWith(ANNOTATION_ID_PREFIX)) {
				this.editor.moveAnnotation({
					...payload,
					id: id.slice(ANNOTATION_ID_PREFIX.length),
				})
				return
			}

			this.editor.moveNode(payload)
		},

		/**
		 * A connection's own drawing style, when it declares one.
		 *
		 * DRAWING ONLY. `colour`, `lineStyle` and `width` are read here and
		 * nowhere else — the engine takes `from`/`to` from an edge and nothing
		 * more. An author styling a line cannot change what the flow does.
		 *
		 * Returned as inline style rather than classes because colour and
		 * thickness are continuous: a class per value would be a palette this
		 * file had to keep in step with the editor's.
		 *
		 * @param {object} edge The connection.
		 * @return {object} Style bindings; empty for an unstyled edge.
		 * @spec openspec/specs/flow-canvas/spec.md
		 */
		edgeStyle(edge) {
			const style = {}

			if (edge?.colour) {
				style.stroke = edge.colour
			}

			if (Number(edge?.width) > 0) {
				style.strokeWidth = Number(edge.width)
			}

			const dashes = { dashed: '8 6', dotted: '2 5' }
			if (dashes[edge?.lineStyle]) {
				style.strokeDasharray = dashes[edge.lineStyle]
			}

			return style
		},

		/**
		 * The marker url for an end of a line.
		 *
		 * @param {string}      name     The marker name, or empty.
		 * @param {string|null} fallback A marker to use when none is named.
		 * @return {string|null} The `url(#…)` reference, or null for a bare end.
		 * @spec openspec/specs/flow-canvas/spec.md
		 */
		markerRef(name, fallback = null) {
			const markers = {
				arrow: this.arrowId,
				dot: 'flow-builder-dot',
				none: null,
			}

			// `none` is a real choice and must beat the fallback: an author who
			// removed the arrowhead did not ask for the default back.
			if (name && Object.hasOwn(markers, name)) {
				return markers[name] === null ? null : `url(#${markers[name]})`
			}

			return fallback === null ? null : `url(#${fallback})`
		},

		/**
		 * Where a connection's label sits, as a point on its line.
		 *
		 * `labelT` is a FRACTION (0 = source, 1 = target), defaulting to the
		 * midpoint. Storing a fraction rather than a pixel offset is what makes
		 * the position survive a pan, a zoom and an auto-sort: the label keeps
		 * its place ON THE LINE rather than its place on the screen.
		 *
		 * @param {object} edge The connection.
		 * @param {{x: number, y: number}} from Source centre.
		 * @param {{x: number, y: number}} to   Target centre.
		 * @return {{x: number, y: number}} The label's canvas point.
		 *
		 * @spec openspec/specs/flow-canvas/spec.md
		 */
		/**
		 * Store a label's new place on its line.
		 *
		 * The label's geometry, the pointer drag and the arrow keys are all the
		 * canvas's now; what arrives here is the finished answer. `labelT` is a
		 * FRACTION (0 = source, 1 = target) and is stored as one, which is what
		 * makes the position survive a pan, a zoom and an auto-sort: the label
		 * keeps its place ON THE LINE rather than its place on the screen.
		 *
		 * @param {{id: string, labelT: number}} payload The line and fraction.
		 *   `id` is the LINE's id, so the connection is recovered from it.
		 * @return {void}
		 *
		 * @spec openspec/specs/flow-canvas/spec.md
		 */
		onEdgeLabelMove({ id, labelT }) {
			const edge = this.edgeOfLine(id)
			if (edge === null) {
				return
			}

			this.editor.setEdgeFieldById(edge.id, 'labelT', labelT)
		},

		/**
		 * Open what a connection's label points at.
		 *
		 * @param {string} id The LINE's id.
		 * @return {void}
		 * @spec openspec/specs/flow-canvas/spec.md
		 */
		onEdgeLabelClick(id) {
			const edge = this.edgeOfLine(id)
			if (edge !== null) {
				this.onStepClick(edge)
			}
		},

		/**
		 * Right-clicking a label opens the same menu as right-clicking its line.
		 *
		 * @param {{id: string, event: MouseEvent}} payload The line and event.
		 * @return {void}
		 * @spec openspec/specs/flow-canvas/spec.md
		 */
		onEdgeLabelContext({ id, event }) {
			const edge = this.edgeOfLine(id)
			if (edge !== null) {
				this.onEdgeContext(edge, event)
			}
		},

		/**
		 * Selecting a line selects the connection it was drawn from.
		 *
		 * @param {object} payload Vue Flow's `{ edge }`.
		 * @return {void}
		 * @spec openspec/specs/flow-canvas/spec.md
		 */
		onCanvasEdgeSelect(payload) {
			const edge = this.edgeOfLine(payload?.edge?.id)
			if (edge !== null) {
				this.editor.selectEdge(edge.id)
			}
		},

		/**
		 * The connection a drawn line came from.
		 *
		 * A split renders one connection as several lines, each with its own id,
		 * so selection and editing always have to travel back to the connection
		 * — acting on the line would act on half a step.
		 *
		 * @param {string} id The line's id.
		 * @return {object|null} The connection, or null if it has gone.
		 * @spec openspec/specs/flow-canvas/spec.md
		 */
		edgeOfLine(id) {
			const line = this.editor.canvasEdges.find(
				(candidate) => candidate.id === id,
			)

			return line ? line.data.edge : null
		},

		/**
		 * Whether the replayed run followed this connection.
		 *
		 * A connection was followed when the run fired the node it LEAVES —
		 * the transitions are what a run records, and an edge has no record of
		 * its own. That is why a run with no replay selected marks nothing:
		 * `replayedNodeIds` is empty, so this is false everywhere and the
		 * canvas looks exactly as it does when no run is chosen.
		 *
		 * @param {object} edge The connection.
		 * @return {boolean} Whether it was followed.
		 *
		 * @spec openspec/specs/flow-canvas/spec.md#requirement-selecting-a-run-replays-its-path-on-the-canvas
		 */
		wasFollowed(edge) {
			if (this.editor.replayRunId === null) {
				return false
			}

			const fired = this.editor.replayedNodeIds

			return edge.from.some((id) => fired.includes(id))
		},

		/**
		 * Open the node context menu, selecting the node first.
		 *
		 * Selects before opening: every entry acts on the SELECTION, so a menu
		 * raised without selecting would edit, copy or delete whichever node
		 * happened to be selected before.
		 *
		 * @param {object} node  The node right-clicked.
		 * @param {MouseEvent} event The event, for the position.
		 * @return {void}
		 *
		 * @spec openspec/specs/flow-canvas/spec.md#requirement-the-canvas-offers-per-element-actions-reachable-two-ways
		 */
		onNodeContext(node, event) {
			this.editor.selectNode(node.id)
			this.editor.contextMenu = {
				kind: 'node',
				id: node.id,
				x: event.clientX,
				y: event.clientY,
			}
		},

		/**
		 * Open the connection context menu, selecting the connection first.
		 *
		 * @param {object} edge  The connection right-clicked.
		 * @param {MouseEvent} event The event, for the position.
		 * @return {void}
		 *
		 * @spec openspec/specs/flow-canvas/spec.md#requirement-the-canvas-offers-per-element-actions-reachable-two-ways
		 */
		onEdgeContext(edge, event) {
			this.editor.selectEdge(edge.id)
			this.editor.contextMenu = {
				kind: 'edge',
				id: edge.id,
				x: event.clientX,
				y: event.clientY,
			}
		},

		/**
		 * Open the canvas's own context menu on empty space.
		 *
		 * Node and connection menus stop propagation, so reaching here means
		 * the click landed on the BACKGROUND — the one place where "paste" and
		 * "pin a note" mean something, because both need a point.
		 *
		 * @param {MouseEvent} event The event, for the position.
		 * @return {void}
		 *
		 * @spec openspec/specs/flow-canvas/spec.md#requirement-the-canvas-offers-per-element-actions-reachable-two-ways
		 */
		onCanvasContext(event) {
			this.editor.contextMenu = {
				kind: 'canvas',
				id: null,
				// Where the MENU is drawn: viewport coordinates, because that
				// is where the pointer is.
				x: event.clientX,
				y: event.clientY,
				// Where the RESULT lands: canvas coordinates, so a paste or a
				// note appears under the pointer whatever the pan and zoom.
				point: this.canvasPointOf(event),
			}
		},

		/**
		 * The canvas-space point a pointer event happened at.
		 *
		 * Only the canvas knows its own pan and zoom, so only it can undo them
		 * — the same reason it hands `canvas-drop` a converted point rather
		 * than raw client coordinates. Asked through the ref, and guarded: if
		 * that method ever stops being there, a note still gets pinned at the
		 * default spot rather than the feature disappearing.
		 *
		 * @param {MouseEvent} event The event.
		 * @return {{x: number, y: number}|null} The canvas point, or null.
		 * @spec openspec/specs/flow-canvas/spec.md
		 */
		canvasPointOf(event) {
			const canvas = this.$refs.graph

			if (canvas === undefined || typeof canvas.toCanvasPoint !== 'function') {
				return null
			}

			try {
				return canvas.toCanvasPoint(event)
			} catch (e) {
				return null
			}
		},

		/**
		 * Place the copied node where the menu was raised.
		 *
		 * @return {void}
		 *
		 * @spec openspec/specs/flow-canvas/spec.md#requirement-the-canvas-offers-per-element-actions-reachable-two-ways
		 */
		onContextPaste() {
			const point = this.editor.contextMenu?.point
			this.editor.contextMenu = null

			if (point === null || point === undefined) {
				this.editor.pasteNode()
				return
			}

			this.editor.pasteNode(point.x, point.y)
		},

		/**
		 * Pin a note where the menu was raised.
		 *
		 * @return {void}
		 *
		 * @spec openspec/specs/flow-canvas/spec.md
		 */
		onContextAddNote() {
			const point = this.editor.contextMenu?.point
			this.editor.contextMenu = null

			if (point === null || point === undefined) {
				this.editor.addAnnotation()
				return
			}

			this.editor.addAnnotation(point.x, point.y)
		},

		/**
		 * Edit whatever the menu was raised on.
		 *
		 * @return {void}
		 * @spec openspec/specs/flow-canvas/spec.md
		 */
		onContextEdit() {
			const kind = this.editor.contextMenu?.kind
			this.editor.contextMenu = null
			if (kind === 'node') {
				this.editor.nodeEditOpen = true
				return
			}

			this.editor.edgeEditOpen = true
		},

		/**
		 * Copy the node the menu was raised on.
		 *
		 * @return {void}
		 * @spec openspec/specs/flow-canvas/spec.md
		 */
		onContextCopy() {
			const id = this.editor.contextMenu?.id
			this.editor.contextMenu = null
			if (id) {
				this.editor.copyNode(id)
			}
		},

		/**
		 * Open what this node received and returned in the replayed run.
		 *
		 * @return {void}
		 */
		/**
		 * Preview what the node at the END of this connection RECEIVED.
		 *
		 * The complement of clicking, which opens what the node at the START
		 * returned. Both are true of the same line — one node's output is the
		 * next one's input — but they are different questions, and "what did
		 * fetch1 get" was answerable only by opening the modal on the line
		 * BEFORE it and reading the output half.
		 *
		 * A hover, so it costs nothing to ask. Keyboard reaches it through
		 * focus, since a hover alone would make this mouse-only (WCAG 2.1 AA
		 * 2.1.1).
		 *
		 * @param {object} edge  The connection.
		 * @param {Event}  event The pointer or focus event, for the anchor.
		 *
		 * @return {void}
		 * @spec openspec/specs/flow-canvas/spec.md
		 */
		onPayloadHover(edge, event) {
			const detail =
				this.editor.replayRunId === null
					? null
					: this.editor.runDetail[this.editor.replayRunId]

			if (!detail || !edge?.to) {
				return
			}

			const entry = (detail.log || []).find((line) => {
				const id = String(edge.to)

				return (
					String(line.transition || '') === id
					|| String(line.node || '') === id
					|| String(line.step || '') === id
				)
			})

			if (!entry) {
				return
			}

			const box = event.target?.getBoundingClientRect?.()
			const envelope = entry.input
			const items = envelope?.items ?? envelope

			this.hoverPayload = {
				id: edge.id,
				node: String(edge.to),
				x: box ? box.left + box.width / 2 : 0,
				y: box ? box.bottom + 8 : 0,
				// Bounded here rather than in the template: a recorded payload
				// can be thousands of lines, and a hover card is a glance.
				json: JSON.stringify(items, null, 2)
					.split('\n')
					.slice(0, 14)
					.join('\n'),
			}
		},

		/**
		 * Save the flow, and follow the id a first save mints.
		 *
		 * A flow created in the editor has no id until it is stored, so the
		 * route still says "new" afterwards — reloading would lose the work
		 * that was just saved. Replacing the route rather than pushing keeps
		 * Back meaning "the page before the editor".
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/flow-canvas/spec.md
		 */
		async onSave() {
			try {
				const saved = await this.editor.save()
				showSuccess(this.t('hermiq', 'Flow saved.'))
				if (
					saved
					&& saved.id
					&& String(this.$route.params.id) !== String(saved.id)
				) {
					this.$router.replace({
						name: 'FlowDetail',
						params: { id: String(saved.id) },
					})
				}
			} catch (e) {
				showError(
					e?.response?.data?.error
						|| this.t('hermiq', 'Could not save the flow.'),
				)
			}
		},

		/**
		 * @spec openspec/specs/flow-canvas/spec.md
		 */
		onContextData() {
			const id = this.editor.contextMenu?.id
			this.editor.contextMenu = null
			if (id) {
				this.editor.openStepPayload(id)
			}
		},

		/**
		 * Delete whatever the menu was raised on.
		 *
		 * @return {void}
		 * @spec openspec/specs/flow-canvas/spec.md
		 */
		onContextDelete() {
			const menu = this.editor.contextMenu
			this.editor.contextMenu = null
			if (!menu) {
				return
			}

			if (menu.kind === 'node') {
				this.editor.removeNode(menu.id)
				return
			}

			this.editor.removeEdge(menu.id)
		},

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
				ports.push({
					id: 'in',
					side: 'left',
					kind: 'in',
					label: this.t('hermiq', 'In'),
				})
			}

			// A loop owns its body (IterateNode's `config.body` is a list of
			// node ids). Those hang off the TOP as a visible sub-list, kept
			// clear of the left-to-right run of the main chain — a loop body
			// drawn inline reads as a detour rather than as a repeat.
			if (this.isLoopNode(node)) {
				ports.push({
					id: 'body-out',
					side: 'top',
					kind: 'out',
					label: this.t('hermiq', 'Loop body'),
				})
				ports.push({
					id: 'body-in',
					side: 'top',
					kind: 'in',
					label: this.t('hermiq', 'Loop body returns'),
				})
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
				ports.push({
					id: 'out',
					side: 'right',
					kind: 'out',
					label: this.t('hermiq', 'Out'),
				})
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

			const entry = this.editor.catalogueEntryFor(node.type)

			// A type the catalogue cannot explain is shown as its raw id rather
			// than guessed at from a list that may not match the engine.
			return entry ? entry.displayName || entry.id : node.type
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
			const keys = Object.keys(config).filter(
				(key) => key.startsWith('$') === false,
			)
			if (keys.length === 0) {
				return ''
			}

			const key = keys[0]
			const value = config[key]

			let rendered = ''
			if (
				typeof value === 'string'
				|| typeof value === 'number'
				|| typeof value === 'boolean'
			) {
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
		 * @spec openspec/specs/flow-canvas/spec.md
		 */
		zoomBy(delta) {
			const next = Math.min(
				this.maxZoom,
				Math.max(this.minZoom, this.zoom + delta),
			)
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
		 * @spec openspec/specs/flow-canvas/spec.md
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
		 * @spec openspec/specs/flow-canvas/spec.md
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

				this.resultDialog = { title: label || fallback, result: entry }
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
		 * @spec openspec/specs/flow-canvas/spec.md
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
				return this.t(
					'hermiq',
					'Unassigned: branch “{branch}” no longer exists',
					{
						branch: String(edge.fromExit || '').trim(),
					},
				)
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
				return this.t('hermiq', 'Connection from {from} to {to}', {
					from,
					to,
				})
			}

			return this.t('hermiq', '{label}, from {from} to {to}', {
				label,
				from,
				to,
			})
		},

		/**
		 * The last run's entry for this step, or null when it did not run.
		 *
		 * @param {object} edge The step.
		 * @return {object|null} The log entry.
		 * @spec openspec/specs/flow-canvas/spec.md
		 */
		resultFor(edge) {
			if (!edge) {
				return null
			}

			return this.editor.resultByEdge[this.editor.transitionName(edge)] || null
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

/* Viewport-anchored, translated back by half its own width so it hangs under
   the `{}` it belongs to. `pointer-events: none` matters: the card appears
   directly below the dot, and a card that swallowed the pointer would make the
   dot un-clickable the moment its own preview opened. */
.flow-builder__payload-preview {
	position: fixed;
	z-index: 100;
	transform: translateX(-50%);
	max-width: 320px;
	padding: 8px 10px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background-color: var(--color-main-background);
	box-shadow: 0 2px 8px rgba(0, 0, 0, 0.18);
	pointer-events: none;
}

.flow-builder__payload-preview-head {
	display: block;
	margin-block-end: 4px;
	color: var(--color-text-maxcontrast);
	font-size: 0.8em;
}

.flow-builder__payload-preview-json {
	max-height: 220px;
	margin: 0;
	overflow: hidden;
	font-family: monospace;
	font-size: 0.75em;
	white-space: pre;
}

/* The same floating card as the zoom cluster, beside it. Both are canvas
   controls, so they read as one control strip rather than two conventions.

   Floating in the TOP-RIGHT rather than the top-left, which matters: the first
   attempt floated top-left and covered a node — measured, the bar sat at 321,62
   and the first node's rect intersected it, so that node could not be clicked
   at all. Auto-sort lays a flow out from the top-LEFT, so that corner is the
   one place a node is guaranteed to be; the right-hand corner is where the
   canvas already keeps its own controls for the same reason. */
.flow-builder__verbs {
	display: flex;
	gap: 4px;
	align-items: center;
	padding: 4px;
	border-radius: var(--border-radius-large, 8px);
	background-color: var(--color-main-background);
	box-shadow: 0 1px 4px var(--color-box-shadow, rgba(0, 0, 0, 0.2));
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
 * specificity with anything written here — `:deep(.cn-flow-node)`
 * compiles to `[data-v-builder] .cn-flow-node`, (0,2,0), exactly
 * matching `.cn-flow-node[data-v-canvas]` — so which one won came down
 * to bundle order. Anchoring on `.flow-builder` settles it at (0,3,0). */
.flow-builder :deep(.cn-flow-node) {
	/* THE CARD IS THE NODE COMPONENT'S. WE ONLY CLIP IT.
	 *
	 * This used to restate `border`, `background-color` and `border-radius`
	 * here, with the same values CnFlowNode already sets — three declarations
	 * that existed to win a specificity tie against a rule saying the same
	 * thing. That is the container-in-container: two owners for one card, kept
	 * in step by hand.
	 *
	 * CnFlowNode owns the frame. What is genuinely ours is the clip, so the
	 * body's role accent follows the card's curve instead of drawing a second
	 * corner over it. */
	overflow: hidden;
}

.flow-builder :deep(.cn-flow-node--selected) {
	border-color: var(--color-primary-element);
}

/* The connection handle is the node's OUTPUT PORT, and it is the one piece of
   node chrome the canvas renders outside our slot — so it is styled from here,
   selected structurally on what our slot rendered inside the same wrapper.
   Sized explicitly because Nextcloud's global button rules give every <button>
   a minimum height: the port is declared 16x16 round in the canvas and measured
   16x34 on screen, a bar rather than a dot. */
.flow-builder :deep(.cn-flow-node__handle) {
	width: 16px;
	height: 16px;
	min-height: 16px;
	min-width: 16px;
	border-radius: 50%;
}

/* Role, on the port: green where a run begins, red where it ends. The port is a
   sibling of our slot content, so it cannot be given a class from inside the
   slot — `:has()` reads the role off the card we DID render. */
.flow-builder
	:deep(.cn-flow-node:has(.flow-builder__node--trigger) .cn-flow-node__handle) {
	background-color: var(--color-success, #46ba61);
}

.flow-builder
	:deep(.cn-flow-node:has(.flow-builder__node--end) .cn-flow-node__handle) {
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
	/*
	   FILL the card by positioning, not by `height: 100%`.

	   The canvas sizes a node with `min-height`, not `height` — so the wrapper
	   has no definite height and a percentage height on the child resolves
	   against `auto`, which is the child's own content. A node with fewer lines
	   then came out SHORTER than its card and the white wrapper showed around
	   it: the container-in-a-container. Measured on the demo flow — a step (3
	   lines) filled at 76px while a trigger (2 lines, no config summary) sat at
	   57px inside the same 80px card.

	   It reads as a trigger/end problem because those two rarely carry a config
	   summary, but the cause is line count, not role. The wrapper is
	   `position: absolute`, so insetting the child fills it whatever it holds.
	*/
	position: absolute;
	inset: 0;
	padding: 8px 10px 8px 14px;
	box-shadow: inset 6px 0 0 0 var(--color-border);
	box-sizing: border-box;
	overflow: hidden;
}

/* Selection is the wrapper's: CnGraphCanvas sets --selected on the element it
   positions, so restating it here would be a second, competing highlight. */

/* Role accents — NC variables only (ADR-010). Keyed on the node's declared
   ROLE, which OpenRegister ships on the catalogue entry, so a trigger or end
   node contributed by any app is coloured correctly whatever it is called.
   These used to key off graph POSITION, which painted an unconnected step
   green as though a run began there. */
.flow-builder__node--trigger {
	box-shadow: inset 6px 0 0 0 var(--color-success, #46ba61);
}

.flow-builder__node--end {
	box-shadow: inset 6px 0 0 0 var(--color-error, #e9322d);
}

.flow-builder__node--step {
	box-shadow: inset 6px 0 0 0 var(--color-primary-element);
}

/* A trigger is green because it is a TRIGGER. Declared after the role accents
   so it wins over the topology-inferred one: the two normally agree, and where
   they disagree the node's own type is the truer answer. */
/* The context menu floats over the canvas in VIEWPORT coordinates — that is
   where the right-click happened, and anchoring it to the canvas would move it
   the moment the canvas is zoomed or panned. */
.flow-builder__context {
	position: fixed;
	z-index: 100;
	display: flex;
	flex-direction: column;
	min-width: 140px;
	padding: 4px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background-color: var(--color-main-background);
	box-shadow: 0 2px 8px var(--color-box-shadow);
}

.flow-builder__context button {
	border: none;
	background: transparent;
	color: var(--color-main-text);
	padding: 6px 10px;
	text-align: start;
	border-radius: var(--border-radius);
}

.flow-builder__context button:hover,
.flow-builder__context button:focus-visible {
	background-color: var(--color-background-hover);
}

.flow-builder__context-destructive {
	color: var(--color-error);
}

/*
	A note is PAPER, not a card.

	The canvas draws its own card around everything it positions — background,
	2px border, rounded corners — so a note rendered inside it came out as a
	sheet within a card, the "container in a container" the board is full of.
	This strips the wrapper's chrome for notes only, keeping its border WIDTH so
	the geometry the canvas laid out is unchanged and the note does not shift by
	two pixels when it stops being a card.
*/
:deep(.cn-flow-node:has(.flow-builder__annotation)) {
	background-color: transparent;
	border-color: transparent;
}

.flow-builder__annotation {
	position: relative;
	display: flex;
	gap: 4px;
	width: 100%;
	height: 100%;
	padding: 8px;
	/* Square-ish corners, a warm sheet, a soft shadow and a turned-up bottom
	   corner: the things that make a rectangle read as a sticky note rather
	   than as another node. It must not look like part of the run — the engine
	   never sees it. */
	background-color: #fdf6a9;
	border: none;
	border-radius: 2px;
	box-shadow: 0 2px 6px rgba(0, 0, 0, 0.28);
	/* The sheet is a FIXED colour, so its ink is fixed too: a theme's light
	   text would disappear on yellow paper. */
	color: #2f2c14;
}

/* The turned-up corner. Decorative only, and hidden from assistive tech by
   being a pseudo-element with no content. */
.flow-builder__annotation::after {
	content: '';
	position: absolute;
	inset-inline-end: 0;
	inset-block-end: 0;
	border-width: 0 0 14px 14px;
	border-style: solid;
	border-color: transparent transparent rgba(0, 0, 0, 0.16) transparent;
}

.flow-builder__annotation-text {
	flex: 1;
	border: none;
	background: transparent;
	/* Inherits the sheet's ink rather than the theme's. */
	color: inherit;
	resize: none;
	font-size: 0.9em;
}

.flow-builder__annotation-text::placeholder {
	color: rgba(47, 44, 20, 0.55);
}

.flow-builder__annotation-remove {
	flex: 0 0 auto;
	/* Same reason as the text: on a fixed sheet the button's glyph cannot take
	   its colour from the theme. */
	color: inherit;
}

/* A replayed run's path. Marked with a colour AND a heavier line, because a
   path drawn only in hue is unreadable in greyscale and to a reader who cannot
   distinguish it (WCAG 2.1 AA 1.4.1). Lines the run did NOT take keep their
   ordinary weight — the contrast is the information. */
.flow-builder__step--replayed .flow-builder__edge {
	stroke: var(--color-success, #46ba61);
	stroke-width: 3;
}

.flow-builder__node--replayed {
	outline: 2px solid var(--color-success, #46ba61);
	outline-offset: 1px;
}

.flow-builder__payload-dot {
	fill: var(--color-main-background);
	stroke: var(--color-success, #46ba61);
	stroke-width: 2;
}

.flow-builder__payload-text {
	fill: var(--color-main-text);
	font-size: 10px;
	font-family: monospace;
}

.flow-builder__payload {
	cursor: pointer;
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
