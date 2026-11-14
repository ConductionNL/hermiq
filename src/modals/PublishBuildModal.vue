<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  PublishBuildModal: "Publish build" on the agent page
  (tools-agent-workspace-and-installed-tools). The owner picks one of their own
  conversations with this agent and writes a note; the server archives that
  conversation's workspace with the agent's current version and pins the build.
  Only the publisher's own workspace can be chosen: the server keys it by the
  agent, the conversation and the signed-in user.

  @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-versioning/spec.md#requirement-an-agent-owner-can-publish-a-build-that-pins-files-and-installed-tools-req-build-001
-->
<template>
	<NcModal v-if="show" size="normal" :noClose="busy" @close="$emit('close')">
		<div class="publish-build">
			<h2 class="publish-build__title">
				{{ t('hermiq', 'Publish build') }}
			</h2>
			<p>
				{{
					t(
						'hermiq',
						'The files in the conversation\'s workspace and its installed tools are frozen in the build. Every new conversation with this agent starts from them. The instructions stay live.',
					)
				}}
			</p>

			<NcSelect
				v-model="session"
				:options="sessions"
				:inputLabel="t('hermiq', 'Conversation')"
				:loading="loadingSessions"
				:placeholder="t('hermiq', 'Select a conversation')"
				label="label"
				data-testid="publish-build-session" />

			<NcTextArea
				v-model="note"
				:label="t('hermiq', 'Note')"
				:maxlength="500"
				data-testid="publish-build-note" />

			<NcNoteCard v-if="!loadingSessions && sessions.length === 0" type="info">
				{{ t('hermiq', 'You have no conversation with this agent yet.') }}
			</NcNoteCard>
			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>

			<div class="publish-build__actions">
				<NcButton
					variant="tertiary"
					:disabled="busy"
					@click="$emit('close')">
					{{ t('hermiq', 'Cancel') }}
				</NcButton>
				<NcButton
					variant="primary"
					:disabled="busy || !session"
					data-testid="publish-build-submit"
					@click="submit">
					{{ t('hermiq', 'Publish build') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { NcButton, NcModal, NcNoteCard, NcSelect, NcTextArea } from '@nextcloud/vue'
import { publishAgentBuild } from '../api/agentBuilds.js'
import { listSessions } from '../api/chat.js'
import { errorMessage } from '../utils/errorMessage.js'

export default {
	name: 'PublishBuildModal',

	components: {
		NcButton,
		NcModal,
		NcNoteCard,
		NcSelect,
		NcTextArea,
	},

	props: {
		show: {
			type: Boolean,
			default: false,
		},

		agentId: {
			type: String,
			required: true,
		},
	},

	emits: ['close', 'published'],

	data() {
		return {
			sessions: [],
			session: null,
			note: '',
			loadingSessions: false,
			busy: false,
			error: '',
		}
	},

	watch: {
		show: {
			immediate: true,
			/**
			 * @param {boolean} open Whether the modal is open.
			 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-versioning/spec.md#requirement-an-agent-owner-can-publish-a-build-that-pins-files-and-installed-tools-req-build-001
			 */
			handler(open) {
				if (open) {
					this.reset()
					this.loadSessions()
				}
			},
		},
	},

	methods: {
		/**
		 * Clear the form.
		 *
		 * @return {void}
		 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-versioning/spec.md#requirement-an-agent-owner-can-publish-a-build-that-pins-files-and-installed-tools-req-build-001
		 */
		reset() {
			this.session = null
			this.note = ''
			this.error = ''
		},

		/**
		 * The caller's conversations with this agent, newest first as listed.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-versioning/spec.md#requirement-an-agent-owner-can-publish-a-build-that-pins-files-and-installed-tools-req-build-001
		 */
		async loadSessions() {
			this.loadingSessions = true
			try {
				const page = await listSessions({ limit: 100 })
				this.sessions = page.results
					.filter((row) => row && row.agentId === this.agentId && (row.uuid || row.id))
					.map((row) => ({
						id: row.uuid || row.id,
						label: row.title || this.t('hermiq', 'Untitled conversation'),
					}))
			} catch (e) {
				this.sessions = []
				this.error = errorMessage(e, this.t('hermiq', 'Your conversations could not be loaded.'))
			} finally {
				this.loadingSessions = false
			}
		},

		/**
		 * Publish the build and tell the panel.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-versioning/spec.md#requirement-an-agent-owner-can-publish-a-build-that-pins-files-and-installed-tools-req-build-001
		 */
		async submit() {
			if (!this.session) {
				return
			}
			this.busy = true
			this.error = ''
			try {
				const build = await publishAgentBuild(this.agentId, this.session.id, this.note)
				this.$emit('published', build)
			} catch (e) {
				this.error = e?.response?.status === 400
					? this.t('hermiq', 'This conversation has no workspace to publish.')
					: errorMessage(e, this.t('hermiq', 'The build could not be published.'))
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.publish-build {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 20px;
}
.publish-build__title {
	margin: 0;
}
.publish-build__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
}
</style>
