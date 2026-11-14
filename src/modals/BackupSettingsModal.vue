<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  BackupSettingsModal — an administrator switches the nightly backup on, and
  sets the folder in their Files, the hour, how many backups to keep and
  whether chat sessions are included (operations-backup-and-restore, design
  D1). Own file per the modal-isolation gate. The server refuses a value out
  of range and the refusal is shown as it comes.

  @spec openspec/changes/operations-backup-and-restore/specs/backup-restore/spec.md#requirement-an-administrator-can-back-up-an-organisations-hermiq-data-to-files-on-a-schedule-req-bkup-001
-->
<template>
	<NcModal
		:show="show"
		size="normal"
		:name="t('hermiq', 'Backups')"
		@close="$emit('close')">
		<div class="backup-settings">
			<h2 class="backup-settings__title">
				{{ t('hermiq', 'Backups') }}
			</h2>

			<NcNoteCard type="info">
				{{ t('hermiq', 'Backups contain your agents, skills, memories and settings. With sessions included they also contain every chat of every person in the organisation. Only people who can open this folder can read them.') }}
			</NcNoteCard>

			<NcCheckboxRadioSwitch v-model="form.enabled" type="switch">
				{{ t('hermiq', 'Back up every night') }}
			</NcCheckboxRadioSwitch>
			<NcTextField
				v-model="form.folder"
				:label="t('hermiq', 'Folder in your Files')" />
			<NcTextField
				v-model="form.hour"
				type="number"
				min="0"
				max="23"
				:label="t('hermiq', 'Hour of the day (0 to 23)')" />
			<NcTextField
				v-model="form.keep"
				type="number"
				min="1"
				max="365"
				:label="t('hermiq', 'Backups to keep per organisation')" />
			<NcCheckboxRadioSwitch v-model="form.includeSessions" type="switch">
				{{ t('hermiq', 'Include chat sessions') }}
			</NcCheckboxRadioSwitch>

			<div class="backup-settings__actions">
				<NcButton @click="$emit('close')">
					{{ t('hermiq', 'Cancel') }}
				</NcButton>
				<NcButton variant="primary" :disabled="saving" @click="save">
					<template v-if="saving" #icon>
						<NcLoadingIcon :size="20" />
					</template>
					{{ t('hermiq', 'Save') }}
				</NcButton>
			</div>

			<p v-if="error" class="backup-settings__error" role="alert">
				{{ error }}
			</p>
		</div>
	</NcModal>
</template>

<script>
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcLoadingIcon,
	NcModal,
	NcNoteCard,
	NcTextField,
} from '@nextcloud/vue'
import { backupForm, backupPayload, saveBackupSettings } from '../api/backup.js'

export default {
	name: 'BackupSettingsModal',
	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcModal,
		NcNoteCard,
		NcTextField,
	},

	props: {
		show: { type: Boolean, default: false },
		settings: { type: Object, default: null },
	},

	emits: ['close', 'saved'],

	data() {
		return {
			form: backupForm(this.settings),
			saving: false,
			error: '',
		}
	},

	watch: {
		show(open) {
			if (open) {
				this.form = backupForm(this.settings)
				this.error = ''
			}
		},
	},

	methods: {
		/**
		 * Save the settings; on a refusal, show the server's reason.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/operations-backup-and-restore/specs/backup-restore/spec.md#requirement-an-administrator-can-back-up-an-organisations-hermiq-data-to-files-on-a-schedule-req-bkup-001
		 */
		async save() {
			this.saving = true
			this.error = ''
			try {
				const saved = await saveBackupSettings(backupPayload(this.form))
				this.$emit('saved', saved)
				this.$emit('close')
			} catch (e) {
				this.error = e?.response?.data?.error || this.t('hermiq', 'The backup settings could not be saved.')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.backup-settings {
	padding: calc(var(--default-grid-baseline, 4px) * 5);
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline, 4px) * 3);
}

.backup-settings__actions {
	display: flex;
	justify-content: flex-end;
	gap: calc(var(--default-grid-baseline, 4px) * 2);
}

.backup-settings__error {
	color: var(--color-error-text, var(--color-error));
}
</style>
