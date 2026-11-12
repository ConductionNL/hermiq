/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * How LlmProviderModal's form is filled from the stored LLM configuration.
 */

/**
 * Every form field read back from the stored configuration, in order, with the
 * value an absent or empty one falls back to.
 */
const STORED_FIELDS = [
	['openaiConfig', 'chatModel', ''],
	['openaiConfig', 'credentialId', ''],
	['ollamaConfig', 'url', ''],
	['ollamaConfig', 'chatModel', ''],
	['fireworksConfig', 'baseUrl', ''],
	['fireworksConfig', 'chatModel', ''],
	['fireworksConfig', 'credentialId', ''],
	['anthropicConfig', 'chatModel', ''],
	['anthropicConfig', 'credentialId', ''],
	['anthropicConfig', 'authMode', 'api_key'],
]

/**
 * Copy the stored (masked) configuration into the form, field by field.
 *
 * @param {object} form The modal's form.
 * @param {object} config The stored configuration.
 * @return {void}
 *
 * @spec openspec/changes/taskprocessing-consume-ui/tasks.md#task-2-2
 */
export function applyStoredConfig(form, config) {
	for (const [section, field, fallback] of STORED_FIELDS) {
		form[section][field] = (config[section] && config[section][field]) || fallback
	}
}

/**
 * The option carrying a value, or null when none does.
 *
 * @param {Array<{value: string}>} options The select options.
 * @param {string} value The value to find.
 * @return {object|null} The option.
 *
 * @spec openspec/changes/taskprocessing-consume-ui/tasks.md#task-2-2
 */
export function optionWithValue(options, value) {
	return options.find((option) => option.value === value) || null
}
