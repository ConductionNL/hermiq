<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  - SPDX-License-Identifier: EUPL-1.2
-->

<template>
	<NcAppSidebar
		v-if="editor.sidebarOpen"
		:name="editor.flow.name || t('hermiq', 'Untitled flow')"
		:subname="subname"
		:active="activeTab"
		@update:active="activeTab = $event"
		@close="editor.sidebarOpen = false">
		<!-- Nodes: the places a run moves between. -->
		<NcAppSidebarTab id="nodes" :name="t('hermiq', 'Nodes')" :order="1">
			<template #icon>
				<Sitemap :size="20" />
			</template>

			<div class="flow-sidebar__pane">
				<!--
					The palette. One card per type the ENGINE can execute, with a
					search box above it, because the catalogue is long enough that
					a flat list is a scroll-and-scan: the engine ships fifteen node
					types before any app contributes one.

					A card, not a bare row: the type's own description is the thing
					an author picks on, and a name alone ("Route items", "Explode")
					does not say what it does.
				-->
				<NcTextField
					:model-value="nodeSearch"
					:label="t('hermiq', 'Search node types')"
					trailing-button-icon="close"
					:show-trailing-button="nodeSearch !== ''"
					@update:model-value="nodeSearch = $event"
					@trailing-button-click="nodeSearch = ''">
					<Magnify :size="16" />
				</NcTextField>

				<p v-if="paletteTypes.length === 0" class="flow-sidebar__hint">
					{{ paletteEmptyText }}
				</p>

				<div v-else class="flow-sidebar__palette">
					<button
						v-for="type in paletteTypes"
						:key="type.id"
						class="flow-sidebar__palette-card"
						:title="type.description"
						draggable="true"
						@dragstart="editor.paletteDragType = type.id"
						@dragend="editor.paletteDragType = null"
						@click="editor.addNode(type.id)">
						<span class="flow-sidebar__palette-name">{{ type.label }}</span>
						<span v-if="type.description" class="flow-sidebar__palette-desc">
							{{ type.description }}
						</span>
					</button>
				</div>

				<p class="flow-sidebar__hint">
					{{ t('hermiq', 'Click to add, or drag onto the canvas.') }}
				</p>

				<hr class="flow-sidebar__rule">

				<template v-if="editor.selectedNode">
					<p class="flow-sidebar__hint">
						{{ editor.selectedNode.name || editor.selectedNode.id }} · {{ roleLabel }}
					</p>

					<!--
						The keyboard-reachable way into the node editor. The canvas
						opens the same modal on double-click, which is a pointer
						gesture and therefore cannot be the only route (WCAG 2.1 AA
						2.1.1).
					-->
					<NcButton type="secondary" @click="editor.nodeEditOpen = true">
						<template #icon>
							<Pencil :size="20" />
						</template>
						{{ t('hermiq', 'Edit node') }}
					</NcButton>
				</template>

				<p v-else class="flow-sidebar__hint">
					{{ t('hermiq', 'Select a node on the canvas to edit it.') }}
				</p>
			</div>
		</NcAppSidebarTab>

		<!--
			The CONNECTION tab: what a line is, and nothing more.

			This pane used to pick a step type and edit its configuration, both
			written onto the edge. That is the pre-inversion dialect, and it does
			not merely look wrong — `FlowDefinitionBuilder::assertNotPreInversion()`
			REFUSES any document in which an edge carries a non-empty `type`, so
			every flow configured through this pane became unrunnable in whole.
			Behaviour lives on the node (NodeEditModal); a connection carries
			only sequence and the words a reader needs.
		-->
		<NcAppSidebarTab id="step" :name="t('hermiq', 'Connection')" :order="2">
			<template #icon>
				<ArrowRightBold :size="20" />
			</template>

			<div v-if="editor.selectedEdge" class="flow-sidebar__pane" data-testid="flow-step-pane">
				<p class="flow-sidebar__hint">
					{{ t('hermiq', '{from} → {to}', { from: editor.selectedEdge.from.join(', '), to: editor.selectedEdge.to.join(', ') }) }}
				</p>

				<NcTextField
					:model-value="editor.selectedEdge.title || ''"
					:label="t('hermiq', 'Title')"
					:placeholder="t('hermiq', 'The words on the line, e.g. “approved”')"
					@update:model-value="editor.setEdgeField('title', $event)" />

				<NcTextArea
					:model-value="editor.selectedEdge.description || ''"
					:label="t('hermiq', 'Description')"
					:placeholder="t('hermiq', 'What this connection means — when the flow takes it.')"
					rows="3"
					@update:model-value="editor.setEdgeField('description', $event)" />

				<NcTextArea
					:model-value="editor.selectedEdge.notes || ''"
					:label="t('hermiq', 'Notes')"
					:placeholder="t('hermiq', 'Anything the next person should know about this connection.')"
					rows="4"
					@update:model-value="editor.setEdgeField('notes', $event)" />

				<NcButton type="error" @click="editor.removeEdge(editor.selectedEdge.id)">
					<template #icon>
						<Delete :size="20" />
					</template>
					{{ t('hermiq', 'Remove connection') }}
				</NcButton>
			</div>

			<p v-else class="flow-sidebar__hint">
				{{ t('hermiq', 'Select a connection on the canvas — the line between two nodes — to describe it. What a step DOES is on the node.') }}
			</p>
		</NcAppSidebarTab>

		<!--
			Runs: what this flow has actually done.

			Its own tab rather than a panel under Flow, because it answers a
			different question. Flow is "what should happen"; Runs is "what did".
			An operator opens this one when something looks wrong, and making
			them scroll past the editor's settings to reach it is the wrong way
			round.
		-->
		<NcAppSidebarTab id="runs" :name="t('hermiq', 'Runs')" :order="3">
			<template #icon>
				<History :size="20" />
			</template>

			<div class="flow-sidebar__pane">
				<NcButton type="secondary" :disabled="!editor.flow.id" @click="editor.loadRuns()">
					<template #icon>
						<NcLoadingIcon v-if="editor.runsLoading" :size="20" />
						<Refresh v-else :size="20" />
					</template>
					{{ t('hermiq', 'Load runs') }}
				</NcButton>

				<NcNoteCard v-if="editor.runsError" type="error">
					{{ editor.runsError }}
				</NcNoteCard>

				<p v-else-if="!editor.flow.id" class="flow-sidebar__hint">
					{{ t('hermiq', 'Save this flow first — a flow that has never been stored has no runs.') }}
				</p>

				<!-- An unloaded list and an empty one are different claims, and
				     only the second says anything about the flow. -->
				<p v-else-if="editor.runs.length === 0 && !editor.runsLoading" class="flow-sidebar__hint">
					{{ t('hermiq', 'No runs loaded yet, or this flow has never run.') }}
				</p>

				<ul v-else class="flow-sidebar__runs">
					<li v-for="run in editor.runs" :key="run.id || run.uuid" class="flow-sidebar__run">
						<button
							class="flow-sidebar__run-head"
							:aria-expanded="editor.expandedRunId === (run.uuid || run.id) ? 'true' : 'false'"
							@click="editor.toggleRun(run.uuid || run.id)">
							<span :class="`flow-sidebar__run-status flow-sidebar__run-status--${run.status || 'unknown'}`">
								{{ run.status || t('hermiq', 'unknown') }}
							</span>
							<span class="flow-sidebar__run-when">{{ formatWhen(run) }}</span>
						</button>

						<div v-if="editor.expandedRunId === (run.uuid || run.id)" class="flow-sidebar__run-log">
							<p v-if="editor.runDetail[run.uuid || run.id] === undefined" class="flow-sidebar__hint">
								{{ t('hermiq', 'Loading the step log…') }}
							</p>
							<p v-else-if="editor.runDetail[run.uuid || run.id] === null" class="flow-sidebar__hint">
								{{ t('hermiq', 'Could not read this run’s step log.') }}
							</p>
							<ol v-else-if="logOf(run).length > 0">
								<li v-for="(entry, index) in logOf(run)" :key="index">
									<strong>{{ entry.node || entry.step || '—' }}</strong>
									· {{ entry.status || '—' }}
									<span v-if="entry.error" class="flow-sidebar__run-error">{{ entry.error }}</span>
								</li>
							</ol>
							<p v-else class="flow-sidebar__hint">
								{{ t('hermiq', 'This run recorded no steps.') }}
							</p>
						</div>
					</li>
				</ul>
			</div>
		</NcAppSidebarTab>

		<!-- Flow: identity, trigger wiring and the two verbs. -->
		<NcAppSidebarTab id="flow" :name="t('hermiq', 'Flow')" :order="4">
			<template #icon>
				<Cog :size="20" />
			</template>

			<div class="flow-sidebar__pane">
				<div class="flow-sidebar__verbs">
					<NcButton type="primary" :disabled="editor.saving || !editor.flow.name" @click="save">
						<template #icon>
							<NcLoadingIcon v-if="editor.saving" :size="20" />
							<ContentSave v-else :size="20" />
						</template>
						{{ t('hermiq', 'Save') }}
					</NcButton>
					<NcButton type="secondary" :disabled="!editor.flow.id" @click="editor.showRun = true">
						<template #icon>
							<Play :size="20" />
						</template>
						{{ t('hermiq', 'Run…') }}
					</NcButton>
				</div>
				<p v-if="editor.dirty" class="flow-sidebar__hint">
					{{ t('hermiq', 'Unsaved changes') }}
				</p>

				<!-- The engine's own preflight, not a second opinion: it builds
				     the same definition the run builds and calls each step's
				     validateConfig(), so a step written in another step's
				     dialect — which resolves, runs and reports COMPLETED while
				     doing nothing — is reported here instead of at 03:00. -->
				<NcNoteCard v-if="validationMessage" :type="editor.validation.valid ? 'success' : 'error'">
					{{ validationMessage }}
				</NcNoteCard>
				<NcButton type="tertiary" @click="editor.validate()">
					<template #icon>
						<CheckDecagram :size="20" />
					</template>
					{{ t('hermiq', 'Check this flow') }}
				</NcButton>

				<NcTextField
					:model-value="editor.flow.name"
					:label="t('hermiq', 'Name')"
					required
					@update:model-value="editor.setFlowField('name', $event)" />
				<NcTextField
					:model-value="editor.flow.description || ''"
					:label="t('hermiq', 'Description')"
					@update:model-value="editor.setFlowField('description', $event)" />

				<!--
					No trigger fields here.

					What starts a flow is a NODE on the canvas — the green ones
					— and there may be several of them. This pane used to carry
					the trigger, its register/schema subject and its cron
					expression, which is four fields holding exactly ONE trigger
					between them: "on a schedule AND when an object changes" had
					nowhere to go, and the only workaround was to duplicate the
					flow and keep the copies in step by hand.

					It also put the flow's BEGINNING in a settings pane while
					everything the flow does was on the graph.

					The flow row's `trigger`/`triggerRegister`/`triggerSchema`/
					`cron` columns are still authoritative at run time and are
					NOT edited here any more. They are migrated onto trigger
					nodes in their own change — a flow whose trigger stops
					resolving does not fail loudly, it simply never fires again,
					which is indistinguishable from a flow with nothing to do.
				-->
				<p v-if="triggerNodeCount === 0" class="flow-sidebar__hint">
					{{ t('hermiq', 'Nothing starts this flow yet. Add a trigger node from the Nodes tab.') }}
				</p>
				<p v-else class="flow-sidebar__hint">
					{{ n('hermiq', '%n trigger node starts this flow.', '%n trigger nodes start this flow.', triggerNodeCount) }}
				</p>

				<!-- How long this flow's runs are kept. A flow setting rather
				     than an instance one because the right answer differs per
				     flow: a five-minute sequencer produces 288 runs a day and
				     wants a short window, while a quarterly reconciliation has
				     to still be auditable months later. Empty means the
				     instance default — NOT "keep forever". -->
				<NcTextField
					:model-value="editor.flow.retentionDays === null || editor.flow.retentionDays === undefined ? '' : String(editor.flow.retentionDays)"
					type="number"
					:label="t('hermiq', 'Keep run logs for (days)')"
					:placeholder="t('hermiq', 'Instance default')"
					@update:model-value="editor.setFlowField('retentionDays', $event === '' ? null : Number($event))" />

				<NcSelect
					:model-value="editor.flow.executionMode || 'async'"
					:options="executionModes"
					:input-label="t('hermiq', 'Execution')"
					@update:model-value="editor.setFlowField('executionMode', $event)" />

				<NcCheckboxRadioSwitch
					:model-value="editor.flow.enabled === true"
					type="switch"
					@update:model-value="editor.setFlowField('enabled', $event)">
					{{ t('hermiq', 'Enabled') }}
				</NcCheckboxRadioSwitch>

				<p class="flow-sidebar__hint">
					{{ n('hermiq', '%n node', '%n nodes', editor.nodes.length) }} ·
					{{ n('hermiq', '%n connection', '%n connections', editor.edges.length) }}
				</p>
			</div>
		</NcAppSidebarTab>
	</NcAppSidebar>
