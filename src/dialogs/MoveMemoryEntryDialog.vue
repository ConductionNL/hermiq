<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  - SPDX-License-Identifier: EUPL-1.2
-->

<template>
	<NcDialog
		:name="t('hermiq', 'Move to project')"
		:open="true"
		size="small"
		@update:open="$emit('close')">
		<p class="move-memory__text">
			{{ text }}
		</p>
		<NcSelect
			v-model="target"
			:inputLabel="t('hermiq', 'Project')"
			:options="options"
			:clearable="false"
			label="label"
			trackBy="value" />

		<template #actions>
			<NcButton variant="tertiary" :disabled="busy" @click="$emit('close')">
				{{ t('hermiq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="busy || !target"
				@click="$emit('confirm', target.value)">
				{{ t('hermiq', 'Move') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcSelect } from '@nextcloud/vue'

/**
 * MoveMemoryEntryDialog: which project a shared fact moves to
 * (memory-project-scopes). Emits `confirm` with the project key, or `close`;
 * the caller does the request.
 *
 * @spec openspec/changes/memory-project-scopes/specs/agent-memory/spec.md#requirement-an-owner-sees-and-moves-entries-between-layers-req-pmem-004
 */
export default {
	name: 'MoveMemoryEntryDialog',

	components: {
		NcButton,
		NcDialog,
		NcSelect,
	},

	props: {
		/** The text of the fact that moves. */
		text: {
			type: String,
			required: true,
		},

		/** The agent's projects ([{ projectKey, projectName }]). */
		projects: {
			type: Array,
			default: () => [],
		},

		/** True while the request runs. */
		busy: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['close', 'confirm'],

	data() {
		return {
			target: null,
		}
	},

	computed: {
		/**
		 * The projects as NcSelect options.
		 *
		 * @return {Array<{label: string, value: string}>} The options.
		 */
		options() {
			return this.projects.map((project) => ({
				label: project.projectName || project.projectKey,
				value: project.projectKey,
			}))
		},
	},
}
</script>

<style scoped>
.move-memory__text {
	font-weight: 600;
	margin-bottom: 8px;
}
</style>
