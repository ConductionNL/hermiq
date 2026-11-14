/**
 * The app an agent serves, on the agent form (agents-bound-to-their-app).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/specs/agent-management-ui/spec.md#requirement-an-agent-owner-ties-an-agent-to-the-app-it-serves-req-appag-001
 */

/**
 * The apps to choose from: the enabled apps of this instance (their web roots),
 * sorted, plus the agent's current app when the instance does not list it (a
 * built app lives under its builder, not as an installed app).
 *
 * @param {object|undefined} webroots The instance's app web roots, by app id.
 * @param {string} current The agent's current app slug.
 * @return {Array<{label: string, value: string}>} The options.
 * @spec openspec/specs/agent-management-ui/spec.md#requirement-an-agent-owner-ties-an-agent-to-the-app-it-serves-req-appag-001
 */
export function appOptions(webroots, current) {
	const apps = new Set(Object.keys(webroots || {}).filter((app) => app !== 'core'))
	const slug = appSlugOf(current)
	if (slug !== '') {
		apps.add(slug)
	}
	return [...apps].sort().map((app) => ({ label: app, value: app }))
}

/**
 * The slug stored in applicationSlug for a chosen option or a typed value.
 *
 * @param {object|string|null} choice The option, or a plain value.
 * @return {string} The slug, trimmed and lower case; '' for none.
 * @spec openspec/specs/agent-management-ui/spec.md#requirement-an-agent-owner-ties-an-agent-to-the-app-it-serves-req-appag-001
 */
export function appSlugOf(choice) {
	const value = choice && typeof choice === 'object' ? choice.value : choice
	return String(value || '')
		.trim()
		.toLowerCase()
}

/**
 * Whether the agent answers in its app's assistant: it was chosen for the same
 * app it serves now. Moving it to another app ends the choice.
 *
 * @param {object} agent The stored agent.
 * @return {boolean} True when it answers in its app.
 * @spec openspec/specs/agent-management-ui/spec.md#requirement-an-organisation-admin-picks-the-agent-that-answers-in-an-app-req-appag-002
 */
export function answersInItsApp(agent) {
	const app = appSlugOf(agent?.applicationSlug)
	return app !== '' && appSlugOf(agent?.appAssistantFor) === app
}
