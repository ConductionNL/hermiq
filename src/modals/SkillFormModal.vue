<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  SkillFormModal — create or edit a Skill (hermiq-skill-markdown-authoring),
  and optionally land a chat-authored skill on the review path
  (hermiq-skill-conversational-authoring).

  Own file per ADR-004 modal-isolation. Mirrors AgentFormModal's `#form-dialog`
  slot contract exactly (`{ show, item, schema, close }`): SkillsCatalog's
  top-level `slots.form-dialog: "SkillFormModal"` replaces CnIndexPage's
  built-in generic schema-driven create/edit dialog for BOTH create and edit.

  The SKILL.md `body` is authored with the lib's `CnMarkdownEditor`
  (value-in/@input-out); `name` (required), `description`, and `frontmatter`
  (the raw YAML block) are plain fields — `frontmatter` uses `NcTextArea`
  rather than `NcTextField` since it is inherently multi-line YAML and
  `NcTextField` renders a single-line `<input>` that cannot hold newlines
  (the same reasoning `AgentFormModal` already applied to its multi-line
  `prompt` field).

  Two distinct write paths, deliberately NOT the same for create vs edit:
  - CREATE goes through the existing `SkillController`/`SkillService` import
    path (`importSkill()` in `src/api/skills.js`), which composes the typed
    `frontmatter`+`body` into a fenced package and lets the server-side
    `SkillSerializer::fromPackage()` be the single source of truth for the
    split — never reimplemented client-side. A pasted full agentskills.io
    package (starts with a `---` fence) is routed through this SAME import
    path (task 2): it is not split in the browser, so the persisted result is
    always exactly what the server's serializer produces.
  - EDIT goes through the generic OpenRegister object write path the
    SkillsCatalog page already used for its (now-superseded) built-in dialog —
    the same `createObjectStore` PUT `AgentFormModal`/`EvalDatasetFormModal`
    use for their own schemas — merging the existing Skill payload first so
    fields this form does not surface (`state`, `source`, `installedOn`,
    `githubOwner`/`githubRepo`/`publishedAt`, `scanReport`, …) survive the
    write untouched.

  `files` (auxiliary agentskills.io files, each `{ name, content }`) round-trip
  through BOTH paths. Historically `importSkill()`/`installFromSource()` always
  persisted `files: []` server-side because they only accepted a `package`
  string, so CREATE mode authored files were landed by a follow-up PUT via the
  same generic object path EDIT already uses — no new endpoint, since that PUT
  route already exists and is exercised by every other schema's form modal.

  As of `skill-package-multifile` the server-side limitation is gone: both
  install paths take an optional `auxFiles` argument and persist it. This modal
  deliberately still uses the follow-up PUT, because `importSkill(text)` here
  sends only the pasted package — the user authors files in the form AFTER the
  paste, so there is nothing to send at import time. The PUT is the correct
  seam for that ordering, not a workaround for a server gap that no longer
  exists.

  hermiq-skill-conversational-authoring extends this modal with two OPTIONAL
  props, both inert unless explicitly set by a caller: `initialBody` (pre-fill
  `body` when opened fresh, e.g. from the chat "Save as skill" seam) and
  `saveTarget` ('active' default | 'quarantine') — when 'quarantine', CREATE
  routes through `installFromSource(pkg, 'local')` instead of `importSkill()`,
  so the result lands `quarantined` and must be Approved before use. This only
  ever affects the CREATE branch; an edit's save target is always the
  existing generic-object PUT regardless of `saveTarget`.

  @spec openspec/specs/skills-catalog/spec.md#requirement-a-dedicated-markdown-authoring-form-replaces-the-generic-skill-create-edit-dialog
  @spec openspec/specs/skills-catalog/spec.md#requirement-authored-skills-persist-through-the-existing-catalog-write-path-without-a-new-backend
  @spec openspec/specs/skills-catalog/spec.md#requirement-a-pasted-agentskills-io-package-is-split-into-frontmatter-and-body
  @spec openspec/specs/skills-catalog/spec.md#requirement-a-chat-assistant-message-can-be-saved-as-a-reviewable-skill
