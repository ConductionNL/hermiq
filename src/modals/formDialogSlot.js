/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The CnIndexPage `#form-dialog` scoped-slot contract (agent-form-slot parity)
 * the form modals share: the `item`, `close` and `schema` bindings the slot
 * passes (`{ show, item, schema, close }`), and closing through both.
 */

export const formDialogSlot = {
	props: {
		/**
		 * The item being edited, or null in create mode: supplied by CnIndexPage's
		 * `#form-dialog` scoped slot.
		 */
		item: {
			type: Object,
			default: null,
		},

		/**
		 * Closes the host dialog: the `close` binding from CnIndexPage's
		 * `#form-dialog` scoped slot. Called in ADDITION to `$emit('close')`.
		 */
		close: {
			type: Function,
			default: null,
		},

		/**
		 * The effective JSON schema: the `schema` binding from CnIndexPage's
		 * `#form-dialog` scoped slot. Not consumed — the forms are hand-authored —
		 * but accepted so the slot binding lands without a Vue "extraneous
		 * non-prop attribute" warning.
		 */
		schema: {
			type: Object,
			default: null,
		},
	},

	methods: {
		/**
		 * Close the modal. Always emits `close`, and ADDITIONALLY invokes the
		 * `close` prop when supplied (CnIndexPage's `#form-dialog` scoped slot).
		 *
		 * @return {void}
		 * @spec openspec/changes/archive/2026-09-06-agent-management-ui/tasks.md#task-4-1
		 */
		handleClose() {
			this.$emit('close')
			this.close?.()
		},
	},
}