</template>

<script>
import { NcAppSidebar, NcAppSidebarTab, NcButton, NcCheckboxRadioSwitch, NcLoadingIcon, NcNoteCard, NcSelect, NcTextArea, NcTextField } from '@nextcloud/vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import ArrowRightBold from 'vue-material-design-icons/ArrowRightBold.vue'
import CheckDecagram from 'vue-material-design-icons/CheckDecagram.vue'
import Cog from 'vue-material-design-icons/Cog.vue'
import ContentSave from 'vue-material-design-icons/ContentSave.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import History from 'vue-material-design-icons/History.vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Play from 'vue-material-design-icons/Play.vue'
import Sitemap from 'vue-material-design-icons/Sitemap.vue'
import { useFlowEditorStore } from '../store/flowEditor.js'

/**
 * FlowSidebar — the flow editor's controls, in Nextcloud's real app sidebar.
 *
 * Declared as the FlowDetail page's `sidebarComponent`, so CnPageRenderer
 * hands it to CnAppRoot's #sidebar slot and it renders as a genuine
 * NcAppSidebar (same place CnObjectSidebar renders) rather than a panel drawn
 * inside the page. State is shared with the canvas through the flow-editor
 * store, since the two halves live in different parts of the tree — including
 * whether this sidebar is open at all, because the re-open control has to live
 * on the canvas once this component has stopped rendering.
 *
 * ## Two editors, because there are two things to edit
 *
 * A flow is a Petri net: a NODE is a place and carries no configuration, an
 * EDGE is a transition and carries the step. So the Nodes tab renames places
 * and the Step tab configures the selected edge. The step palette used to live
 * on the Nodes tab and put catalogue step types onto nodes, which the engine
 * refuses outright (`FlowDefinitionBuilder::extractPlaces()` throws on a node
 * carrying `type`) — every flow authored through it was unrunnable.
 */
