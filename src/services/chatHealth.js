// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The chat health block on the Integrations page (spec-coverage-built-rows,
 * row `op-health`): whether chat works, with the AI provider, the default
 * model, and each of hermiq's connections with its state and check time.
 *
 * Pure: the three answers it reads (`GET /api/chat/health`, the masked LLM
 * settings, integriq's `app_connection` rows) are passed in, so it runs under
 * plain node. Only the fields named here are read, so nothing a source adds
 * later (a URL, a credential id, a key) can reach the page.
 *
 * @spec openspec/changes/spec-coverage-built-rows/specs/observability/spec.md#REQ-SPCOV-012
 */

/** Provider ids and the name the page shows for each. */
export const PROVIDER_NAMES = Object.freeze({
	openai: 'OpenAI',
	anthropic: 'Anthropic',
	ollama: 'Ollama',
	fireworks: 'Fireworks AI',
	nextcloud: 'Nextcloud Assistant',
})

/** Connection states in which the dependency answers. */
const ANSWERING = new Set(['configured', 'limited', 'simulated'])

/** Connection states in which the dependency was asked and did not answer. */
const NOT_ANSWERING = new Set(['error', 'unavailable'])

/**
 * Whether one connection answers: `yes`, `no` (asked, no answer) or `off`
 * (not set up or switched off, so nothing was asked).
 *
 * @param {unknown} status The row's stored status.
 * @return {'yes'|'no'|'off'} The state the page shows.
 * @spec openspec/changes/spec-coverage-built-rows/specs/observability/spec.md#REQ-SPCOV-012
 */
export function connectionState(status) {
	if (ANSWERING.has(status)) {
		return 'yes'
	}
	if (NOT_ANSWERING.has(status)) {
		return 'no'
	}
	return 'off'
}

/**
 * The default chat model of the chosen provider, or '' when it has none.
 *
 * @param {object|null} llm The masked `hermiq.llm` settings.
 * @param {string} provider The chosen provider id.
 * @return {string} The model name.
 * @spec openspec/changes/spec-coverage-built-rows/specs/observability/spec.md#REQ-SPCOV-012
 */
export function defaultModel(llm, provider) {
	const config = llm && provider ? llm[provider + 'Config'] : null
	const model = config && typeof config === 'object' ? config.chatModel : ''
	return typeof model === 'string' ? model : ''
}

/**
 * Build the block's view from the three answers.
 *
 * @param {{health: object|null, llm: object|null, connections: Array<object>|null}} sources
 *        The health answer (null when it failed), the LLM settings (null when
 *        unreadable) and the app's connection rows.
 * @return {{chatWorks: boolean, chatStatus: string, provider: string, model: string, rows: Array<{id: string, title: string, state: string, checkedAt: string}>}}
 *         The view; `chatStatus` is `ok`, `unconfigured` or `error`.
 * @spec openspec/changes/spec-coverage-built-rows/specs/observability/spec.md#REQ-SPCOV-012
 */
export function buildChatHealth({ health, llm, connections }) {
	let chatStatus = 'error'
	if (health && health.status === 'ok') {
		chatStatus = 'ok'
	} else if (health && health.status === 'unconfigured') {
		chatStatus = 'unconfigured'
	}

	const providerId = llm && typeof llm.chatProvider === 'string' ? llm.chatProvider : ''
	const provider = Object.hasOwn(PROVIDER_NAMES, providerId) ? PROVIDER_NAMES[providerId] : providerId

	const rows = (Array.isArray(connections) ? connections : [])
		.filter((row) => row && typeof row === 'object')
		.slice()
		.sort((a, b) => (Number(a.order) || 0) - (Number(b.order) || 0))
		.map((row) => ({
			id: String(row.key ?? row.id ?? row.title ?? ''),
			title: String(row.title ?? row.key ?? ''),
			state: connectionState(row.status),
			checkedAt: typeof row.checkedAt === 'string' ? row.checkedAt : '',
		}))

	return {
		chatWorks: chatStatus === 'ok',
		chatStatus,
		provider,
		model: defaultModel(llm, providerId),
		rows,
	}
}
