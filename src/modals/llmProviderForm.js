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
	['openaiConfig', 'baseUrl', ''],
	['openaiConfig', 'ownNetwork', false],
	['openaiConfig', 'apiMode', 'chat_completions'],
	['openaiCompatibleConfig', 'label', ''],
	['openaiCompatibleConfig', 'baseUrl', ''],
	['openaiCompatibleConfig', 'chatModel', ''],
	['openaiCompatibleConfig', 'credentialId', ''],
	['openaiCompatibleConfig', 'ownNetwork', false],
	['openaiCompatibleConfig', 'apiMode', 'chat_completions'],
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
 * The settings block a provider's fields live in.
 *
 * @param {string} provider The provider id.
 * @return {string} The block's key, such as `openaiCompatibleConfig`.
 * @spec openspec/changes/models-provider-connections/specs/llm-provider-connections/spec.md#requirement-an-admin-can-connect-an-openai-compatible-endpoint-by-name-req-pconn-001
 */
export function configKeyOf(provider) {
	if (provider === 'openai-compatible') {
		return 'openaiCompatibleConfig'
	}
	return `${provider}Config`
}

/**
 * The API path of a stored Fireworks base URL: the broker sends every call to
 * the host locked to the credential, so only the path is the admin's to set.
 *
 * @param {string} baseUrl A stored base URL, full or a bare path.
 * @return {string} The path, or '' when none is stored.
 * @spec openspec/changes/models-provider-connections/specs/llm-provider-connections/spec.md#requirement-a-chosen-host-is-reached-without-hermiq-holding-a-key-req-pconn-002
 */
export function apiPathOf(baseUrl) {
	const value = String(baseUrl || '').trim()
	if (value === '') {
		return ''
	}
	try {
		return new URL(value).pathname
	} catch {
		return value.startsWith('/') ? value : `/${value}`
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
