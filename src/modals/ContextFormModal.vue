<!--
  ContextFormModal (hermiq-context-documents).

  The create/edit dialog for a Context bundle, wired into the Contexts index
  page through `slots.form-dialog` and the CnIndexPage slot contract
  (`{ show, item, schema, close }`), the same seam SkillFormModal uses.

  A Context carries three source kinds: inline `documents` (authored here with a
  markdown editor per entry), `files` (paths in the acting user's Nextcloud
  folder) and `objectQueries` (live data, kept as stored). An edit spreads the
  stored object first, so `viewRefs`, `charBudget` and `needsConsolidation`
  survive the save. The payload goes through the generic OpenRegister object
  write path (createObjectStore 'context'); there is no hermiq endpoint.

  @spec openspec/specs/context-documents/spec.md#requirement-a-context-editor-authors-documents-with-a-markdown-editor-per-entry
-->
<template>
	<NcModal :show="show" size="large" :name="heading" @close="handleClose">
		<div class="context-form">
			<h2 class="context-form__title">
				{{ heading }}
			</h2>

			<NcNoteCard
				v-if="error"
				type="error"
				:heading="t('hermiq', 'Could not save the context')">
				{{ error }}
			</NcNoteCard>

			<NcTextField
				v-model="form.name"
				:label="t('hermiq', 'Name')"
				:required="true" />
			<NcTextArea
				v-model="form.description"
				:label="t('hermiq', 'Description')" />

			<h3 class="context-form__subhead">
				{{ t('hermiq', 'Documents') }}
			</h3>
			<p class="context-form__hint">
				{{
					t(
						'hermiq',
						'Instructions the agent reads at the start of every run, such as a coding standard or a design note.',
					)
				}}
			</p>
			<div
				v-for="(doc, index) in form.documents"
				:key="doc.key"
				class="context-form__document">
				<NcTextField
					v-model="doc.name"
					:label="t('hermiq', 'Document name')" />
				<CnMarkdownEditor
					:value="doc.body"
					:placeholder="t('hermiq', 'Write the document in Markdown')"
					:aria-label="t('hermiq', 'Document text')"
					@input="doc.body = $event" />
				<NcButton variant="tertiary" @click="removeDocument(index)">
					{{ t('hermiq', 'Remove document') }}
				</NcButton>
			</div>
			<NcButton @click="addDocument">
				{{ t('hermiq', 'Add document') }}
			</NcButton>

			<h3 class="context-form__subhead">
				{{ t('hermiq', 'Files') }}
			</h3>
			<div
				v-for="(file, index) in form.files"
				:key="file.key"
				class="context-form__file">
				<NcTextField
					v-model="file.path"
					:label="t('hermiq', 'Path in the user\'s files')" />
				<NcButton variant="tertiary" @click="form.files.splice(index, 1)">
					{{ t('hermiq', 'Remove file') }}
				</NcButton>
			</div>
			<NcButton @click="form.files.push({ key: nextKey(), path: '' })">
				{{ t('hermiq', 'Add file') }}
			</NcButton>
			<p v-if="queryCount > 0" class="context-form__hint">
				{{
					n(
						'hermiq',
						'This context also reads %n live data query, which stays as it is.',
						'This context also reads %n live data queries, which stay as they are.',
						queryCount,
					)
				}}
			</p>

			<div class="context-form__actions">
				<NcButton :disabled="saving" @click="handleClose">
					{{ t('hermiq', 'Cancel') }}
				</NcButton>
				<NcButton
					variant="primary"
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
import { CnMarkdownEditor } from '@conduction/nextcloud-vue'
import {
	NcButton,
	NcLoadingIcon,
	NcModal,
	NcNoteCard,
	NcTextArea,
	NcTextField,
} from '@nextcloud/vue'
import { useContextStore } from '../store/store.js'

let keySeed = 0

