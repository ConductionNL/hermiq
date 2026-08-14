<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  - SPDX-License-Identifier: EUPL-1.2
-->

<template>
	<CnIndexPage
		:title="t('hermiq', 'Flows')"
		:description="
			t(
				'hermiq',
				'Flows owned by Hermiq. A flow is a flow — the same definition the engine runs, stored once in OpenRegister.',
			)
		"
		:columns="columns"
		:objects="rows"
		:loading="editor.loading"
		:selectable="false"
		:showViewAction="false"
		:showEditAction="false"
		:actions="rowActions"
		rowClickToView
		@rowClick="open">
		<template #header-actions>
			<NcButton variant="primary" @click="$router.push('/flows/new')">
				<template #icon>
					<Plus :size="20" />
				</template>
				{{ t('hermiq', 'New flow') }}
			</NcButton>
		</template>
	</CnIndexPage>
</template>

<script>
import { CnIndexPage } from '@conduction/nextcloud-vue'
import { NcButton } from '@nextcloud/vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import { useFlowEditorStore } from '../store/flowEditor.js'

/**
 * FlowIndex — the list of hermiq's flows.
 *
 * A custom page rather than a manifest `type:index`, for one reason: a
 * `type:index` page is an OBJECT index, bound to a register and a schema, and a
 * flow is not an object. `flow-storage/spec.md` is explicit — "A flow
 * definition SHALL NOT be stored as an OpenRegister object" — so there is no
 * register/schema pair for this page to point at.
 *
 * It used to point at `hermiq/agentflow`, a duplicate mirror of the native flow
 * rows seeded alongside them. The list therefore showed objects while the
 * engine ran the native rows, and the two were free to drift — which they had.
 *
 * The rendering is still CnIndexPage: only the SOURCE differs, supplied through
 * its external `objects` prop from the same store the editor uses, so the list
 * and the canvas can never disagree about what exists.
 */
export default {
	name: 'FlowIndex',

	components: {
		CnIndexPage,
		NcButton,
		// Pencil is deliberately NOT registered: it is passed as an icon
		// COMPONENT in `rowActions`, never used as a tag in this template.
		Plus,
	},

	setup() {
		return { editor: useFlowEditorStore() }
	},

	computed: {
		/**
		 * The row-action menu: Edit, and only Edit.
		 *
		 * Both built-in actions are switched off rather than bound, because
		 * neither could reach this page's detail view:
		 *
		 * - The built-in **Edit** never emits. Its handler sets `editItem` and
		 *   opens CnIndexPage's schema-driven form dialog — a form over an
		 *   OpenRegister object. A flow is not an object (flow-storage/spec.md:
		 *   "A flow definition SHALL NOT be stored as an OpenRegister object"),
		 *   so this page passes no register/schema and the dialog had nothing
		 *   to render. Clicking Edit did nothing, visibly or in the console.
		 *   There is no `@edit` to bind instead — the emit is on the dialog's
		 *   save, not on the menu item — so the action has to be REPLACED.
		 *
		 * - The built-in **View** emits a dedicated `view` event that was never
		 *   bound here. It is dropped rather than wired: a flow has no
		 *   read-only detail page to view it in. The canvas IS the flow, and
		 *   offering two menu entries that land on the same editor invites the
		 *   reading that one of them is safe and the other is not.
		 *
		 * Row click still opens the same canvas via `row-click-to-view`, so the
		 * menu entry and the click agree.
		 *
		 * @return {Array<object>} The row actions.
		 *
		 * @spec openspec/specs/flow-canvas/spec.md
		 */
		rowActions() {
			return [
				{
					label: this.t('hermiq', 'Edit'),
					icon: Pencil,
					handler: (row) => this.open(row),
				},
			]
		},

		/**
		 * The columns, over the NATIVE flow fields.
		 *
		 * `cron` earns a column that the object mirror could not offer: most of
		 * these flows are scheduled, and "every 5 minutes" is the single most
		 * useful thing to know about one at a glance.
		 *
		 * @return {Array<object>} The column definitions.
		 *
		 * @spec openspec/specs/flow-canvas/spec.md
		 */
		columns() {
			return [
				{ key: 'name', label: this.t('hermiq', 'Name') },
				{ key: 'description', label: this.t('hermiq', 'Description') },
				{ key: 'trigger', label: this.t('hermiq', 'Trigger') },
				{ key: 'cron', label: this.t('hermiq', 'Schedule') },
				{ key: 'enabled', label: this.t('hermiq', 'Enabled') },
				// The two questions a list of scheduled flows exists to answer,
				// and neither could be answered here before: a flow refused for
				// a dead end produces NO run at all, so "refused" and "nobody
				// has triggered it" looked identical.
				{ key: 'lastRunLabel', label: this.t('hermiq', 'Last run') },
				{ key: 'statusLabel', label: this.t('hermiq', 'Status') },
			]
		},

		/**
		 * The flows, with the two run columns rendered for display.
		 *
		 * @return {Array<object>} The rows.
		 *
		 * @spec openspec/specs/flow-canvas/spec.md
		 */
		rows() {
			return (this.editor.flows || []).map((flow) => ({
				...flow,
				lastRunLabel: this.lastRunLabel(flow),
				statusLabel: this.statusLabel(flow),
			}))
		},
	},

	created() {
		// `load` with no routed id: the list needs the collection, and passing
		// `new` would also reset the editor's canvas to a blank flow, which is
		// exactly right for a page that is not editing one.
		this.editor.load('new')
	},

	methods: {
		/**
		 * When this flow last finished, in words.
		 *
		 * "Never" is a real answer and is shown as one. A dash would read as
		 * "unknown", and the difference matters: a flow that has never run is
		 * not the same as one whose history was not loaded.
		 *
		 * @param {object} flow The flow.
		 * @return {string} The label.
		 *
		 * @spec openspec/specs/flow-canvas/spec.md
		 */
		lastRunLabel(flow) {
			if (!flow.lastRunAt) {
				return this.t('hermiq', 'Never')
			}

			const when = new Date(flow.lastRunAt).toLocaleString()
			if (!flow.lastRunStatus) {
				return when
			}

			return `${flow.lastRunStatus} — ${when}`
		},

		/**
		 * The flow's own verdict, when something has judged it.
		 *
		 * Empty for a null status, which means "no verdict" rather than "ok" —
		 * claiming ok for a flow nothing has looked at is the false green this
		 * column exists to remove.
		 *
		 * @param {object} flow The flow.
		 * @return {string} The label.
		 *
		 * @spec openspec/specs/flow-canvas/spec.md
		 */
		statusLabel(flow) {
			if (!flow.status) {
				return ''
			}

			if (flow.status === 'error') {
				return this.t('hermiq', 'Will not run')
			}

			return this.t('hermiq', 'OK')
		},

		/**
		 * Open a flow on the canvas.
		 *
		 * @param {object} row The clicked flow.
		 * @return {void}
		 */
		open(row) {
			const id = row?.id || row?.uuid
			if (!id) {
				return
			}

			this.$router.push({ name: 'FlowDetail', params: { id: String(id) } })
		},
	},
}
</script>
