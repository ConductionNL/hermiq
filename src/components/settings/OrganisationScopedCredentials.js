// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * CnCredentials for ONE chosen organisation.
 *
 * `CnCredentials` (nextcloud-vue 2.65) always works on the caller's active organisation:
 * it has no organisation prop. This extends it with one, and overrides only the two
 * methods that talk to the server, so the list and the create call carry the chosen
 * organisation. The template, the wizard and every other method stay the library's.
 * OpenRegister checks the caller's access to the organisation on both calls.
 *
 * Follow-up: move the `organisation` prop into CnCredentials itself, then drop this file.
 *
 * @spec openspec/changes/claude-provider-for-every-member/specs/claude-provider-for-every-member/spec.md#requirement-the-organisation-credential-form-shows-and-chooses-its-organisation
 */

import { CnCredentials } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

const CREDENTIALS_PATH = '/apps/openregister/api/credentials'
const PROVIDERS_PATH = '/apps/openregister/api/credentials/providers'

export default {
	name: 'OrganisationScopedCredentials',
	extends: CnCredentials,

	props: {
		/**
		 * The organisation UUID the list shows and new credentials are stored for.
		 *
		 * @type {string}
		 */
		organisation: {
			type: String,
			required: true,
		},
	},

	methods: {
		/**
		 * Load the chosen organisation's credentials and the provider catalogue.
		 *
		 * @return {Promise<void>}
		 */
		async load() {
			this.loading = true
			this.error = false
			try {
				const [credsRes, provRes] = await Promise.all([
					axios.get(generateUrl(CREDENTIALS_PATH), {
						params: {
							scope: 'organisation',
							organisation: this.organisation,
						},
					}),
					axios.get(generateUrl(PROVIDERS_PATH)),
				])
				this.credentials = this.mapCredentials(credsRes?.data?.results)
				this.providers = Array.isArray(provRes?.data?.results)
					? provRes.data.results
					: []
			} catch {
				this.error = true
				this.credentials = []
				this.providers = []
			} finally {
				this.loading = false
			}
		},

		/**
		 * Create a credential for the chosen organisation.
		 *
		 * @return {Promise<void>}
		 */
		async onCreate() {
			if (!this.canSubmit) {
				return
			}
			this.saving = true
			try {
				await axios.post(generateUrl(CREDENTIALS_PATH), {
					name: this.form.name.trim(),
					provider: this.form.provider,
					secret: this.form.secret,
					scope: 'organisation',
					organisation: this.organisation,
					allowedApps: this.form.allowedApps.length
						? this.form.allowedApps
						: this.appId
							? [this.appId]
							: [],
				})
				this.cancelAdd()
				await this.load()
			} catch {
				this.showError(t('hermiq', 'Could not save the credential'))
			} finally {
				this.saving = false
			}
		},
	},
}
