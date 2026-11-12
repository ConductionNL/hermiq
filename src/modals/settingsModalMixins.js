/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Behaviour the admin settings modals share: loading their configuration each
 * time they open, and listing the caller's broker credentials.
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * A modal that (re)loads its configuration whenever it is shown: a `show` prop,
 * and `load()` called when it turns true and on mount when already open. The
 * component provides `load()`.
 */
export const reloadOnOpen = {
	props: {
		/**
		 * Whether the modal is visible.
		 *
		 * @spec exclude Trivial visibility prop; no behavioural spec.
		 */
		show: {
			type: Boolean,
			default: false,
		},
	},

	watch: {
		/**
		 * Reload the config each time the modal is opened.
		 *
		 * @param {boolean} visible Whether the modal became visible.
		 * @return {void}
		 *
		 * @spec exclude Trivial watch handler delegating to load(); no behavioural spec.
		 */
		show(visible) {
			if (visible) {
				this.load()
			}
		},
	},

	/**
	 * Load the config when the modal mounts already open.
	 *
	 * @return {void}
	 *
	 * @spec exclude Trivial lifecycle hook delegating to load(); no behavioural spec.
	 */
	mounted() {
		if (this.show) {
			this.load()
		}
	},
}

/**
 * Lists the caller's broker credentials into `credentials`, with
 * `loadingCredentials` set while it runs. The component declares both in its
 * data.
 */
export const brokerCredentials = {
	methods: {
		/**
		 * Load the user's broker credentials.
		 *
		 * The endpoint already scopes to the caller's own credentials, and the response
		 * carries no secrets — only names, providers and UUIDs.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/llm-keys-via-broker/tasks.md#task-5-admin-ui
		 */
		async fetchCredentials() {
			this.loadingCredentials = true
			try {
				const { data } = await axios.get(
					generateUrl('/apps/openregister/api/credentials'),
				)
				this.credentials = data.results || []
			} catch {
				this.credentials = []
			} finally {
				this.loadingCredentials = false
			}
		},
	},
}
