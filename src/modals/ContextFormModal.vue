<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  ContextFormModal — create or edit a Context (hermiq-context-documents),
  mirroring SkillFormModal's authoring pattern for ADR-024's fourth Context
  source kind, inline `documents`.

  Own file per ADR-004 modal-isolation. Mirrors SkillFormModal's/AgentFormModal's
  `#form-dialog` slot contract exactly (`{ show, item, schema, close }`): the
  Contexts management page's top-level `slots.form-dialog: "ContextFormModal"`
  replaces CnIndexPage's built-in generic schema-driven create/edit dialog for
  BOTH create and edit.

  Each `documents[]` entry's `body` is authored with the lib's
  `CnMarkdownEditor` (value-in/@input-out), exactly like a Skill's SKILL.md
  body — the same markdown-authoring surface, applied to Context documents
  (ADR-024 Rule 4: reuse, don't reinvent). `name`/`description` are required/
  optional plain fields; `charBudget` is a numeric field.

  Unlike a Skill (whose CREATE goes through a dedicated import path), a
  Context has no package format — `documents` is authored inline — so BOTH
  create and edit persist through the SAME generic OpenRegister object write
  path (`useContextStore.saveObject`), which itself decides POST vs PUT from
  whether the payload carries an `id`. The edit payload spreads the existing
  Context first so fields this form does not surface (`viewRefs`,
  `needsConsolidation`) survive the write untouched — the same
  `buildEditPayload()` pattern `SkillFormModal` uses for its own edit path.

  `files` ({path, description}) and `objectQueries` ({register, schema,
  search, limit, …}) are simple add/remove array editors alongside the
  `documents` editor — the Context's other two existing source kinds,
  unchanged by this modal beyond being surfaced for authoring.

  @spec openspec/changes/hermiq-context-documents/specs/context-documents/spec.md#requirement-a-context-editor-authors-documents-with-a-markdown-editor-per-entry
  @spec openspec/changes/hermiq-context-documents/specs/context-documents/spec.md#requirement-context-objects-are-managed-through-a-dedicated-surface
-->
<template>
	<NcModal
		:show="show"
		size="large"
		:name="heading"
		@close="handleClose">
		<div class="context-form">
			<h2 class="context-form__title">
				{{ heading }}
			</h2>

			<NcNoteCard v-if="error" type="error" :heading="t('hermiq', 'Could not save context')">
				{{ error }}
			</NcNoteCard>

			<NcTextField
				:value.sync="form.name"
				:label="t('hermiq', 'Name')"
				:placeholder="t('hermiq', 'Permit case law')"
				required />

			<NcTextField
				:value.sync="form.description"
				:label="t('hermiq', 'Description')"
				:placeholder="t('hermiq', 'What does this context bundle provide?')" />

			<NcTextField
				:value.sync="form.charBudget"
				type="number"
				:label="t('hermiq', 'Character budget')"
				:placeholder="'8000'" />

			<h3 class="context-form__subhead">
				{{ t('hermiq', 'Documents') }}
			</h3>
			<p class="context-form__hint">
				{{ t('hermiq', 'Curated document-shaped context authored inline on this Context (e.g. a design.md, persona brief, or standards doc).') }}
			</p>

			<div v-for="(document, index) in documents" :key="index" class="context-form__document">
				<div class="context-form__document-head">
					<NcTextField
						:value="document.name"
						:label="t('hermiq', 'Document name')"
						:placeholder="t('hermiq', 'design.md')"
						@update:value="renameDocument(index, $event)" />
					<NcButton
						type="tertiary"
						:aria-label="t('hermiq', 'Remove document')"
						@click="removeDocument(index)">
						{{ t('hermiq', 'Remove') }}
					</NcButton>
				</div>
				<NcTextField
					:value.sync="document.description"
					:label="t('hermiq', 'Description')"
					:placeholder="t('hermiq', 'Why is this document included?')" />
				<div class="context-form__field">
					<label class="context-form__label">{{ t('hermiq', 'Body') }}</label>
					<CnMarkdownEditor
						:value="document.body"
						:placeholder="t('hermiq', 'Write the document body…')"
						:aria-label="t('hermiq', 'Document body markdown editor')"
						@input="document.body = $event" />
				</div>
			</div>

			<div class="context-form__add-row">
				<NcTextField
					:value.sync="newDocumentName"
					:label="t('hermiq', 'New document name')"
					:placeholder="t('hermiq', 'design.md')" />
				<NcButton type="secondary" :disabled="!newDocumentName.trim()" @click="addDocument">
					{{ t('hermiq', 'Add document') }}
				</NcButton>
			</div>

			<h3 class="context-form__subhead">
				{{ t('hermiq', 'Files') }}
			</h3>
			<p class="context-form__hint">
				{{ t('hermiq', "Nextcloud files read from the acting user's folder at run start.") }}
			</p>

			<div v-for="(file, index) in files" :key="index" class="context-form__row">
				<NcTextField
					:value="file.path"
					:label="t('hermiq', 'File path')"
					:placeholder="t('hermiq', 'notes.md')"
					@update:value="renameFilePath(index, $event)" />
				<NcTextField
					:value.sync="file.description"
					:label="t('hermiq', 'Description')" />
				<NcButton
					type="tertiary"
					:aria-label="t('hermiq', 'Remove file')"
					@click="removeFile(index)">
					{{ t('hermiq', 'Remove') }}
				</NcButton>
			</div>

			<div class="context-form__add-row">
				<NcTextField
					:value.sync="newFilePath"
					:label="t('hermiq', 'New file path')"
					:placeholder="t('hermiq', 'notes.md')" />
				<NcButton type="secondary" :disabled="!newFilePath.trim()" @click="addFile">
					{{ t('hermiq', 'Add file') }}
				</NcButton>
			</div>

			<h3 class="context-form__subhead">
				{{ t('hermiq', 'Object queries') }}
			</h3>
			<p class="context-form__hint">
				{{ t('hermiq', 'OpenRegister queries run live via ObjectService at assembly time.') }}
			</p>

			<div v-for="(query, index) in objectQueries" :key="index" class="context-form__query">
				<div class="context-form__row">
					<NcTextField
						:value.sync="query.register"
						:label="t('hermiq', 'Register')" />
					<NcTextField
						:value.sync="query.schema"
						:label="t('hermiq', 'Schema')" />
				</div>
				<div class="context-form__row">
					<NcTextField
						:value.sync="query.search"
						:label="t('hermiq', 'Search')" />
					<NcTextField
						:value.sync="query.limit"
						type="number"
						:label="t('hermiq', 'Limit')" />
					<NcButton
						type="tertiary"
						:aria-label="t('hermiq', 'Remove object query')"
						@click="removeObjectQuery(index)">
						{{ t('hermiq', 'Remove') }}
					</NcButton>
				</div>
			</div>

			<div class="context-form__add-row">
				<NcButton type="secondary" @click="addObjectQuery">
					{{ t('hermiq', 'Add object query') }}
				</NcButton>
			</div>

			<div class="context-form__actions">
				<NcButton :disabled="saving" @click="handleClose">
					{{ t('hermiq', 'Cancel') }}
				</NcButton>
				<NcButton
					type="primary"
					:disabled="saving || !form.name.trim()"
					@click="save">
					<template v-if="saving" #icon>
						<NcLoadingIcon :size="20" />
					</template>
					{{ t('hermiq', 'Save') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { NcButton, NcLoadingIcon, NcModal, NcNoteCard, NcTextField } from '@nextcloud/vue'
import { CnMarkdownEditor } from '@conduction/nextcloud-vue'
import { useContextStore } from '../store/store.js'

export default {
	name: 'ContextFormModal',

	components: {
		CnMarkdownEditor,
		NcButton,
		NcLoadingIcon,
		NcModal,
		NcNoteCard,
		NcTextField,
	},

	props: {
		/** Whether the modal is visible. */
		show: {
			type: Boolean,
			default: false,
		},
		/**
		 * The item being edited, or null in create mode: supplied by
		 * CnIndexPage's `#form-dialog` scoped slot (`{ show, item, schema,
		 * close }`) when this modal is wired via the Contexts page's
		 * `slots.form-dialog`.
		 */
		item: {
			type: Object,
			default: null,
		},
		/**
		 * Closes the host dialog: the `close` binding from CnIndexPage's
		 * `#form-dialog` scoped slot. Called in ADDITION to `$emit('close')`
		 * on cancel/close/save.
		 */
		close: {
			type: Function,
			default: null,
		},
		/**
		 * The effective JSON schema driving the form (form-dialog slot
		 * parity). Not consumed — this form's fields are hand-authored —
		 * but accepted so the slot binding lands without a Vue "extraneous
		 * non-prop attribute" warning.
		 */
		schema: {
			type: Object,
			default: null,
		},
	},

	emits: ['close', 'saved'],

	data() {
		return {
			form: this.blankForm(),
			documents: [],
			files: [],
			objectQueries: [],
			newDocumentName: '',
			newFilePath: '',
			saving: false,
			error: '',
		}
	},

	computed: {
		/**
		 * The Context being edited — the `item` prop, or null when creating.
		 *
		 * @return {object|null} The effective context, or null when creating.
		 */
		effectiveContext() {
			return this.item || null
		},

		/**
		 * Modal heading — differs for create vs edit.
		 *
		 * @return {string} The localised heading.
		 */
		heading() {
			return this.effectiveContext ? this.t('hermiq', 'Edit context') : this.t('hermiq', 'Create context')
		},
	},

	watch: {
		show(open) {
			if (open) {
				this.resetForm()
			}
		},
	},

	created() {
		this.store = useContextStore()
		this.store.registerObjectType('context', 'context', 'hermiq')
	},

	methods: {
		/**
		 * An empty context form.
		 *
		 * @return {object} The blank form model.
		 */
		blankForm() {
			return { name: '', description: '', charBudget: '8000' }
		},

		/**
		 * Seed the form from `effectiveContext` (edit) or blank (create).
		 *
		 * @return {void}
		 */
		resetForm() {
			this.error = ''
			this.newDocumentName = ''
			this.newFilePath = ''

			if (!this.effectiveContext) {
				this.form = this.blankForm()
				this.documents = []
				this.files = []
				this.objectQueries = []
				return
			}

			const source = this.effectiveContext
			this.form = {
				name: source.name || '',
				description: source.description || '',
				charBudget: String(source.charBudget ?? 8000),
			}
			this.documents = Array.isArray(source.documents)
				? source.documents.map((document) => ({
					name: document.name || '',
					body: document.body || '',
					format: document.format || 'markdown',
					description: document.description || '',
				}))
				: []
			this.files = Array.isArray(source.files)
				? source.files.map((file) => ({ path: file.path || '', description: file.description || '' }))
				: []
			this.objectQueries = Array.isArray(source.objectQueries)
				? source.objectQueries.map((query) => ({
					...query,
					limit: query.limit !== undefined && query.limit !== null ? String(query.limit) : '',
				}))
				: []
		},

		/**
		 * Append a blank document entry.
		 *
		 * @return {void}
		 */
		addDocument() {
			const name = this.newDocumentName.trim()
			if (!name) {
				return
			}
			this.documents.push({ name, body: '', format: 'markdown', description: '' })
			this.newDocumentName = ''
		},

		/**
		 * Rename a document entry.
		 *
		 * @param {number} index The document index.
		 * @param {string} nextName The new name.
		 * @return {void}
		 */
		renameDocument(index, nextName) {
			const trimmed = (nextName || '').trim()
			if (!trimmed) {
				return
			}
			this.documents[index].name = trimmed
		},

		/**
		 * Remove a document entry.
		 *
		 * @param {number} index The document index.
		 * @return {void}
		 */
		removeDocument(index) {
			this.documents.splice(index, 1)
		},

		/**
		 * Append a blank file entry.
		 *
		 * @return {void}
		 */
		addFile() {
			const path = this.newFilePath.trim()
			if (!path) {
				return
			}
			this.files.push({ path, description: '' })
			this.newFilePath = ''
		},

		/**
		 * Rename a file entry's path.
		 *
		 * @param {number} index The file index.
		 * @param {string} nextPath The new path.
		 * @return {void}
		 */
		renameFilePath(index, nextPath) {
			const trimmed = (nextPath || '').trim()
			if (!trimmed) {
				return
			}
			this.files[index].path = trimmed
		},

		/**
		 * Remove a file entry.
		 *
		 * @param {number} index The file index.
		 * @return {void}
		 */
		removeFile(index) {
			this.files.splice(index, 1)
		},

		/**
		 * Append a blank object-query entry.
		 *
		 * @return {void}
		 */
		addObjectQuery() {
			this.objectQueries.push({ register: '', schema: '', search: '', limit: '' })
		},

		/**
		 * Remove an object-query entry.
		 *
		 * @param {number} index The object-query index.
		 * @return {void}
		 */
		removeObjectQuery(index) {
			this.objectQueries.splice(index, 1)
		},

		/**
		 * Close the modal. Always emits `close`, and ADDITIONALLY invokes the
		 * `close` prop when supplied (CnIndexPage's `#form-dialog` scoped
		 * slot).
		 *
		 * @return {void}
		 */
		handleClose() {
			this.$emit('close')
			this.close?.()
		},

		/**
		 * Build the payload sent to the generic object write path. Spreads the
		 * existing Context payload first so schema fields this form does not
		 * surface (`viewRefs`, `needsConsolidation`) survive the write.
		 *
		 * @return {object} The context payload.
		 */
		buildEditPayload() {
			const base = this.effectiveContext ? { ...this.effectiveContext } : {}
			delete base['@self']

			const charBudget = Number(this.form.charBudget)

			const payload = {
				...base,
				name: this.form.name,
				description: this.form.description,
				documents: this.documents.map((document) => ({
					name: document.name,
					body: document.body,
					format: document.format || 'markdown',
					description: document.description,
				})),
				files: this.files.map((file) => ({ path: file.path, description: file.description })),
				objectQueries: this.objectQueries.map((query) => {
					const limit = Number(query.limit)
					return {
						...query,
						limit: query.limit !== '' && !Number.isNaN(limit) ? limit : undefined,
					}
				}),
			}

			if (this.form.charBudget !== '' && !Number.isNaN(charBudget)) {
				payload.charBudget = charBudget
			}

			if (this.effectiveContext && this.effectiveContext.id) {
				payload.id = this.effectiveContext.id
			}
			return payload
		},

		/**
		 * Persist the context and notify the parent. Both create and edit use
		 * the generic OpenRegister object write path — `saveObject` itself
		 * decides POST vs PUT from whether the payload carries an `id`.
		 *
		 * @return {Promise<void>}
		 */
		async save() {
			if (!this.form.name.trim()) {
				return
			}

			this.saving = true
			this.error = ''
			try {
				const saved = await this.store.saveObject('context', this.buildEditPayload())
				if (saved === null) {
					this.error = this.store.errors?.context?.message || this.t('hermiq', 'Could not save context')
					return
				}

				this.$emit('saved', saved)
				this.handleClose()
			} catch (e) {
				this.error = e?.response?.data?.error || e?.message || this.t('hermiq', 'Unknown error')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.context-form {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 20px;
}

.context-form__title {
	margin: 0 0 4px;
	font-size: 20px;
	font-weight: 600;
}

.context-form__subhead {
	margin: 8px 0 0;
	font-size: 15px;
}

.context-form__hint {
	margin: 0;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.context-form__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.context-form__label {
	font-weight: bold;
}

.context-form__document {
	display: flex;
	flex-direction: column;
	gap: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 12px;
}

.context-form__document-head {
	display: flex;
	align-items: flex-end;
	gap: 8px;
}

.context-form__document-head > *:first-child {
	flex: 1;
}

.context-form__query {
	display: flex;
	flex-direction: column;
	gap: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 12px;
}

.context-form__row {
	display: flex;
	align-items: flex-end;
	gap: 8px;
}

.context-form__row > *:first-child {
	flex: 1;
}

.context-form__add-row {
	display: flex;
	align-items: flex-end;
	gap: 8px;
}

.context-form__add-row > *:first-child {
	flex: 1;
}

.context-form__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 8px;
}
</style>
