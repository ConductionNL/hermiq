<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  SkillImportModal — import an agentskills.io package into the tenant's skills.

  Paste a package (Markdown front-matter + body) and either import it directly or
  install it from a hub in quarantine (must be approved before an agent can use
  it). Lives in its own file per the modal-isolation rule; opened from the Skills
  index page's "Import skill" button. Emits `imported` on success (parent
  refreshes the list) and `close` to dismiss.
-->
<template>
	<NcModal size="normal" :can-close="!busy" @close="$emit('close')">
		<div class="skill-import">
			<h2 class="skill-import__title">
				{{ t('hermiq', 'Import a skill') }}
			</h2>

			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>

			<textarea
				v-model="importText"
				class="skill-import__textarea"
				:disabled="busy"
				:placeholder="placeholder" />

			<p class="skill-import__note">
				{{ t('hermiq', 'Skills installed from a hub or another organisation start quarantined and must be approved before an agent can use them.') }}
			</p>

			<div class="skill-import__actions">
				<NcButton type="tertiary" :disabled="busy" @click="$emit('close')">
					{{ t('hermiq', 'Cancel') }}
				</NcButton>
				<NcButton
					type="secondary"
					:disabled="busy || importText.trim() === ''"
					@click="run('hub')">
					{{ t('hermiq', 'Install from hub (quarantine)') }}
				</NcButton>
				<NcButton
					type="primary"
					:disabled="busy || importText.trim() === ''"
					@click="run('direct')">
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
import { importSkill, installFromSource } from '../api/skills.js'

export default {
	name: 'SkillImportModal',

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
			placeholder: '---\nname: My Skill\ndescription: What it does\n---\n# My Skill\n\nInstructions…',
		}
	},

	methods: {
		/**
		 * Import the pasted package, either directly or via a quarantined hub install.
		 *
		 * @param {string} mode `direct` (importSkill) or `hub` (installFromSource).
		 * @return {Promise<void>}
		 */
		async run(mode) {
			const pkg = this.importText.trim()
			if (pkg === '') {
				return
			}
			this.busy = true
			this.error = ''
			try {
				if (mode === 'hub') {
					await installFromSource(pkg, 'hub')
				} else {
					await importSkill(pkg)
				}
				/**
				 * @event imported Emitted after a successful import so the parent refreshes.
				 */
				this.$emit('imported')
				this.$emit('close')
			} catch (e) {
				this.error = e?.response?.data?.error || e?.message || this.t('hermiq', 'Could not import the skill')
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.skill-import {
	padding: 24px;
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.skill-import__title {
	margin: 0;
	font-size: 20px;
	font-weight: 600;
}

.skill-import__textarea {
	width: 100%;
	min-height: 180px;
	font-family: var(--font-face-monospace, monospace);
	font-size: 13px;
	resize: vertical;
}

.skill-import__note {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	margin: 0;
}

.skill-import__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 8px;
}
</style>
