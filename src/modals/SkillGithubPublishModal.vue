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
			<NcNoteCard v-if="publishError" type="error">
				{{ publishError }}
			</NcNoteCard>
			<NcTextField
				v-model="form.owner"
				:label="t('hermiq', 'Owner')"
				:placeholder="t('hermiq', 'e.g. acme-council')" />
			<NcTextField
				v-model="form.repo"
				:label="t('hermiq', 'Repository name')"
				:placeholder="t('hermiq', 'e.g. demo-skill')" />
			<NcSelect
				v-model="visibility"
				:options="visibilityOptions"
				:inputLabel="t('hermiq', 'Visibility')"
				:clearable="false"
				label="label"
				trackBy="value" />
			<NcSelect
				v-model="credential"
				:options="githubCredentials"
				:inputLabel="t('hermiq', 'GitHub credential')"
				:loading="loadingCredentials"
				:placeholder="t('hermiq', 'Select a credential')"
				label="label" />
			<p
				v-if="!loadingCredentials && githubCredentials.length === 0"
				class="skill-github-publish-modal__hint">
				{{
					t(
						'hermiq',
						'No GitHub credential yet. Add a personal one under your Personal settings, or ask an organisation admin to add one under the Hermiq admin settings, then reopen this dialog.',
					)
				}}
			</p>
			<NcButton
				type="primary"
				:disabled="publishing || !canPublish"
				@click="doPublish">
				{{
					publishing
						? t('hermiq', 'Publishing…')
						: t('hermiq', 'Publish')
				}}
			</NcButton>
		</div>
	</NcModal>
</template>

<script>
import { NcButton, NcModal, NcNoteCard, NcSelect, NcTextField } from '@nextcloud/vue'
import { publishSkillToGithub } from '../api/skills.js'
import { githubPublishForm } from './githubPublishForm.js'

export default {
	name: 'SkillGithubPublishModal',

	components: {
		NcButton,
		NcModal,
		NcNoteCard,
		NcSelect,
		NcTextField,
	},

	mixins: [githubPublishForm],

	props: {
		/** The skill uuid the publish applies to. */
		skillId: {
			type: String,
			required: true,
		},
	},

	emits: ['close', 'published'],

	methods: {
		/**
		 * Publish this skill to a new GitHub repository tagged
		 * `topic:hermiq-skill` (hermiq-github-store — the primary publish
		 * path). A broker `github` credential is required — the GitHub token
		 * never reaches Hermiq.
		 *
		 * @spec openspec/specs/skills-marketplace/spec.md#requirement-a-skill-can-be-published-to-a-tagged-github-repository-as-the-primary-path
		 *
		 * @param {object} payload The owner, repo, visibility and credentialId.
		 * @return {Promise<object>} The endpoint's result, with `repoUrl`.
		 */
		publishToGithub(payload) {
			return publishSkillToGithub(this.skillId, payload)
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