-->
<template>
	<NcModal :show="show" size="large" :name="heading" @close="handleClose">
		<div class="skill-form">
			<h2 class="skill-form__title">
				{{ heading }}
			</h2>

			<NcNoteCard
				v-if="error"
				type="error"
				:heading="t('hermiq', 'Could not save skill')">
				{{ error }}
			</NcNoteCard>

			<!-- Package paste (create mode only — importing always creates a new -->
			<!-- Skill, so this affordance would be surprising once editing one). -->
			<div v-if="showPastePackage" class="skill-form__paste">
				<h3 class="skill-form__subhead">
					{{ t('hermiq', 'Or paste a full agentskills.io package') }}
				</h3>
				<NcNoteCard v-if="pasteError" type="error">
					{{ pasteError }}
				</NcNoteCard>
				<NcTextArea
					v-model="pasteText"
					:label="
						t('hermiq', 'Package (starts with a --- frontmatter fence)')
					"
					:placeholder="
						t(
							'hermiq',
							'Paste the whole package, starting with the --- fence',
						)
					"
					resize="vertical" />
				<NcButton
					type="secondary"
					:disabled="pasteBusy || !pasteText.trim()"
					@click="importPastedPackage">
					<template v-if="pasteBusy" #icon>
						<NcLoadingIcon :size="20" />
					</template>
					{{ t('hermiq', 'Split into frontmatter + body') }}
				</NcButton>
			</div>

			<NcTextField
				v-model="form.name"
				:label="t('hermiq', 'Name')"
				:placeholder="t('hermiq', 'my-skill')"
				required />

			<NcTextField
				v-model="form.description"
				:label="t('hermiq', 'Description')"
				:placeholder="
					t('hermiq', 'What does this skill teach an agent to do?')
				" />

			<NcTextArea
				v-model="form.frontmatter"
				:label="t('hermiq', 'Frontmatter (YAML)')"
				:placeholder="
					t('hermiq', 'name: my-skill, description: …, version: 0.1.0')
				"
				resize="vertical" />

			<div class="skill-form__field">
				<label class="skill-form__label">{{
					t('hermiq', 'Body (SKILL.md)')
				}}</label>
				<CnMarkdownEditor
					:value="form.body"
					:placeholder="t('hermiq', 'Write the SKILL.md body…')"
					:aria-label="t('hermiq', 'Skill body markdown editor')"
					@input="form.body = $event" />
			</div>

			<h3 class="skill-form__subhead">
				{{ t('hermiq', 'Auxiliary files') }}
			</h3>
			<p class="skill-form__hint">
				{{
					t(
						'hermiq',
						'Extra files an agent may read alongside SKILL.md (optional).',
					)
				}}
			</p>

			<div
				v-for="(file, index) in files"
				:key="index"
				class="skill-form__file">
				<div class="skill-form__file-head">
					<NcTextField
						:model-value="file.name"
						:label="t('hermiq', 'File name')"
						:placeholder="t('hermiq', 'reference.md')"
						@update:modelValue="renameFile(index, $event)" />
					<NcButton
						type="tertiary"
						:aria-label="t('hermiq', 'Remove file')"
						@click="removeFile(index)">
						{{ t('hermiq', 'Remove') }}
					</NcButton>
				</div>
				<CnMarkdownEditor
					v-if="isMarkdownFile(file.name)"
					:value="file.content"
					:aria-label="t('hermiq', 'File content markdown editor')"
					@input="file.content = $event" />
				<NcTextArea
					v-else
					v-model="file.content"
					:label="t('hermiq', 'File content')"
					resize="vertical" />
			</div>

			<div class="skill-form__add-file">
				<NcTextField
					v-model="newFileName"
					:label="t('hermiq', 'New file name')"
					:placeholder="t('hermiq', 'reference.md')" />
				<NcButton
					type="secondary"
					:disabled="!newFileName.trim()"
					@click="addFile">
					{{ t('hermiq', 'Add file') }}
				</NcButton>
			</div>

			<div class="skill-form__actions">
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
import {
	NcButton,
	NcLoadingIcon,
	NcModal,
	NcNoteCard,
	NcTextArea,
	NcTextField,
} from '@nextcloud/vue'
import { CnMarkdownEditor } from '@conduction/nextcloud-vue'
import { importSkill, installFromSource, updateSkill } from '../api/skills.js'