export default {
	name: 'ContextFormModal',

	components: {
		CnMarkdownEditor,
		NcButton,
		NcLoadingIcon,
		NcModal,
		NcNoteCard,
		NcTextArea,
		NcTextField,
	},

	props: {
		/** Whether the modal is visible. */
		show: {
			type: Boolean,
			default: false,
		},

		/** The Context being edited, or null to create one. */
		item: {
			type: Object,
			default: null,
		},

		/** Closes the host dialog (CnIndexPage form-dialog slot contract). */
		close: {
			type: Function,
			default: null,
		},

		/** The effective JSON schema (slot contract; the form is hand-built). */
		schema: {
			type: Object,
			default: null,
		},
	},

	emits: ['close', 'saved'],

	data() {
		return {
			form: this.formFrom(this.item),
			saving: false,
			error: '',
		}
	},

	computed: {
		/**
		 * The dialog title.
		 * @spec openspec/specs/context-documents/spec.md#requirement-a-context-editor-authors-documents-with-a-markdown-editor-per-entry
		 */
		heading() {
			return this.item
				? this.t('hermiq', 'Edit context')
				: this.t('hermiq', 'New context')
		},

		/**
		 * How many live data queries the stored Context carries.
		 * @spec openspec/specs/context-documents/spec.md#requirement-a-context-editor-authors-documents-with-a-markdown-editor-per-entry
		 */
		queryCount() {
			return Array.isArray(this.item?.objectQueries)
				? this.item.objectQueries.length
				: 0
		},
	},

	watch: {
		/**
		 * Reset the form each time the dialog opens.
		 * @spec openspec/specs/context-documents/spec.md#requirement-a-context-editor-authors-documents-with-a-markdown-editor-per-entry
		 */
		show(open) {
			if (open) {
				this.form = this.formFrom(this.item)
				this.error = ''
			}
		},

		/**
		 * Reload the form when the edited Context changes.
		 * @spec openspec/specs/context-documents/spec.md#requirement-a-context-editor-authors-documents-with-a-markdown-editor-per-entry
		 */
		item(value) {
			this.form = this.formFrom(value)
		},
	},

	methods: {
		/**
		 * A fresh key for a list row.
		 *
		 * @return {number}
		 * @spec openspec/specs/context-documents/spec.md#requirement-a-context-editor-authors-documents-with-a-markdown-editor-per-entry
		 */
		nextKey() {
			keySeed += 1
			return keySeed
		},

		/**
		 * The editable form state for a stored Context (or an empty one).
		 *
		 * @param {object|null} source The stored Context.
		 * @return {object}
		 * @spec openspec/specs/context-documents/spec.md#requirement-a-context-editor-authors-documents-with-a-markdown-editor-per-entry
		 */
		formFrom(source) {
			const docs = Array.isArray(source?.documents) ? source.documents : []
			const files = Array.isArray(source?.files) ? source.files : []
			const documents = docs.map((doc) => ({
				...doc,
				key: this.nextKey(),
				name: doc?.name || '',
				body: doc?.body || '',
			}))
			return {
				name: source?.name || '',
				description: source?.description || '',
				documents,
				files: files.map((file) => ({
					...file,
					key: this.nextKey(),
					path: file?.path || '',
				})),
			}
		},

		/**
		 * Add an empty document entry.
		 * @spec openspec/specs/context-documents/spec.md#requirement-a-context-editor-authors-documents-with-a-markdown-editor-per-entry
		 */
		addDocument() {
			this.form.documents.push({
				key: this.nextKey(),
				name: '',
				body: '',
				format: 'markdown',
			})
		},

		/**
		 * Remove one document entry.
		 * @spec openspec/specs/context-documents/spec.md#requirement-a-context-editor-authors-documents-with-a-markdown-editor-per-entry
		 */
		removeDocument(index) {
			this.form.documents.splice(index, 1)
		},

		/**
		 * The payload for the object write path. Spreads the stored Context first so
		 * fields this form does not show survive the save.
		 *
		 * @return {object}
		 * @spec openspec/specs/context-documents/spec.md#requirement-a-context-editor-authors-documents-with-a-markdown-editor-per-entry
		 */
		buildPayload() {
			const base = this.item ? { ...this.item } : {}
			delete base['@self']
			const strip = ({ key, ...rest }) => rest
			return {
				...base,
				name: this.form.name.trim(),
				description: this.form.description,
				documents: this.form.documents
					.filter(
						(doc) => doc.name.trim() !== '' || doc.body.trim() !== '',
					)
					.map((doc) => ({
						format: 'markdown',
						...strip(doc),
						name: doc.name.trim(),
					})),

				files: this.form.files
					.filter((file) => file.path.trim() !== '')
					.map((file) => ({ ...strip(file), path: file.path.trim() })),
			}
		},

		/**
		 * Save through the generic OpenRegister object store.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/context-documents/spec.md#requirement-a-context-editor-authors-documents-with-a-markdown-editor-per-entry
		 */
		async save() {
			this.saving = true
			this.error = ''
			try {
				const store = useContextStore()
				store.registerObjectType('context', 'context', 'hermiq')
				const saved = await store.saveObject('context', this.buildPayload())
				if (!saved) {
					throw new Error(
						this.t('hermiq', 'The server did not accept the context.'),
					)
				}
				this.$emit('saved', saved)
				this.handleClose()
			} catch (e) {
				this.error =
					e?.message
					|| this.t('hermiq', 'The server did not accept the context.')
			} finally {
				this.saving = false
			}
		},

		/**
		 * Close the dialog (slot contract: emit and call the close prop).
		 * @spec openspec/specs/context-documents/spec.md#requirement-a-context-editor-authors-documents-with-a-markdown-editor-per-entry
		 */
		handleClose() {
			this.$emit('close')
			this.close?.()
		},
	},
}
</script>

<style scoped>
.context-form {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
	padding: calc(var(--default-grid-baseline) * 5);
}

.context-form__document,
.context-form__file {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
	padding: calc(var(--default-grid-baseline) * 3);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.context-form__hint {
	color: var(--color-text-maxcontrast);
}

.context-form__actions {
	display: flex;
	justify-content: flex-end;
	gap: calc(var(--default-grid-baseline) * 2);
}
</style>
