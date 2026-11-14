<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  KnowledgeBaseSources — the KnowledgeBaseDetail page's sources section
  (memory-knowledge-bases). Lists the knowledge base's sources with their
  indexing state, warns the owner about each source only they can read, shows
  where an outside source comes from (its label, e.g. "Synced by integriq from
  SharePoint"), and adds documents by upload, from Files or as a folder.
  Sources are a property of the knowledge base object, so adding from Files and
  removing are ordinary object writes; uploads and the state are Hermiq routes
  because they touch Files. Removing a source never deletes the file.

  @spec openspec/changes/memory-knowledge-bases/specs/knowledge-bases/spec.md#requirement-an-owner-can-create-a-knowledge-base-and-fill-it-from-files-req-kb-001
  @spec openspec/changes/memory-knowledge-bases/specs/knowledge-bases/spec.md#requirement-a-person-only-gets-answers-from-documents-they-may-read-req-kb-003
-->
<template>
	<div class="kb-sources">
		<NcNoteCard v-if="!loading && !searchAvailable" type="warning">
			{{ t('hermiq', 'Search is not available on this server. Ask your administrator to set up vector search in OpenRegister.') }}
		</NcNoteCard>
		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<div class="kb-sources__actions">
			<NcButton variant="primary" :disabled="busy" @click="$refs.upload.click()">
				<template #icon>
					<NcLoadingIcon v-if="busy" :size="20" />
					<Upload v-else :size="20" />
				</template>
				{{ t('hermiq', 'Upload documents') }}
			</NcButton>
			<input
				ref="upload"
				class="kb-sources__file-input"
				type="file"
				multiple
				:aria-label="t('hermiq', 'Upload documents')"
				@change="onUpload">
			<NcButton variant="secondary" :disabled="busy" @click="addFromFiles(false)">
				<template #icon>
					<FileDocumentOutline :size="20" />
				</template>
				{{ t('hermiq', 'Add from Files') }}
			</NcButton>
			<NcButton variant="secondary" :disabled="busy" @click="addFromFiles(true)">
				<template #icon>
					<FolderOutline :size="20" />
				</template>
				{{ t('hermiq', 'Add a folder') }}
			</NcButton>
		</div>

		<NcLoadingIcon v-if="loading" :size="32" />
		<p v-else-if="sources.length === 0" class="kb-sources__empty">
			{{ t('hermiq', 'No documents yet. Upload documents, or add files or a folder from Files.') }}
		</p>
		<ul v-else class="kb-sources__list">
			<li v-for="(source, index) in sources" :key="sourceKey(source, index)" class="kb-sources__item">
				<div class="kb-sources__main">
					<span class="kb-sources__name">{{ sourceName(source) }}</span>
					<span v-if="source.label" class="kb-sources__label">{{ source.label }}</span>
					<span class="kb-sources__state">{{ stateLabel(source) }}</span>
					<p v-if="source.onlyYou" class="kb-sources__notice">
						{{ source.type === 'folder'
							? t('hermiq', 'Only you can read this folder. Other people who use the agent get no answers from it. Share it with them, or use a team folder.')
							: t('hermiq', 'Only you can read this file. Other people who use the agent get no answers from it. Share it with them, or use a team folder.') }}
					</p>
				</div>
				<NcButton
					variant="tertiary"
					:disabled="busy"
					:aria-label="t('hermiq', 'Remove source')"
					@click="remove(index)">
					<template #icon>
						<Close :size="20" />
					</template>
					{{ t('hermiq', 'Remove') }}
				</NcButton>
			</li>
		</ul>
	</div>
</template>

<script>
import { getFilePickerBuilder, showSuccess } from '@nextcloud/dialogs'
import { NcButton, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import Close from 'vue-material-design-icons/Close.vue'
import FileDocumentOutline from 'vue-material-design-icons/FileDocumentOutline.vue'
import FolderOutline from 'vue-material-design-icons/FolderOutline.vue'
import Upload from 'vue-material-design-icons/Upload.vue'
import { fetchSources, uploadDocuments } from '../api/knowledgeBases.js'
import { useKnowledgeBaseStore } from '../store/store.js'
import { errorMessage } from '../utils/errorMessage.js'

/** The schema slug of a knowledge base. */
const SCHEMA = 'agentknowledgebase'

/**
 * The stored part of a source: what the knowledge base object keeps.
 *
 * @param {object} source A source as the sources route answers it.
 * @return {object} The source without its computed state.
 */
export function storedSource(source) {
	const { onlyYou, missing, indexState, ...stored } = source
	return stored
}

/**
 * The sources a Files pick adds, skipping ones already present.
 *
 * @param {Array<object>} sources The current sources.
 * @param {Array<object>} nodes The picked nodes ({fileid, path, type}).
 * @param {boolean} folders Whether folders were picked.
 * @return {Array<object>} The sources after the pick.
 */
export function withPicked(sources, nodes, folders) {
	const next = [...sources]
	for (const node of nodes || []) {
		const fileId = Number(node.fileid)
		if (!fileId || next.some((source) => Number(source.fileId) === fileId)) {
			continue
		}
		next.push({ type: folders ? 'folder' : 'file', fileId, path: String(node.path || '').replace(/^\/+/, '') })
	}
	return next
}

export default {
	name: 'KnowledgeBaseSources',

	components: {
		Close,
		FileDocumentOutline,
		FolderOutline,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		Upload,
	},

	data() {
		return {
			knowledgeBase: null,
			sources: [],
			searchAvailable: true,
			loading: true,
			busy: false,
			error: '',
		}
	},

	computed: {
		/**
		 * This knowledge base's uuid from the route param.
		 *
		 * @spec exclude framework route-param accessor
		 * @return {string} The uuid.
		 */
		knowledgeBaseId() {
			return this.$route.params.id
		},
	},

	/**
	 * Wire the store and load.
	 *
	 * @spec exclude framework lifecycle hook; delegates to the spec-tagged load()
	 */
	created() {
		this.store = useKnowledgeBaseStore()
		this.store.registerObjectType(SCHEMA, SCHEMA, 'hermiq')
		this.load()
	},

	methods: {
		/**
		 * Load the knowledge base and its sources' state.
		 *
		 * @spec openspec/changes/memory-knowledge-bases/specs/knowledge-bases/spec.md#requirement-the-vector-facade-offers-a-file-scope-and-an-indexing-state-req-kb-005
		 * @return {Promise<void>}
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				const [knowledgeBase, state] = await Promise.all([
					this.store.fetchObject(SCHEMA, this.knowledgeBaseId),
					fetchSources(this.knowledgeBaseId),
				])
				this.knowledgeBase = knowledgeBase || null
				this.sources = state.sources
				this.searchAvailable = state.searchAvailable
			} catch (e) {
				this.error = errorMessage(e, this.t('hermiq', 'Could not load the knowledge base.'))
			} finally {
				this.loading = false
			}
		},

		/**
		 * Upload the chosen documents.
		 *
		 * @spec openspec/changes/memory-knowledge-bases/specs/knowledge-bases/spec.md#requirement-an-owner-can-create-a-knowledge-base-and-fill-it-from-files-req-kb-001
		 * @param {Event} event The input's change event.
		 * @return {Promise<void>}
		 */
		async onUpload(event) {
			const files = Array.from(event?.target?.files || [])
			if (files.length === 0) {
				return
			}
			await this.run(async () => {
				await uploadDocuments(this.knowledgeBaseId, files)
				showSuccess(this.n('hermiq', '{count} document uploaded', '{count} documents uploaded', files.length, { count: files.length }))
			})
			if (event?.target) {
				event.target.value = ''
			}
		},

		/**
		 * Add files or a folder from Files as sources; nothing is copied.
		 *
		 * @spec openspec/changes/memory-knowledge-bases/specs/knowledge-bases/spec.md#requirement-an-owner-can-create-a-knowledge-base-and-fill-it-from-files-req-kb-001
		 * @param {boolean} folders Whether to pick a folder.
		 * @return {Promise<void>}
		 */
		async addFromFiles(folders) {
			let picked = []
			const builder = getFilePickerBuilder(folders ? this.t('hermiq', 'Add a folder') : this.t('hermiq', 'Add from Files'))
				.setMultiSelect(!folders)
				.allowDirectories(folders)
			if (folders) {
				builder.setMimeTypeFilter(['httpd/unix-directory'])
			}
			const picker = builder
				.addButton({
					label: this.t('hermiq', 'Add'),
					variant: 'primary',
					callback: (nodes) => {
						picked = nodes
					},
				})
				.build()
			try {
				await picker.pick()
			} catch {
				// Closing the picker without choosing is not an error.
			}
			if (picked.length > 0) {
				await this.saveSources(withPicked(this.sources.map(storedSource), picked, folders))
			}
		},

		/**
		 * Take a source out of the knowledge base; the file stays in Files.
		 *
		 * @spec openspec/changes/memory-knowledge-bases/specs/knowledge-bases/spec.md#requirement-an-owner-can-create-a-knowledge-base-and-fill-it-from-files-req-kb-001
		 * @param {number} index The source's position.
		 * @return {Promise<void>}
		 */
		async remove(index) {
			await this.saveSources(this.sources.filter((_, i) => i !== index).map(storedSource))
		},

		/**
		 * Save the sources through the generic object path.
		 *
		 * @spec openspec/changes/memory-knowledge-bases/specs/knowledge-bases/spec.md#requirement-an-owner-can-create-a-knowledge-base-and-fill-it-from-files-req-kb-001
		 * @param {Array<object>} sources The stored sources.
		 * @return {Promise<void>}
		 */
		async saveSources(sources) {
			await this.run(() => this.store.saveObject(SCHEMA, { ...this.knowledgeBase, sources }))
		},

		/**
		 * Run a write, then reload; a failure shows as the error card.
		 *
		 * @spec exclude busy-state wrapper around the spec-tagged writes
		 * @param {Function} work The write.
		 * @return {Promise<void>}
		 */
		async run(work) {
			this.busy = true
			this.error = ''
			try {
				await work()
			} catch (e) {
				this.error = errorMessage(e, this.t('hermiq', 'The knowledge base could not be saved.'))
			} finally {
				this.busy = false
			}
			await this.load()
		},

		/**
		 * The display name of a source.
		 *
		 * @spec exclude display helper
		 * @param {object} source The source.
		 * @return {string} Its path, or register and schema.
		 */
		sourceName(source) {
			if (source.type === 'objects') {
				return this.t('hermiq', 'Objects: {register} / {schema}', { register: source.register || '', schema: source.schema || '' })
			}
			return source.path || String(source.fileId || '')
		},

		/**
		 * The indexing state of a source, in words.
		 *
		 * @spec openspec/changes/memory-knowledge-bases/specs/knowledge-bases/spec.md#requirement-the-vector-facade-offers-a-file-scope-and-an-indexing-state-req-kb-005
		 * @param {object} source The source.
		 * @return {string} The state.
		 */
		stateLabel(source) {
			if (source.missing) {
				return this.t('hermiq', 'No longer in Files')
			}
			return {
				indexed: this.t('hermiq', 'Indexed'),
				pending: this.t('hermiq', 'Waiting to be indexed'),
				failed: this.t('hermiq', 'Could not be indexed'),
				unsupported: this.t('hermiq', 'This file type cannot be searched'),
			}[source.indexState] || ''
		},

		/**
		 * A stable key for a source row.
		 *
		 * @spec exclude render key helper
		 * @param {object} source The source.
		 * @param {number} index Its position.
		 * @return {string} The key.
		 */
		sourceKey(source, index) {
			return `${source.type}-${source.fileId || source.register + '/' + source.schema}-${index}`
		},
	},
}
</script>

<style scoped>
.kb-sources {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
	height: 100%;
	overflow-y: auto;
}

.kb-sources__actions {
	display: flex;
	flex-wrap: wrap;
	gap: calc(var(--default-grid-baseline) * 2);
}

.kb-sources__file-input {
	display: none;
}

.kb-sources__list {
	list-style: none;
	margin: 0;
	padding: 0;
}

.kb-sources__item {
	display: flex;
	align-items: flex-start;
	justify-content: space-between;
	gap: calc(var(--default-grid-baseline) * 2);
	padding: calc(var(--default-grid-baseline) * 2) 0;
	border-bottom: 1px solid var(--color-border);
}

.kb-sources__main {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	min-width: 0;
}

.kb-sources__name {
	font-weight: bold;
	overflow-wrap: anywhere;
}

.kb-sources__label,
.kb-sources__state,
.kb-sources__empty {
	color: var(--color-text-maxcontrast);
}

.kb-sources__notice {
	margin: 0;
	color: var(--color-warning-text, var(--color-warning));
}
</style>
