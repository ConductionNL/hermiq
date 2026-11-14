<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  AgentBuildsPanel: the published builds of an agent on its page
  (tools-agent-workspace-and-installed-tools). Each build shows its number, who
  published it and when, its note, its files and its installed tools; the
  pinned build is marked, and the panel says when the agent changed since it.
  Someone who may modify the agent can publish a new build from one of their
  conversations (PublishBuildModal).

  @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-versioning/spec.md#requirement-an-agent-owner-can-publish-a-build-that-pins-files-and-installed-tools-req-build-001
-->
<template>
	<div class="agent-builds" data-testid="agent-builds">
		<div class="agent-builds__head">
			<h3 class="agent-builds__title">
				{{ t('hermiq', 'Builds') }}
			</h3>
			<NcButton
				v-if="canPublish"
				data-testid="agent-builds-publish"
				@click="publishing = true">
				{{ t('hermiq', 'Publish build') }}
			</NcButton>
		</div>

		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>
		<NcNoteCard
			v-if="unpublishedChanges && pinnedBuildNumber !== null"
			type="warning"
			data-testid="agent-builds-unpublished">
			{{
				t('hermiq', 'Unpublished changes since build {number}', {
					number: pinnedBuildNumber,
				})
			}}
		</NcNoteCard>

		<div v-if="loading" class="agent-builds__loading">
			<NcLoadingIcon :size="32" />
		</div>
		<p v-else-if="builds.length === 0" class="agent-builds__none">
			{{
				t(
					'hermiq',
					'No build published yet. A build keeps the workspace files and installed tools that every new conversation starts from.',
				)
			}}
		</p>
		<ul v-else class="agent-builds__list">
			<li
				v-for="build in builds"
				:key="build.id"
				class="agent-builds__item"
				data-testid="agent-builds-row">
				<div class="agent-builds__line">
					<strong class="agent-builds__name">{{
						t('hermiq', 'Build {number}', { number: build.number })
					}}</strong>
					<span
						v-if="build.id === pinnedBuildId"
						class="agent-builds__pinned"
						data-testid="agent-builds-pinned">{{ t('hermiq', 'Pinned') }}</span>
					<span class="agent-builds__meta">{{
						t('hermiq', 'published by {name} on {date}', {
							name: build.createdByName || build.createdBy,
							date: formatDate(build.createdAt),
						})
					}}</span>
				</div>
				<p v-if="build.note" class="agent-builds__note">
					{{ build.note }}
				</p>
				<p class="agent-builds__counts">
					{{ countsText(build) }}
				</p>
			</li>
		</ul>

		<PublishBuildModal
			:show="publishing"
			:agentId="agentId"
			@close="publishing = false"
			@published="onPublished" />
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import PublishBuildModal from '../modals/PublishBuildModal.vue'
import { listAgentBuilds } from '../api/agentBuilds.js'
import { errorMessage } from '../utils/errorMessage.js'

export default {
	name: 'AgentBuildsPanel',

	components: {
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		PublishBuildModal,
	},

	data() {
		return {
			builds: [],
			pinnedBuildId: null,
			pinnedBuildNumber: null,
			unpublishedChanges: false,
			canPublish: false,
			loading: false,
			error: '',
			publishing: false,
		}
	},

	computed: {
		/**
		 * This agent's uuid from the route param.
		 *
		 * @spec exclude framework route-param accessor; behaviour owned by the panel's spec-tagged load method
		 * @return {string} The agent uuid.
		 */
		agentId() {
			return this.$route?.params?.id || ''
		},
	},

	watch: {
		agentId: {
			immediate: true,
			/**
			 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-versioning/spec.md#requirement-an-agent-owner-can-publish-a-build-that-pins-files-and-installed-tools-req-build-001
			 */
			handler() {
				this.load()
			},
		},
	},

	methods: {
		/**
		 * Load the builds and the pin.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-versioning/spec.md#scenario-edits-after-publishing-are-visible-to-the-owner
		 */
		async load() {
			if (!this.agentId) {
				return
			}
			this.loading = true
			this.error = ''
			try {
				const overview = await listAgentBuilds(this.agentId)
				this.builds = overview.builds
				this.pinnedBuildId = overview.pinnedBuildId
				this.pinnedBuildNumber = overview.pinnedBuildNumber
				this.unpublishedChanges = overview.unpublishedChanges
				this.canPublish = overview.canPublish
			} catch (e) {
				this.error = errorMessage(e, this.t('hermiq', 'The builds could not be loaded.'))
			} finally {
				this.loading = false
			}
		},

		/**
		 * Close the modal and show the new build.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-versioning/spec.md#requirement-an-agent-owner-can-publish-a-build-that-pins-files-and-installed-tools-req-build-001
		 */
		async onPublished() {
			this.publishing = false
			await this.load()
		},

		/**
		 * "2 files, 1 tool" for a build.
		 *
		 * @param {object} build The build.
		 * @return {string} The counts.
		 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-versioning/spec.md#scenario-an-agent-owner-publishes-build-3
		 */
		countsText(build) {
			const files = Array.isArray(build.files) ? build.files.length : 0
			const tools = Array.isArray(build.tools) ? build.tools.length : 0
			return this.n('hermiq', '%n file', '%n files', files)
				+ ', '
				+ this.n('hermiq', '%n tool', '%n tools', tools)
		},

		/**
		 * A readable date.
		 *
		 * @param {string} value The ISO timestamp.
		 * @return {string} The formatted date.
		 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-versioning/spec.md#scenario-an-agent-owner-publishes-build-3
		 */
		formatDate(value) {
			const date = new Date(value)
			return value && !Number.isNaN(date.getTime())
				? date.toLocaleDateString()
				: ''
		},
	},
}
</script>

<style scoped>
.agent-builds {
	display: flex;
	flex-direction: column;
	gap: 12px;
	height: 100%;
}
.agent-builds__head {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
}
.agent-builds__title {
	font-size: 16px;
	font-weight: 600;
	margin: 0;
}
.agent-builds__loading {
	display: flex;
	justify-content: center;
	padding: 24px 0;
}
.agent-builds__none,
.agent-builds__meta,
.agent-builds__counts {
	color: var(--color-text-maxcontrast);
}
.agent-builds__list {
	flex: 1 1 auto;
	min-height: 0;
	overflow-y: auto;
	list-style: none;
	margin: 0;
	padding: 0;
}
.agent-builds__item {
	padding: 8px 0;
	border-bottom: 1px solid var(--color-border);
}
.agent-builds__line {
	display: flex;
	flex-wrap: wrap;
	align-items: baseline;
	gap: 8px;
}
.agent-builds__pinned {
	padding: 0 8px;
	border-radius: var(--border-radius-pill);
	background-color: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text);
}
.agent-builds__note,
.agent-builds__counts {
	margin: 4px 0 0;
}
</style>
