/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * A form dialog that starts from a fresh form each time it opens: a `show`
 * prop, and `resetForm()` called when it turns true. Mounting already open
 * does not reset. The component provides `resetForm()`.
 */

export const resetOnOpen = {
	props: {
		/** Whether the dialog is visible. */
		show: {
			type: Boolean,
			default: false,
		},
	},

	watch: {
		/**
		 * Reset the form each time the dialog is opened.
		 *
		 * @param {boolean} open Whether the dialog became visible.
		 * @return {void}
		 *
		 * @spec exclude Trivial watch handler delegating to resetForm(); no behavioural spec.
		 */
		show(open) {
			if (open) {
				this.resetForm()
			}
		},
	},
}