export default {
	name: 'SkillFormModal',

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
		/**
		 * The item being edited, or null in create mode (agent-form-slot
		 * parity): supplied by CnIndexPage's `#form-dialog` scoped slot
		 * (`{ show, item, schema, close }`) when this modal is wired via
		 * SkillsCatalog's `slots.form-dialog`.
		 */
		item: {
			type: Object,
			default: null,
		},
		/**
		 * Closes the host dialog (agent-form-slot parity): the `close`
		 * binding from CnIndexPage's `#form-dialog` scoped slot. Called in
		 * ADDITION to `$emit('close')` on cancel/close/save.
		 */
		close: {
			type: Function,
			default: null,
		},
		/**
		 * The effective JSON schema driving the form (agent-form-slot
		 * parity): the `schema` binding from CnIndexPage's `#form-dialog`
		 * scoped slot. Not consumed — this form's fields are hand-authored —
		 * but accepted so the slot binding lands without a Vue "extraneous
		 * non-prop attribute" warning.
		 */
		schema: {
			type: Object,
			default: null,
		},
		/**
		 * hermiq-skill-conversational-authoring: pre-fill `body` when opened
		 * fresh (no `item`) — used by the chat "Save as skill" seam to seed
		 * the modal with the assistant message's SKILL.md content for review.
		 * Ignored once an `item` is being edited.
		 */
		initialBody: {
			type: String,
			default: '',
		},
		/**
		 * hermiq-skill-conversational-authoring: where a newly CREATED skill
		 * lands. `'active'` (default) is the catalog-authoring path
		 * (`importSkill`, existing default). `'quarantine'` routes through
		 * `installFromSource(pkg, 'local')` instead, so the result lands
		 * `quarantined` and requires the existing Approve gate — used by the
		 * chat seam, since chat output is machine-generated. Never affects an
		 * EDIT save, which always uses the generic-object PUT.
		 *
		 * @type {'active'|'quarantine'}
		 */
		saveTarget: {
			type: String,
			default: 'active',
			validator: (v) => ['active', 'quarantine'].includes(v),
		},
	},

	emits: ['close', 'saved'],

	data() {
		return {
			form: this.blankForm(),
			files: [],
			newFileName: '',
			saving: false,
			error: '',
			// Set once a create-mode package-paste (task 2) has imported a
			// Skill — subsequent Save clicks then UPDATE that object (via the
			// generic edit path) instead of importing a second one.
			importedSkill: null,
			pasteText: '',
			pasteBusy: false,
			pasteError: '',
		}
	},

	computed: {
		/**
		 * The Skill being edited — the `item` prop (create/edit slot) wins,
		 * then a create-mode package-paste result (task 2), else null.
		 *
		 * @return {object|null} The effective skill, or null when creating.
		 */
		effectiveSkill() {
			return this.item || this.importedSkill
		},

		/**
		 * Modal heading — differs for create vs edit.
		 *
		 * @return {string} The localised heading.
		 */
		heading() {
			return this.effectiveSkill
				? this.t('hermiq', 'Edit skill')
				: this.t('hermiq', 'Create skill')
		},

		/**
		 * Whether to show the "paste a full package" affordance — create
		 * mode only (importing a package always creates a NEW Skill, so it
		 * would be surprising to offer it while editing one).
		 *
		 * @return {boolean} True in create mode.
		 */
		showPastePackage() {
			return !this.effectiveSkill
		},
	},

	watch: {
		show(open) {
			if (open) {
				this.resetForm()
			}
		},
	},

	methods: {
		/**
		 * An empty skill form.
		 *
		 * @return {object} The blank form model.
		 */
		blankForm() {
			return { name: '', description: '', frontmatter: '', body: '' }
		},

		/**
		 * Seed the form from `effectiveSkill` (edit) or blank + `initialBody`
		 * (create — hermiq-skill-conversational-authoring's chat pre-fill).
		 *
		 * @return {void}
		 */
		resetForm() {
			this.error = ''
			this.pasteError = ''
			this.pasteText = ''
			this.importedSkill = null

			if (!this.effectiveSkill) {
				this.form = this.blankForm()
				this.form.body = this.initialBody || ''
				this.files = []
				return
			}

			const source = this.effectiveSkill
			this.form = {
				name: source.name || '',
				description: source.description || '',
				frontmatter: source.frontmatter || '',
				body: source.body || '',
			}
			this.files = Array.isArray(source.files)
				? source.files.map((file) => ({
						name: file.name || '',
						content: file.content || '',
					}))
				: []
		},

		/**
		 * Whether a file name should be authored with CnMarkdownEditor (a
		 * `.md`/`.markdown` file) or a plain textarea.
		 *
		 * @param {string} name The file name.
		 * @return {boolean} True for a markdown file.
		 */
		isMarkdownFile(name) {
			return /\.mdx?$/i.test(name || '')
		},

		/**
		 * Append a blank auxiliary file.
		 *
		 * @return {void}
		 */
		addFile() {
			const name = this.newFileName.trim()
			if (!name) {
				return
			}
			if (this.files.some((file) => file.name === name)) {
				this.error = this.t(
					'hermiq',
					'A file named "{name}" already exists.',
					{ name },
				)
				return
			}
			this.files.push({ name, content: '' })
			this.newFileName = ''
		},

		/**
		 * Rename an auxiliary file.
		 *
		 * @param {number} index The file index.
		 * @param {string} nextName The new file name.
		 * @return {void}
		 */
		renameFile(index, nextName) {
			const trimmed = (nextName || '').trim()
			if (!trimmed) {
				return
			}
			this.files[index].name = trimmed
		},

		/**
		 * Remove an auxiliary file.
		 *
		 * @param {number} index The file index.
		 * @return {void}
		 */
		removeFile(index) {
			this.files.splice(index, 1)
		},

		/**
		 * Close the modal (agent-form-slot parity). Always emits `close`,
		 * and ADDITIONALLY invokes the `close` prop when supplied (CnIndexPage's
		 * `#form-dialog` scoped slot).
		 *
		 * @return {void}
		 */
		handleClose() {
			this.$emit('close')
			this.close?.()
		},

		/**
		 * Compose the frontmatter block sent to the server, injecting `name`/
		 * `description` from the required form fields when the raw
		 * frontmatter text does not already declare them (so the required
		 * Name field always reaches the persisted object, regardless of
		 * whether the user also typed a `name:`/`description:` YAML line).
		 * Defensively strips a leading/trailing `---` fence in case the user
		 * pasted a full package into this field directly.
		 *
		 * @return {string} The raw frontmatter block (no fences).
		 */
		buildFrontmatterBlock() {
			const block = (this.form.frontmatter || '')
				.replace(/^---\s*\n/, '')
				.replace(/\n---\s*$/, '')
				.trim()

			const lines = []
			if (/^\s*name\s*:/im.test(block) === false) {
				lines.push(`name: ${this.form.name}`)
			}
			if (
				this.form.description
				&& /^\s*description\s*:/im.test(block) === false
			) {
				lines.push(`description: ${this.form.description}`)
			}

			return [...lines, block].filter((part) => part !== '').join('\n')
		},

		/**
		 * Compose the fenced agentskills.io package sent to `importSkill()`/
		 * `installFromSource()` on create — the server-side
		 * `SkillSerializer::fromPackage()` is the single source of truth for
		 * splitting it back into `frontmatter` + `body`.
		 *
		 * @return {string} The fenced package string.
		 */
		buildPackage() {
			return `---\n${this.buildFrontmatterBlock()}\n---\n${this.form.body || ''}`
		},

		/**
		 * Build the edit payload. Spreads the existing skill payload first so
		 * schema fields this form does not surface (`state`, `source`,
		 * `installedOn`, provenance, `scanReport`, …) survive the PUT.
		 *
		 * @return {object} The skill payload for the generic object write path.
		 */
		buildEditPayload() {
			const base = this.effectiveSkill ? { ...this.effectiveSkill } : {}
			delete base['@self']

			const payload = {
				...base,
				name: this.form.name,
				description: this.form.description,
				frontmatter: this.form.frontmatter,
				body: this.form.body,
				files: this.files.map((file) => ({
					name: file.name,
					content: file.content,
				})),
			}

			if (this.effectiveSkill && this.effectiveSkill.id) {
				payload.id = this.effectiveSkill.id
			}
			return payload
		},

		/**
		 * Import a pasted full agentskills.io package (task 2) — routes
		 * through the EXISTING `importSkill()` path so the server's
		 * `SkillSerializer::fromPackage()` splits it, never reimplemented
		 * client-side. Populates the form from the created Skill so the user
		 * can review/edit before the modal's own Save (which will then UPDATE
		 * this newly-created object).
		 *
		 * @return {Promise<void>}
		 */
		async importPastedPackage() {
			const text = this.pasteText.trim()
			if (!text) {
				return
			}
			if (text.startsWith('---') === false) {
				this.pasteError = this.t(
					'hermiq',
					'This does not look like a fenced agentskills.io package (it must start with "---").',
				)
				return
			}

			this.pasteBusy = true
			this.pasteError = ''
			try {
				const result = await importSkill(text)
				this.importedSkill = result
				this.form = {
					name: result.name || '',
					description: result.description || '',
					frontmatter: result.frontmatter || '',
					body: result.body || '',
				}
				this.files = Array.isArray(result.files)
					? result.files.map((file) => ({
							name: file.name || '',
							content: file.content || '',
						}))
					: []
				this.pasteText = ''
			} catch (e) {
				this.pasteError =
					e?.response?.data?.error
					|| e?.message
					|| this.t('hermiq', 'Could not import the pasted package.')
			} finally {
				this.pasteBusy = false
			}
		},

		/**
		 * Persist the skill and notify the parent. EDIT uses the generic
		 * OpenRegister object write path (merging unsurfaced fields); CREATE
		 * uses the existing `importSkill()`/`installFromSource()` path
		 * (`saveTarget`), with a follow-up PUT to land any authored `files`
		 * (both create endpoints always persist `files: []`).
		 *
		 * @spec openspec/specs/skills-catalog/spec.md#requirement-authored-skills-persist-through-the-existing-catalog-write-path-without-a-new-backend
		 * @return {Promise<void>}
		 */
		async save() {
			if (!this.form.name.trim()) {
				return
			}

			this.saving = true
			this.error = ''
			try {
				let saved

				if (this.effectiveSkill && this.effectiveSkill.id) {
					// skill-maturity: EDIT goes through the guarded hermiq merge path
					// (PUT /api/skills/{id}) so the server silently preserves the
					// computed maturity fields; targetLevel stays freely editable.
					saved = await updateSkill(
						this.effectiveSkill.id,
						this.buildEditPayload(),
					)
					if (saved === null) {
						this.error = this.t('hermiq', 'Could not save skill')
						return
					}
				} else {
					const pkg = this.buildPackage()
					saved =
						this.saveTarget === 'quarantine'
							? await installFromSource(pkg, 'local')
							: await importSkill(pkg)

					if (this.files.length > 0) {
						// skill-maturity: the follow-up files write uses the same
						// guarded merge path as an edit save.
						const withFiles = await updateSkill(saved.uuid || saved.id, {
							...saved,
							files: this.files.map((file) => ({
								name: file.name,
								content: file.content,
							})),
						})
						if (withFiles) {
							saved = withFiles
						}
					}
				}

				this.$emit('saved', saved)
				this.handleClose()
			} catch (e) {
				this.error =
					e?.response?.data?.error
					|| e?.message
					|| this.t('hermiq', 'Unknown error')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.skill-form {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 20px;
}

.skill-form__title {
	margin: 0 0 4px;
	font-size: 20px;
	font-weight: 600;
}

.skill-form__subhead {
	margin: 8px 0 0;
	font-size: 15px;
}

.skill-form__hint {
	margin: 0;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.skill-form__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.skill-form__label {
	font-weight: bold;
}

.skill-form__paste {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.skill-form__file {
	display: flex;
	flex-direction: column;
	gap: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 12px;
}

.skill-form__file-head {
	display: flex;
	align-items: flex-end;
	gap: 8px;
}

.skill-form__file-head > *:first-child {
	flex: 1;
}

.skill-form__add-file {
	display: flex;
	align-items: flex-end;
	gap: 8px;
}

.skill-form__add-file > *:first-child {
	flex: 1;
}

.skill-form__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 8px;
}
</style>
