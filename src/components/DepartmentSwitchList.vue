<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  DepartmentSwitchList — the "Per department" list on the approvals page, next
  to the organisation emergency stop (oversight-switch-ai-off-per-department).

  One row per group an administrator marked as a department: an On/Off switch,
  who switched it last and when, its agent count, its last run, how many runs
  it refused since it was switched off, and a button that downloads the
  evidence file. Shown only to administrators (`is_admin`, read via loadState);
  the server refuses everyone else on every endpoint.

  @spec openspec/changes/oversight-switch-ai-off-per-department/specs/human-approval-gate/spec.md
-->
<template>
	<section
		v-if="isAdmin && (loading || error || departments.length > 0)"
		class="department-switches"
		:aria-label="t('hermiq', 'Per department')">
		<h3 class="department-switches__heading">
			{{ t('hermiq', 'Per department') }}
		</h3>

		<NcNoteCard
			v-if="error"
			type="error"
			:heading="t('hermiq', 'Department switch error')">
			{{ error }}
		</NcNoteCard>

		<div v-if="loading" class="department-switches__loading">
			<NcLoadingIcon :size="20" />
		</div>

		<table v-else-if="departments.length > 0" class="department-switches__table">
			<thead>
				<tr>
					<th scope="col">
						{{ t('hermiq', 'Department') }}
					</th>
					<th scope="col">
						{{ t('hermiq', 'AI switch') }}
					</th>
					<th scope="col">
						{{ t('hermiq', 'Switched by') }}
					</th>
					<th scope="col">
						{{ t('hermiq', 'Agents') }}
					</th>
					<th scope="col">
						{{ t('hermiq', 'Last run') }}
					</th>
					<th scope="col">
						{{ t('hermiq', 'Refused runs') }}
					</th>
					<th scope="col">
						<span class="hidden-visually">{{ t('hermiq', 'Evidence') }}</span>
					</th>
				</tr>
			</thead>
			<tbody>
				<tr
					v-for="department in departments"
					:key="department.group"
					:class="{ 'department-switches__row--off': department.aiOff }">
					<td>{{ department.label || department.group }}</td>
					<td>
						<NcCheckboxRadioSwitch
							:modelValue="!department.aiOff"
							:disabled="saving === department.group"
							:aria-label="t('hermiq', 'AI for {department}', { department: department.label || department.group })"
							type="switch"
							@update:modelValue="(on) => onToggle(department, on)">
							{{ department.aiOff ? t('hermiq', 'Off') : t('hermiq', 'On') }}
						</NcCheckboxRadioSwitch>
					</td>
					<td>
						<template v-if="department.switchedBy">
							{{ t('hermiq', '{user} at {time}', { user: department.switchedBy, time: dateOrDash(department.switchedAt) }) }}
						</template>
						<template v-else>
							—
						</template>
					</td>
					<td>{{ department.agentCount }}</td>
					<td>{{ dateOrDash(department.lastRun) }}</td>
					<td>{{ department.refusedRuns }}</td>
					<td>
						<NcButton
							:href="evidenceUrl(department.group)"
							:aria-label="t('hermiq', 'Download evidence for {department}', { department: department.label || department.group })"
							download
							variant="tertiary">
							{{ t('hermiq', 'Download evidence') }}
						</NcButton>
					</td>
				</tr>
			</tbody>
		</table>
	</section>
</template>

<script>
import { loadState } from '@nextcloud/initial-state'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcLoadingIcon,
	NcNoteCard,
} from '@nextcloud/vue'
import {
	departmentEvidenceUrl,
	listDepartments,
	toggleDepartment,
} from '../api/departments.js'
import { dateOrDash } from '../utils/displayDate.js'
import { errorMessage } from '../utils/errorMessage.js'

export default {
	name: 'DepartmentSwitchList',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcNoteCard,
	},

	data() {
		return {
			isAdmin: loadState('hermiq', 'is_admin', false) === true,
			departments: [],
			loading: false,
			saving: '',
			error: '',
		}
	},

	/**
	 * @spec openspec/changes/oversight-switch-ai-off-per-department/specs/human-approval-gate/spec.md#requirement-an-admin-shall-be-able-to-download-evidence-that-ai-was-off-for-a-department
	 */
	created() {
		if (this.isAdmin) {
			this.load()
		}
	},

	methods: {
		dateOrDash,

		/**
		 * The evidence download address of a department.
		 *
		 * @param {string} group The department's group id.
		 * @return {string} The URL.
		 * @spec openspec/changes/oversight-switch-ai-off-per-department/specs/human-approval-gate/spec.md#requirement-an-admin-shall-be-able-to-download-evidence-that-ai-was-off-for-a-department
		 */
		evidenceUrl(group) {
			return departmentEvidenceUrl(group)
		},

		/**
		 * Read the departments and their switches.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/oversight-switch-ai-off-per-department/specs/human-approval-gate/spec.md#requirement-an-admin-shall-be-able-to-download-evidence-that-ai-was-off-for-a-department
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				this.departments = await listDepartments()
			} catch (e) {
				this.error = errorMessage(e, this.t('hermiq', 'Unknown error'))
			} finally {
				this.loading = false
			}
		},

		/**
		 * Switch a department's AI on or off, then show the stored state.
		 *
		 * @param {object} department The department row.
		 * @param {boolean} on True to switch AI on.
		 * @return {Promise<void>}
		 * @spec openspec/changes/oversight-switch-ai-off-per-department/specs/human-approval-gate/spec.md#requirement-an-admin-shall-be-able-to-switch-ai-off-for-one-department
		 */
		async onToggle(department, on) {
			this.saving = department.group
			let failure = ''
			try {
				await toggleDepartment(department.group, !on, '')
			} catch (e) {
				failure = errorMessage(e, this.t('hermiq', 'Unknown error'))
			} finally {
				this.saving = ''
			}
			// Reflect the server's state, whether the switch held or failed, and
			// keep a failed switch's error in view after the reload.
			await this.load()
			if (failure !== '') {
				this.error = failure
			}
		},
	},
}
</script>

<style scoped>
.department-switches {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin-bottom: 16px;
	padding: 12px 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
}

.department-switches__heading {
	margin: 0;
	font-size: 1rem;
	font-weight: 600;
}

.department-switches__table {
	width: 100%;
	border-collapse: collapse;
}

.department-switches__table th,
.department-switches__table td {
	padding: 6px 8px;
	text-align: start;
	border-bottom: 1px solid var(--color-border);
}

.department-switches__table th {
	color: var(--color-text-maxcontrast);
	font-weight: normal;
}

.hidden-visually {
	position: absolute;
	width: 1px;
	height: 1px;
	overflow: hidden;
	clip: rect(0 0 0 0);
	white-space: nowrap;
}

.department-switches__row--off {
	box-shadow: inset 3px 0 0 0 var(--color-error);
}
</style>
