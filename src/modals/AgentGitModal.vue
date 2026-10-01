<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  AgentGitModal: "Keep in git" on the agent page.

  An agent not kept in git offers "Publish to GitHub" (a new repository, stamped on
  the agent). Once stamped it offers "Push changes" and "Pull from git": the pull
  shows what would change and writes only after the owner confirms. The repository
  comes from the agent's stamp, never from this form. The GitHub token stays in the
  credential broker; this modal only picks a credential.

  @spec openspec/specs/agent-template-github-store/spec.md#requirement-an-agent-owner-can-keep-an-agent-in-a-git-repository-req-agexp-004
-->
<template>
	<NcModal v-if="show" size="normal" :noClose="busy" @close="$emit('close')">
		<div class="agent-git">
			<h2 class="agent-git__title">
				{{ t('hermiq', 'Keep in git') }}
			</h2>

			<p v-if="link">
				{{ t('hermiq', 'This agent is kept in') }}
				<a :href="link" target="_blank" rel="noopener noreferrer">{{
					link
				}}</a>
			</p>
			<p v-else>
				{{
					t(
						'hermiq',
						'Publish this agent to a new GitHub repository. Edit it there, then pull the changes back here.',
					)
				}}
			</p>

			<NcSelect
				v-model="credential"
				:options="githubCredentials"
				:inputLabel="t('hermiq', 'GitHub credential')"
				:loading="loadingCredentials"
				:placeholder="t('hermiq', 'Select a credential')"
				label="label" />

			<template v-if="actions.includes('publish')">
				<NcTextField
					v-model="githubOwner"
					:label="t('hermiq', 'GitHub owner (user or organisation)')" />
				<NcTextField
					v-model="repo"
					:label="t('hermiq', 'New repository name')" />
			</template>

			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>
			<NcNoteCard v-if="done" type="success">
				{{ done }}
			</NcNoteCard>

			<div v-if="changes !== null" class="agent-git__changes">
				<p v-if="changes.length === 0">
					{{ t('hermiq', 'The agent already matches the repository.') }}
				</p>
				<table v-else>
					<thead>
						<tr>
							<th scope="col">
								{{ t('hermiq', 'Field') }}
							</th>
							<th scope="col">
								{{ t('hermiq', 'Now') }}
							</th>
							<th scope="col">
								{{ t('hermiq', 'In git') }}
							</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="change in changes" :key="change.field">
							<td>{{ fieldLabel(change.field) }}</td>
							<td class="agent-git__value">
								{{ asText(change.from) }}
							</td>
							<td class="agent-git__value">
								{{ asText(change.to) }}
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<div class="agent-git__actions">
				<NcButton
					variant="tertiary"
					:disabled="busy"
					@click="$emit('close')">
					{{ t('hermiq', 'Close') }}
				</NcButton>
				<NcButton
					v-if="actions.includes('publish')"
					variant="primary"
					:disabled="busy || !credential || !githubOwner || !repo"
					@click="publish">
					{{ t('hermiq', 'Publish to GitHub') }}
				</NcButton>
				<NcButton
					v-if="actions.includes('push')"
					:disabled="busy || !credential"
					@click="push">
					{{ t('hermiq', 'Push changes') }}
				</NcButton>
				<NcButton
					v-if="actions.includes('pull') && changes === null"
					variant="primary"
					:disabled="busy || !credential"
					@click="preview">
					{{ t('hermiq', 'Pull from git') }}
				</NcButton>
				<NcButton
					v-if="changes !== null && changes.length > 0"
					variant="primary"
					:disabled="busy"
					@click="apply">
					{{ t('hermiq', 'Take these changes') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcModal, NcNoteCard, NcSelect, NcTextField } from '@nextcloud/vue'
import {
	applyAgentPull,
	previewAgentPull,
	publishAgentToGit,
	pushAgentToGit,
} from '../api/agents.js'
import { gitActions, repoLink } from '../utils/agentGit.js'

export default {
	name: 'AgentGitModal',

	components: {
		NcButton,
		NcModal,
		NcNoteCard,
		NcSelect,
		NcTextField,
	},

	props: {
		/** Whether the modal is visible. */
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

	emits: ['close'],

	data() {
		return {
			agent: {},
			credentials: [],
			credential: null,
			loadingCredentials: false,
			githubOwner: '',
			repo: '',
			busy: false,
			error: '',
			done: '',
			changes: null,
		}
	},

	computed: {
		/**
		 * The agent uuid: the prop, else the route's `:id`.
		 *
		 * @return {string}
		 */
		resolvedAgentId() {
			return this.agentId || this.$route?.params?.id || ''
		},

		/**
		 * The actions on offer for this agent.
		 *
		 * @return {Array<string>}
		 */
		actions() {
			return gitActions(this.agent)
		},

		/**
		 * The stamped repository's URL.
		 *
		 * @return {string}
		 */
		link() {
			return repoLink(this.agent)
		},

		/**
		 * The caller's GitHub credentials in the broker.
		 *
		 * @return {Array<object>} NcSelect options.
		 */
		githubCredentials() {
			return this.credentials
				.filter((c) => c.provider === 'github')
				.map((c) => ({ label: c.name || c.id, value: c.id }))
		},
	},

	watch: {
		show: {
			immediate: true,
			/**
			 * Load the agent and the credentials each time the modal opens.
			 *
			 * @param {boolean} open Whether it is open.
			 * @return {void}
			 */
			handler(open) {
				if (open) {
					this.load()
				}
			},
		},
	},

	methods: {
		/**
		 * Read the agent's stamp and the caller's GitHub credentials.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-an-agent-owner-can-keep-an-agent-in-a-git-repository-req-agexp-004
		 */
		async load() {
			this.error = ''
			this.done = ''
			this.changes = null
			this.loadingCredentials = true
			try {
				const [agent, credentials] = await Promise.all([
					axios.get(
						generateUrl(
							`/apps/openregister/api/objects/hermiq/agent/${this.resolvedAgentId}`,
						),
					),
					axios.get(generateUrl('/apps/openregister/api/credentials')),
				])
				this.agent = agent.data || {}
				this.credentials = credentials.data?.results || []
			} catch {
				this.error = this.t('hermiq', 'Could not load this agent')
			} finally {
				this.loadingCredentials = false
			}
		},

		/**
		 * Publish to a new repository.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-an-agent-owner-can-keep-an-agent-in-a-git-repository-req-agexp-004
		 */
		async publish() {
			await this.attempt(async () => {
				const result = await publishAgentToGit(this.resolvedAgentId, {
					githubOwner: this.githubOwner,
					repo: this.repo,
					visibility: 'private',
					credentialId: this.credential?.value,
				})
				this.agent = {
					...this.agent,
					gitOwner: this.githubOwner,
					gitRepo: this.repo,
				}
				this.done = this.t('hermiq', 'Published to {url}', {
					url: result.repoUrl,
				})
			})
		},

		/**
		 * Push the agent to its repository.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-an-agent-owner-can-keep-an-agent-in-a-git-repository-req-agexp-004
		 */
		async push() {
			await this.attempt(async () => {
				await pushAgentToGit(this.resolvedAgentId, this.credential?.value)
				this.done = this.t('hermiq', 'Your changes are in the repository.')
			})
		},

		/**
		 * Show what a pull would change.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-edits-made-in-git-come-back-into-the-same-agent-after-a-diff-req-agexp-005
		 */
		async preview() {
			await this.attempt(async () => {
				const result = await previewAgentPull(
					this.resolvedAgentId,
					this.credential?.value,
				)
				this.changes = result.changes || []
			})
		},

		/**
		 * Take the shown changes: a new version of this agent.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-edits-made-in-git-come-back-into-the-same-agent-after-a-diff-req-agexp-005
		 */
		async apply() {
			await this.attempt(async () => {
				this.agent = await applyAgentPull(
					this.resolvedAgentId,
					this.credential?.value,
				)
				this.changes = null
				this.done = this.t(
					'hermiq',
					'The agent now matches the repository. Version history has the previous version.',
				)
			})
		},

		/**
		 * Run an action, showing the server's reason when it refuses.
		 *
		 * @param {Function} action The action.
		 * @return {Promise<void>}
		 */
		async attempt(action) {
			this.busy = true
			this.error = ''
			this.done = ''
			try {
				await action()
			} catch (e) {
				this.error =
					e?.response?.data?.error
					|| this.t('hermiq', 'Keep in git failed')
			} finally {
				this.busy = false
			}
		},

		/**
		 * A changed field's name in words.
		 *
		 * @param {string} field The agent field.
		 * @return {string}
		 */
		fieldLabel(field) {
			const labels = {
				name: this.t('hermiq', 'Name'),
				description: this.t('hermiq', 'Description'),
				type: this.t('hermiq', 'Category'),
				prompt: this.t('hermiq', 'Instructions'),
				provider: this.t('hermiq', 'Provider'),
				model: this.t('hermiq', 'Model'),
				tools: this.t('hermiq', 'Tools'),
			}
			return labels[field] || field
		},

		/**
		 * A value as text for the table.
		 *
		 * @param {*} value The value.
		 * @return {string}
		 */
		asText(value) {
			return Array.isArray(value) ? value.join(', ') : String(value ?? '')
		},
	},
}
</script>

<style scoped>
.agent-git {
	padding: 24px;
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.agent-git__title {
	margin: 0;
	font-size: 20px;
	font-weight: 600;
}

.agent-git__value {
	white-space: pre-wrap;
	word-break: break-word;
}

.agent-git__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	flex-wrap: wrap;
}
</style>
