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

				<ul v-else class="flow-sidebar__palette">
					<li
						v-for="type in paletteTypes"
						:key="type.id"
						class="flow-sidebar__palette-card"
						:class="`flow-sidebar__palette-card--${type.role}`"
						draggable="true"
						@dragstart="editor.paletteDragType = type.id"
						@dragend="editor.paletteDragType = null">
						<!--
							Selecting a card EXPANDS it rather than adding the
							node: an author is choosing between types, and a
							choice is made by comparison. Adding is the explicit
							button below, so a mis-click costs a fold-out and
							not a node on the canvas.
						-->
						<button
							class="flow-sidebar__palette-head"
							:aria-expanded="expandedType === type.id ? 'true' : 'false'"
							@click="expandedType = expandedType === type.id ? '' : type.id">
							<img
								v-if="type.icon"
								:src="type.icon"
								alt=""
								class="flow-sidebar__palette-icon">
							<span class="flow-sidebar__palette-name">{{ type.label }}</span>
							<!-- The role in WORDS as well as the accent stripe:
							     a colour-only code is unreadable in greyscale
							     and to a reader who cannot distinguish the hues
							     (WCAG 2.1 AA 1.4.1). -->
							<span class="flow-sidebar__palette-role">{{ roleWord(type.role) }}</span>
						</button>

						<p
							class="flow-sidebar__palette-desc"
							:class="{ 'flow-sidebar__palette-desc--full': expandedType === type.id }">
							{{ type.description }}
						</p>

						<div v-if="expandedType === type.id" class="flow-sidebar__palette-actions">
							<span class="flow-sidebar__palette-provider">{{ type.provider }}</span>
							<NcButton type="secondary" @click="editor.addNode(type.id)">
								{{ t('hermiq', 'Add to flow') }}
							</NcButton>
						</div>
					</li>
				</ul>

				<p class="flow-sidebar__hint">
					{{ t('hermiq', 'Select a card to read what it does, or drag it onto the canvas.') }}
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

				<!--
					The keyboard route to the connection editor.

					The Connection TAB is gone — its three fields did not earn a
					quarter of the tab strip — but its editor must still be
					reachable without a pointer: right-click is the shortcut, and
					a shortcut cannot be the only way to an action (WCAG 2.1 AA
					2.1.1). Selecting a line on the canvas is keyboard-operable,
					so this button completes the path.
				-->
				<template v-if="editor.selectedEdge">
					<hr class="flow-sidebar__rule">
					<p class="flow-sidebar__hint">
						{{ t('hermiq', '{from} → {to}', { from: editor.selectedEdge.from.join(', '), to: editor.selectedEdge.to.join(', ') }) }}
					</p>
					<NcButton type="secondary" @click="editor.edgeEditOpen = true">
						<template #icon>
							<Pencil :size="20" />
						</template>
						{{ t('hermiq', 'Edit connection') }}
					</NcButton>
				</template>
			</div>
		</NcAppSidebarTab>

		<!--
			Runs: what this flow has actually done.

			Its own tab rather than a panel under Flow, because it answers a
			different question. Flow is "what should happen"; Runs is "what did".
			An operator opens this one when something looks wrong, and making
			them scroll past the editor's settings to reach it is the wrong way
			round.
		-->
		<NcAppSidebarTab id="runs" :name="t('hermiq', 'Runs')" :order="2">
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
							:class="{ 'flow-sidebar__run-head--replayed': editor.replayRunId === (run.uuid || run.id) }"
							:aria-expanded="editor.expandedRunId === (run.uuid || run.id) ? 'true' : 'false'"
							@click="editor.toggleRun(run.uuid || run.id)">
							<span :class="`flow-sidebar__run-status flow-sidebar__run-status--${run.status || 'unknown'}`">
								{{ run.status || t('hermiq', 'unknown') }}
							</span>
							<span class="flow-sidebar__run-when">{{ formatWhen(run) }}</span>
						</button>

						<!--
							Replay is a separate control from expanding. Opening
							a run to read its log and painting its path across
							the canvas are different intents, and binding both
							to one click means an operator cannot do either
							without the other.
						-->
						<NcButton
							type="tertiary"
							class="flow-sidebar__run-replay"
							@click="editor.replayRun(run.uuid || run.id)">
							{{ editor.replayRunId === (run.uuid || run.id)
								? t('hermiq', 'Hide on canvas')
								: t('hermiq', 'Show on canvas') }}
						</NcButton>

						<!--
							The log is READ in the modal, not here. This pane is
							346px wide and a recorded payload is wider than that
							before it wraps — the inline list below says which
							steps ran; the modal is where you look at one.
						-->
						<NcButton
							type="tertiary"
							@click="editor.openRunLog(run.uuid || run.id)">
							{{ t('hermiq', 'Open log') }}
						</NcButton>

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
		<NcAppSidebarTab id="flow" :name="t('hermiq', 'Flow')" :order="3">
			<template #icon>
				<Cog :size="20" />
			</template>

			<div class="flow-sidebar__pane">
				<!--
					The three verbs together. "Check this flow" used to sit
					below the validation card, several controls away from Run —
					which is the one it belongs beside: checking is what you do
					BEFORE running, and separating them made the check look like
					a property of the result rather than an alternative to
					starting one.
				-->
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
					<NcButton type="secondary" @click="editor.validate()">
						<template #icon>
							<CheckDecagram :size="20" />
						</template>
						{{ t('hermiq', 'Check') }}
					</NcButton>
				</div>

				<!--
					Unsaved changes as a WARNING, not a hint. It was a line of
					muted grey among five other muted grey lines, which is where
					an operator's eye does not go — and the thing it reports is
					that closing this page loses work.
				-->
				<NcNoteCard v-if="editor.dirty" type="warning">
					{{ t('hermiq', 'Unsaved changes. Save before leaving this page.') }}
				</NcNoteCard>

				<!-- Auto-sort moves nothing but coordinates. See the layout
				     function: the node list, connections, types, configurations
				     and branch targets are identical before and after, which is
				     what makes this safe to press on a flow that works. -->
				<NcButton type="tertiary" :disabled="editor.nodes.length === 0" @click="editor.autoSort()">
					<template #icon>
						<SortVariant :size="20" />
					</template>
					{{ t('hermiq', 'Auto sort') }}
				</NcButton>

				<!-- The engine's own preflight, not a second opinion: it builds
				     the same definition the run builds and calls each step's
				     validateConfig(), so a step written in another step's
				     dialect — which resolves, runs and reports COMPLETED while
				     doing nothing — is reported here instead of at 03:00. -->
				<NcNoteCard v-if="validationMessage" :type="editor.validation.valid ? 'success' : 'error'">
					{{ validationMessage }}
				</NcNoteCard>

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
import { NcAppSidebar, NcAppSidebarTab, NcButton, NcCheckboxRadioSwitch, NcLoadingIcon, NcNoteCard, NcSelect, NcTextField } from '@nextcloud/vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import CheckDecagram from 'vue-material-design-icons/CheckDecagram.vue'
import Cog from 'vue-material-design-icons/Cog.vue'
import ContentSave from 'vue-material-design-icons/ContentSave.vue'
import History from 'vue-material-design-icons/History.vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import SortVariant from 'vue-material-design-icons/SortVariant.vue'
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
		CheckDecagram,
		Cog,
		ContentSave,
		History,
		Magnify,
		Refresh,
		SortVariant,
		Pencil,
		NcAppSidebar,
		NcAppSidebarTab,
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
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
			// Which palette card is folded open. One at a time: the point of
			// expanding is to read one description, and several open at once
			// pushes the rest of the list off the pane.
			expandedType: '',
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
				// The catalogue already ships a per-node icon URL from the
				// contributing app — better than that app's generic icon,
				// because it distinguishes "Call a source" from "Run a
				// synchronization" rather than marking both as openconnector.
				icon: entry.icon || '',
				// The provider is the id's namespace. Which app a node came
				// from decides where it is documented and who to ask when it
				// misbehaves, and the catalogue mixes three of them.
				provider: String(entry.id || '').split('.')[0] || '',
				role: this.roleOfType(entry.id),
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
		 * A node TYPE's role in a flow: entry point, terminal, or ordinary.
		 *
		 * Derived from the type, which is the only thing a palette card can
		 * know — a card describes a type that is not on any canvas yet, so
		 * there is no topology to infer a role from.
		 *
		 * @param {string} type The node type id.
		 * @return {string} `'trigger'`, `'terminal'` or `'step'`.
		 *
		 * @spec openspec/specs/flow-canvas/spec.md#requirement-the-node-palette-is-a-card-per-type-and-the-card-explains-itself
		 */
		roleOfType(type) {
			const id = String(type || '')
			if (id.includes('.trigger-')) {
				return 'trigger'
			}

			if (id.endsWith('.stop')) {
				return 'terminal'
			}

			return 'step'
		},
		/**
		 * A role as a word, for the card.
		 *
		 * @param {string} role The role key.
		 * @return {string} The label.
		 *
		 * @spec openspec/specs/flow-canvas/spec.md#requirement-the-node-palette-is-a-card-per-type-and-the-card-explains-itself
		 */
		roleWord(role) {
			if (role === 'trigger') {
				return this.t('hermiq', 'starts')
			}

			if (role === 'terminal') {
				return this.t('hermiq', 'ends')
			}

			return this.t('hermiq', 'step')
		},

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

