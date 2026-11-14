<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  - SPDX-License-Identifier: EUPL-1.2
-->

<template>
	<NcDialog
		:name="t('hermiq', 'Project')"
		:open="true"
		size="small"
		@update:open="$emit('close')">
		<p class="project-picker__intro">
			{{
				t(
					'hermiq',
					'In a project the agent remembers what belongs to that project apart from its shared memory.',
				)
			}}
		</p>
		<fieldset class="project-picker__choices" data-testid="project-picker">
			<legend class="hidden-visually">
				{{ t('hermiq', 'Project') }}
			</legend>
			<NcCheckboxRadioSwitch
				v-for="project in projects"
				:key="project.projectKey"
				v-model="choice"
				type="radio"
				name="project"
				:value="project.projectKey">
				{{ project.projectName || project.projectKey }}
			</NcCheckboxRadioSwitch>
			<NcCheckboxRadioSwitch
				v-model="choice"
				type="radio"
				name="project"
				:value="NEW">
				{{ t('hermiq', 'New project') }}
			</NcCheckboxRadioSwitch>
			<NcTextField
				v-if="choice === NEW"
				v-model="newName"
				class="project-picker__name"
				:label="t('hermiq', 'Name of the new project')"
				@keydown.enter="start" />
			<NcCheckboxRadioSwitch
				v-model="choice"
				type="radio"
				name="project"
				:value="NONE">
				{{ t('hermiq', 'No project') }}
			</NcCheckboxRadioSwitch>
		</fieldset>

		<template #actions>
			<NcButton variant="tertiary" @click="$emit('close')">
				{{ t('hermiq', 'Cancel') }}
			</NcButton>
			<NcButton variant="primary" :disabled="!canStart" @click="start">
				{{ t('hermiq', 'Start session') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcCheckboxRadioSwitch, NcDialog, NcTextField } from '@nextcloud/vue'
import { projectChoice } from '../utils/memoryProjects.js'

/** The radio value for "New project". */
const NEW = '__new__'

/** The radio value for "No project". */
const NONE = '__none__'

/**
 * ProjectPickerDialog: which project a new session works in (memory-project-scopes).
 * Lists the agent's projects, "New project" with a name, and "No project". Emits
 * `start` with `{ projectKey }`, `{ projectName }` or `null`, or `close`; the
 * caller starts the session.
 *
 * @spec openspec/changes/memory-project-scopes/specs/agent-memory/spec.md#requirement-an-agent-has-one-shared-memory-and-a-memory-per-project-req-pmem-001
 */
export default {
	name: 'ProjectPickerDialog',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcDialog,
		NcTextField,
	},

	props: {
		/** The agent's projects ([{ projectKey, projectName }]). */
		projects: {
			type: Array,
			default: () => [],
		},
	},

	emits: ['close', 'start'],

	data() {
		return {
			NEW,
			NONE,
			choice: NONE,
			newName: '',
		}
	},

	computed: {
		/**
		 * Whether the choice is complete: a new project needs a name.
		 *
		 * @return {boolean} True when the session can start.
		 */
		canStart() {
			return this.choice !== NEW || this.newName.trim() !== ''
		},
	},

	methods: {
		/**
		 * Emit the chosen project.
		 *
		 * @return {void}
		 * @spec openspec/changes/memory-project-scopes/specs/agent-memory/spec.md#requirement-an-agent-has-one-shared-memory-and-a-memory-per-project-req-pmem-001
		 */
		start() {
			if (!this.canStart) {
				return
			}
			this.$emit('start', projectChoice(this.choice, this.newName, { NEW, NONE }))
		},
	},
}
</script>

<style scoped>
.project-picker__intro {
	margin-bottom: 8px;
}

.project-picker__choices {
	border: none;
	padding: 0;
	margin: 0;
}

.project-picker__name {
	margin: 4px 0 8px 36px;
}
</style>
