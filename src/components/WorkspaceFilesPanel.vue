<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  WorkspaceFilesPanel — the files an agent keeps in this conversation's
  workspace (tools-agent-workspace-and-installed-tools REQ-WKSP-003): name,
  size and changed time, a download, and "Save to my Files". Saving copies the
  file into the person's own Files under "Hermiq" with the agent-authored tag;
  the server fails the save when the tag cannot be applied. Shows nothing for a
  conversation without workspace files.
-->
<template>
	<section
		v-if="files.length > 0"
		class="workspace-files"
		data-testid="workspace-files"
		:aria-label="t('hermiq', 'Files')">
		<div class="workspace-files__header">
			<h3 class="workspace-files__title">
				{{ t('hermiq', 'Files') }}
			</h3>
			<NcButton
				variant="tertiary"
				data-testid="workspace-files-refresh"
				:disabled="loading"
				@click="load">
				{{ t('hermiq', 'Refresh') }}
			</NcButton>
		</div>
		<ul class="workspace-files__list">
			<li
				v-for="file in files"
				:key="file.path"
				class="workspace-files__row"
				data-testid="workspace-files-row">
				<span class="workspace-files__name">{{ file.path }}</span>
				<span class="workspace-files__meta">{{ sizeOf(file.size) }} · {{ changedAt(file.modified) }}</span>
				<a
					class="workspace-files__download"
					:href="downloadUrl(file.path)"
					data-testid="workspace-files-download">
					{{ t('hermiq', 'Download') }}
				</a>
				<NcButton
					variant="secondary"
					data-testid="workspace-files-save"
					:disabled="savingPath === file.path"
					@click="save(file.path)">
					{{ t('hermiq', 'Save to my Files') }}
				</NcButton>
			</li>
		</ul>
		<p v-if="truncated" class="workspace-files__note">
			{{ t('hermiq', 'Only the first files are shown.') }}
		</p>
		<p
			v-if="message"
			class="workspace-files__note"
			role="status"
			data-testid="workspace-files-message">
			{{ message }}
		</p>
	</section>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import { listWorkspaceFiles, saveWorkspaceFile, workspaceFileDownloadUrl } from '../api/workspaceFiles.js'

export default {
	name: 'WorkspaceFilesPanel',

	components: { NcButton },

	props: {
		/** The session (conversation) UUID. */
		sessionId: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			files: [],
			truncated: false,
			loading: false,
			savingPath: '',
			message: '',
		}
	},

	watch: {
		sessionId: {
			immediate: true,
			/**
			 * Reload for another conversation.
			 *
			 * @return {Promise<void>}
			 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#requirement-the-user-sees-the-workspace-and-can-keep-a-file-req-wksp-003
			 */
			handler() {
				this.message = ''
				return this.load()
			},
		},
	},

	methods: {
		/**
		 * Load the conversation's workspace files; a failed load shows none.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#requirement-the-user-sees-the-workspace-and-can-keep-a-file-req-wksp-003
		 */
		async load() {
			this.loading = true
			try {
				const listing = await listWorkspaceFiles(this.sessionId)
				this.files = listing.files
				this.truncated = listing.truncated
			} catch {
				this.files = []
				this.truncated = false
			} finally {
				this.loading = false
			}
		},

		/**
		 * Save one file to the person's Files and say where it went, or why not.
		 *
		 * @param {string} path The file's path inside the workspace.
		 * @return {Promise<void>}
		 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#requirement-the-user-sees-the-workspace-and-can-keep-a-file-req-wksp-003
		 */
		async save(path) {
			this.savingPath = path
			this.message = ''
			try {
				const saved = await saveWorkspaceFile(this.sessionId, path)
				this.message = this.t('hermiq', 'Saved to your Files as {path}.', { path: saved.path })
			} catch (e) {
				this.message = e?.response?.data?.error || this.t('hermiq', 'The file could not be saved to your Files.')
			} finally {
				this.savingPath = ''
			}
		},

		/**
		 * The download link of one file.
		 *
		 * @param {string} path The file's path inside the workspace.
		 * @return {string} The URL.
		 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#requirement-the-user-sees-the-workspace-and-can-keep-a-file-req-wksp-003
		 */
		downloadUrl(path) {
			return workspaceFileDownloadUrl(this.sessionId, path)
		},

		/**
		 * A size in bytes as the panel shows it.
		 *
		 * @param {number} bytes The size.
		 * @return {string} The size with its unit.
		 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#requirement-the-user-sees-the-workspace-and-can-keep-a-file-req-wksp-003
		 */
		sizeOf(bytes) {
			if (bytes < 1024) {
				return `${bytes} B`
			}
			if (bytes < 1024 * 1024) {
				return `${(bytes / 1024).toFixed(1)} KB`
			}
			return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
		},

		/**
		 * A changed time (seconds since the epoch) in the person's locale.
		 *
		 * @param {number} seconds The time.
		 * @return {string} The date and time.
		 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#requirement-the-user-sees-the-workspace-and-can-keep-a-file-req-wksp-003
		 */
		changedAt(seconds) {
			return new Date(seconds * 1000).toLocaleString(undefined, { dateStyle: 'short', timeStyle: 'short' })
		},
	},
}
</script>

<style scoped>
.workspace-files {
	margin: 8px 16px;
	padding: 8px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background: var(--color-main-background);
}

.workspace-files__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
}

.workspace-files__title {
	margin: 0;
	font-size: 16px;
	font-weight: 600;
}

.workspace-files__list {
	margin: 4px 0 0;
	padding: 0;
	list-style: none;
}

.workspace-files__row {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px;
	padding: 4px 0;
}

.workspace-files__name {
	flex: 1 1 200px;
	overflow-wrap: anywhere;
}

.workspace-files__meta,
.workspace-files__note {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.workspace-files__download {
	color: var(--color-primary-element);
	text-decoration: underline;
}
</style>
