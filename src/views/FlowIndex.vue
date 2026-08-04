<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  - SPDX-License-Identifier: EUPL-1.2
-->

<template>
	<CnIndexPage
		:title="t('hermiq', 'Graphs')"
		:description="t('hermiq', 'Flows owned by Hermiq. A graph is a flow — the same definition the engine runs, stored once in OpenRegister.')"
		:columns="columns"
		:objects="editor.graphs"
		:loading="editor.loading"
		:selectable="false"
		row-click-to-view
		@row-click="open">
		<template #header-actions>
			<NcButton type="primary" @click="$router.push('/graphs/new')">
				<template #icon>
					<Plus :size="20" />
				</template>
				{{ t('hermiq', 'New graph') }}
			</NcButton>
		</template>
	</CnIndexPage>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import { CnIndexPage } from '@conduction/nextcloud-vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import { useGraphEditorStore } from '../store/graphEditor.js'

/**
 * GraphIndex — the list of hermiq's flows.
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
	name: 'GraphIndex',

	components: {
		CnIndexPage,
		NcButton,
		Plus,
	},

	setup() {
		return { editor: useGraphEditorStore() }
	},

	computed: {
		/**
		 * The columns, over the NATIVE flow fields.
		 *
		 * `cron` earns a column that the object mirror could not offer: most of
		 * these flows are scheduled, and "every 5 minutes" is the single most
		 * useful thing to know about one at a glance.
		 *
		 * @return {Array<object>} The column definitions.
		 */
		columns() {
			return [
				{ key: 'name', label: this.t('hermiq', 'Name') },
				{ key: 'description', label: this.t('hermiq', 'Description') },
				{ key: 'trigger', label: this.t('hermiq', 'Trigger') },
				{ key: 'cron', label: this.t('hermiq', 'Schedule') },
				{ key: 'enabled', label: this.t('hermiq', 'Enabled') },
			]
		},
	},

	created() {
		// `load` with no routed id: the list needs the collection, and passing
		// `new` would also reset the editor's canvas to a blank graph, which is
		// exactly right for a page that is not editing one.
		this.editor.load('new')
	},

	methods: {
		/**
		 * Open a graph on the canvas.
		 *
		 * @param {object} row The clicked flow.
		 * @return {void}
		 */
		open(row) {
			const id = row?.id || row?.uuid
			if (!id) {
				return
			}

			this.$router.push({ name: 'GraphDetail', params: { id: String(id) } })
		},
	},
}
</script>