.flow-sidebar__run-head--replayed {
	border-color: var(--color-success, #46ba61);
}

.flow-sidebar__run-replay {
	margin-inline-start: auto;
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
	gap: 2px;
	padding: 8px 10px;
	border: 1px solid var(--color-border);
	/* Role as a left stripe. The WORD is on the card too — a colour-only code
	   is unreadable in greyscale and to a reader who cannot distinguish the
	   hues (WCAG 2.1 AA 1.4.1). */
	border-left-width: 5px;
	border-radius: var(--border-radius-large);
	background-color: var(--color-main-background);
	cursor: grab;
}

.flow-sidebar__palette-card--trigger {
	border-left-color: var(--color-success, #46ba61);
}

.flow-sidebar__palette-card--terminal {
	border-left-color: var(--color-error, #e9322d);
}

.flow-sidebar__palette-card--step {
	border-left-color: var(--color-primary-element);
}

.flow-sidebar__palette-head {
	display: flex;
	align-items: center;
	gap: 8px;
	width: 100%;
	border: none;
	background: transparent;
	color: var(--color-main-text);
	padding: 0;
	text-align: start;
}

.flow-sidebar__palette-icon {
	width: 16px;
	height: 16px;
	flex: 0 0 auto;
}

.flow-sidebar__palette-role {
	margin-inline-start: auto;
	color: var(--color-text-maxcontrast);
	font-size: 0.8em;
	text-transform: uppercase;
	letter-spacing: 0.04em;
}

.flow-sidebar__palette-actions {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
	margin-top: 6px;
}

.flow-sidebar__palette-provider {
	color: var(--color-text-maxcontrast);
	font-size: 0.8em;
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
	margin: 0;
	display: -webkit-box;
	-webkit-line-clamp: 2;
	-webkit-box-orient: vertical;
	overflow: hidden;
}

/* Expanded: the whole description, in place. */
.flow-sidebar__palette-desc--full {
	-webkit-line-clamp: unset;
	overflow: visible;
}

.flow-sidebar__verbs {
	display: flex;
	gap: 8px;
}
</style>
