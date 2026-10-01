<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  AgentDeleteDialog: the agent catalog's delete confirmation, naming the
  schedules that go with the agent (agents-switch-off-and-stop).

  Mounted by AgentCatalog's `slots.delete-dialog` in place of CnIndexPage's
  generic one. Deleting an agent deletes its schedules (Schedule.agentId is
  onDelete CASCADE), so the confirmation says how many.

  @spec openspec/specs/agent-management-ui/spec.md#requirement-deleting-an-agent-removes-its-schedules-req-agoff-004
-->
<template>
	<CnDeleteDialog
		v-if="show && item"
		:item="item"
		:dialogTitle="t('hermiq', 'Delete agent')"
		:warningText="warningText"
		data-testid="agent-delete-dialog"
		@confirm="onConfirm"
		@close="close()" />
</template>

<script>
import { CnDeleteDialog } from '@conduction/nextcloud-vue'
import { getAvailability } from '../api/agentAvailability.js'

export default {
	name: 'AgentDeleteDialog',

	components: {
		CnDeleteDialog,
	},

	props: {
		/** Whether the dialog is visible. */
		show: {
			type: Boolean,
			default: false,
		},

		/** The agent row to delete. */
		item: {
			type: Object,
			default: null,
		},

		/** Performs the delete: CnIndexPage's own path. */
		confirm: {
			type: Function,
			required: true,
		},

		/** Closes the dialog. */
		close: {
			type: Function,
			required: true,
		},
	},

	data() {
		return {
			scheduleCount: 0,
		}
	},

	computed: {
		/**
		 * The warning, with the schedules that go with the agent.
		 *
		 * @return {string} The warning; `{name}` is filled in by CnDeleteDialog.
		 * @spec openspec/specs/agent-management-ui/spec.md#requirement-deleting-an-agent-removes-its-schedules-req-agoff-004
		 */
		warningText() {
			const base = this.t('hermiq', 'Delete "{name}"? This cannot be undone.')
			if (this.scheduleCount === 1) {
				return (
					base
					+ ' '
					+ this.t('hermiq', '1 schedule is deleted with this agent.')
				)
			}
			if (this.scheduleCount > 1) {
				return (
					base
					+ ' '
					+ this.t(
						'hermiq',
						'{count} schedules are deleted with this agent.',
						{ count: this.scheduleCount },
					)
				)
			}
			return base
		},
	},

	watch: {
		item: {
			immediate: true,
			/**
			 * Count the agent's schedules when a row is picked for deletion.
			 *
			 * @return {Promise<void>}
			 * @spec openspec/specs/agent-management-ui/spec.md#requirement-deleting-an-agent-removes-its-schedules-req-agoff-004
			 */
			async handler() {
				this.scheduleCount = 0
				const id =
					this.item?.id || this.item?.uuid || this.item?.['@self']?.id
				if (!id) {
					return
				}
				try {
					const state = await getAvailability(id)
					this.scheduleCount = Number(state?.scheduleCount) || 0
				} catch {
					// The count is information, not a guard: the delete still works.
					this.scheduleCount = 0
				}
			},
		},
	},

	methods: {
		/**
		 * Delete through CnIndexPage's path, then close.
		 *
		 * @param {string} id The agent.
		 * @return {Promise<void>}
		 * @spec openspec/specs/agent-management-ui/spec.md#requirement-deleting-an-agent-removes-its-schedules-req-agoff-004
		 */
		async onConfirm(id) {
			await this.confirm(id)
			this.close()
		},
	},
}
</script>
