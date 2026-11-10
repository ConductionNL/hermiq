/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * What the dialogs opened from an agent page share. They are also the targets
 * of registry `open-modal` actions, whose props are static JSON: such a dialog
 * gets no agent prop, so it falls back to the route's `:id`. CnAppRoot mounts
 * it FRESH with `show` already `true`, which a plain watcher never sees, so the
 * load-on-open watcher is `immediate`.
 */

/**
 * The `show` and `agentId` props and the agent the dialog acts on.
 */
export const agentModalTarget = {
	props: {
		/** Whether the dialog is visible. */
		show: {
			type: Boolean,
			default: false,
		},

		/** The agent; when absent, the route's `:id` (open-modal props are static). */
		agentId: {
			type: String,
			default: '',
		},
	},

	computed: {
		/**
		 * The agent uuid: the prop, else the route's `:id`.
		 *
		 * @return {string} The agent uuid.
		 * @spec openspec/specs/agent-management-ui/spec.md#requirement-an-agent-can-be-switched-off-and-on-without-deleting-it-req-agoff-001
		 */
		resolvedAgentId() {
			return this.agentId || this.$route?.params?.id || ''
		},
	},
}

/**
 * Call the component's `load()` each time the dialog is shown, including when
 * it is mounted already open.
 */
export const loadWhenShown = {
	watch: {
		show: {
			immediate: true,
			/**
			 * Load each time the dialog opens.
			 *
			 * @param {boolean} open Whether it is open.
			 * @return {void}
			 * @spec openspec/specs/agent-management-ui/spec.md#requirement-an-agent-can-be-switched-off-and-on-without-deleting-it-req-agoff-001
			 */
			handler(open) {
				if (open) {
					this.load()
				}
			},
		},
	},
}
