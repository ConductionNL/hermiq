<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  TemplateImportModal — import an agent-template-gallery JSON package.

  Paste a package (from AgentTemplateGallery.vue's "Export", or from another
  organisation) and either import it directly (a locally-authored/trusted package) or
  import it as coming from another organisation, which lands it quarantined and
  content-scanned — it must be approved before "Use this template" is available. Lives
  in its own file per the modal-isolation rule; opened from the gallery's "Import
  template" button. Emits `imported` on success (parent refreshes the list) and `close`
  to dismiss. Mirrors SkillImportModal.vue's shape exactly.

  @spec openspec/changes/agent-template-gallery/tasks.md#task-8-templateimportmodalvue-agentcatalogvue-entry-point
-->
<template>
	<NcModal size="normal" :canClose="!busy" @close="$emit('close')">
		<div class="template-import">
			<h2 class="template-import__title">
				{{ t('hermiq', 'Import an agent template') }}
			</h2>

			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>

			<!--
			  The <label> WRAPS the control — the canonical HTML association, no
			  for/id pair needed. A placeholder is NOT a label: it disappears the
			  moment the field has content, so it names the field for nobody once
			  the user starts typing.
			-->
			<label class="template-import__label">
				<span>{{ t('hermiq', 'Template package') }}</span>
				<textarea
					v-model="importText"
					class="template-import__textarea"
					:disabled="busy"
					:placeholder="placeholder" />
			</label>

			<p class="template-import__note">
				{{
					t(
						'hermiq',
						'Templates imported from another organisation start quarantined and must be approved before they can be used.',
					)
				}}
			</p>

			<div class="template-import__actions">
				<NcButton type="tertiary" :disabled="busy" @click="$emit('close')">
					{{ t('hermiq', 'Cancel') }}
				</NcButton>
				<NcButton
					type="secondary"
					:disabled="busy || importText.trim() === ''"
					@click="run('org')">
					{{
						t('hermiq', 'Import from another organisation (quarantine)')
					}}
				</NcButton>
				<NcButton
					type="primary"
					:disabled="busy || importText.trim() === ''"
					@click="run('local')">
					<template v-if="busy" #icon>
						<NcLoadingIcon :size="18" />
					</template>
					{{ t('hermiq', 'Import') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { NcButton, NcLoadingIcon, NcModal, NcNoteCard } from '@nextcloud/vue'
import { importAgentTemplate } from '../api/agentTemplates.js'

export default {
	name: 'TemplateImportModal',

	components: {
		NcButton,
		NcLoadingIcon,
		NcModal,
		NcNoteCard,
	},

	data() {
		return {
			importText: '',
			busy: false,
			error: '',
			placeholder:
				'{\n  "name": "My template",\n  "description": "What it does",\n  "systemPrompt": "…"\n}',
		}
	},

	methods: {
		/**
		 * Import the pasted package with the chosen source.
		 *
		 * @param {string} source The import source ('local' | 'org').
		 * @return {Promise<void>}
		 */
		async run(source) {
			const pkg = this.importText.trim()
			if (pkg === '') {
				return
			}
			this.busy = true
			this.error = ''
			try {
				await importAgentTemplate(pkg, source)
				/**
				 * @event imported Emitted after a successful import so the parent refreshes.
				 */
				this.$emit('imported')
				this.$emit('close')
			} catch (e) {
				this.error =
					e?.response?.data?.error
					|| e?.message
					|| this.t('hermiq', 'Could not import the template')
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.template-import {
	padding: 24px;
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.template-import__title {
	margin: 0;
	font-size: 20px;
	font-weight: 600;
}

.template-import__label {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.template-import__textarea {
	width: 100%;
	min-height: 180px;
	font-family: var(--font-face-monospace, monospace);
	font-size: 13px;
	resize: vertical;
}

.template-import__note {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	margin: 0;
}

.template-import__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 8px;
}
</style>
