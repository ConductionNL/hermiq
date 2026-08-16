<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  AgentToolGovernanceWidget — combines the tool-grant editor and the
  tool-invocation audit history as one type:"detail" custom widget
  (manifest-driven-pages).

  Both are read/write surfaces over the SAME capability (ADR-063 derived tool
  catalogue: grants + the audit trail of their use) — merged into one widget so
  the grid gets one coherent "tool governance" cell instead of two thin strips
  (design.md Decision 3). Self-fetches the agent id from `$route.params.id`
  and the agent object (for the owner-only `canEdit` gate on ToolGrantEditor —
  the server enforces owner-only regardless of this client-side hint).

  agent-detail-redesign (2026-08-04): the two surfaces are now TABBED rather than
  stacked. Stacked, this widget was the page's single worst cell — it needed
  gridHeight 14 (1120px measured) because it rendered a ~100-row tool catalogue
  AND the full invocation audit trail one under the other, which is what forced
  every widget below it 14 rows down the page. They are peers over one
  capability, not a sequence: you are either granting tools or auditing their
  use, never reading both at once. Tabbing halves the cell to the height of the
  TALLER surface instead of their SUM, and each surface keeps its own internal
  scroll region (both lists are unbounded, so no fixed gridHeight can contain
  them — ADR-062's "fills its gridHeight exactly" is only satisfiable for an
  unbounded list by scrolling it internally).

  The tablist is a real one: roving tabindex, ArrowLeft/ArrowRight/Home/End,
  aria-selected + aria-controls, and panels wired back with aria-labelledby. The
  children's own <h3> headings are hidden here (the tab label already names the
  panel, and the panel is labelled by its tab) but left in the components so
  they stay meaningful if ever hosted standalone.

  @spec openspec/specs/manifest-driven-pages/spec.md#requirement-a-tool-governance-custom-widget-must-combine-tool-grants-and-tool-activity-audit-history
-->
<template>
	<div class="agent-tool-governance-widget">
		<!--
			The section heading lives INSIDE the widget (and the manifest sets
			showTitle:false for this cell) rather than being drawn by the page
			chrome. Measured: with the chrome title on, it is a SIBLING of this
			root inside a display:block grid item, so `height:100%` here resolved
			against the full 616px box and ignored the ~30px title above it —
			616 + 30 = 646 in a 640px cell, a permanent 6px overflow that no
			gridHeight could remove. Owning the heading puts it inside this flex
			column, so the whole widget fits its cell exactly (ADR-062).
		-->
		<h3 class="agent-tool-governance-widget__title">
			{{ t('hermiq', 'Tool governance') }}
		</h3>

		<div
			ref="tablist"
			class="agent-tool-governance-widget__tablist"
			role="tablist"
			:aria-label="t('hermiq', 'Tool governance sections')">
			<button
				v-for="(tab, index) in tabs"
				:id="`${uid}-tab-${tab.id}`"
				:key="tab.id"
				type="button"
				role="tab"
				class="agent-tool-governance-widget__tab"
				:class="{
					'agent-tool-governance-widget__tab--active':
						activeTab === tab.id,
				}"
				:aria-selected="activeTab === tab.id ? 'true' : 'false'"
				:aria-controls="`${uid}-panel-${tab.id}`"
				:tabindex="activeTab === tab.id ? 0 : -1"
				@click="activeTab = tab.id"
				@keydown="onTabKeydown($event, index)">
				{{ tab.label }}
			</button>
		</div>

		<div
			v-show="activeTab === 'grants'"
			:id="`${uid}-panel-grants`"
			class="agent-tool-governance-widget__panel"
			role="tabpanel"
			:aria-labelledby="`${uid}-tab-grants`">
			<ToolGrantEditor
				:agentId="agentId"
				:canEdit="isOwner"
				@saved="onGrantsSaved" />
		</div>

		<div
			v-show="activeTab === 'activity'"
			:id="`${uid}-panel-activity`"
			class="agent-tool-governance-widget__panel"
			role="tabpanel"
			:aria-labelledby="`${uid}-tab-activity`">
			<ToolInvocationTable :agentId="agentId" />
		</div>
	</div>
</template>

<script>
import { getCurrentUser } from '@nextcloud/auth'
// Explicit import: `t` is a template-only global here (installed on
// app.config.globalProperties), so a bare t() inside a computed would be a
// ReferenceError. The tab labels are built in script, hence the import.
import { translate as t } from '@nextcloud/l10n'
import ToolGrantEditor from '../components/ToolGrantEditor.vue'
import ToolInvocationTable from '../components/ToolInvocationTable.vue'
import { useAgentStore } from '../store/store.js'

let widgetUid = 0

