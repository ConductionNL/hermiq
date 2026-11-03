/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @nextcloud/vue stand-ins: each renders a tag named after the component with
 * its props as data attributes and its slots inside, and an NcSelect, NcTextArea
 * or NcTextField emits `update:modelValue` when a test calls `pick(value)` on it.
 */

import { h } from 'vue'

/**
 * A stand-in component that shows its name, props and slots.
 *
 * @param {string} name The component name.
 * @return {object} The component options.
 */
function standIn(name) {
	return {
		name,
		inheritAttrs: false,
		emits: ['update:modelValue', 'click'],
		methods: {
			pick(value) {
				this.$emit('update:modelValue', value)
			},
		},
		render() {
			const props = {}
			for (const [key, value] of Object.entries(this.$attrs)) {
				if (typeof value !== 'function') {
					props['data-' + key.toLowerCase()] =
						typeof value === 'string' ? value : JSON.stringify(value)
				}
			}

			const slots = Object.values(this.$slots).map((slot) => slot())
			return h('nc-' + name.slice(2).toLowerCase(), props, slots)
		},
	}
}

export const NcAppSettingsSection = standIn('NcAppSettingsSection')
export const NcButton = standIn('NcButton')
export const NcCheckboxRadioSwitch = standIn('NcCheckboxRadioSwitch')
export const NcDialog = standIn('NcDialog')
export const NcEmptyContent = standIn('NcEmptyContent')
export const NcLoadingIcon = standIn('NcLoadingIcon')
export const NcModal = standIn('NcModal')
export const NcNoteCard = standIn('NcNoteCard')
export const NcSelect = standIn('NcSelect')
export const NcSettingsSection = standIn('NcSettingsSection')
export const NcTextArea = standIn('NcTextArea')
export const NcTextField = standIn('NcTextField')
