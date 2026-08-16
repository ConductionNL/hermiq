<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  CreateIncidentDialog — open a new incident record (agent-lifecycle-governance).

  Own file per ADR-004 modal-isolation, using NcDialog (design.md), mirroring
  BudgetFormModal.vue's structure. Persists via TenantOpsController::createIncident()
  (action-auth-gated), NOT the generic createObjectStore object path — incident
  creation is a governance action (ADR-023), not ordinary CRUD.

  @spec openspec/changes/agent-lifecycle-governance/tasks.md#task-9-tenantopsvue-access-review-incidents-retention-ui
  @spec openspec/changes/agent-lifecycle-governance/specs/agent-lifecycle-governance/spec.md#requirement-incident-records-linked-to-runs-and-agents
-->
<template>
	<NcDialog
		:name="t('hermiq', 'Open incident')"
		:open="show"
		size="normal"
		@update:open="$emit('close')">
		<div class="create-incident-dialog">
			<NcNoteCard
				v-if="error"
				type="error"
				:heading="t('hermiq', 'Could not create incident')">
				{{ error }}
			</NcNoteCard>

			<NcTextArea
				v-model="form.description"
				:label="t('hermiq', 'Description')"
				:placeholder="t('hermiq', 'What happened?')"
				required
				resize="vertical" />

			<NcTextArea
				v-model="form.impact"
				:label="t('hermiq', 'Impact')"
				:placeholder="
					t('hermiq', 'Who or what was affected, and how severely?')
				"
				required
				resize="vertical" />

			<NcTextArea
				v-model="form.actionsTaken"
				:label="t('hermiq', 'Actions taken')"
				:placeholder="t('hermiq', 'What remedial action was taken?')"
				required
				resize="vertical" />

			<NcTextField
				v-model="form.linkedAgentId"
				:label="t('hermiq', 'Linked agent UUID (optional)')"
				placeholder="00000000-0000-0000-0000-000000000000" />

			<NcTextField
				v-model="linkedRunIdsText"
				:label="t('hermiq', 'Linked run IDs (optional, comma-separated)')" />
		</div>

		<template #actions>
			<NcButton :disabled="saving" @click="$emit('close')">
				{{ t('hermiq', 'Cancel') }}
			</NcButton>
			<NcButton type="primary" :disabled="saving || !canSave" @click="save">
				<template v-if="saving" #icon>
					<NcLoadingIcon :size="20" />
				</template>
				{{ t('hermiq', 'Open incident') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import {
	NcButton,
	NcDialog,
	NcLoadingIcon,
	NcNoteCard,
	NcTextArea,
	NcTextField,
} from '@nextcloud/vue'
import { createIncident } from '../api/tenantOps.js'

export default {
	name: 'CreateIncidentDialog',

	components: {
		NcButton,
		NcDialog,
		NcLoadingIcon,
		NcNoteCard,
		NcTextArea,
		NcTextField,
	},

	props: {
		/** Whether the dialog is visible. */
		show: {
			type: Boolean,
			default: false,
		},

		/** Optional agent UUID to preselect (e.g. opened from an agent/run context). */
		defaultAgentId: {
			type: String,
			default: '',
		},

		/** Optional run (AuditTrail entry) uuids to preselect. */
		defaultRunIds: {
			type: Array,
			default: () => [],
		},
	},

	emits: ['close', 'created'],

	data() {
		return {
			form: this.blankForm(),
			linkedRunIdsText: '',
			saving: false,
			error: '',
		}
	},

	computed: {
		/**
		 * Whether the form has enough to submit: description, impact, and
		 * actionsTaken are all required (matches the Incident schema).
		 *
		 * @return {boolean}
		 */
		canSave() {
			return (
				this.form.description.trim() !== ''
				&& this.form.impact.trim() !== ''
				&& this.form.actionsTaken.trim() !== ''
			)
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
		 * A blank incident form.
		 *
		 * @return {object} The blank form model.
		 */
		blankForm() {
			return {
				description: '',
				impact: '',
				actionsTaken: '',
				linkedAgentId: this.defaultAgentId || '',
			}
		},

		/**
		 * Seed the form from the default props (create-only; no edit mode).
		 *
		 * @return {void}
		 */
		resetForm() {
			this.error = ''
			this.form = this.blankForm()
			this.linkedRunIdsText = (this.defaultRunIds || []).join(', ')
		},

		/**
		 * Persist the incident and notify the parent.
		 *
		 * @return {Promise<void>}
		 */
		async save() {
			this.saving = true
			this.error = ''
			try {
				const linkedRunIds = this.linkedRunIdsText
					.split(',')
					.map((id) => id.trim())
					.filter((id) => id !== '')

				const payload = {
					description: this.form.description,
					impact: this.form.impact,
					actionsTaken: this.form.actionsTaken,
					linkedAgentId: this.form.linkedAgentId || null,
					linkedRunIds,
				}

				const created = await createIncident(payload)
				this.$emit('created', created)
				this.$emit('close')
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
.create-incident-dialog {
	display: flex;
	flex-direction: column;
	gap: 12px;
}
</style>
