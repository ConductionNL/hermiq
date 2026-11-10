/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The organisation picker the kill switch and the tenant operations page share:
 * the component's `organisations` as NcSelect options, and the selected one
 * bridged to its `selectedOrg` id, reloading the page's data on a change.
 */

/**
 * The picker mixin for a component that reloads with the named method.
 *
 * @param {string} reload The component method that reloads for the chosen organisation.
 * @return {object} The mixin.
 */
export function organisationPicker(reload) {
	return {
		computed: {
			/**
			 * The manageable organisations as NcSelect options.
			 *
			 * @return {Array<object>} The { label, value } options.
			 * @spec openspec/changes/multi-tenant-ops/tasks.md#task-3-2
			 * @spec openspec/changes/human-approval-gate-ui/tasks.md#task-4-1
			 */
			orgOptions() {
				return this.organisations.map((org) => ({
					label: org.label || org.id,
					value: org.id,
				}))
			},

			/**
			 * Two-way bridge between the selected org id and the NcSelect option object.
			 */
			orgOption: {
				/**
				 * @return {object|undefined} The selected option, else the first.
				 * @spec openspec/changes/multi-tenant-ops/tasks.md#task-3-2
				 * @spec openspec/changes/human-approval-gate-ui/tasks.md#task-4-1
				 */
				get() {
					return (
						this.orgOptions.find(
							(option) => option.value === this.selectedOrg,
						) || this.orgOptions[0]
					)
				},

				/**
				 * @param {object|null} option The chosen option, or none.
				 * @return {void}
				 * @spec openspec/changes/multi-tenant-ops/tasks.md#task-3-2
				 * @spec openspec/changes/human-approval-gate-ui/tasks.md#task-4-1
				 */
				set(option) {
					this.selectedOrg = option ? option.value : ''
					this[reload]()
				},
			},
		},
	}
}
