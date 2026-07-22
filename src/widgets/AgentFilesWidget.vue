<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  AgentFilesWidget — this agent's curated RELATED FILES section as a
  type:"detail" custom widget (manifest-driven-pages).

  A Claude-project-style list of existing Nextcloud files the agent can scan
  and use, backed by the Context system (ADR-024): the files live on an
  agent-owned Context bundle's files[] array (referenced from
  agent.contextRefs) and ride the existing ContextAssembler preamble path at
  run start — this is DISTINCT from chat attachments (per-turn uploads).

  The picker UI is the shared nc-vue component CnRelatedFiles, which opens the
  native Nextcloud file picker itself and emits @add / @remove; this widget
  owns only persistence (src/api/agentFiles.js) and re-fetches after each
  mutation so the list reflects the server (dedupe, basename-derived name).

  Self-fetches the agent id from `$route.params.id` since the scoped detail
  slot only forwards `{ item, widget }`, not the loaded object — the same
  pattern AgentSkillsWidget uses.

  @spec openspec/changes/agent-context-system/tasks.md#2-contextassembler
-->
<template>
	<div class="agent-files-widget">
		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<div v-if="loading" class="agent-files-widget__loading">
			<NcLoadingIcon :size="24" />
			<span>{{ t('hermiq', 'Loading related files…') }}</span>
		</div>

		<CnRelatedFiles
			v-else
			:files="files"
			:title="t('hermiq', 'Related files')"
			:description="t('hermiq', 'Existing Nextcloud files this agent can scan and use. They are added to the agent\'s context at run start.')"
			:add-label="t('hermiq', 'Add files')"
			:empty-label="t('hermiq', 'No related files yet. Add one to give this agent something to scan.')"
			:remove-label="t('hermiq', 'Remove')"
			:picker-title="t('hermiq', 'Select files to relate to this agent')"
			@add="onAdd"
			@remove="onRemove" />
	</div>
</template>

<script>
import { CnRelatedFiles } from '@conduction/nextcloud-vue'
import { NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { addAgentFile, listAgentFiles, removeAgentFile } from '../api/agentFiles.js'

export default {
	name: 'AgentFilesWidget',

	components: {
		CnRelatedFiles,
		NcLoadingIcon,
		NcNoteCard,
	},

	data() {
		return {
			files: [],
			loading: true,
			error: '',
		}
	},

	computed: {
		/**
		 * This agent's uuid from the route param.
		 *
		 * @return {string} The agent uuid.
		 */
		agentId() {
			return this.$route.params.id
		},
	},

	created() {
		this.load()
	},

	methods: {
		/**
		 * Load this agent's related files from the server.
		 *
		 * @return {Promise<void>}
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				this.files = await listAgentFiles(this.agentId)
			} catch (e) {
				this.error = this.t('hermiq', 'Could not load related files.')
				this.files = []
			} finally {
				this.loading = false
			}
		},

		/**
		 * Persist file(s) added via the picker/path field, then re-fetch. The payload
		 * is a single ref when one file was added, otherwise an array of refs.
		 *
		 * @param {object|Array<object>} payload The added ref(s) ({ path, name }).
		 * @return {Promise<void>}
		 */
		async onAdd(payload) {
			const refs = Array.isArray(payload) ? payload : [payload]
			try {
				for (const ref of refs) {
					if (ref && ref.path) {
						await addAgentFile(this.agentId, { path: ref.path, name: ref.name, description: ref.description })
					}
				}
				await this.load()
				showSuccess(this.t('hermiq', 'Related file added.'))
			} catch (e) {
				showError(this.t('hermiq', 'Could not add the related file.'))
				await this.load()
			}
		},

		/**
		 * Unrelate a file, then re-fetch.
		 *
		 * @param {object} file The removed ref ({ path, name, description }).
		 * @return {Promise<void>}
		 */
		async onRemove(file) {
			if (!file || !file.path) {
				return
			}
			try {
				await removeAgentFile(this.agentId, file.path)
				await this.load()
				showSuccess(this.t('hermiq', 'Related file removed.'))
			} catch (e) {
				showError(this.t('hermiq', 'Could not remove the related file.'))
				await this.load()
			}
		},
	},
}
</script>

<style scoped>
.agent-files-widget__loading {
	display: flex;
	align-items: center;
	gap: 8px;
	color: var(--color-text-maxcontrast);
	padding: 12px 0;
}
</style>
