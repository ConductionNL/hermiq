/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * What the admin settings blocks share: they load once when created, run each
 * call behind a `loading` or `busy` flag, and show a failed call's message in
 * place. The component provides `load()`.
 */

/**
 * The settings-block mixin: the `loading`, `busy` and `error` state, the load on
 * create, `withFlag()` around a call and `messageFor()` for its failure.
 */
export const settingsCall = {
	data() {
		return {
			loading: true,
			busy: false,
			error: '',
		}
	},

	/**
	 * Load the block's settings when it is created.
	 *
	 * @return {void}
	 *
	 * @spec exclude Trivial lifecycle hook delegating to load(); no behavioural spec.
	 */
	created() {
		this.load()
	},

	methods: {
		/**
		 * Run one call with a flag raised and the old error cleared; a failure is
		 * shown in `error`, and the flag drops either way.
		 *
		 * @param {string} flag The flag to raise: `loading` or `busy`.
		 * @param {() => Promise<void>} action The call.
		 * @return {Promise<void>}
		 *
		 * @spec exclude Shared busy/error plumbing of the settings blocks; no behavioural spec.
		 */
		async withFlag(flag, action) {
			this[flag] = true
			this.error = ''
			try {
				await action()
			} catch (e) {
				this.error = this.messageFor(e)
			} finally {
				this[flag] = false
			}
		},

		/**
		 * The server's own wording where there is one.
		 *
		 * @param {object} e The caught error.
		 * @return {string} The message to show.
		 *
		 * @spec exclude Shared error wording of the settings blocks; no behavioural spec.
		 */
		messageFor(e) {
			return (
				e?.response?.data?.error
				|| e?.message
				|| this.t('hermiq', 'Unknown error')
			)
		},
	},
}