export default {
	name: 'AgentToolGovernanceWidget',

	components: {
		ToolGrantEditor,
		ToolInvocationTable,
	},

	data() {
		return {
			agent: null,
			activeTab: 'grants',
			uid: `agent-tool-governance-${++widgetUid}`,
		}
	},

	computed: {
		/**
		 * This agent's uuid from the route param.
		 *
		 * @return {string} The agent uuid.
		 */
		agentId() {
			return this.$route.params.id
		},

		/**
		 * The two peer surfaces, in tab order.
		 *
		 * @return {Array<{id: string, label: string}>} Tab descriptors.
		 */
		tabs() {
			return [
				{ id: 'grants', label: t('hermiq', 'Tool grants') },
				{ id: 'activity', label: t('hermiq', 'Tool activity') },
			]
		},

		/**
		 * Whether the current user owns this agent — the grant editor is
		 * read-only for everyone else (the server enforces owner-only on PUT
		 * .../tool-grants regardless; this only avoids offering an action that
		 * would be refused).
		 *
		 * `owner` is OBJECT METADATA, so it lives under `@self` — not on the
		 * object body. This reads `agent.owner` as a fallback only: the store
		 * returns an OpenRegister object, whose own properties are the agent's
		 * schema fields (name, model, tools, ...) and never include the owner.
		 * Reading the top level alone made this `undefined === uid` for
		 * EVERY user, so the grant editor was permanently read-only — the owner
		 * included, which is the only person it is meant to be writable for.
		 *
		 * @return {boolean} True when the current user is the agent's owner.
		 */
		isOwner() {
			const user = getCurrentUser()
			if (!user || !this.agent) {
				return false
			}
			const owner = this.agent['@self']?.owner ?? this.agent.owner
			return !!owner && owner === user.uid
		},
	},

	created() {
		this.agentStore = useAgentStore()
		this.agentStore.registerObjectType('agent', 'agent', 'hermiq')
		this.loadAgent()
	},

	methods: {
		/**
		 * Roving-tabindex keyboard navigation across the tablist (WCAG 2.2 AA /
		 * APG tabs pattern): arrows move AND activate, Home/End jump to the ends.
		 *
		 * @param {KeyboardEvent} event The keydown event.
		 * @param {number}        index Index of the tab the event fired on.
		 *
		 * @return {void}
		 */
		onTabKeydown(event, index) {
			const last = this.tabs.length - 1
			let next = null

			if (event.key === 'ArrowRight') {
				next = index === last ? 0 : index + 1
			} else if (event.key === 'ArrowLeft') {
				next = index === 0 ? last : index - 1
			} else if (event.key === 'Home') {
				next = 0
			} else if (event.key === 'End') {
				next = last
			}

			if (next === null) {
				return
			}

			event.preventDefault()
			this.activeTab = this.tabs[next].id
			this.$nextTick(() => {
				const buttons = this.$refs.tablist?.querySelectorAll('[role="tab"]')
				buttons?.[next]?.focus()
			})
		},

		/**
		 * Load this agent (only used to resolve `owner` for the isOwner gate).
		 *
		 * @return {Promise<void>}
		 */
		async loadAgent() {
			this.agent = await this.agentStore
				.fetchObject('agent', this.agentId)
				.catch(() => null)
		},

		/**
		 * Reload the agent after tool grants are saved (agent's `tools` display
		 * on the sibling data widget reflects the change after its own reload).
		 *
		 * @return {Promise<void>}
		 */
		async onGrantsSaved() {
			await this.loadAgent()
		},
	},
}
</script>

<style scoped>
/* box-sizing + overflow:hidden so the widget can never exceed its grid cell
   (ADR-062). Measured at gridHeight 8 the root stood 6px proud of the 640px
   cell — a constant chrome offset (tablist border + panel padding), not one
   proportional to the height, so growing gridHeight would not have removed it.
   Clipping here is lossless: the panels below scroll internally. */
.agent-tool-governance-widget {
	box-sizing: border-box;
	display: flex;
	flex-direction: column;
	height: 100%;
	min-height: 0;
	overflow: hidden;
}

.agent-tool-governance-widget__title {
	flex: 0 0 auto;
	font-size: 1rem;
	font-weight: 600;
	margin: 0 0 8px;
}

.agent-tool-governance-widget__tablist {
	display: flex;
	gap: 4px;
	border-bottom: 1px solid var(--color-border);
	flex: 0 0 auto;
}

.agent-tool-governance-widget__tab {
	appearance: none;
	background: transparent;
	border: none;
	border-bottom: 2px solid transparent;
	border-radius: var(--border-radius) var(--border-radius) 0 0;
	color: var(--color-text-maxcontrast);
	cursor: pointer;
	font-size: var(--default-font-size);
	font-weight: 500;
	padding: 8px 16px;
}

.agent-tool-governance-widget__tab:hover {
	background-color: var(--color-background-hover);
	color: var(--color-main-text);
}

.agent-tool-governance-widget__tab:focus-visible {
	outline: 2px solid var(--color-primary-element);
	outline-offset: -2px;
}

.agent-tool-governance-widget__tab--active {
	border-bottom-color: var(--color-primary-element);
	color: var(--color-main-text);
}

/* Each panel owns the remaining height and scrolls internally: both lists are
   unbounded (the instance-wide tool catalogue is ~100 rows), so the cell can
   only "fill its gridHeight exactly" per ADR-062 if the list scrolls in place. */
.agent-tool-governance-widget__panel {
	flex: 1 1 auto;
	min-height: 0;
	overflow-y: auto;
	padding-top: 12px;
}

/* The tab label already names the panel (and labels it via aria-labelledby),
   so the child's own heading would be a visible duplicate. */
.agent-tool-governance-widget__panel :deep(.tool-grants__title),
.agent-tool-governance-widget__panel :deep(.tool-oversight__title) {
	display: none;
}
</style>
