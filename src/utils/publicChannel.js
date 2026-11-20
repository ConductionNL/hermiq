// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The Public chat settings (delivery-public-web-chat): which agents may be
 * public, the payload a channel is saved with, and the two embed snippets a
 * website pastes. Pure, so they run under vitest.
 *
 * @spec openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-an-organisation-admin-declares-a-public-chat-channel-req-pubchat-001
 */

/**
 * The disclosure a new channel starts with.
 */
export const DEFAULT_DISCLOSURE = 'You are chatting with an AI assistant. It can make mistakes. Do not share personal details.'

/**
 * Whether an agent holds a tool grant: anything but an empty list or the `__none__` sentinel.
 *
 * @param {object} agent The agent.
 * @return {boolean} True when it holds one.
 * @spec openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-an-organisation-admin-declares-a-public-chat-channel-req-pubchat-001
 */
export function agentHasTools(agent) {
	const tools = Array.isArray(agent?.tools) ? agent.tools : []
	return tools.some((tool) => typeof tool === 'string' && tool.trim() !== '' && tool.trim() !== '__none__')
}

/**
 * The agents a channel may answer with, as picker options: only those without tools.
 *
 * @param {Array<object>} agents The agents.
 * @return {Array<{id: string, label: string}>} The options.
 * @spec openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-an-organisation-admin-declares-a-public-chat-channel-req-pubchat-001
 */
export function publicAgentOptions(agents) {
	return (Array.isArray(agents) ? agents : [])
		.filter((agent) => agent && !agentHasTools(agent))
		.map((agent) => ({ id: String(agent.id ?? agent.uuid ?? ''), label: String(agent.name || agent.id || '') }))
		.filter((option) => option.id !== '')
}

/**
 * Allowed origins from the text field: one per line or comma, trimmed, without a trailing slash.
 *
 * @param {string} text What the admin typed.
 * @return {Array<string>} The origins.
 * @spec openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-an-organisation-admin-declares-a-public-chat-channel-req-pubchat-001
 */
export function originsFrom(text) {
	return [...new Set(String(text || '').split(/[\n,]+/).map((o) => o.trim().replace(/\/+$/, '')).filter((o) => o !== ''))]
}

/**
 * The origins that are not a scheme and a host.
 *
 * @param {Array<string>} origins The origins.
 * @return {Array<string>} The wrong ones.
 * @spec openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-an-organisation-admin-declares-a-public-chat-channel-req-pubchat-001
 */
export function invalidOrigins(origins) {
	return origins.filter((origin) => !/^https?:\/\/[^/\s]+$/i.test(origin))
}

/**
 * The source scope from its text form: one `register/schema` per line, schema optional.
 *
 * @param {string} text What the admin typed.
 * @return {Array<{register: string, schema: string}>} The scope.
 * @spec openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-an-organisation-admin-declares-a-public-chat-channel-req-pubchat-001
 */
export function scopeFrom(text) {
	return String(text || '')
		.split('\n')
		.map((line) => line.trim())
		.filter((line) => line !== '')
		.map((line) => {
			const [register, schema = ''] = line.split('/').map((part) => part.trim())
			return { register, schema }
		})
		.filter((pair) => pair.register !== '')
}

/**
 * The source scope as text, the inverse of scopeFrom.
 *
 * @param {Array<{register: string, schema?: string}>} scope The scope.
 * @return {string} One pair per line.
 * @spec openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-an-organisation-admin-declares-a-public-chat-channel-req-pubchat-001
 */
export function scopeText(scope) {
	return (Array.isArray(scope) ? scope : [])
		.filter((pair) => pair && pair.register)
		.map((pair) => (pair.schema ? `${pair.register}/${pair.schema}` : pair.register))
		.join('\n')
}

/**
 * The editable form for a stored channel, or a new one.
 *
 * @param {object|null} channel The stored channel.
 * @return {object} The form.
 * @spec openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-an-organisation-admin-declares-a-public-chat-channel-req-pubchat-001
 */
export function channelForm(channel) {
	return {
		name: channel?.name || '',
		kind: channel?.kind === 'app' ? 'app' : 'website',
		channelKey: channel?.channelKey || '',
		agentId: channel?.agentId || '',
		origins: (Array.isArray(channel?.allowedOrigins) ? channel.allowedOrigins : []).join('\n'),
		scope: scopeText(channel?.sourceScope),
		disclosureText: channel?.disclosureText || DEFAULT_DISCLOSURE,
		dailyQuestionLimit: Number.isInteger(channel?.dailyQuestionLimit) ? channel.dailyQuestionLimit : 500,
		enabled: channel?.enabled === true,
	}
}

/**
 * The payload a channel is saved with. Spreads the stored channel first so
 * fields this form does not show, such as its token, survive.
 *
 * @param {object} form The form.
 * @param {object|null} stored The stored channel.
 * @return {object} The payload.
 * @spec openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-an-organisation-admin-declares-a-public-chat-channel-req-pubchat-001
 */
export function channelPayload(form, stored) {
	const base = stored ? { ...stored } : {}
	delete base['@self']
	const website = form.kind === 'website'
	return {
		...base,
		name: form.name.trim(),
		kind: form.kind,
		channelKey: website ? '' : form.channelKey.trim(),
		agentId: form.agentId,
		allowedOrigins: website ? originsFrom(form.origins) : [],
		sourceScope: scopeFrom(form.scope),
		disclosureText: form.disclosureText.trim() || DEFAULT_DISCLOSURE,
		dailyQuestionLimit: Math.max(1, parseInt(form.dailyQuestionLimit, 10) || 500),
		enabled: form.enabled === true,
	}
}

/**
 * What is wrong with a form before it is saved, as sentences; empty when nothing.
 *
 * @param {object} form The form.
 * @param {function(string, string): string} t Translates.
 * @return {Array<string>} The problems.
 * @spec openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-an-organisation-admin-declares-a-public-chat-channel-req-pubchat-001
 */
export function formProblems(form, t) {
	const problems = []
	if (form.name.trim() === '') {
		problems.push(t('hermiq', 'Name the channel.'))
	}
	if (form.agentId === '') {
		problems.push(t('hermiq', 'Pick the agent that answers on this channel.'))
	}
	if (form.kind === 'website' && invalidOrigins(originsFrom(form.origins)).length > 0) {
		problems.push(t('hermiq', 'An allowed origin is a scheme and a host, such as https://www.example.nl, without a path.'))
	}
	if (form.kind === 'app' && !/^app:[a-z0-9-]+:[A-Za-z0-9._-]+$/.test(form.channelKey.trim())) {
		problems.push(t('hermiq', 'An app channel key looks like app:portaliq:wonen.'))
	}
	return problems
}

/**
 * The two snippets a website pastes to show the chat.
 *
 * @param {string} token The channel's token.
 * @param {string} origin This instance's origin, such as https://cloud.example.nl.
 * @param {string} scriptPath The embed script's path on this instance.
 * @param {string} pagePath The chat page's path on this instance.
 * @return {{script: string, iframe: string}|null} The snippets, or null without a token.
 * @spec openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-an-organisation-admin-declares-a-public-chat-channel-req-pubchat-001
 */
export function embedSnippets(token, origin, scriptPath, pagePath) {
	if (typeof token !== 'string' || !/^[A-Za-z0-9]{16,64}$/.test(token)) {
		return null
	}
	return {
		script: `<script src="${origin}${scriptPath}?channel=${token}" async></script>`,
		iframe: `<iframe src="${origin}${pagePath}" title="Chat" width="380" height="560" style="border:0"></iframe>`,
	}
}