export default {
	name: 'FlowSidebar',

	components: {
		ArrowRightBold,
		CheckDecagram,
		Cog,
		ContentSave,
		Delete,
		History,
		Magnify,
		Refresh,
		Pencil,
		NcAppSidebar,
		NcAppSidebarTab,
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
		NcTextArea,
		NcTextField,
		Play,
		Sitemap,
	},

	setup() {
		return { editor: useFlowEditorStore() }
	},

	data() {
		return {
			activeTab: 'nodes',
			// Palette filter. Empty means "show the whole catalogue".
			nodeSearch: '',
			executionModes: ['async', 'sync'],
		}
	},

	computed: {

		/**
		 * How many entry points this flow has.
		 *
		 * Counted from the nodes, because that is where a trigger now lives.
		 * Zero is reported plainly rather than left blank: a flow nothing
		 * starts is not obviously broken — it simply never runs, which looks
		 * exactly like a flow with nothing to do.
		 *
		 * @return {number} The trigger-node count.
		 *
		 * @spec openspec/specs/flow-canvas/spec.md
		 */
		triggerNodeCount() {
			return this.editor.nodes.filter((node) => String(node.type || '').includes('.trigger-')).length
		},

		/**
		 * The palette: the engine's node types, filtered by the search box.
		 *
		 * Matches the id as well as the name and description — an author who
		 * knows the engine reaches for `openregister.object-read` before
		 * "Read an object".
		 *
		 * @return {Array<{id: string, label: string, description: string}>} The cards.
		 *
		 * @spec openspec/specs/flow-canvas/spec.md
		 */
		paletteTypes() {
			const needle = this.nodeSearch.trim().toLowerCase()
			const all = (this.editor.nodeCatalog || []).map((entry) => ({
				id: entry.id,
				label: entry.displayName || entry.id,
				description: entry.description || '',
			}))

			if (needle === '') {
				return all
			}

			return all.filter((entry) =>
				`${entry.id} ${entry.label} ${entry.description}`.toLowerCase().includes(needle),
			)
		},

		/**
		 * Why the palette is empty — a failed catalogue read and a search that
		 * matched nothing are different problems and only one is the author's.
		 *
		 * @return {string} The empty-state text.
		 *
		 * @spec openspec/specs/flow-canvas/spec.md
		 */
		paletteEmptyText() {
			if ((this.editor.nodeCatalog || []).length === 0) {
				return this.t('hermiq', 'Could not read the flow engine’s node types. Reload to try again — the palette is deliberately empty rather than offering types the engine cannot run.')
			}

			return this.t('hermiq', 'No node type matches “{search}”.', { search: this.nodeSearch })
		},

		/**
		 * The selected place's role in the flow, in words.
		 *
		 * @return {string} The role.
		 */
		roleLabel() {
			const id = this.editor.selectedNodeId
			if (this.editor.startNodeIds.includes(id)) {
				return this.t('hermiq', 'A run starts here')
			}

			if (this.editor.endNodeIds.includes(id)) {
				return this.t('hermiq', 'A run ends here')
			}

			return this.t('hermiq', 'A run passes through here')
		},

		/**
		 * The preflight verdict, in words.
		 *
		 * @return {string} The message, or '' when nothing has been checked.
		 */
		validationMessage() {
			if (this.editor.validation === null) {
				return ''
			}

			if (this.editor.validation.valid) {
				return this.t('hermiq', 'The flow engine accepts this flow.')
			}

			return this.editor.validation.message
				|| this.t('hermiq', 'The flow engine will not run this flow.')
		},

		/** @return {string} Sidebar subtitle: what this flow reacts to. */
		subname() {
			const trigger = this.editor.flow.trigger || ''
			if (trigger === 'schedule') {
				return this.t('hermiq', 'Schedule · {cron}', { cron: this.editor.flow.cron || '—' })
			}

			if (!this.editor.flow.triggerSchema) {
				return trigger || this.t('hermiq', 'No trigger set')
			}

			return `${trigger || 'object.updated'} · ${this.editor.flow.triggerSchema}`
		},

	},

	watch: {
		/**
		 * Follow the selection: show the Nodes tab, and drop any raw-config draft.
		 *
		 * @param {string|null} id The newly selected place id.
		 */
		'editor.selectedNodeId'(id) {
			if (id !== null) {
				this.activeTab = 'nodes'
			}
		},

		/**
		 * Follow the step selection onto the Step tab, and drop any draft.
		 *
		 * Dropping the draft matters — without it the textarea would keep showing
		 * the PREVIOUS step's configuration, and the next valid keystroke would
		 * write it onto the newly-selected step.
		 *
		 * @param {string|null} id The newly selected step id.
		 */
		'editor.selectedEdgeId'(id) {
			if (id !== null) {
				this.activeTab = 'step'
			}
		},
	},

	methods: {
		/**
		 * When a run happened, in the reader's locale.
		 *
		 * Falls back to the raw value rather than showing "Invalid Date": the
		 * string the server sent tells an operator more than the words
		 * "Invalid Date" do.
		 *
		 * @param {object} run The run.
		 * @return {string} The display value.
		 *
		 * @spec openspec/specs/flow-canvas/spec.md
		 */
		formatWhen(run) {
			const raw = run.started || run.created || run.updated || ''
			const when = new Date(raw)

			return Number.isNaN(when.getTime()) ? String(raw) : when.toLocaleString()
		},

		/**
		 * A run's per-step entries.
		 *
		 * Read from the DETAIL fetched on expand, not from the list row: the
		 * index endpoint returns runs without their logs, so reading the log off
		 * a list row yields an empty array for every run and looks exactly like
		 * "this run recorded no steps".
		 *
		 * @param {object} run The run.
		 * @return {Array<object>} The step entries.
		 *
		 * @spec openspec/specs/flow-canvas/spec.md
		 */
		logOf(run) {
			const detail = this.editor.runDetail[run.uuid || run.id]

			return detail?.log || detail?.steps || []
		},

		/**
		 * Persist the flow, keeping the route in step when it gains an id.
		 *
		 * @return {Promise<void>}
		 */
		async save() {
			try {
				const saved = await this.editor.save()
				showSuccess(this.t('hermiq', 'Flow saved.'))
				if (saved && saved.id && String(this.$route.params.id) !== String(saved.id)) {
					this.$router.replace({ name: 'FlowDetail', params: { id: String(saved.id) } })
				}
			} catch (e) {
				showError(e?.response?.data?.error || this.t('hermiq', 'Could not save the flow.'))
			}
		},
	},
}
</script>

