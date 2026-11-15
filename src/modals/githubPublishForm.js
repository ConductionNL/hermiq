/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The "Publish to GitHub" form the agent template and skill modals share:
 * owner, repository, visibility and a broker `github` credential, read on
 * mount. The GitHub token never reaches Hermiq; the component's own
 * `publishToGithub(payload)` sends the coordinates to its guarded endpoint.
 * A successful publish emits `published` with the created repository URL.
 */

import { errorMessage } from '../utils/errorMessage.js'
import { brokerCredentials, githubCredentialChoices } from './settingsModalMixins.js'

export const githubPublishForm = {
	mixins: [brokerCredentials, githubCredentialChoices],

	data() {
		return {
			form: {
				owner: '',
				repo: '',
			},
			visibility: { label: this.t('hermiq', 'Private'), value: 'private' },
			credential: null,
			credentials: [],
			loadingCredentials: false,
			publishing: false,
			publishError: '',
		}
	},

	computed: {
		/**
		 * The publish visibility options (mirrors OpenBuild's `ExportsController`
		 * default: new repos default to `private`).
		 *
		 * @return {Array<object>} NcSelect options.
		 *
		 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-let-a-template-owner-publish-it-to-a-new-tagged-github-repository
		 */
		visibilityOptions() {
			return [
				{ label: this.t('hermiq', 'Private'), value: 'private' },
				{ label: this.t('hermiq', 'Public'), value: 'public' },
			]
		},

		/**
		 * Whether the publish form has everything it needs to submit.
		 *
		 * @return {boolean} True when the form can be submitted.
		 *
		 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-validate-repo-coordinates-before-any-github-call
		 */
		canPublish() {
			return (
				this.form.owner.trim() !== ''
				&& this.form.repo.trim() !== ''
				&& !!this.credential
			)
		},
	},

	mounted() {
		this.fetchCredentials()
	},

	methods: {
		/**
		 * Publish to a new GitHub repository through the component's endpoint.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-let-a-template-owner-publish-it-to-a-new-tagged-github-repository
		 */
		async doPublish() {
			this.publishing = true
			this.publishError = ''
			try {
				const result = await this.publishToGithub({
					owner: this.form.owner.trim(),
					repo: this.form.repo.trim(),
					visibility: this.visibility?.value || 'private',
					credentialId: this.credential?.value,
				})
				this.$emit('published', result.repoUrl)
			} catch (e) {
				this.publishError = errorMessage(e, this.t('hermiq', 'Unknown error'))
			} finally {
				this.publishing = false
			}
		},
	},
}
