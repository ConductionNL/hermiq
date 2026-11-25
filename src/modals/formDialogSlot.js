/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The CnIndexPage `#form-dialog` scoped-slot contract (agent-form-slot parity)
 * the form modals share: the `item`, `close`, `refresh` and `schema` bindings
 * the slot passes (`{ show, item, schema, close, refresh }`), closing through
 * both, and re-reading the list after a save.
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
		 * Re-reads the host list: the `refresh` binding from CnIndexPage's
		 * `#form-dialog` scoped slot. A dialog mounted from the manifest gets
		 * props only, so its `saved` event reaches no listener; without this
		 * call a record saved in the dialog did not appear in the list until
		 * the page was reloaded (seen live on Public chat, 25 Nov 2026).
		 */
		refresh: {
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

		/**
		 * Announce a save: emits `saved` and ADDITIONALLY re-reads the host list
		 * through the `refresh` prop when supplied.
		 *
		 * @param {object} saved The saved record.
		 * @return {void}
		 * @spec openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-an-organisation-admin-declares-a-public-chat-channel-req-pubchat-001
		 */
		announceSaved(saved) {
			this.$emit('saved', saved)
			this.refresh?.()
		},
	},
}
