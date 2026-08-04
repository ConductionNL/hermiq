<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  - SPDX-License-Identifier: EUPL-1.2
-->

<template>
	<NcDialog
		:name="t('hermiq', 'Run flow')"
		:open="true"
		size="normal"
		@update:open="$emit('close')">
		<div class="run-flow-dialog">
			<NcNoteCard type="info">
				{{ t('hermiq', 'The flow walks a concrete object. Name the object it should run against — its state seeds the run, and object-write steps write back onto it.') }}
			</NcNoteCard>

			<CnRegisterSchemaSelect
				:register="subjectRegister"
				:schema="subjectSchema"
				@update:register="onRegister"
				@update:schema="onSchema" />

			<NcSelect
				v-if="candidates.length > 0"
				:model-value="selectedCandidate"
				:options="candidates"
				:loading="loadingCandidates"
				label="label"
				:input-label="t('hermiq', 'Test against object')"
				@update:model-value="onCandidate" />

			<NcTextField
				:model-value="subjectUuid"
				:label="t('hermiq', 'Subject object UUID')"
				:placeholder="t('hermiq', 'Pick above, or paste a UUID')"
				required
				@update:model-value="subjectUuid = $event" />

			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>
		</div>

		<template #actions>
			<NcButton type="tertiary" :disabled="running" @click="$emit('close')">
				{{ t('hermiq', 'Cancel') }}
			</NcButton>
			<NcButton type="primary" :disabled="running || !canRun" @click="run">
				<template #icon>
					<NcLoadingIcon v-if="running" :size="20" />
					<Play v-else :size="20" />
				</template>
				{{ t('hermiq', 'Run') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcLoadingIcon, NcNoteCard, NcSelect, NcTextField } from '@nextcloud/vue'
import { CnRegisterSchemaSelect } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import Play from 'vue-material-design-icons/Play.vue'
import { useFlowEditorStore } from '../store/flowEditor.js'

/**
 * RunFlowDialog — collect the subject object a flow run walks against.
 *
 * The engine seeds a run's items from a real OpenRegister object and writes
 * results back onto it, so a run cannot be started from the flow alone. Kept
 * in its own file per the modal-isolation rule.
 *
 * The run goes through the editor store's `run()` action rather than calling
 * the API helper here: the store is where the resulting log lands, and the
 * canvas reads that log to put a result dot on each step. Executing directly
 * would leave the log in this dialog and the canvas blank.
 *
 * A run is QUEUED, not awaited. `POST /api/flows/{id}/run` returns a FlowRun
 * whose status is pending — flows execute asynchronously unless the flow says
 * otherwise (`executionMode`) — so this dialog reports that the run started,
 * and the store reads it back once for the log. It is also why the run needs a
 * SAVED flow: the endpoint runs a stored flow by uuid, and there is no
 * execute-this-unsaved-document endpoint to call instead.
 */
export default {
	name: 'RunFlowDialog',

	components: {
		NcButton,
		NcDialog,
		NcLoadingIcon,
		CnRegisterSchemaSelect,
		NcNoteCard,
		NcSelect,
		NcTextField,
		Play,
	},

	emits: ['close', 'ran'],

	setup() {
		return { editor: useFlowEditorStore() }
	},

	data() {
		// A flow that declares a trigger already names the register and schema
		// it fires on, so "test this trigger" starts pre-aimed at those.
		return {
			subjectRegister: this.editor.flow.triggerRegister || 'hermiq',
			subjectSchema: this.editor.flow.triggerSchema || '',
			subjectUuid: '',
			candidates: [],
			loadingCandidates: false,
			running: false,
			error: '',
		}
	},

	computed: {
		/**
		 * Whether every field the executor requires is present.
		 *
		 * @return {boolean} True when the run can be submitted.
		 */
		canRun() {
			return this.subjectRegister !== '' && this.subjectSchema !== '' && this.subjectUuid !== ''
		},

		/** @return {object|null} The candidate matching the current uuid. */
		selectedCandidate() {
			return this.candidates.find((row) => row.id === this.subjectUuid) || null
		},
	},

	created() {
		// Opened from a flow that already declares its trigger wiring: load
		// candidates straight away so "test this trigger" is one click.
		if (this.subjectRegister && this.subjectSchema) {
			this.onSchema(this.subjectSchema)
		}
	},

	methods: {
		/**
		 * Register changed: reset the schema and any loaded candidates.
		 *
		 * @param {string} value The register id.
		 * @return {void}
		 */
		onRegister(value) {
			this.subjectRegister = value || ''
			this.subjectSchema = ''
			this.candidates = []
			this.subjectUuid = ''
		},

		/**
		 * Schema chosen: load a few objects so the flow can be tested against a
		 * real record without going to look up a UUID.
		 *
		 * @param {string} value The schema id.
		 * @return {Promise<void>}
		 */
		async onSchema(value) {
			this.subjectSchema = value || ''
			this.candidates = []
			this.subjectUuid = ''
			if (!this.subjectRegister || !this.subjectSchema) {
				return
			}

			this.loadingCandidates = true
			try {
				const url = generateUrl('/apps/openregister/api/objects/{register}/{schema}', {
					register: this.subjectRegister,
					schema: this.subjectSchema,
				})
				// `_limit` (underscored) — plain `limit` is treated as a property filter.
				const response = await axios.get(`${url}?_limit=20`)
				const rows = response?.data?.results || []
				this.candidates = rows.map((row) => ({
					id: row['@self']?.id || row.id,
					label: row.name || row.title || row['@self']?.id || row.id,
				})).filter((row) => row.id)
			} catch (e) {
				this.candidates = []
			} finally {
				this.loadingCandidates = false
			}
		},

		/**
		 * Candidate picked from the list.
		 *
		 * @param {object} option The chosen option.
		 * @return {void}
		 */
		onCandidate(option) {
			this.subjectUuid = option?.id || ''
		},

		/**
		 * Execute the flow and hand the trace back to the builder.
		 *
		 * @return {Promise<void>}
		 */
		async run() {
			this.running = true
			this.error = ''
			try {
				const result = await this.editor.run({
					uuid: this.subjectUuid,
					register: this.subjectRegister,
					schema: this.subjectSchema,
				})
				this.$emit('ran', result)
			} catch (e) {
				this.error = e?.response?.data?.error || e?.message || this.t('hermiq', 'The flow run failed.')
			} finally {
				this.running = false
			}
		},
	},
}
</script>

<style scoped>
.run-flow-dialog {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 0 12px 12px;
}
</style>
