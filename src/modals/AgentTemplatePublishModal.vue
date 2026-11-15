<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  AgentTemplatePublishModal — the "Publish to GitHub" form for an active
  AgentTemplate. Own file per the modal-isolation rule (gate-13); imported by
  AgentTemplateRowActions.vue, where it used to be written inline.

  Owns only its own form state and the credential list it needs to render the
  picker. A broker `credentialId` is required and the GitHub token never
  reaches Hermiq — the publish call goes to the guarded `publish-github`
  endpoint, which resolves the credential through OpenRegister's broker.

  Emits `published` with the created repository URL so the parent can show the
  notice and refresh the list; the parent owns both, because both outlive this
  component.

  @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-let-a-template-owner-publish-it-to-a-new-tagged-github-repository
-->
<template>
	<NcModal @close="$emit('close')">
		<div class="agent-template-publish-modal">
			<h3>{{ t('hermiq', 'Publish to GitHub') }}</h3>
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
				:placeholder="t('hermiq', 'e.g. morning-briefing-template')" />
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
				class="agent-template-publish-modal__hint">
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
					publishing ? t('hermiq', 'Publishing…') : t('hermiq', 'Publish')
				}}
			</NcButton>
		</div>
	</NcModal>
</template>

<script>
import { NcButton, NcModal, NcNoteCard, NcSelect, NcTextField } from '@nextcloud/vue'
import { publishAgentTemplateToGithub } from '../api/agentTemplates.js'
import { githubPublishForm } from './githubPublishForm.js'

export default {
	name: 'AgentTemplatePublishModal',

	components: {
		NcButton,
		NcModal,
		NcNoteCard,
		NcSelect,
		NcTextField,
	},

	mixins: [githubPublishForm],

	props: {
		/** The uuid of the template being published. */
		templateId: {
			type: String,
			required: true,
		},
	},

	emits: ['close', 'published'],

	methods: {
		/**
		 * Publish this template to a new GitHub repository tagged
		 * `topic:hermiq-agent-template`. A broker `github` credential is
		 * required — the GitHub token never reaches Hermiq.
		 *
		 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-let-a-template-owner-publish-it-to-a-new-tagged-github-repository
		 *
		 * @param {object} payload The owner, repo, visibility and credentialId.
		 * @return {Promise<object>} The endpoint's result, with `repoUrl`.
		 */
		publishToGithub(payload) {
			return publishAgentTemplateToGithub(this.templateId, payload)
		},
	},
}
</script>

<style scoped>
.agent-template-publish-modal {
	padding: 20px;
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.agent-template-publish-modal__hint {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	margin: -4px 0 0;
}
</style>
