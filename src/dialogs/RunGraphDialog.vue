<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  - SPDX-License-Identifier: EUPL-1.2
-->

<template>
	<NcDialog
		:name="t('hermiq', 'Run graph')"
		:open="true"
		size="normal"
		@update:open="$emit('close')">
		<div class="run-graph-dialog">
			<NcNoteCard type="info">
				{{ t('hermiq', 'The graph walks a concrete object. Name the object it should run against — its state seeds the run, and object-write nodes write back onto it.') }}
			</NcNoteCard>

			<NcTextField
				:model-value="subjectRegister"
				:label="t('hermiq', 'Subject register')"
				placeholder="hermiq"
				required
				@update:model-value="subjectRegister = $event" />

			<NcTextField
				:model-value="subjectSchema"
				:label="t('hermiq', 'Subject schema')"
				placeholder="agent"
				required
				@update:model-value="subjectSchema = $event" />

			<NcTextField
				:model-value="subjectUuid"
				:label="t('hermiq', 'Subject object UUID')"
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
import { NcButton, NcDialog, NcLoadingIcon, NcNoteCard, NcTextField } from '@nextcloud/vue'
import Play from 'vue-material-design-icons/Play.vue'
import { runGraph } from '../api/graph.js'

/**
 * RunGraphDialog — collect the subject object a graph run walks against.
 *
 * GraphExecutor seeds its state from a real OpenRegister object and writes
 * results back onto it, so a run cannot be started from the graph alone. Kept
 * in its own file per the modal-isolation rule.
 */
export default {
	name: 'RunGraphDialog',

	components: {
		NcButton,
		NcDialog,
		NcLoadingIcon,
		NcNoteCard,
		NcTextField,
		Play,
	},

	props: {
		/**
		 * The graph definition to execute ({name, nodes, edges, limits}).
		 */
		graph: {
			type: Object,
			required: true,
		},
	},

	emits: ['close', 'ran'],

	data() {
		return {
			subjectRegister: 'hermiq',
			subjectSchema: '',
			subjectUuid: '',
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
	},

	methods: {
		/**
		 * Execute the graph and hand the trace back to the builder.
		 *
		 * @return {Promise<void>}
		 */
		async run() {
			this.running = true
			this.error = ''
			try {
				const result = await runGraph(this.graph, this.subjectUuid, this.subjectRegister, this.subjectSchema)
				this.$emit('ran', result)
			} catch (e) {
				this.error = e?.response?.data?.error || e?.message || this.t('hermiq', 'The graph run failed.')
			} finally {
				this.running = false
			}
		},
	},
}
</script>

<style scoped>
.run-graph-dialog {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 0 12px 12px;
}
</style>
