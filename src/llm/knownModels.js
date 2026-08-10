// SPDX-FileCopyrightText: 2026 Conduction B.V.
// SPDX-License-Identifier: EUPL-1.2
//
// The models each provider is known to serve.
//
// WHY THIS EXISTS
// ---------------
// The agent form offers a model DROPDOWN when the tenant's model policy
// enumerates models for the chosen provider, and a free-text box when it does
// not. On an instance with no policy — which is every instance until someone
// writes one — that meant typing `claude-opus-4-8` from memory into an empty
// box, with no indication of what the provider even serves.
//
// The names were not unknown to the app: `LlmProviderModal` has listed them for
// Anthropic in help text since it shipped ("Suggested models: claude-opus-4-8,
// …"). They were prose in one modal instead of data the other could use.
//
// THIS IS A FALLBACK, NEVER A CONSTRAINT. A tenant policy still wins where it
// exists, and free entry is still allowed everywhere: a provider ships new
// models faster than an app is released, and a hardcoded list that REFUSED an
// unlisted model would make this file the reason a new model cannot be used.
//
// @spec openspec/specs/agent-management-ui/spec.md

/**
 * Known model ids, by provider id.
 *
 * @type {Object<string, Array<string>>}
 */
export const KNOWN_MODELS = {
	anthropic: [
		'claude-opus-5',
		'claude-sonnet-5',
		'claude-fable-5',
		'claude-haiku-4-5',
		'claude-opus-4-8',
	],
	openai: [
		'gpt-4o',
		'gpt-4o-mini',
		'o3-mini',
	],
	ollama: [
		'llama3',
		'qwen2.5',
		'mistral',
	],
	fireworks: [
		'accounts/fireworks/models/llama-v3p1-8b-instruct',
		'accounts/fireworks/models/llama-v3p1-70b-instruct',
	],
}

/**
 * The models to offer for a provider.
 *
 * @param {string} provider The provider id.
 *
 * @return {Array<string>} The known models, or an empty list.
 */
export function knownModelsFor(provider) {
	return KNOWN_MODELS[String(provider || '').toLowerCase()] || []
}