<style scoped>
.flow-sidebar__pane {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 4px 0 16px;
}

.flow-sidebar__hint {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	margin: 0;
}

.flow-sidebar__rule {
	border: none;
	border-top: 1px solid var(--color-border);
	margin: 12px 0;
}

.flow-sidebar__runs {
	list-style: none;
	padding: 0;
	margin: 0;
	display: flex;
	flex-direction: column;
	gap: 4px;
	max-height: 45vh;
	overflow-y: auto;
}

.flow-sidebar__run-head {
	display: flex;
	gap: 8px;
	align-items: center;
	width: 100%;
	padding: 6px 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	text-align: start;
}

.flow-sidebar__run-head:hover,
.flow-sidebar__run-head:focus-visible {
	background-color: var(--color-background-hover);
}

/* The status is also the WORD, never colour alone (WCAG 2.1 AA 1.4.1). */
.flow-sidebar__run-status {
	font-weight: bold;
}

.flow-sidebar__run-status--failed,
.flow-sidebar__run-status--dead_letter {
	color: var(--color-error);
}

.flow-sidebar__run-status--completed {
	color: var(--color-success);
}

.flow-sidebar__run-status--suspended {
	color: var(--color-warning-text, var(--color-main-text));
}

.flow-sidebar__run-when {
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
}

.flow-sidebar__run-log {
	padding: 6px 10px;
	font-size: 0.9em;
}

.flow-sidebar__run-error {
	color: var(--color-error);
}

.flow-sidebar__palette {
	display: flex;
	flex-direction: column;
	gap: 6px;
	/* The catalogue is long; cap it so the palette cannot push the selected
	   node's controls off the bottom of the sidebar. */
	max-height: 40vh;
	overflow-y: auto;
}

.flow-sidebar__palette-card {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: 2px;
	width: 100%;
	padding: 8px 10px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	text-align: start;
	cursor: grab;
}

.flow-sidebar__palette-card:hover,
.flow-sidebar__palette-card:focus-visible {
	background-color: var(--color-background-hover);
}

.flow-sidebar__palette-name {
	font-weight: bold;
}

.flow-sidebar__palette-desc {
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
	/* Two lines, then ellipsis: a description long enough to wrap four times
	   turns the palette back into the scroll-and-scan the search box exists to
	   avoid. The full text is on the card's title attribute. */
	display: -webkit-box;
	-webkit-line-clamp: 2;
	-webkit-box-orient: vertical;
	overflow: hidden;
}

.flow-sidebar__verbs {
	display: flex;
	gap: 8px;
}
</style>
