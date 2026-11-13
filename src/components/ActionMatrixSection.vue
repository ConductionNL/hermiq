<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  The section "Rechten per actie" on the tenant management page: per governance
  action, its plain-language label and description and the groups allowed to run
  it, with save and back-to-default per action. Instance-wide (ADR-023), and
  shown to Nextcloud administrators only; the parent page decides that.

  @spec openspec/changes/action-matrix-screen/specs/action-authorization/spec.md#requirement-an-administrator-edits-the-action-matrix-on-the-tenant-management-page-req-actmx-001
-->
<template>
	<section class="action-matrix" data-testid="action-matrix">
		<h3 class="action-matrix__heading">
			{{ t('hermiq', 'Rights per action') }}
		</h3>
		<p class="action-matrix__hint" data-testid="action-matrix-scope">
			{{ t('hermiq', 'These rights apply to every organisation on this instance. Administrators can always run every action.') }}
		</p>
		<NcLoadingIcon v-if="loading" />
		<NcNoteCard v-else-if="error" type="error" data-testid="action-matrix-error">
			{{ error }}
		</NcNoteCard>
		<table v-else class="action-matrix__table">
			<thead>
				<tr>
					<th scope="col">
						{{ t('hermiq', 'Action') }}
					</th>
					<th scope="col">
						{{ t('hermiq', 'Groups') }}
					</th>
					<th scope="col">
						<span class="hidden-visually">{{ t('hermiq', 'Save or reset') }}</span>
					</th>
				</tr>
			</thead>
			<tbody>
				<tr
v-for="row in rows"
					:key="row.action"
					:data-action="row.action"
					data-testid="action-matrix-row">
					<td>
						<strong>{{ row.label }}</strong>
						<span v-if="row.changed" class="action-matrix__changed" data-testid="action-matrix-changed">
							{{ t('hermiq', 'Changed') }}
						</span>
						<p class="action-matrix__hint">
							{{ row.description }}
						</p>
					</td>
					<td class="action-matrix__groups">
						<NcSelect
v-model="drafts[row.action]"
							:inputLabel="t('hermiq', 'Groups allowed to: {action}', { action: row.label })"
							:options="groupOptions"
							:multiple="true"
							:disabled="busy === row.action" />
					</td>
					<td class="action-matrix__buttons">
						<NcButton
:disabled="busy === row.action || !isDirty(row)"
							data-testid="action-matrix-save"
							@click="save(row, drafts[row.action])">
							{{ t('hermiq', 'Save') }}
						</NcButton>
						<NcButton
variant="tertiary"
							:disabled="busy === row.action || !row.changed"
							data-testid="action-matrix-reset"
							@click="save(row, row.seeded)">
							{{ t('hermiq', 'Back to default') }}
						</NcButton>
					</td>
				</tr>
			</tbody>
		</table>
	</section>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { NcButton, NcLoadingIcon, NcNoteCard, NcSelect } from '@nextcloud/vue'
import { listActions, saveActionGroups } from '../api/actionMatrix.js'

/**
 * The groups, sorted and without duplicates, for comparing two selections.
 *
 * @param {Array<string>} groups The groups.
 * @return {string} A comparable key.
 */
function groupKey(groups) {
	return [...new Set(groups || [])].sort().join('\n')
}

export default {
	name: 'ActionMatrixSection',

	components: { NcButton, NcLoadingIcon, NcNoteCard, NcSelect },

	data() {
		return {
			rows: [],
			groups: [],
			drafts: {},
			loading: true,
			error: '',
			busy: '',
		}
	},

	computed: {
		/**
		 * The groups an administrator can choose; `admin` is kept by the server whatever is chosen.
		 *
		 * @return {Array<string>} The group ids.
		 */
		groupOptions() {
			return this.groups
		},
	},

	async mounted() {
		await this.load()
	},

	methods: {
		/**
		 * Load the matrix and reset the drafts to what is stored.
		 *
		 * @return {Promise<void>}
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				const data = await listActions()
				this.rows = Array.isArray(data.actions) ? data.actions : []
				this.groups = Array.isArray(data.groups) ? data.groups : []
				this.drafts = Object.fromEntries(this.rows.map((row) => [row.action, [...row.groups]]))
			} catch {
				this.error = t('hermiq', 'The rights per action could not be loaded.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Whether the draft for a row differs from what is stored.
		 *
		 * @param {object} row The row.
		 * @return {boolean} True when there is something to save.
		 */
		isDirty(row) {
			return groupKey(this.drafts[row.action]) !== groupKey(row.groups)
		},

		/**
		 * Store these groups for the row's action and show what the server kept.
		 *
		 * @param {object} row The row.
		 * @param {Array<string>} groups The groups to store.
		 * @return {Promise<void>}
		 */
		async save(row, groups) {
			this.busy = row.action
			try {
				const stored = await saveActionGroups(row.action, groups || [])
				this.rows = this.rows.map((r) => (r.action === row.action ? stored : r))
				this.drafts = { ...this.drafts, [row.action]: [...stored.groups] }
				showSuccess(t('hermiq', 'Rights for "{action}" saved.', { action: row.label }))
			} catch (e) {
				const message = e?.response?.data?.message
				showError(message || t('hermiq', 'The rights for "{action}" could not be saved.', { action: row.label }))
			} finally {
				this.busy = ''
			}
		},
	},
}
</script>

<style scoped>
.action-matrix__heading {
	font-size: 16px;
	font-weight: 600;
	margin: 0 0 8px;
}

.action-matrix__hint {
	color: var(--color-text-maxcontrast);
	margin: 4px 0;
}

.action-matrix__table {
	width: 100%;
	border-collapse: collapse;
}

.action-matrix__table th,
.action-matrix__table td {
	padding: 8px;
	text-align: start;
	vertical-align: top;
	border-bottom: 1px solid var(--color-border);
}

.action-matrix__groups {
	min-width: 240px;
}

.action-matrix__buttons {
	display: flex;
	gap: 8px;
	flex-wrap: wrap;
}

.action-matrix__changed {
	margin-inline-start: 8px;
	padding: 0 8px;
	border: 1px solid var(--color-warning);
	border-radius: var(--border-radius-pill);
	color: var(--color-warning-text);
	font-size: 0.9em;
}
</style>
