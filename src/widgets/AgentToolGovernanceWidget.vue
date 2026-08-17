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
		<!--
			Save and Reset live in the TITLE BAR, where every other widget on
			this page puts its actions, rather than floating above the table.
			They are the widget's actions, not the table's.
		-->
		<div class="agent-tool-governance-widget__titlebar">
			<h3 class="agent-tool-governance-widget__title">
				{{ t('hermiq', 'Tool grants') }}
			</h3>

			<div v-if="isOwner" class="agent-tool-governance-widget__actions">
				<NcButton
					variant="tertiary"
					:disabled="!dirty || saving"
					@click="onReset">
					{{ t('hermiq', 'Reset') }}
				</NcButton>
				<NcButton
					variant="primary"
					:disabled="!dirty || saving"
					@click="onSave">
					<template #icon>
						<NcLoadingIcon v-if="saving" :size="20" />
						<ContentSaveIcon v-else :size="20" />
					</template>
					{{ t('hermiq', 'Save') }}
				</NcButton>
			</div>
		</div>

		<p v-if="!isOwner" class="agent-tool-governance-widget__readonly">
			{{ t('hermiq', 'Only the agent’s owner can change its grants.') }}
		</p>

		<div class="agent-tool-governance-widget__panel">
			<ToolGrantMatrix
				ref="matrix"
				:agentId="agentId"
				:canEdit="isOwner"
				:saving="saving"
				@dirtyChanged="dirty = $event" />
		</div>
	</div>
</template>

<script>
import { getCurrentUser } from '@nextcloud/auth'
// Explicit import: `t` is a template-only global here (installed on
// app.config.globalProperties), so a bare t() inside a computed would be a
// ReferenceError.
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import ContentSaveIcon from 'vue-material-design-icons/ContentSave.vue'
import ToolGrantMatrix from '../components/ToolGrantMatrix.vue'
import { useAgentStore } from '../store/store.js'

let widgetUid = 0

export default {
	name: 'AgentToolGovernanceWidget',

	components: {
		ContentSaveIcon,
		NcButton,
		NcLoadingIcon,
		ToolGrantMatrix,
	},

	data() {
		return {
			agent: null,
			dirty: false,
			saving: false,
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
		 * Persist the matrix's draft, then refresh the agent so the sibling
		 * data widget's `tools` display reflects the change.
		 *
		 * @return {Promise<void>}
		 */
		async onSave() {
			this.saving = true
			try {
				await this.$refs.matrix.persist()
				showSuccess(t('hermiq', 'Tool grants saved'))
				await this.loadAgent()
			} catch (error) {
				showError(error.response?.data?.error ?? error.message)
			} finally {
				this.saving = false
			}
		},

		/**
		 * Discard the matrix's unsaved changes.
		 *
		 * @return {void}
		 */
		onReset() {
			this.$refs.matrix.reset()
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

/* Title and actions on one row, as every other widget on this page does it.
   The actions are pushed to the end rather than the title being stretched, so
   a long translated title does not shove the buttons off the edge. */
.agent-tool-governance-widget__titlebar {
	display: flex;
	align-items: center;
	gap: 8px;
	flex: 0 0 auto;
	margin-bottom: 8px;
}

.agent-tool-governance-widget__title {
	font-size: 1rem;
	font-weight: 600;
	margin: 0;
}

.agent-tool-governance-widget__actions {
	display: flex;
	gap: 4px;
	margin-inline-start: auto;
}

.agent-tool-governance-widget__readonly {
	flex: 0 0 auto;
	margin: 0 0 8px;
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
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

/* The widget's own title bar names this panel, so a child heading would be a
   visible duplicate. */
.agent-tool-governance-widget__panel :deep(.tool-grants__title),
.agent-tool-governance-widget__panel :deep(.tool-oversight__title) {
	display: none;
}
</style>
