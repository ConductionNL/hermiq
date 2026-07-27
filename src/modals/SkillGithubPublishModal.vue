<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  SkillGithubPublishModal — the "Publish to GitHub" form opened from the
  Skills catalog row actions (hermiq-github-store). Own file per the
  modal-isolation rule; imported by SkillRowActions.vue.

  A form (owner/repo/visibility/credential) that calls the guarded
  `github/publish` endpoint — the PRIMARY skill-publish path. A broker
  `credentialId` is required; the GitHub token never reaches Hermiq —
  mirrors AgentTemplateRowActions' publish-to-GitHub form exactly. Emits
  `published` with the endpoint's `repoUrl` so the parent shows the notice
  and refreshes the list.

  @spec openspec/specs/skills-marketplace/spec.md#requirement-a-skill-can-be-published-to-a-tagged-github-repository-as-the-primary-path
-->
<template>
	<NcModal @close="$emit('close')">
		<div class="skill-github-publish-modal">
			<h3>{{ t('hermiq', 'Publish skill to GitHub') }}</h3>
			<NcNoteCard v-if="githubPublishError" type="error">
				{{ githubPublishError }}
			</NcNoteCard>
			<NcTextField :value.sync="githubPublishForm.owner"
				:label="t('hermiq', 'Owner')"
				:placeholder="t('hermiq', 'e.g. acme-council')" />
			<NcTextField :value.sync="githubPublishForm.repo"
				:label="t('hermiq', 'Repository name')"
				:placeholder="t('hermiq', 'e.g. demo-skill')" />
			<NcSelect v-model="githubPublishVisibility"
				:options="visibilityOptions"
				:input-label="t('hermiq', 'Visibility')"
				:clearable="false"
				label="label"
				track-by="value" />
			<NcSelect v-model="githubPublishCredential"
				:options="githubCredentials"
				:input-label="t('hermiq', 'GitHub credential')"
				:loading="loadingGithubCredentials"
				:placeholder="t('hermiq', 'Select a credential')"
				label="label" />
			<p v-if="!loadingGithubCredentials && githubCredentials.length === 0" class="skill-github-publish-modal__hint">
				{{ t('hermiq', 'No GitHub credential yet. Add a personal one under your Personal settings, or ask an organisation admin to add one under the Hermiq admin settings, then reopen this dialog.') }}
			</p>
			<NcButton
				type="primary"
				:disabled="githubPublishing || !canGithubPublish"
				@click="doGithubPublish">
				{{ githubPublishing ? t('hermiq', 'Publishing…') : t('hermiq', 'Publish') }}
			</NcButton>
		</div>
	</NcModal>
</template>

<script>
import { NcButton, NcModal, NcNoteCard, NcSelect, NcTextField } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { publishSkillToGithub } from '../api/skills.js'

export default {
	name: 'SkillGithubPublishModal',

	components: {
		NcButton,
		NcModal,
		NcNoteCard,
		NcSelect,
		NcTextField,
	},

	props: {
		/** The skill uuid the publish applies to. */
		skillId: {
			type: String,
			required: true,
		},
	},

	emits: ['close', 'published'],

	data() {
		return {
			githubPublishing: false,
			githubPublishError: '',
			githubPublishForm: {
				owner: '',
				repo: '',
			},
			githubPublishVisibility: { label: this.t('hermiq', 'Private'), value: 'private' },
			githubPublishCredential: null,
			githubCredentialsList: [],
			loadingGithubCredentials: false,
		}
	},

	computed: {
		/**
		 * The publish visibility options (hermiq-github-store, mirrors
		 * AgentTemplateRowActions' `visibilityOptions`).
		 *
		 * @spec openspec/specs/skills-marketplace/spec.md#requirement-a-skill-can-be-published-to-a-tagged-github-repository-as-the-primary-path
		 * @return {Array<object>} NcSelect options.
		 */
		visibilityOptions() {
			return [
				{ label: this.t('hermiq', 'Private'), value: 'private' },
				{ label: this.t('hermiq', 'Public'), value: 'public' },
			]
		},

		/**
		 * The caller's broker credentials scoped to the `github` provider
		 * (hermiq-github-store).
		 *
		 * @spec openspec/specs/skills-marketplace/spec.md#requirement-a-skill-can-be-published-to-a-tagged-github-repository-as-the-primary-path
		 * @return {Array<object>} NcSelect options.
		 */
		githubCredentials() {
			return this.githubCredentialsList
				.filter((c) => c.provider === 'github')
				.map((c) => ({ label: c.name || c.id, value: c.id }))
		},

		/**
		 * Whether the "Publish to GitHub" form has everything it needs to submit.
		 *
		 * @spec openspec/specs/skills-marketplace/spec.md#requirement-a-skill-can-be-published-to-a-tagged-github-repository-as-the-primary-path
		 * @return {boolean}
		 */
		canGithubPublish() {
			return this.githubPublishForm.owner.trim() !== '' && this.githubPublishForm.repo.trim() !== '' && !!this.githubPublishCredential
		},
	},

	mounted() {
		this.fetchGithubCredentials()
	},

	methods: {
		/**
		 * Load the caller's broker credentials (for the GitHub credential
		 * picker) (hermiq-github-store).
		 *
		 * @spec openspec/specs/skills-marketplace/spec.md#requirement-a-skill-can-be-published-to-a-tagged-github-repository-as-the-primary-path
		 * @return {Promise<void>}
		 */
		async fetchGithubCredentials() {
			this.loadingGithubCredentials = true
			try {
				const { data } = await axios.get(generateUrl('/apps/openregister/api/credentials'))
				this.githubCredentialsList = data.results || []
			} catch (e) {
				this.githubCredentialsList = []
			} finally {
				this.loadingGithubCredentials = false
			}
		},

		/**
		 * Publish this skill to a new GitHub repository tagged
		 * `topic:hermiq-skill` (hermiq-github-store — the primary publish
		 * path). A broker `github` credential is required — the GitHub token
		 * never reaches Hermiq. Emits `published` with the repo URL.
		 *
		 * @spec openspec/specs/skills-marketplace/spec.md#requirement-a-skill-can-be-published-to-a-tagged-github-repository-as-the-primary-path
		 * @return {Promise<void>}
		 */
		async doGithubPublish() {
			this.githubPublishing = true
			this.githubPublishError = ''
			try {
				const result = await publishSkillToGithub(this.skillId, {
					owner: this.githubPublishForm.owner.trim(),
					repo: this.githubPublishForm.repo.trim(),
					visibility: this.githubPublishVisibility?.value || 'private',
					credentialId: this.githubPublishCredential?.value,
				})
				this.$emit('published', result.repoUrl)
			} catch (e) {
				this.githubPublishError = e?.response?.data?.error || e?.message || this.t('hermiq', 'Unknown error')
			} finally {
				this.githubPublishing = false
			}
		},
	},
}
</script>

<style scoped>
.skill-github-publish-modal {
	padding: 20px;
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.skill-github-publish-modal__hint {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	margin: -4px 0 0;
}
</style>
