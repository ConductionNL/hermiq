<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  RestoreDialog — an administrator restores an organisation to a backup file or
  to a moment in time (operations-backup-and-restore, design D3). The preview
  comes first and writes nothing: per schema, how many objects will be put
  back, changed back, were created after the point, are missing from it, or
  have no history at that time. Only after a preview that is not refused can
  the restore be queued, with the choice for objects created after the point.
  Own file per the modal-isolation gate; every NcSelect carries an inputLabel.

  @spec openspec/changes/operations-backup-and-restore/specs/backup-restore/spec.md#requirement-a-restore-starts-with-a-preview-of-what-will-change-req-bkup-002
-->
<template>
	<NcModal
		:show="show"
		size="large"
		:name="t('hermiq', 'Restore')"
		@close="$emit('close')">
		<div class="restore-dialog">
			<h2 class="restore-dialog__title">
				{{ t('hermiq', 'Restore') }}
			</h2>

			<NcNoteCard type="warning">
				{{ t('hermiq', 'While a restore runs, the organisation\'s agents are stopped with the kill switch. Every object written back is on the audit trail, so a later state can still be brought back.') }}
			</NcNoteCard>

			<div class="restore-dialog__kind" role="radiogroup" :aria-label="t('hermiq', 'Restore to')">
				<NcCheckboxRadioSwitch
					v-model="form.kind"
					type="radio"
					value="backup"
					name="restore-kind"
					data-testid="restore-kind-backup"
					@update:modelValue="clearPreview">
					{{ t('hermiq', 'A backup') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch
					v-model="form.kind"
					type="radio"
					value="moment"
					name="restore-kind"
					data-testid="restore-kind-moment"
					@update:modelValue="clearPreview">
					{{ t('hermiq', 'A moment in time') }}
				</NcCheckboxRadioSwitch>
			</div>

			<NcSelect
				v-if="form.kind === 'backup'"
				v-model="backupOption"
				:inputLabel="t('hermiq', 'Backup')"
				:options="backupOptions"
				:loading="loadingBackups"
				:placeholder="t('hermiq', 'Pick a backup')"
				label="label"
				trackBy="value"
				data-testid="restore-backup"
				@update:modelValue="clearPreview" />

			<template v-else>
				<NcSelect
					v-model="organisationOption"
					:inputLabel="t('hermiq', 'Organisation')"
					:options="organisationOptions"
					:taggable="true"
					:placeholder="t('hermiq', 'Pick or type an organisation')"
					label="label"
					trackBy="value"
					data-testid="restore-organisation"
					@update:modelValue="clearPreview" />
				<NcTextField
					v-model="form.at"
					type="datetime-local"
					:label="t('hermiq', 'Moment to restore to')"
					data-testid="restore-moment"
					@update:modelValue="clearPreview" />
			</template>

			<div class="restore-dialog__actions">
				<NcButton :disabled="!canPreview || previewing" data-testid="restore-preview" @click="runPreview">
					<template v-if="previewing" #icon>
						<NcLoadingIcon :size="20" />
					</template>
					{{ t('hermiq', 'Preview') }}
				</NcButton>
			</div>

			<NcNoteCard v-if="preview && preview.refused" type="error" data-testid="restore-refused">
				{{ preview.refused }}
			</NcNoteCard>

			<template v-if="preview && !preview.refused">
				<p v-if="rows.length === 0" data-testid="restore-nothing">
					{{ t('hermiq', 'Nothing would change: every object is as it was at this point.') }}
				</p>
				<table v-else class="restore-dialog__preview" data-testid="restore-preview-table">
					<caption>{{ t('hermiq', 'What the restore will change, per kind of object') }}</caption>
					<thead>
						<tr>
							<th scope="col">
								{{ t('hermiq', 'Kind') }}
							</th>
							<th scope="col">
								{{ t('hermiq', 'Put back') }}
							</th>
							<th scope="col">
								{{ t('hermiq', 'Changed back') }}
							</th>
							<th scope="col">
								{{ t('hermiq', 'Created after this point') }}
							</th>
							<th scope="col">
								{{ t('hermiq', 'Missing from this point') }}
							</th>
							<th scope="col">
								{{ t('hermiq', 'No history at that time') }}
							</th>
							<th scope="col">
								{{ t('hermiq', 'Not restorable') }}
							</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="row in rows" :key="row.schema">
							<th scope="row">
								{{ row.schema }}
							</th>
							<td>{{ row.putBack }}</td>
							<td>{{ row.changed }}</td>
							<td>{{ row.createdAfter }}</td>
							<td>{{ row.missing }}</td>
							<td>{{ row.noHistory }}</td>
							<td>{{ row.notRestorable.join(', ') }}</td>
						</tr>
					</tbody>
				</table>

				<NcCheckboxRadioSwitch
					v-model="form.removeCreatedAfter"
					type="switch"
					:disabled="preview.createdAfter === 0"
					data-testid="restore-remove-later">
					{{ t('hermiq', 'Move objects created after this point to the deleted objects (they can still be recovered there)') }}
				</NcCheckboxRadioSwitch>
			</template>

			<NcNoteCard v-if="queued" type="success" data-testid="restore-queued">
				{{ t('hermiq', 'The restore is queued. You get a notification when it has finished.') }}
			</NcNoteCard>

			<p v-if="error" class="restore-dialog__error" role="alert">
				{{ error }}
			</p>

			<div class="restore-dialog__actions">
				<NcButton @click="$emit('close')">
					{{ t('hermiq', 'Close') }}
				</NcButton>
				<NcButton
					variant="error"
					:disabled="!canRestore || restoring"
					data-testid="restore-start"
					@click="runRestore">
					<template v-if="restoring" #icon>
						<NcLoadingIcon :size="20" />
					</template>
					{{ t('hermiq', 'Restore') }}
				</NcButton>
			</div>
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
	NcSelect,
	NcTextField,
} from '@nextcloud/vue'
import {
	backupOrganisations,
	listBackups,
	previewRestore,
	previewRows,
	restorePoint,
	startRestore,
} from '../api/backup.js'

export default {
	name: 'RestoreDialog',
	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcModal,
		NcNoteCard,
		NcSelect,
		NcTextField,
	},

	props: {
		show: { type: Boolean, default: false },
	},

	emits: ['close'],

	data() {
		return {
			form: { kind: 'backup', file: '', organisation: '', at: '', removeCreatedAfter: false },
			backups: [],
			loadingBackups: false,
			backupOption: null,
			organisationOption: null,
			preview: null,
			previewing: false,
			restoring: false,
			queued: false,
			error: '',
		}
	},

	computed: {
		/**
		 * The backups to pick from: file time and organisation.
		 *
		 * @return {Array<{value: string, label: string}>} The options.
		 */
		backupOptions() {
			return this.backups.map((backup) => ({
				value: backup.name,
				label: backup.manifest?.organisation
					? `${backup.manifest.organisation} · ${backup.manifest.createdAt || backup.name}`
					: backup.name,
			}))
		},

		/**
		 * The organisations the backups name.
		 *
		 * @return {Array<{value: string, label: string}>} The options.
		 */
		organisationOptions() {
			return backupOrganisations(this.backups).map((name) => ({ value: name, label: name }))
		},

		/**
		 * The point the form describes.
		 *
		 * @return {object} The point.
		 */
		point() {
			return restorePoint({
				...this.form,
				file: this.backupOption?.value || '',
				organisation: this.organisationOption?.value || this.organisationOption?.label || '',
			})
		},

		/**
		 * Whether the form names a point.
		 *
		 * @return {boolean} Whether it does.
		 */
		canPreview() {
			if (this.point.kind === 'moment') {
				return this.point.organisation !== '' && this.point.at !== ''
			}
			return this.point.file !== ''
		},

		/**
		 * Whether a restore may start: only after a preview that is not refused.
		 *
		 * @return {boolean} Whether it may.
		 */
		canRestore() {
			return this.preview !== null && !this.preview.refused && !this.queued
		},

		/**
		 * The preview, one row per schema with something to say.
		 *
		 * @return {Array<object>} The rows.
		 */
		rows() {
			return previewRows(this.preview)
		},
	},

	watch: {
		show(open) {
			if (open) {
				this.reset()
				this.loadBackups()
			}
		},
	},

	mounted() {
		if (this.show) {
			this.loadBackups()
		}
	},

	methods: {
		/**
		 * Start over: no preview, nothing queued.
		 *
		 * @return {void}
		 */
		reset() {
			this.form = { kind: 'backup', file: '', organisation: '', at: '', removeCreatedAfter: false }
			this.backupOption = null
			this.organisationOption = null
			this.queued = false
			this.clearPreview()
		},

		/**
		 * A changed point needs a new preview before it can be restored.
		 *
		 * @return {void}
		 */
		clearPreview() {
			this.preview = null
			this.error = ''
		},

		/**
		 * Read the backups in the folder.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/operations-backup-and-restore/specs/backup-restore/spec.md#requirement-a-restore-starts-with-a-preview-of-what-will-change-req-bkup-002
		 */
		async loadBackups() {
			this.loadingBackups = true
			try {
				this.backups = await listBackups()
			} catch {
				this.backups = []
				this.error = this.t('hermiq', 'The backup folder could not be read.')
			} finally {
				this.loadingBackups = false
			}
		},

		/**
		 * Ask what the restore would change.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/operations-backup-and-restore/specs/backup-restore/spec.md#requirement-a-restore-starts-with-a-preview-of-what-will-change-req-bkup-002
		 */
		async runPreview() {
			this.previewing = true
			this.error = ''
			try {
				this.preview = await previewRestore(this.point)
			} catch (e) {
				this.preview = null
				this.error = e?.response?.data?.error || this.t('hermiq', 'The preview could not be made.')
			} finally {
				this.previewing = false
			}
		},

		/**
		 * Queue the restore of the previewed point.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/operations-backup-and-restore/specs/backup-restore/spec.md#requirement-a-restore-stops-the-organisations-agents-and-writes-through-the-audit-trail-req-bkup-003
		 */
		async runRestore() {
			this.restoring = true
			this.error = ''
			try {
				await startRestore(this.point)
				this.queued = true
			} catch (e) {
				this.error = e?.response?.data?.refused || e?.response?.data?.error || this.t('hermiq', 'The restore could not be started.')
			} finally {
				this.restoring = false
			}
		},
	},
}
</script>

<style scoped>
.restore-dialog {
	padding: calc(var(--default-grid-baseline, 4px) * 5);
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline, 4px) * 3);
}

.restore-dialog__kind {
	display: flex;
	gap: calc(var(--default-grid-baseline, 4px) * 4);
}

.restore-dialog__actions {
	display: flex;
	justify-content: flex-end;
	gap: calc(var(--default-grid-baseline, 4px) * 2);
}

.restore-dialog__preview {
	border-collapse: collapse;
	width: 100%;
}

.restore-dialog__preview caption {
	text-align: start;
	color: var(--color-text-maxcontrast);
	padding-block-end: calc(var(--default-grid-baseline, 4px) * 2);
}

.restore-dialog__preview th,
.restore-dialog__preview td {
	text-align: start;
	padding: calc(var(--default-grid-baseline, 4px) * 2);
	border-block-end: 1px solid var(--color-border);
}

.restore-dialog__error {
	color: var(--color-error-text, var(--color-error));
}
</style>
